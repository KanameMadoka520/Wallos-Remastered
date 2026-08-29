#!/usr/bin/env node

import fs from "node:fs/promises";
import path from "node:path";

let chromium;
try {
  ({ chromium } = await import("playwright"));
} catch (error) {
  console.error("SKIP: Playwright is not installed. Run `npm install` before this E2E check.");
  process.exit(77);
}

const baseUrl = (process.env.WALLOS_BASE_URL || "http://127.0.0.1:18282").replace(/\/$/, "");
const primaryUsername = process.env.WALLOS_TEST_USERNAME || "";
const primaryPassword = process.env.WALLOS_TEST_PASSWORD || "";
const secondaryUsername = process.env.WALLOS_SECOND_USERNAME || "";
const secondaryPassword = process.env.WALLOS_SECOND_PASSWORD || "";
const headless = process.env.WALLOS_E2E_HEADLESS !== "0";
const artifactRoot = path.resolve(process.env.WALLOS_E2E_ARTIFACT_DIR || "screenshots/e2e");
const navigationBudgetMs = 150;

if (!primaryUsername || !primaryPassword || !secondaryUsername || !secondaryPassword) {
  console.error(
    "FAIL: WALLOS_TEST_USERNAME, WALLOS_TEST_PASSWORD, "
      + "WALLOS_SECOND_USERNAME and WALLOS_SECOND_PASSWORD are required.",
  );
  process.exit(1);
}

if (primaryUsername === secondaryUsername) {
  console.error("FAIL: the primary and secondary E2E accounts must be different.");
  process.exit(1);
}

const secrets = [primaryPassword, secondaryPassword, primaryUsername, secondaryUsername]
  .filter((value) => value !== "")
  .sort((left, right) => right.length - left.length);
const diagnostics = {
  consoleErrors: [],
  pageErrors: [],
  failedRequests: [],
  failedResponses: [],
};
let diagnosticPhase = "startup";
let offlineProbeActive = false;

function redactSecrets(value) {
  let result = String(value || "");
  for (const secret of secrets) {
    result = result.split(secret).join("[REDACTED]");
  }
  return result;
}

function formatUrl(targetUrl) {
  return String(targetUrl || "").replace(baseUrl, "");
}

function shouldIgnoreConsoleError(message) {
  const normalizedMessage = String(message || "").toLowerCase();
  return normalizedMessage.includes("favicon.ico")
    || normalizedMessage.includes("net::err_aborted")
    || normalizedMessage.includes("failed to load resource: the server responded with a status of 404");
}

function attachDiagnostics(targetPage) {
  targetPage.on("console", (message) => {
    if (message.type() !== "error") {
      return;
    }

    const messageText = redactSecrets(message.text());
    if (!shouldIgnoreConsoleError(messageText)) {
      diagnostics.consoleErrors.push({
        phase: diagnosticPhase,
        expectedOffline: offlineProbeActive,
        text: messageText,
      });
    }
  });

  targetPage.on("pageerror", (error) => {
    diagnostics.pageErrors.push({
      phase: diagnosticPhase,
      expectedOffline: offlineProbeActive,
      text: redactSecrets(error?.stack || error?.message || String(error)),
    });
  });

  targetPage.on("requestfailed", (request) => {
    const failureText = request.failure()?.errorText || "";
    if (failureText.includes("net::ERR_ABORTED")) {
      return;
    }

    diagnostics.failedRequests.push({
      phase: diagnosticPhase,
      expectedOffline: offlineProbeActive,
      text: `${request.method()} ${formatUrl(request.url())} :: ${failureText}`,
    });
  });

  targetPage.on("response", (response) => {
    if (response.status() >= 500) {
      diagnostics.failedResponses.push({
        phase: diagnosticPhase,
        expectedOffline: false,
        text: `HTTP ${response.status()} ${formatUrl(response.url())}`,
      });
    }
  });

  targetPage.on("dialog", async (dialog) => {
    await dialog.dismiss().catch(() => {});
  });
}

function unexpectedDiagnostics() {
  return [
    ...diagnostics.pageErrors.map((entry) => ({ kind: "pageerror", ...entry })),
    ...diagnostics.consoleErrors.map((entry) => ({ kind: "console.error", ...entry })),
    ...diagnostics.failedRequests.map((entry) => ({ kind: "requestfailed", ...entry })),
    ...diagnostics.failedResponses.map((entry) => ({ kind: "response", ...entry })),
  ].filter((entry) => !entry.expectedOffline);
}

function assertNoUnexpectedBrowserErrors() {
  const problems = unexpectedDiagnostics();
  if (problems.length > 0) {
    throw new Error(
      `Browser diagnostics found ${problems.length} unexpected problem(s):\n`
        + problems.slice(0, 10).map((entry) => (
          `${entry.kind} [${entry.phase}]: ${entry.text}`
        )).join("\n"),
    );
  }
}

async function step(label, callback) {
  const startedAt = Date.now();
  diagnosticPhase = label;
  process.stdout.write(`STEP ${label} ... `);
  try {
    await callback();
    console.log(`${Date.now() - startedAt}ms`);
  } catch (error) {
    error.message = `${label}: ${redactSecrets(error.message || error)}`;
    console.log("FAIL");
    throw error;
  }
}

const browser = await chromium.launch({ headless });
const context = await browser.newContext({
  viewport: { width: 1440, height: 1000 },
  ignoreHTTPSErrors: true,
  serviceWorkers: "allow",
});
const page = await context.newPage();
attachDiagnostics(page);

async function writeFailureArtifacts(error) {
  await fs.mkdir(artifactRoot, { recursive: true });
  const stamp = new Date().toISOString().replace(/[:.]/g, "-");
  const screenshotPath = path.join(artifactRoot, `navigation-resilience-${stamp}.png`);
  const htmlPath = path.join(artifactRoot, `navigation-resilience-${stamp}.html`);
  const diagnosticsPath = path.join(artifactRoot, `navigation-resilience-${stamp}.json`);

  await page.screenshot({ path: screenshotPath, fullPage: true }).catch(() => null);
  const redactedHtml = redactSecrets(await page.content().catch(() => ""));
  await fs.writeFile(htmlPath, redactedHtml).catch(() => null);
  await fs.writeFile(diagnosticsPath, JSON.stringify({
    error: redactSecrets(error?.stack || error?.message || String(error)),
    url: page.url(),
    diagnostics,
  }, null, 2)).catch(() => null);

  console.error(`Artifacts: ${screenshotPath}`);
  console.error(`Artifacts: ${htmlPath}`);
  console.error(`Artifacts: ${diagnosticsPath}`);
}

async function waitForAuthenticatedShell(targetPage = page) {
  await targetPage.locator("#user").waitFor({ state: "attached", timeout: 20000 });
  await targetPage.waitForFunction(() => document.readyState !== "loading");
  await targetPage.waitForFunction(() => {
    return !document.documentElement.classList.contains("wallos-page-transition-loading");
  }, null, { timeout: 10000 }).catch(() => null);
}

async function login(targetPage, username, password) {
  await targetPage.goto(`${baseUrl}/login.php`, { waitUntil: "domcontentloaded", timeout: 30000 });
  await targetPage.locator("#username").fill(username);
  await targetPage.locator("#password").fill(password);
  await Promise.all([
    targetPage.waitForURL((url) => !url.pathname.endsWith("/login.php"), { timeout: 30000 }),
    targetPage.locator('input[type="submit"]').click(),
  ]);
  await waitForAuthenticatedShell(targetPage);
}

async function assertDisplayedAccount(targetPage, expectedUsername) {
  const displayedUsername = (await targetPage.locator("#user").textContent() || "").trim();
  if (displayedUsername !== expectedUsername) {
    throw new Error("the authenticated header belongs to a different account");
  }
}

async function waitForTransitionApi() {
  await page.waitForFunction(() => {
    return !!window.WallosPageTransitions
      && typeof window.WallosPageTransitions.configure === "function";
  }, null, { timeout: 15000 });
}

async function configureTransition(style, enabled = true) {
  await waitForTransitionApi();
  const state = await page.evaluate(({ nextStyle, nextEnabled }) => {
    window.WallosPageTransitions.configure({ style: nextStyle, enabled: nextEnabled });
    const html = document.documentElement;
    return {
      enabled: !!window.pageTransitionEnabled,
      style: String(window.pageTransitionStyle || ""),
      dataStyle: String(html.dataset.pageTransitionStyle || ""),
      enabledClass: html.classList.contains("wallos-page-transition-enabled"),
      overlayExists: !!document.getElementById("wallos-page-transition"),
    };
  }, { nextStyle: style, nextEnabled: enabled });

  if (state.enabled !== enabled || !state.overlayExists) {
    throw new Error("the page-transition enabled state was not applied");
  }
  if (state.style !== style || state.dataStyle !== style) {
    throw new Error(`the ${style} page-transition style was not applied`);
  }
  if (state.enabledClass !== enabled) {
    throw new Error("the page-transition CSS state does not match its configured state");
  }
}

async function visibleInternalLink(href) {
  const links = page.locator(`a[href="${href}"]`);
  for (let index = 0; index < await links.count(); index += 1) {
    const candidate = links.nth(index);
    if (await candidate.isVisible()) {
      return candidate;
    }
  }

  const dropdownButton = page.locator("header .dropbtn");
  if (await dropdownButton.isVisible()) {
    await dropdownButton.click();
    for (let index = 0; index < await links.count(); index += 1) {
      const candidate = links.nth(index);
      if (await candidate.isVisible()) {
        return candidate;
      }
    }
  }

  throw new Error(`no visible internal link exists for ${href}`);
}

function pathMatches(actualPath, expectedPath) {
  if (expectedPath === "/") {
    return actualPath === "/" || actualPath.endsWith("/index.php");
  }
  return actualPath.endsWith(expectedPath);
}

async function clickAndMeasureRequestStart(href, expectedPath, label) {
  const target = await visibleInternalLink(href);
  const timingKey = `wallos-navigation-resilience-${Date.now()}-${Math.random()}`;
  await target.evaluate((anchor, key) => {
    anchor.addEventListener("click", () => {
      sessionStorage.setItem(key, String(performance.timeOrigin + performance.now()));
    }, { capture: true, once: true });
  }, timingKey);

  const navigation = page.waitForNavigation({ waitUntil: "domcontentloaded", timeout: 30000 });
  await target.click({ noWaitAfter: true });
  await navigation;
  await waitForAuthenticatedShell();

  if (!pathMatches(new URL(page.url()).pathname, expectedPath)) {
    throw new Error(`${label} landed on an unexpected route`);
  }

  const clickToRequestMs = await page.evaluate((key) => {
    const clickedAt = Number(sessionStorage.getItem(key) || 0);
    sessionStorage.removeItem(key);
    const navigationEntry = performance.getEntriesByType("navigation")[0];
    if (!clickedAt || !navigationEntry) {
      return Number.NaN;
    }
    const requestStart = navigationEntry.requestStart || navigationEntry.fetchStart || 0;
    return Math.max(0, performance.timeOrigin + requestStart - clickedAt);
  }, timingKey);

  if (!Number.isFinite(clickToRequestMs)) {
    throw new Error(`${label} did not expose a valid navigation timing`);
  }
  if (clickToRequestMs >= navigationBudgetMs) {
    throw new Error(
      `${label} started its document request after ${Math.round(clickToRequestMs)}ms `
        + `(budget: <${navigationBudgetMs}ms)`,
    );
  }

  return clickToRequestMs;
}

async function goToAuthenticatedPath(pathname) {
  await page.goto(`${baseUrl}${pathname}`, { waitUntil: "domcontentloaded", timeout: 30000 });
  await waitForAuthenticatedShell();
  if (!pathMatches(new URL(page.url()).pathname, pathname)) {
    throw new Error(`direct navigation did not reach ${pathname}`);
  }
}

async function readWallosCacheViolations(targetPage = page) {
  return targetPage.evaluate(async () => {
    if (!("caches" in window)) {
      return [{ type: "unsupported", value: "CacheStorage is unavailable" }];
    }

    const violations = [];
    const cacheNames = await caches.keys();
    for (const cacheName of cacheNames) {
      if (cacheName.toLowerCase().includes("pages-cache")) {
        violations.push({ type: "cache-name", value: cacheName });
      }

      const cache = await caches.open(cacheName);
      const requests = await cache.keys();
      for (const request of requests) {
        const url = new URL(request.url);
        const pathname = url.pathname.toLowerCase();
        const forbiddenUrl = request.mode === "navigate"
          || request.destination === "document"
          || pathname.endsWith(".php")
          || pathname.includes("/endpoints/")
          || pathname.includes("/api/")
          || pathname.includes("/images/uploads/logos/subscription-media/");
        const response = await cache.match(request);
        const contentType = String(response?.headers.get("content-type") || "").toLowerCase();

        if (forbiddenUrl || contentType.includes("text/html")) {
          violations.push({
            type: contentType.includes("text/html") ? "html-response" : "forbidden-request",
            value: `${cacheName}:${url.pathname}`,
          });
        }
      }
    }

    return violations;
  });
}

async function assertNoPrivateCacheEntries(targetPage = page) {
  const violations = await readWallosCacheViolations(targetPage);
  if (violations.length > 0) {
    throw new Error(
      `CacheStorage contains ${violations.length} forbidden private/document entry or cache`,
    );
  }
}

async function ensureServiceWorkerReady() {
  await page.evaluate(async () => {
    if (!("serviceWorker" in navigator)) {
      throw new Error("service workers are unavailable");
    }
    await Promise.race([
      navigator.serviceWorker.ready,
      new Promise((resolve, reject) => {
        window.setTimeout(() => reject(new Error("service worker readiness timed out")), 15000);
      }),
    ]);
  });

  if (!await page.evaluate(() => !!navigator.serviceWorker.controller)) {
    await page.reload({ waitUntil: "domcontentloaded", timeout: 30000 });
    await waitForAuthenticatedShell();
    await page.waitForFunction(() => !!navigator.serviceWorker.controller, null, { timeout: 15000 });
  }
}

async function logout(targetPage = page) {
  await targetPage.goto(`${baseUrl}/logout.php`, { waitUntil: "domcontentloaded", timeout: 30000 });
  await targetPage.waitForURL((url) => url.pathname.endsWith("/login.php"), { timeout: 30000 });
  await targetPage.locator("#username").waitFor({ state: "visible", timeout: 15000 });
}

async function bestEffortLogout() {
  offlineProbeActive = false;
  await context.setOffline(false).catch(() => null);
  if (page.isClosed()) {
    return;
  }
  if (!page.url().includes("/login.php")) {
    await logout(page).catch(() => null);
  }
}

try {
  await step("real login with primary account", async () => {
    await login(page, primaryUsername, primaryPassword);
    await assertDisplayedAccount(page, primaryUsername);
    await ensureServiceWorkerReady();
  });

  await step("all transition styles and disabled mode keep navigation immediate", async () => {
    await goToAuthenticatedPath("/subscriptions.php");
    await configureTransition("shutter", true);
    await clickAndMeasureRequestStart(".", "/", "shutter navigation");

    await configureTransition("bluearchive", true);
    await clickAndMeasureRequestStart("subscriptions.php", "/subscriptions.php", "bluearchive navigation");

    await configureTransition("bluearchive_theme", true);
    await clickAndMeasureRequestStart(".", "/", "theme-driven Blue Archive navigation");

    await configureTransition("shutter", false);
    await clickAndMeasureRequestStart("subscriptions.php", "/subscriptions.php", "disabled-animation navigation");
  });

  await step("rapid double click does not crash or strand navigation", async () => {
    await goToAuthenticatedPath("/subscriptions.php");
    await configureTransition("bluearchive_theme", true);
    const target = await visibleInternalLink(".");
    const navigation = page.waitForNavigation({ waitUntil: "domcontentloaded", timeout: 30000 });
    await target.evaluate((anchor) => {
      anchor.dispatchEvent(new MouseEvent("click", {
        bubbles: true,
        cancelable: true,
        button: 0,
        view: window,
      }));
      anchor.dispatchEvent(new MouseEvent("click", {
        bubbles: true,
        cancelable: true,
        button: 0,
        view: window,
      }));
    });
    await navigation;
    await waitForAuthenticatedShell();
    if (!pathMatches(new URL(page.url()).pathname, "/")) {
      throw new Error("rapid double click did not finish on the expected page");
    }
  });

  await step("browser back and forward preserve authenticated navigation", async () => {
    await goToAuthenticatedPath("/subscriptions.php");
    await clickAndMeasureRequestStart(".", "/", "history setup navigation");

    await page.goBack({ waitUntil: "domcontentloaded", timeout: 30000 });
    await waitForAuthenticatedShell();
    if (!pathMatches(new URL(page.url()).pathname, "/subscriptions.php")) {
      throw new Error("browser back did not restore subscriptions");
    }

    await page.goForward({ waitUntil: "domcontentloaded", timeout: 30000 });
    await waitForAuthenticatedShell();
    if (!pathMatches(new URL(page.url()).pathname, "/")) {
      throw new Error("browser forward did not restore the dashboard");
    }
  });

  await step("375px mobile viewport can switch pages", async () => {
    await page.setViewportSize({ width: 375, height: 812 });
    await goToAuthenticatedPath("/subscriptions.php");
    await configureTransition("shutter", true);
    await clickAndMeasureRequestStart(".", "/", "mobile navigation");
    if (await page.evaluate(() => window.innerWidth) !== 375) {
      throw new Error("mobile navigation did not retain the 375px viewport");
    }
    await page.setViewportSize({ width: 1440, height: 1000 });
  });

  await step("300ms network latency does not delay navigation request start", async () => {
    await goToAuthenticatedPath("/subscriptions.php");
    await configureTransition("bluearchive", true);
    const cdp = await context.newCDPSession(page);
    try {
      await cdp.send("Network.enable");
      await cdp.send("Network.emulateNetworkConditions", {
        offline: false,
        latency: 300,
        downloadThroughput: 1_500_000,
        uploadThroughput: 750_000,
        connectionType: "cellular3g",
      });
      await clickAndMeasureRequestStart(".", "/", "slow-network navigation");
    } finally {
      await cdp.send("Network.emulateNetworkConditions", {
        offline: false,
        latency: 0,
        downloadThroughput: -1,
        uploadThroughput: -1,
        connectionType: "none",
      }).catch(() => null);
      await cdp.detach().catch(() => null);
    }
  });

  await step("CacheStorage contains no private pages or requests", async () => {
    await assertNoPrivateCacheEntries();
  });

  await step("logout and second-account login remain isolated", async () => {
    await logout(page);
    await login(page, secondaryUsername, secondaryPassword);
    await assertDisplayedAccount(page, secondaryUsername);
    const oldAccountVisible = await page.evaluate((oldUsername) => {
      return (document.querySelector("#user")?.textContent || "").trim() === oldUsername;
    }, primaryUsername);
    if (oldAccountVisible) {
      throw new Error("the previous account header was replayed after the second login");
    }
    await ensureServiceWorkerReady();
    await assertNoPrivateCacheEntries();
  });

  await step("offline navigation cannot replay logged-out authenticated HTML", async () => {
    const offlinePage = await context.newPage();
    attachDiagnostics(offlinePage);
    offlineProbeActive = true;
    await context.setOffline(true);

    let offlineNavigationSucceeded = false;
    try {
      await offlinePage.goto(`${baseUrl}/settings.php`, {
        waitUntil: "domcontentloaded",
        timeout: 15000,
      });
      offlineNavigationSucceeded = true;
    } catch (error) {
      // A failed document request is the secure expected result while offline.
    }

    const replayedAuthenticatedShell = await offlinePage.locator("#user").count().catch(() => 0) > 0;
    const replayedOldAccount = await offlinePage.evaluate((oldUsername) => {
      return document.body?.innerText?.includes(oldUsername) || false;
    }, primaryUsername).catch(() => false);

    if (offlineNavigationSucceeded || replayedAuthenticatedShell || replayedOldAccount) {
      throw new Error("offline navigation replayed an authenticated document");
    }

    await context.setOffline(false);
    offlineProbeActive = false;
    await offlinePage.close();

    await goToAuthenticatedPath("/about.php");
    await assertDisplayedAccount(page, secondaryUsername);
    await assertNoPrivateCacheEntries();
  });

  await step("browser diagnostics stay clean", async () => {
    assertNoUnexpectedBrowserErrors();
  });

  await step("logout cleanup", async () => {
    await logout(page);
  });

  console.log("PASS: navigation resilience E2E smoke completed without persistent test data.");
  await browser.close();
  process.exit(0);
} catch (error) {
  offlineProbeActive = false;
  await context.setOffline(false).catch(() => null);
  await writeFailureArtifacts(error);
  await bestEffortLogout();
  await browser.close();
  console.error(`FAIL: ${redactSecrets(error.message || error)}`);
  process.exit(1);
}
