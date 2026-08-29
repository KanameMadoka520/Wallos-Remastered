#!/usr/bin/env node

import fs from "node:fs/promises";
import path from "node:path";
import { chromium } from "playwright";

const baseUrl = (process.env.WALLOS_BASE_URL || "http://127.0.0.1:18282").replace(/\/$/, "");
const username = process.env.WALLOS_TEST_USERNAME || "";
const password = process.env.WALLOS_TEST_PASSWORD || "";
const rounds = Math.max(10, Number.parseInt(process.env.WALLOS_PERF_ROUNDS || "10", 10) || 10);
const outputPath = process.env.WALLOS_PERF_OUTPUT ? path.resolve(process.env.WALLOS_PERF_OUTPUT) : "";
const baselinePath = process.env.WALLOS_PERF_BASELINE ? path.resolve(process.env.WALLOS_PERF_BASELINE) : "";
const minimumWarmImprovement = Number.parseFloat(process.env.WALLOS_PERF_MIN_WARM_IMPROVEMENT || "30");
const maximumPageRegression = Number.parseFloat(process.env.WALLOS_PERF_MAX_PAGE_REGRESSION || "10");
const maximumNavigationP95 = Number.parseFloat(process.env.WALLOS_PERF_MAX_NAVIGATION_P95 || "100");
const headless = process.env.WALLOS_E2E_HEADLESS !== "0";
const targets = ["subscriptions.php", "calendar.php", "stats.php", "settings.php", "about.php"];
const modes = String(process.env.WALLOS_PERF_MODES || "cold,warm")
  .split(",")
  .map((value) => value.trim().toLowerCase())
  .filter((value) => value === "cold" || value === "warm");

if (!username || !password) {
  console.error("FAIL: WALLOS_TEST_USERNAME and WALLOS_TEST_PASSWORD are required.");
  process.exit(1);
}
if (modes.length === 0) {
  console.error("FAIL: WALLOS_PERF_MODES must include cold and/or warm.");
  process.exit(1);
}

function percentile(values, fraction) {
  if (values.length === 0) return 0;
  const sorted = [...values].sort((left, right) => left - right);
  return sorted[Math.max(0, Math.ceil(sorted.length * fraction) - 1)];
}

function summarize(samples) {
  const metrics = [
    "click_to_navigation_ms",
    "click_to_fetch_ms",
    "click_to_request_ms",
    "ttfb_ms",
    "dom_content_loaded_ms",
    "load_ms",
    "request_count",
    "transfer_bytes",
    "decoded_bytes",
    "long_task_count",
    "long_task_total_ms",
  ];
  const result = { samples: samples.length };
  for (const metric of metrics) {
    const values = samples.map((sample) => Number(sample[metric] || 0));
    result[metric] = {
      median: Math.round(percentile(values, 0.5) * 100) / 100,
      p95: Math.round(percentile(values, 0.95) * 100) / 100,
    };
  }
  return result;
}

async function installMetricsObserver(context) {
  await context.addInitScript(() => {
    window.__wallosBenchmarkLongTasks = [];
    try {
      const observer = new PerformanceObserver((list) => {
        for (const entry of list.getEntries()) {
          window.__wallosBenchmarkLongTasks.push(entry.duration);
        }
      });
      observer.observe({ type: "longtask", buffered: true });
    } catch (error) {
      // Long Task API is optional; a zero count remains a valid measurement.
    }
  });
}

async function login(page) {
  await page.goto(`${baseUrl}/login.php`, { waitUntil: "domcontentloaded" });
  await page.locator("#username").fill(username);
  await page.locator("#password").fill(password);
  await Promise.all([
    page.waitForURL((url) => !url.pathname.endsWith("/login.php"), { timeout: 20000 }),
    page.locator('input[type="submit"]').click(),
  ]);
  await page.waitForLoadState("load");
}

async function createModeSession(browser, mode) {
  const context = await browser.newContext({
    viewport: { width: 1440, height: 1000 },
    serviceWorkers: mode === "cold" ? "block" : "allow",
  });
  await installMetricsObserver(context);
  const page = await context.newPage();
  const cdp = await context.newCDPSession(page);
  await cdp.send("Network.enable");
  await cdp.send("Network.setCacheDisabled", { cacheDisabled: mode === "cold" });
  await login(page);

  if (mode === "warm") {
    for (const target of targets) {
      await page.goto(`${baseUrl}/${target}`, { waitUntil: "load" });
    }
    await page.goto(`${baseUrl}/index.php`, { waitUntil: "load" });
    await page.evaluate(async () => {
      if ("serviceWorker" in navigator) {
        await navigator.serviceWorker.ready.catch(() => null);
      }
    });
  }

  return { context, page, cdp };
}

async function measureNavigation(page, cdp, mode, target, round) {
  if (mode === "cold") {
    await cdp.send("Network.clearBrowserCache");
  }

  await page.evaluate((nextTarget) => {
    const existing = document.getElementById("wallos-performance-target");
    if (existing) existing.remove();
    const anchor = document.createElement("a");
    anchor.id = "wallos-performance-target";
    anchor.href = nextTarget;
    anchor.textContent = `Benchmark ${nextTarget}`;
    anchor.style.cssText = "position:fixed;left:4px;top:4px;z-index:2147483647;padding:4px;background:#fff;color:#000";
    anchor.addEventListener("click", () => {
      sessionStorage.setItem(
        "wallos-performance-click-epoch",
        String(performance.timeOrigin + performance.now()),
      );
    }, { capture: true, once: true });
    document.body.appendChild(anchor);
  }, target);
  const navigationPromise = page.waitForNavigation({ waitUntil: "load", timeout: 30000 });
  await page.locator("#wallos-performance-target").click({ noWaitAfter: true });
  await navigationPromise;
  await page.waitForTimeout(80);

  const metrics = await page.evaluate(() => {
    const navigation = performance.getEntriesByType("navigation")[0];
    const resources = performance.getEntriesByType("resource");
    const clickEpoch = Number(sessionStorage.getItem("wallos-performance-click-epoch") || 0);
    const longTasks = Array.isArray(window.__wallosBenchmarkLongTasks)
      ? window.__wallosBenchmarkLongTasks
      : [];
    const epoch = performance.timeOrigin;
    const requestStart = navigation.requestStart || navigation.fetchStart || 0;
    return {
      click_to_navigation_ms: Math.max(0, epoch - clickEpoch),
      click_to_fetch_ms: Math.max(0, epoch + (navigation.fetchStart || 0) - clickEpoch),
      click_to_request_ms: Math.max(0, epoch + requestStart - clickEpoch),
      ttfb_ms: Math.max(0, navigation.responseStart - requestStart),
      dom_content_loaded_ms: Math.max(0, epoch + navigation.domContentLoadedEventEnd - clickEpoch),
      load_ms: Math.max(0, epoch + navigation.loadEventEnd - clickEpoch),
      request_count: resources.length + 1,
      transfer_bytes: (navigation.transferSize || 0)
        + resources.reduce((sum, entry) => sum + (entry.transferSize || 0), 0),
      decoded_bytes: (navigation.decodedBodySize || 0)
        + resources.reduce((sum, entry) => sum + (entry.decodedBodySize || 0), 0),
      document_transfer_bytes: navigation.transferSize || 0,
      document_decoded_bytes: navigation.decodedBodySize || 0,
      long_task_count: longTasks.length,
      long_task_total_ms: longTasks.reduce((sum, duration) => sum + duration, 0),
      transition_enabled: !!window.pageTransitionEnabled,
      transition_style: String(window.pageTransitionStyle || ""),
    };
  });

  return {
    mode,
    target,
    round,
    ...Object.fromEntries(Object.entries(metrics).map(([key, value]) => [
      key,
      typeof value === "number" ? Math.round(value * 100) / 100 : value,
    ])),
  };
}

const browser = await chromium.launch({ headless });
const samples = [];
try {
  for (const mode of modes) {
    const session = await createModeSession(browser, mode);
    try {
      for (let round = 1; round <= rounds; round += 1) {
        for (const target of targets) {
          samples.push(await measureNavigation(session.page, session.cdp, mode, target, round));
        }
      }
    } finally {
      await session.context.close();
    }
  }
} finally {
  await browser.close();
}

const summary = {};
for (const mode of modes) {
  const modeSamples = samples.filter((sample) => sample.mode === mode);
  summary[mode] = { overall: summarize(modeSamples), pages: {} };
  for (const target of targets) {
    summary[mode].pages[target] = summarize(
      modeSamples.filter((sample) => sample.target === target),
    );
  }
}

const report = {
  generated_at: new Date().toISOString(),
  base_url: baseUrl,
  rounds,
  targets,
  modes,
  summary,
  samples,
};

let comparisonFailed = false;
if (baselinePath) {
  const baseline = JSON.parse(await fs.readFile(baselinePath, "utf8"));
  const baselineWarm = Number(baseline?.summary?.warm?.overall?.load_ms?.median || 0);
  const currentWarm = Number(summary?.warm?.overall?.load_ms?.median || 0);
  if (baselineWarm <= 0 || currentWarm <= 0) {
    throw new Error("Baseline and current reports must both contain warm overall load medians.");
  }

  const warmImprovementPercent = ((baselineWarm - currentWarm) / baselineWarm) * 100;
  const pageComparisons = {};
  for (const target of targets) {
    const baselineMedian = Number(baseline?.summary?.warm?.pages?.[target]?.load_ms?.median || 0);
    const currentMedian = Number(summary?.warm?.pages?.[target]?.load_ms?.median || 0);
    if (baselineMedian <= 0 || currentMedian <= 0) {
      throw new Error(`Missing warm load median for ${target}.`);
    }

    const changePercent = ((currentMedian - baselineMedian) / baselineMedian) * 100;
    pageComparisons[target] = {
      baseline_load_median_ms: baselineMedian,
      current_load_median_ms: currentMedian,
      change_percent: Math.round(changePercent * 100) / 100,
      passed: changePercent <= maximumPageRegression,
    };
    if (changePercent > maximumPageRegression) {
      comparisonFailed = true;
    }
  }

  const navigationP95 = Number(summary?.warm?.overall?.click_to_navigation_ms?.p95 || 0);
  report.comparison = {
    baseline_path: baselinePath,
    minimum_warm_improvement_percent: minimumWarmImprovement,
    maximum_page_regression_percent: maximumPageRegression,
    maximum_navigation_p95_ms: maximumNavigationP95,
    warm_improvement_percent: Math.round(warmImprovementPercent * 100) / 100,
    navigation_p95_ms: navigationP95,
    page_comparisons: pageComparisons,
    passed: warmImprovementPercent >= minimumWarmImprovement
      && navigationP95 <= maximumNavigationP95
      && !comparisonFailed,
  };
  comparisonFailed = !report.comparison.passed;
}

for (const mode of modes) {
  const overall = summary[mode].overall;
  console.log(
    `${mode}: samples=${overall.samples}`
      + ` click→navigation median=${overall.click_to_navigation_ms.median}ms p95=${overall.click_to_navigation_ms.p95}ms`
      + ` click→request median=${overall.click_to_request_ms.median}ms p95=${overall.click_to_request_ms.p95}ms`
      + ` load median=${overall.load_ms.median}ms p95=${overall.load_ms.p95}ms`
      + ` transfer median=${overall.transfer_bytes.median}B`,
  );
}

if (outputPath) {
  await fs.mkdir(path.dirname(outputPath), { recursive: true });
  await fs.writeFile(outputPath, `${JSON.stringify(report, null, 2)}\n`);
  console.log(`Report: ${outputPath}`);
} else {
  console.log(JSON.stringify(report));
}

if (report.comparison) {
  console.log(
    `comparison: warm improvement=${report.comparison.warm_improvement_percent}%`
      + ` navigation p95=${report.comparison.navigation_p95_ms}ms`
      + ` result=${report.comparison.passed ? "PASS" : "FAIL"}`,
  );
}

if (comparisonFailed) {
  process.exitCode = 1;
}
