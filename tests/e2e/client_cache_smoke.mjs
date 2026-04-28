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
const username = process.env.WALLOS_TEST_USERNAME || "";
const password = process.env.WALLOS_TEST_PASSWORD || "";
const headless = process.env.WALLOS_E2E_HEADLESS !== "0";
const artifactRoot = path.resolve(process.env.WALLOS_E2E_ARTIFACT_DIR || "screenshots/e2e");

if (!username || !password) {
  console.error("FAIL: WALLOS_TEST_USERNAME and WALLOS_TEST_PASSWORD are required.");
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
  viewport: { width: 1440, height: 1000 },
  ignoreHTTPSErrors: true,
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
  const screenshotPath = path.join(artifactRoot, `client-cache-smoke-${stamp}.png`);
  const htmlPath = path.join(artifactRoot, `client-cache-smoke-${stamp}.html`);
  const diagnosticsPath = path.join(artifactRoot, `client-cache-smoke-${stamp}.json`);

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

async function waitForAppHelpers() {
  await page.waitForFunction(() => {
    return typeof window.initializeCacheRefreshMarker === "function"
      && !!window.WallosClientCache
      && typeof window.WallosClientCache.clear === "function"
      && typeof window.WallosClientCache.status === "function";
  }, null, { timeout: 15000 });
}

function getPageScrollWidth() {
  return page.evaluate(() => Math.max(
    document.documentElement?.scrollWidth || 0,
    document.body?.scrollWidth || 0,
  ));
}

attachDiagnostics(page);

try {
  await step("login with test account", async () => {
    await page.goto(`${baseUrl}/login.php`, { waitUntil: "domcontentloaded" });
    await page.locator("#username").fill(username);
    await page.locator("#password").fill(password);
    await Promise.all([
      page.waitForURL((url) => !url.pathname.endsWith("/login.php"), { timeout: 15000 }).catch(() => null),
      page.locator('input[type="submit"]').click(),
    ]);

    if (page.url().includes("/login.php")) {
      throw new Error("login did not leave login.php");
    }
  });

  await step("client cache helpers load on authenticated page", async () => {
    await page.goto(`${baseUrl}/subscriptions.php`, { waitUntil: "domcontentloaded" });
    await page.locator("#subscriptions").waitFor({ state: "visible", timeout: 15000 });
    await waitForAppHelpers();

    const status = await page.evaluate(async () => window.WallosClientCache.status());
    if (!status || status.supported !== true) {
      throw new Error(`expected browser cache status support, got ${JSON.stringify(status)}`);
    }
    if (!Array.isArray(status.wallosCacheNames)) {
      throw new Error("cache status did not return wallosCacheNames");
    }
  });

  await step("unexpected login HTML is normalized as session expiry", async () => {
    await page.route("**/endpoints/e2e-login-html.php", async (route) => {
      await route.fulfill({
        status: 200,
        contentType: "text/html; charset=UTF-8",
        body: '<!doctype html><html><body><form action="login.php"><input id="username"><input id="password" type="password"></form><h1>Please login</h1></body></html>',
      });
    });

    const result = await page.evaluate(async () => {
      window.__wallosSessionExpiredEventSeen = false;
      const stopReloadHandler = (event) => {
        window.__wallosSessionExpiredEventSeen = true;
        event.stopImmediatePropagation();
      };
      window.addEventListener("wallos:session-expired", stopReloadHandler, true);

      try {
        await window.WallosHttp.getJson("endpoints/e2e-login-html.php");
        return { resolved: true, eventSeen: window.__wallosSessionExpiredEventSeen };
      } catch (error) {
        return {
          resolved: false,
          eventSeen: window.__wallosSessionExpiredEventSeen,
          sessionExpired: error?.sessionExpired === true,
          code: String(error?.code || ""),
          hasHtmlMarker: error?.data?.html_login_response === true,
        };
      } finally {
        window.removeEventListener("wallos:session-expired", stopReloadHandler, true);
      }
    });
    await page.unroute("**/endpoints/e2e-login-html.php").catch(() => null);

    if (result.resolved) {
      throw new Error("login HTML unexpectedly resolved as JSON");
    }
    if (!result.sessionExpired || !result.eventSeen || result.code !== "session_expired" || !result.hasHtmlMarker) {
      throw new Error(`login HTML was not normalized as session expiry: ${JSON.stringify(result)}`);
    }
  });

  await step("cache refresh notice is persistent and does not widen the page", async () => {
    await page.evaluate(() => {
      document.querySelector("#successToast .close-success")?.click();
      window.localStorage?.removeItem("wallos-client-cache-refresh-token");
    });

    const beforeScrollWidth = await getPageScrollWidth();
    await page.evaluate(() => {
      window.WallosCacheRefresh = {
        token: `e2e-cache-${Date.now()}`,
        requested_at: new Date().toISOString(),
      };
      window.initializeCacheRefreshMarker();
    });

    const toast = page.locator("#successToast.toast-persistent.active");
    await toast.waitFor({ state: "visible", timeout: 10000 });
    const toastText = (await toast.textContent()) || "";
    if (/translation missing/i.test(toastText) || toastText.trim().length < 10) {
      throw new Error(`cache refresh toast text is not usable: ${toastText}`);
    }

    await page.waitForTimeout(5500);
    if (!await toast.isVisible()) {
      throw new Error("client cache refresh prompt auto-hidden before manual close");
    }

    const layout = await page.evaluate(() => {
      const toastNode = document.querySelector("#successToast");
      const rect = toastNode ? toastNode.getBoundingClientRect() : null;
      const scrollWidth = Math.max(
        document.documentElement?.scrollWidth || 0,
        document.body?.scrollWidth || 0,
      );

      return {
        innerWidth: window.innerWidth,
        scrollWidth,
        rectLeft: rect ? rect.left : null,
        rectRight: rect ? rect.right : null,
      };
    });

    if (layout.rectLeft === null || layout.rectRight === null) {
      throw new Error("toast layout rectangle was not available");
    }

    if (layout.rectLeft < -2 || layout.rectRight > layout.innerWidth + 2) {
      throw new Error(`toast is outside viewport: ${JSON.stringify(layout)}`);
    }

    if (layout.scrollWidth > Math.max(beforeScrollWidth, layout.innerWidth) + 8) {
      throw new Error(`toast widened page: before=${beforeScrollWidth}, after=${layout.scrollWidth}, viewport=${layout.innerWidth}`);
    }

    await page.locator("#successToast .close-success").click();
    await toast.waitFor({ state: "hidden", timeout: 10000 }).catch(() => null);
  });

  assertNoBrowserRuntimeErrors();
  console.log("PASS: client cache browser E2E smoke completed.");
  await browser.close();
  process.exit(0);
} catch (error) {
  await writeFailureArtifacts(error);
  await browser.close();
  console.error(`FAIL: ${error.message || error}`);
  process.exit(1);
}
