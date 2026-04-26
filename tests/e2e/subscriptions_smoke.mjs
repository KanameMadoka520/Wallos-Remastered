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

let createdSubscriptionId = "";
let createdSubscriptionName = "";
let originalPreferences = null;

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
  const screenshotPath = path.join(artifactRoot, `subscriptions-smoke-${stamp}.png`);
  const htmlPath = path.join(artifactRoot, `subscriptions-smoke-${stamp}.html`);
  const diagnosticsPath = path.join(artifactRoot, `subscriptions-smoke-${stamp}.json`);

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

async function expectVisible(selector, label, timeout = 10000) {
  const locator = page.locator(selector);
  await locator.waitFor({ state: "visible", timeout });
  return locator;
}

async function clickFirst(selector, label) {
  const locator = page.locator(selector);
  const count = await locator.count();
  if (count < 1) {
    throw new Error(`${label} not found: ${selector}`);
  }

  await locator.first().click();
}

async function clickAndWaitForNavigation(locator, label) {
  const navigation = page.waitForEvent("framenavigated", { timeout: 15000 }).catch(() => null);
  await locator.click();
  const frame = await navigation;
  await page.waitForLoadState("domcontentloaded", { timeout: 15000 }).catch(() => null);

  if (!frame) {
    throw new Error(`${label} did not trigger a page navigation or reload`);
  }
}

async function waitForSubscriptionsShell() {
  await expectVisible("#subscriptions", "subscriptions container");
  await expectVisible("#subscription-page-tabs", "subscription page tabs");
  await page.locator("#subscription-page-loading-overlay.is-visible").waitFor({ state: "hidden", timeout: 15000 }).catch(() => null);
}

async function readCurrentPreferences() {
  return page.evaluate(() => {
    if (!window.subscriptionPagePreferences) {
      return null;
    }

    return JSON.parse(JSON.stringify(window.subscriptionPagePreferences));
  }).catch(() => null);
}

async function restorePreferences(preferences) {
  if (!preferences) {
    return;
  }

  await page.evaluate(async (payload) => {
    const headers = { "Content-Type": "application/json" };
    if (window.csrfToken) {
      headers["X-CSRF-Token"] = window.csrfToken;
    }

    await fetch("endpoints/settings/subscription_preferences.php", {
      method: "POST",
      headers,
      credentials: "same-origin",
      body: JSON.stringify({
        display_columns: payload.displayColumns || 1,
        value_visibility: payload.valueVisibility || { metrics: true, payment_records: true },
        image_layout_form: payload.imageLayout?.form || "focus",
        image_layout_detail: payload.imageLayout?.detail || "focus",
      }),
    });
  }, preferences).catch(() => null);
}

async function cleanupCreatedSubscription() {
  if (!createdSubscriptionId) {
    return;
  }

  await page.evaluate(async (subscriptionId) => {
    const headers = { "Content-Type": "application/json" };
    if (window.csrfToken) {
      headers["X-CSRF-Token"] = window.csrfToken;
    }

    await fetch("endpoints/subscription/delete.php", {
      method: "POST",
      headers,
      credentials: "same-origin",
      body: JSON.stringify({ id: Number(subscriptionId) }),
    }).catch(() => null);

    await fetch("endpoints/subscription/permanentdelete.php", {
      method: "POST",
      headers,
      credentials: "same-origin",
      body: JSON.stringify({ id: Number(subscriptionId) }),
    }).catch(() => null);
  }, createdSubscriptionId).catch(() => null);
}

async function openCreatedCardActions() {
  const card = page.locator(".subscription-container", { hasText: createdSubscriptionName }).first();
  await card.waitFor({ state: "visible", timeout: 15000 });
  const button = card.locator('[data-subscription-action="expand-subscription-actions"]').first();
  await button.click();
  await card.locator(".actions.is-open").waitFor({ state: "visible", timeout: 10000 });
  return card;
}

async function closeModal(selector, closeSelector, label) {
  const openModal = page.locator(`${selector}.is-open`);
  if (await openModal.count() < 1) {
    return;
  }

  await page.locator(closeSelector).first().click();
  await openModal.waitFor({ state: "hidden", timeout: 10000 }).catch(async () => {
    const isStillOpen = await page.locator(`${selector}.is-open`).count();
    if (isStillOpen > 0) {
      throw new Error(`${label} did not close`);
    }
  });
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
  await step("public login and registration pages expose purple theme", async () => {
    await page.goto(`${baseUrl}/login.php`, { waitUntil: "domcontentloaded" });
    const loginThemeColor = await page.locator('meta[name="theme-color"]').getAttribute("content");
    if (String(loginThemeColor || "").toLowerCase() !== "#6d4aff") {
      throw new Error(`login theme-color should be #6D4AFF, got ${loginThemeColor}`);
    }

    await page.goto(`${baseUrl}/registration.php`, { waitUntil: "domcontentloaded" });
    const registrationThemeColor = await page.locator('meta[name="theme-color"]').getAttribute("content");
    if (String(registrationThemeColor || "").toLowerCase() !== "#6d4aff") {
      throw new Error(`registration theme-color should be #6D4AFF, got ${registrationThemeColor}`);
    }
  });

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

  await step("subscriptions page shell loads", async () => {
    await page.goto(`${baseUrl}/subscriptions.php`, { waitUntil: "domcontentloaded" });
    await waitForSubscriptionsShell();
    originalPreferences = await readCurrentPreferences();
  });

  await step("subscription page tabs navigate and reload cleanly", async () => {
    const tabs = page.locator('[data-subscription-action="select-page-filter"]');
    const pageTabCount = await tabs.count();
    if (pageTabCount <= 1) {
      return;
    }

    const targetTab = tabs.nth(1);
    const targetFilter = await targetTab.getAttribute("data-filter");
    await clickAndWaitForNavigation(targetTab, `subscription tab ${targetFilter}`);
    await waitForSubscriptionsShell();

    const url = new URL(page.url());
    if (targetFilter !== "all" && url.searchParams.get("subscription_page") !== targetFilter) {
      throw new Error(`expected subscription_page=${targetFilter}, got ${url.searchParams.get("subscription_page")}`);
    }

    const allTab = page.locator('[data-subscription-action="select-page-filter"][data-filter="all"]').first();
    await clickAndWaitForNavigation(allTab, "all subscription tab");
    await waitForSubscriptionsShell();
  });

  await step("add subscription saves, closes modal, and refreshes card list", async () => {
    await clickFirst('[data-subscription-action="open-add-subscription"]', "add subscription button");
    await expectVisible("#subscription-form.is-open", "add subscription modal");
    createdSubscriptionName = `E2E Smoke ${Date.now()}`;
    await page.locator("#name").fill(createdSubscriptionName);
    await page.locator("#price").fill("1.23");
    await page.locator("#start_date").fill(new Date().toISOString().slice(0, 10));
    const nextDate = new Date(Date.now() + 31 * 24 * 60 * 60 * 1000).toISOString().slice(0, 10);
    await page.locator("#next_payment").fill(nextDate);
    await page.locator("#save-button").click();
    await page.locator("#subscription-form.is-open").waitFor({ state: "detached", timeout: 20000 }).catch(async () => {
      const stillOpen = await page.locator("#subscription-form.is-open").count();
      if (stillOpen > 0) {
        throw new Error("add subscription modal did not close after save");
      }
    });

    const createdCard = page.locator(".subscription-container", { hasText: createdSubscriptionName }).first();
    await createdCard.waitFor({ state: "visible", timeout: 20000 });
    createdSubscriptionId = await createdCard.getAttribute("data-id") || "";
    if (!createdSubscriptionId) {
      throw new Error("created subscription card did not expose data-id");
    }
  });

  await step("three-dot menu opens edit modal", async () => {
    const card = await openCreatedCardActions();
    await card.locator('[data-subscription-action="open-edit-subscription"]').first().click();
    await expectVisible("#subscription-form.is-open", "edit subscription modal", 15000);
    await closeModal("#subscription-form", '#subscription-form [data-subscription-action="close-add-subscription"]', "edit subscription modal");
  });

  await step("payment history and record-payment modal open", async () => {
    const card = await openCreatedCardActions();
    await card.locator('[data-subscription-action="open-payment-history"]').first().click();
    await expectVisible("#subscription-payment-history-modal.is-open", "payment history modal", 15000);
    await expectVisible("#subscription-payment-history-content", "payment history content", 15000);

    await page.locator("#subscription-payment-history-add-button").click();
    await expectVisible("#subscription-payment-modal.is-open", "record payment modal", 15000);
    await closeModal("#subscription-payment-modal", '#subscription-payment-modal [data-subscription-action="close-payment-modal"]', "record payment modal");
    await closeModal("#subscription-payment-history-modal", '#subscription-payment-history-modal [data-subscription-action="close-payment-history-modal"]', "payment history modal");
  });

  await step("optional image viewer opens when an uploaded image exists", async () => {
    const imageItems = page.locator('[data-subscription-action="open-subscription-image-viewer"]:visible');
    if (await imageItems.count() < 1) {
      return;
    }

    await imageItems.first().click();
    await expectVisible("#subscription-image-viewer.is-open", "image viewer", 15000);
    await expectVisible("#subscription-image-viewer-size-original", "image original size label", 15000);
    await closeModal("#subscription-image-viewer", '#subscription-image-viewer [data-subscription-action="close-image-viewer"]', "image viewer");
  });

  await step("display and value preference toggles persist by reload instead of half-rendering", async () => {
    const columnButton = page.locator('[data-subscription-action="set-display-columns"][data-columns="2"]').first();
    if (await columnButton.count()) {
      await clickAndWaitForNavigation(columnButton, "two-column preference toggle");
      await waitForSubscriptionsShell();
    }

    const metricButton = page.locator('[data-subscription-action="toggle-value-metric"][data-metric="metrics"]').first();
    if (await metricButton.count()) {
      await clickAndWaitForNavigation(metricButton, "cost/value metric preference toggle");
      await waitForSubscriptionsShell();
    }
  });

  await step("dynamic wallpaper mode keeps immersive toggle clickable", async () => {
    await page.evaluate(() => document.body.classList.add("dynamic-wallpaper-enabled"));
    const immersiveButton = page.locator("[data-page-immersive-toggle]").first();
    if (await immersiveButton.count()) {
      await immersiveButton.click({ trial: true });
    }
  });

  await step("CSRF refresh warning stays visible until manually closed", async () => {
    const didShow = await page.evaluate(() => {
      if (window.WallosHttp?.showCsrfTokenRefreshReminder) {
        return window.WallosHttp.showCsrfTokenRefreshReminder();
      }

      return false;
    });

    if (!didShow) {
      throw new Error("CSRF refresh reminder was not shown");
    }

    const toast = page.locator("#errorToast.toast-persistent.active");
    await toast.waitFor({ state: "visible", timeout: 10000 });
    await page.waitForTimeout(5500);
    if (!await toast.isVisible()) {
      throw new Error("CSRF refresh reminder auto-hidden before manual close");
    }

    await page.locator("#errorToast .close-error").click();
    await toast.waitFor({ state: "hidden", timeout: 10000 }).catch(() => null);
  });

  await step("cleanup temporary data and restore preferences", async () => {
    await restorePreferences(originalPreferences);
    await cleanupCreatedSubscription();
    createdSubscriptionId = "";
  });

  assertNoBrowserRuntimeErrors();
  console.log("PASS: subscriptions browser E2E smoke completed.");
  await browser.close();
  process.exit(0);
} catch (error) {
  await restorePreferences(originalPreferences);
  await cleanupCreatedSubscription();
  await writeFailureArtifacts(error);
  await browser.close();
  console.error(`FAIL: ${error.message || error}`);
  process.exit(1);
}
