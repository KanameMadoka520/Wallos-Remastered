#!/usr/bin/env node

import assert from "node:assert/strict";

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

if (!username || !password) {
  console.error("FAIL: WALLOS_TEST_USERNAME and WALLOS_TEST_PASSWORD are required.");
  process.exit(1);
}

const browser = await chromium.launch({ headless });
const context = await browser.newContext({
  viewport: { width: 1440, height: 1000 },
  ignoreHTTPSErrors: true,
  serviceWorkers: "block",
});
const page = await context.newPage();
const sensitiveMediaRequests = [];
const diagnostics = [];
let collectSensitiveMedia = false;
let expectedConflictProbe = false;

page.on("request", (request) => {
  if (!collectSensitiveMedia) return;
  const url = request.url();
  if (/\/images\/uploads\/logos\/(?!avatars\/)/.test(url)
    || /\/endpoints\/media\/subscriptionimage\.php/.test(url)) {
    sensitiveMediaRequests.push(url.replace(baseUrl, ""));
  }
});
page.on("pageerror", (error) => diagnostics.push(`pageerror: ${error.message}`));
page.on("console", (message) => {
  if (expectedConflictProbe && message.type() === "error" && message.text().includes("409")) return;
  if (message.type() === "error" && !message.text().includes("favicon.ico")) {
    diagnostics.push(`console.error: ${message.text()}`);
  }
});
page.on("dialog", async (dialog) => dialog.dismiss().catch(() => {}));

async function waitForShell() {
  await page.locator("#user").waitFor({ state: "attached", timeout: 20000 });
  await page.waitForFunction(() => document.readyState !== "loading");
  await page.waitForFunction(() => {
    return !document.documentElement.classList.contains("wallos-page-transition-loading");
  }, null, { timeout: 12000 }).catch(() => null);
}

async function gotoAuthenticated(path) {
  await page.goto(`${baseUrl}/${path.replace(/^\//, "")}`, {
    waitUntil: "domcontentloaded",
    timeout: 30000,
  });
  await waitForShell();
}

async function login() {
  await page.goto(`${baseUrl}/login.php`, { waitUntil: "domcontentloaded", timeout: 30000 });
  await page.locator("#username").fill(username);
  await page.locator("#password").fill(password);
  await Promise.all([
    page.waitForURL((url) => !url.pathname.endsWith("/login.php"), { timeout: 30000 }),
    page.locator('input[type="submit"]').click(),
  ]);
  await waitForShell();
}

async function waitForPrivacyMask() {
  await page.waitForFunction(() => (
    document.documentElement.classList.contains("wallos-screenshot-privacy-enabled")
      && window.WallosScreenshotPrivacy?.isEnabled?.() === true
  ), null, { timeout: 15000 });
  await page.locator("#subscriptions .subscription").first().waitFor({ state: "visible", timeout: 15000 }).catch(() => null);
  await page.waitForFunction(() => {
    const cards = [...document.querySelectorAll("#subscriptions .subscription")];
    return cards.length === 0 || cards.every((card) => card.classList.contains("wallos-privacy-masked"));
  }, null, { timeout: 15000 });
}

async function togglePrivacy(expectedEnabled) {
  await gotoAuthenticated("settings.php#settings-display");
  const checkbox = page.locator("#screenshotprivacymode");
  await checkbox.waitFor({ state: "visible", timeout: 15000 });
  await page.waitForFunction(() => typeof window.setScreenshotPrivacyMode === "function", null, { timeout: 15000 });
  assert.equal(await checkbox.isChecked(), !expectedEnabled, "privacy toggle did not start in the expected state");
  let endpointResponseError = null;
  const endpointResponsePromise = page.waitForResponse((response) => (
    response.url().includes("/endpoints/settings/screenshot_privacy_mode.php")
  ), { timeout: 15000 }).catch((error) => {
    endpointResponseError = error;
    return null;
  });
  let navigationError = null;
  const navigationPromise = page.waitForNavigation({ waitUntil: "domcontentloaded", timeout: 20000 })
    .catch((error) => {
      navigationError = error;
      return null;
    });
  await checkbox.click({ force: true });
  const endpointResponse = await endpointResponsePromise;
  if (endpointResponseError) throw endpointResponseError;
  assert.equal(endpointResponse.ok(), true, "privacy setting endpoint rejected the toggle");
  await navigationPromise;
  if (navigationError) throw navigationError;
  await waitForShell();
  assert.equal(
    await page.locator("#screenshotprivacymode").isChecked(),
    expectedEnabled,
    "privacy toggle did not persist across the reload",
  );
  assert.equal(
    await page.locator("html").evaluate((element) => element.classList.contains("wallos-screenshot-privacy-enabled")),
    expectedEnabled,
    "server did not emit the matching fail-closed root state",
  );
}

try {
  await login();

  await gotoAuthenticated("subscriptions.php");
  const firstCard = page.locator("#subscriptions .subscription").first();
  await firstCard.waitFor({ state: "visible", timeout: 15000 });
  const baseline = await page.evaluate(() => {
    const card = document.querySelector("#subscriptions .subscription");
    const text = (selector) => (card?.querySelector(selector)?.textContent || "").trim();
    const pageNames = (window.subscriptionPageState?.pages || []).map((item) => String(item.name || "").trim()).filter(Boolean);
    return {
      name: text(".subscription-main > .name") || text(".subscription-secondary > .name"),
      price: text(".subscription-main .price .value"),
      logo: card?.querySelector(".subscription-main .logo img")?.getAttribute("src") || "",
      pageNames,
    };
  });
  assert.ok(baseline.name, "a real subscription name is required for the privacy comparison");
  assert.ok(baseline.price, "a real subscription price is required for the privacy comparison");
  assert.equal(await page.locator(".wallos-header-edition").first().textContent(), "[Remastered]");

  await gotoAuthenticated("stats.php");
  const baselineFilter = await page.evaluate(() => {
    const item = document.querySelector('.filter-item[data-memberid],.filter-item[data-categoryid],.filter-item[data-paymentid]');
    if (!item) return null;
    const type = item.hasAttribute("data-memberid") ? "member"
      : (item.hasAttribute("data-categoryid") ? "category" : "payment");
    return {
      type,
      id: item.getAttribute(`data-${type}id`),
      label: (item.textContent || "").trim(),
    };
  });

  const staleTab = await context.newPage();
  await staleTab.goto(`${baseUrl}/subscriptions.php`, { waitUntil: "domcontentloaded", timeout: 30000 });
  await staleTab.locator("#user").waitFor({ state: "attached", timeout: 15000 });
  assert.equal(
    await staleTab.locator("html").evaluate((element) => element.classList.contains("wallos-screenshot-privacy-enabled")),
    false,
    "cross-tab test did not start from a real-data page",
  );

  await togglePrivacy(true);
  await staleTab.waitForFunction(() => (
    document.documentElement.classList.contains("wallos-screenshot-privacy-enabled")
      && window.WallosScreenshotPrivacy?.isEnabled?.() === true
  ), null, { timeout: 20000 });
  assert.equal(
    (await staleTab.locator("body").innerText()).includes(baseline.name),
    false,
    "an already-open tab did not reload into the privacy state",
  );
  await staleTab.close();
  collectSensitiveMedia = true;

  await gotoAuthenticated("subscriptions.php");
  await waitForPrivacyMask();
  const privateView = await page.evaluate(() => {
    const cards = [...document.querySelectorAll("#subscriptions .subscription")];
    const visibleText = document.querySelector("#subscriptions")?.innerText || "";
    return {
      visibleText,
      names: cards.map((card) => (card.querySelector(".subscription-main > .name")?.textContent || "").trim()),
      prices: cards.map((card) => (card.querySelector(".subscription-main .price .value")?.textContent || "").trim()),
      imageSources: cards.flatMap((card) => [...card.querySelectorAll("img")].map((image) => image.currentSrc || image.src)),
      allMasked: cards.every((card) => card.classList.contains("wallos-privacy-masked")),
      pageNames: (window.subscriptionPageState?.pages || []).map((item) => String(item.name || "").trim()).filter(Boolean),
    };
  });
  assert.equal(privateView.allMasked, true, "not every subscription card reached the masked state");
  assert.equal(privateView.visibleText.includes(baseline.name), false, "a real subscription name remains visible");
  assert.equal(privateView.prices.includes(baseline.price), false, "a real subscription price remains visible");
  assert.equal(privateView.imageSources.every((source) => source.startsWith("data:image/svg+xml")), true, "a subscription image was not replaced with an in-memory demo icon");
  for (const pageName of baseline.pageNames) {
    assert.equal(privateView.pageNames.includes(pageName), false, "a real custom-page name remains in the privacy state");
  }

  const customPageTab = page.locator('.subscription-page-tab[data-filter]:not([data-filter="all"]):not([data-filter="unassigned"])').first();
  if (await customPageTab.count()) {
    const responsePromise = page.waitForResponse((response) => (
      response.url().includes("/endpoints/subscriptions/get.php") && response.request().method() === "GET"
    ), { timeout: 15000 });
    await customPageTab.click();
    const response = await responsePromise;
    assert.equal(response.ok(), true, "AJAX subscription-page refresh failed");
    await waitForPrivacyMask();
    const refreshedPageNames = await page.evaluate(() => (
      (window.subscriptionPageState?.pages || []).map((item) => String(item.name || "").trim()).filter(Boolean)
    ));
    for (const pageName of baseline.pageNames) {
      assert.equal(refreshedPageNames.includes(pageName), false, "AJAX refresh restored a real custom-page name");
    }
  }

  const blockedSelectors = [
    '[data-subscription-action="open-add-subscription"]',
    '[data-subscription-action="open-edit-subscription"]',
    '[data-subscription-action="delete-subscription"]',
    '[data-subscription-action="clone-subscription"]',
    '[data-subscription-action="renew-subscription"]',
  ];
  for (const selector of blockedSelectors) {
    const trigger = page.locator(selector).first();
    if (await trigger.count()) {
      assert.equal(await trigger.getAttribute("aria-disabled"), "true", `sensitive action is not marked blocked: ${selector}`);
    }
  }

  await gotoAuthenticated("index.php");
  await page.waitForFunction(() => window.WallosScreenshotPrivacy?.isEnabled?.() === true);
  assert.equal((await page.locator("body").innerText()).includes(baseline.name), false, "dashboard exposes a real subscription name");

  await gotoAuthenticated("calendar.php");
  await page.waitForFunction(() => window.WallosScreenshotPrivacy?.isEnabled?.() === true);
  assert.equal((await page.locator("body").innerText()).includes(baseline.name), false, "calendar exposes a real subscription name");

  const statsPath = baselineFilter?.id ? `stats.php?${baselineFilter.type}=${encodeURIComponent(baselineFilter.id)}` : "stats.php";
  await gotoAuthenticated(statsPath);
  await page.waitForFunction(() => window.WallosScreenshotPrivacy?.isEnabled?.() === true);
  if (baselineFilter?.label) {
    const subtitle = (await page.locator(".header-subtitle").first().textContent() || "").trim();
    assert.equal(subtitle.includes(baselineFilter.label), false, "filtered statistics subtitle exposes a real group name");
  }
  const visibleGraphs = page.locator(".graphs > .graph:visible, #stats-graphs .graph:visible");
  if (await visibleGraphs.count()) {
    assert.ok(await page.locator(".wallos-privacy-chart-placeholder:visible").count(), "real chart canvas was not replaced with a demo chart");
  }

  await gotoAuthenticated("profile.php");
  for (const selector of ["#export-json", "#export-csv", "#export-uploaded-images"]) {
    const trigger = page.locator(selector);
    if (await trigger.count()) {
      assert.equal(await trigger.getAttribute("aria-disabled"), "true", `profile export is not blocked: ${selector}`);
    }
  }
  expectedConflictProbe = true;
  const exportStatuses = await page.evaluate(async () => {
    const jsonResponse = await fetch("endpoints/subscriptions/export.php", { headers: { Accept: "application/json" } });
    const imageResponse = await fetch("endpoints/user/export_uploaded_images.php", {
      method: "POST",
      headers: { "X-CSRF-Token": window.csrfToken, Accept: "application/json" },
    });
    return [jsonResponse.status, imageResponse.status];
  });
  await page.waitForTimeout(100);
  expectedConflictProbe = false;
  assert.deepEqual(exportStatuses, [409, 409], "real-data export endpoints did not reject privacy-mode requests");

  assert.deepEqual(sensitiveMediaRequests, [], "privacy mode requested a real subscription logo or uploaded image");

  await togglePrivacy(false);
  await gotoAuthenticated("subscriptions.php");
  const restored = await page.evaluate(() => {
    const card = document.querySelector("#subscriptions .subscription");
    return {
      name: (card?.querySelector(".subscription-main > .name")?.textContent || "").trim(),
      price: (card?.querySelector(".subscription-main .price .value")?.textContent || "").trim(),
    };
  });
  assert.equal(restored.name, baseline.name, "disabling privacy mode did not restore the real subscription name");
  assert.equal(restored.price, baseline.price, "disabling privacy mode did not restore the real subscription price");

  assert.deepEqual(diagnostics, [], `browser diagnostics were not clean:\n${diagnostics.join("\n")}`);
  console.log("PASS: live screenshot privacy mode masks, blocks, restores, and avoids real media requests.");
} catch (error) {
  const diagnosticText = diagnostics.length > 0 ? `\n${diagnostics.join("\n")}` : "";
  console.error(`FAIL: ${String(error?.stack || error).split(password).join("[REDACTED]")}${diagnosticText}`);
  process.exitCode = 1;
} finally {
  await context.close();
  await browser.close();
}
