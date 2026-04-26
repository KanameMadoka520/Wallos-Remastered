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
const username = process.env.WALLOS_ADMIN_USERNAME || process.env.WALLOS_TEST_USERNAME || "";
const password = process.env.WALLOS_ADMIN_PASSWORD || process.env.WALLOS_TEST_PASSWORD || "";
const headless = process.env.WALLOS_E2E_HEADLESS !== "0";
const artifactRoot = path.resolve(process.env.WALLOS_E2E_ARTIFACT_DIR || "screenshots/e2e");

if (!username || !password) {
  console.error("FAIL: WALLOS_ADMIN_USERNAME/WALLOS_ADMIN_PASSWORD or WALLOS_TEST_USERNAME/WALLOS_TEST_PASSWORD are required.");
  process.exit(1);
}

const diagnostics = {
  consoleErrors: [],
  pageErrors: [],
  failedRequests: [],
  failedResponses: [],
};

const browser = await chromium.launch({ headless });
const context = await browser.newContext({
  viewport: { width: 1600, height: 1100 },
  ignoreHTTPSErrors: true,
  acceptDownloads: true,
});
const page = await context.newPage();

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

    const text = message.text();
    if (!shouldIgnoreConsoleError(text)) {
      diagnostics.consoleErrors.push(text);
    }
  });

  targetPage.on("pageerror", (error) => {
    diagnostics.pageErrors.push(error?.stack || error?.message || String(error));
  });

  targetPage.on("requestfailed", (request) => {
    const failureText = request.failure()?.errorText || "";
    if (failureText.includes("net::ERR_ABORTED")) {
      return;
    }

    diagnostics.failedRequests.push(`${request.method()} ${formatUrl(request.url())} :: ${failureText}`);
  });

  targetPage.on("response", (response) => {
    const status = response.status();
    const responseUrl = response.url();
    const isEndpoint = responseUrl.includes("/endpoints/");
    if (status >= 500 || (isEndpoint && status >= 400)) {
      diagnostics.failedResponses.push(`HTTP ${status} ${formatUrl(responseUrl)}`);
    }
  });

  targetPage.on("dialog", async (dialog) => {
    await dialog.dismiss().catch(() => {});
  });
}

async function writeFailureArtifacts(error) {
  await fs.mkdir(artifactRoot, { recursive: true });
  const stamp = new Date().toISOString().replace(/[:.]/g, "-");
  const screenshotPath = path.join(artifactRoot, `admin-smoke-${stamp}.png`);
  const htmlPath = path.join(artifactRoot, `admin-smoke-${stamp}.html`);
  const diagnosticsPath = path.join(artifactRoot, `admin-smoke-${stamp}.json`);

  await page.screenshot({ path: screenshotPath, fullPage: true }).catch(() => null);
  await fs.writeFile(htmlPath, await page.content().catch(() => "")).catch(() => null);
  await fs.writeFile(diagnosticsPath, JSON.stringify({
    error: error?.stack || error?.message || String(error),
    url: page.url(),
    diagnostics,
  }, null, 2)).catch(() => null);

  console.error(`Artifacts: ${screenshotPath}`);
  console.error(`Artifacts: ${htmlPath}`);
  console.error(`Artifacts: ${diagnosticsPath}`);
}

async function step(label, callback) {
  const startedAt = Date.now();
  process.stdout.write(`STEP ${label} ... `);
  try {
    await callback();
    console.log(`${Date.now() - startedAt}ms`);
  } catch (error) {
    error.message = `${label}: ${error.message || error}`;
    console.log("FAIL");
    throw error;
  }
}

async function expectVisible(selector, label, timeout = 15000) {
  const locator = page.locator(selector).first();
  await locator.waitFor({ state: "visible", timeout });
  return locator;
}

async function waitForButtonEnabled(selector, timeout = 15000) {
  await page.waitForFunction((targetSelector) => {
    const element = document.querySelector(targetSelector);
    return !!element && !element.disabled;
  }, selector, { timeout });
}

async function waitForModalResults(backdropSelector, timeout = 15000) {
  await page.waitForFunction((selector) => {
    const backdrop = document.querySelector(selector);
    if (!backdrop) {
      return false;
    }

    const resultSummary = backdrop.querySelector(".access-log-results-summary");
    const results = backdrop.querySelector(".access-log-list, .access-log-empty");
    return !!resultSummary && resultSummary.textContent.trim().length > 0 && !!results;
  }, backdropSelector, { timeout });
}

async function closeBackdropModal(backdropSelector) {
  const closeButton = page.locator(`${backdropSelector} .access-log-modal-header button`).first();
  if (await closeButton.count()) {
    await closeButton.click();
  }

  await page.locator(backdropSelector).waitFor({ state: "hidden", timeout: 10000 }).catch(async () => {
    const stillVisible = await page.locator(backdropSelector).count();
    if (stillVisible > 0) {
      throw new Error(`Modal ${backdropSelector} did not close`);
    }
  });
}

async function waitForAdminShell() {
  await expectVisible("#admin-maintenance", "admin maintenance section");
  await expectVisible("#admin-backup", "admin backup section");
  await expectVisible("#backupDB", "backup button");

  const currentUrl = new URL(page.url());
  if (currentUrl.pathname.endsWith("/login.php") || currentUrl.pathname.endsWith("/index.php")) {
    throw new Error(`admin page redirected to ${currentUrl.pathname}`);
  }
}

function assertNoBrowserRuntimeErrors() {
  const problems = [
    ...diagnostics.pageErrors.map((entry) => `pageerror: ${entry}`),
    ...diagnostics.consoleErrors.map((entry) => `console.error: ${entry}`),
    ...diagnostics.failedRequests.map((entry) => `requestfailed: ${entry}`),
    ...diagnostics.failedResponses.map((entry) => `response: ${entry}`),
  ];

  if (problems.length > 0) {
    throw new Error(`Browser diagnostics found ${problems.length} problem(s):\n${problems.slice(0, 8).join("\n")}`);
  }
}

attachDiagnostics(page);

try {
  await step("login with administrator account", async () => {
    await page.goto(`${baseUrl}/login.php`, { waitUntil: "domcontentloaded" });
    await page.locator("#username").fill(username);
    await page.locator("#password").fill(password);
    await Promise.all([
      page.waitForURL((url) => !url.pathname.endsWith("/login.php"), { timeout: 20000 }).catch(() => null),
      page.locator('input[type="submit"]').click(),
    ]);

    if (page.url().includes("/login.php")) {
      throw new Error("login did not leave login.php");
    }
  });

  await step("administrator shell opens cleanly", async () => {
    await page.goto(`${baseUrl}/admin.php`, { waitUntil: "domcontentloaded" });
    await waitForAdminShell();
    await expectVisible("#admin-client-cache-state", "client cache status card");
    await page.waitForFunction(() => {
      const node = document.getElementById("admin-client-cache-state");
      return !!node && node.textContent.trim().length > 1;
    }, null, { timeout: 15000 });
  });

  await step("runtime observability refreshes cleanly", async () => {
    await page.locator("#refreshRuntimeObservabilityButton").click();
    await waitForButtonEnabled("#refreshRuntimeObservabilityButton");
    await page.waitForFunction(() => {
      const feed = document.querySelector("[data-observability-feed]");
      return !!feed && feed.textContent.trim().length > 0;
    }, null, { timeout: 15000 });
  });

  await step("service worker cache refresh request updates observability marker", async () => {
    const beforeText = (await page.locator("[data-observability-cache-refresh]").textContent())?.trim() || "";
    await page.waitForTimeout(1200);
    await page.locator("#requestClientCacheRefreshButton").click();
    await waitForButtonEnabled("#requestClientCacheRefreshButton");
    await page.locator("#refreshRuntimeObservabilityButton").click();
    await waitForButtonEnabled("#refreshRuntimeObservabilityButton");
    const afterText = (await page.locator("[data-observability-cache-refresh]").textContent())?.trim() || "";

    if (!afterText) {
      throw new Error("cache refresh marker stayed empty after requesting a refresh");
    }

    if (beforeText === afterText) {
      throw new Error(`cache refresh marker did not change after refresh request (still ${afterText})`);
    }
  });

  await step("access log modal opens, searches, and closes", async () => {
    await page.locator("#openAccessLogsButton").click();
    await expectVisible("#admin-access-log-backdrop .access-log-modal", "access log modal");
    await waitForModalResults("#admin-access-log-backdrop");

    await page.locator("#accessLogMethod").selectOption("POST");
    await page.locator("#admin-access-log-backdrop .access-log-filter-actions .button").click();
    await waitForModalResults("#admin-access-log-backdrop");
    await closeBackdropModal("#admin-access-log-backdrop");
  });

  await step("security anomaly modal opens with scoped filter and closes", async () => {
    await page.locator("#openClientRuntimeAnomaliesButton").click();
    await expectVisible("#admin-security-anomaly-backdrop .access-log-modal", "security anomaly modal");
    await waitForModalResults("#admin-security-anomaly-backdrop");
    const selectedType = await page.locator("#securityAnomalyType").inputValue();
    if (selectedType !== "client_runtime") {
      throw new Error(`expected client_runtime anomaly filter, got ${selectedType}`);
    }
    await closeBackdropModal("#admin-security-anomaly-backdrop");
  });

  await step("storage usage refresh repaints maintenance summary", async () => {
    await expectVisible("#adminMaintenanceStorageSummary .maintenance-storage-card", "maintenance storage cards");
    await page.locator("#refreshStorageUsageButton").click();
    await page.waitForFunction(() => {
      const result = document.getElementById("adminMaintenanceResult");
      if (!result) {
        return false;
      }

      const text = String(result.value || "").trim();
      return text.length > 20 && !text.toLowerCase().includes("running");
    }, null, { timeout: 30000 });
  });

  await step("backup settings save and manual backup flow complete", async () => {
    const backupNameBefore = (await page.locator(".backup-card code").first().textContent().catch(() => ""))?.trim() || "";
    await page.locator("#saveBackupSettingsButton").click();
    await waitForButtonEnabled("#saveBackupSettingsButton");

    const downloadPromise = page.waitForEvent("download", { timeout: 180000 }).catch(() => null);
    await page.locator("#backupDB").click();
    await expectVisible("#backupProgressCard", "backup progress card", 20000);
    await page.waitForFunction(() => {
      const percentNode = document.getElementById("backupProgressPercent");
      const progressValue = Number.parseInt(percentNode?.textContent || "0", 10);
      return Number.isFinite(progressValue) && progressValue >= 100;
    }, null, { timeout: 180000 });

    const download = await downloadPromise;
    if (download) {
      await download.delete().catch(() => {});
    }

    await page.waitForLoadState("domcontentloaded", { timeout: 30000 }).catch(() => null);
    await expectVisible(".backup-card", "backup card after backup", 30000);
    const backupNameAfter = (await page.locator(".backup-card code").first().textContent())?.trim() || "";

    if (!backupNameAfter) {
      throw new Error("backup list did not render a newest backup entry");
    }

    if (backupNameBefore && backupNameBefore === backupNameAfter) {
      throw new Error(`latest backup card did not change after manual backup (${backupNameAfter})`);
    }
  });

  await step("backup verification updates card state", async () => {
    await page.locator(".backup-verify-button").first().click();
    await page.waitForFunction(() => {
      const status = document.querySelector(".backup-card [data-backup-status]");
      if (!status) {
        return false;
      }

      return status.classList.contains("is-success")
        || status.classList.contains("is-warning")
        || status.classList.contains("is-error");
    }, null, { timeout: 30000 });
  });

  assertNoBrowserRuntimeErrors();
  console.log("PASS: admin browser E2E smoke completed.");
  await browser.close();
  process.exit(0);
} catch (error) {
  await writeFailureArtifacts(error);
  await browser.close();
  console.error(`FAIL: ${error.message || error}`);
  process.exit(1);
}
