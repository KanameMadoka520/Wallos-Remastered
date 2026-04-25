#!/usr/bin/env node

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

if (!username || !password) {
  console.error("FAIL: WALLOS_TEST_USERNAME and WALLOS_TEST_PASSWORD are required.");
  process.exit(1);
}

const browser = await chromium.launch({ headless: process.env.WALLOS_E2E_HEADLESS !== "0" });
const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
let createdSubscriptionId = "";
let createdSubscriptionName = "";

async function expectVisible(selector, label) {
  const locator = page.locator(selector);
  await locator.waitFor({ state: "visible", timeout: 10000 });
  return locator;
}

async function clickFirst(selector, label) {
  const count = await page.locator(selector).count();
  if (count < 1) {
    throw new Error(`${label} not found: ${selector}`);
  }

  await page.locator(selector).first().click();
}

async function cleanupCreatedSubscription() {
  if (!createdSubscriptionId) {
    return;
  }

  await page.evaluate(async (subscriptionId) => {
    const headers = {
      "Content-Type": "application/json",
    };
    if (window.csrfToken) {
      headers["X-CSRF-Token"] = window.csrfToken;
    }

    await fetch("endpoints/subscription/delete.php", {
      method: "POST",
      headers,
      body: JSON.stringify({ id: Number(subscriptionId) }),
    }).catch(() => null);

    await fetch("endpoints/subscription/permanentdelete.php", {
      method: "POST",
      headers,
      body: JSON.stringify({ id: Number(subscriptionId) }),
    }).catch(() => null);
  }, createdSubscriptionId).catch(() => null);
}

try {
  await page.goto(`${baseUrl}/login.php`, { waitUntil: "domcontentloaded" });
  await page.locator("#username").fill(username);
  await page.locator("#password").fill(password);
  await Promise.all([
    page.waitForURL(/(?:index|subscriptions|\.php|\?)|\/$/, { timeout: 15000 }).catch(() => null),
    page.locator('input[type="submit"]').click(),
  ]);

  await page.goto(`${baseUrl}/subscriptions.php`, { waitUntil: "domcontentloaded" });
  await expectVisible("#subscriptions", "subscriptions container");
  await expectVisible("#subscription-page-tabs", "subscription page tabs");

  const pageTabCount = await page.locator('[data-subscription-action="select-page-filter"]').count();
  if (pageTabCount > 1) {
    await page.locator('[data-subscription-action="select-page-filter"]').nth(1).click();
    await page.waitForLoadState("networkidle", { timeout: 15000 }).catch(() => null);
    await expectVisible("#subscriptions", "subscriptions after page switch");
  }

  const actionButtonCount = await page.locator('[data-subscription-action="expand-subscription-actions"]').count();
  if (actionButtonCount > 0) {
    await clickFirst('[data-subscription-action="expand-subscription-actions"]', "three-dot action button");
    await expectVisible(".actions.is-open", "subscription action menu");

    await clickFirst('[data-subscription-action="open-edit-subscription"]', "edit subscription action");
    await expectVisible("#subscription-form.is-open", "edit subscription modal");
    await clickFirst('#subscription-form [data-subscription-action="close-add-subscription"]', "edit modal close");

    await clickFirst('[data-subscription-action="open-payment-history"]', "payment history action");
    await expectVisible("#subscription-payment-history-modal.is-open", "payment history modal");
    await clickFirst('#subscription-payment-history-modal [data-subscription-action="close-payment-history-modal"]', "payment history close");

    const imageCount = await page.locator('[data-subscription-action="open-subscription-image-viewer"]:visible').count();
    if (imageCount > 0) {
      await clickFirst('[data-subscription-action="open-subscription-image-viewer"]:visible', "image preview item");
      await expectVisible("#subscription-image-viewer.is-open", "image viewer");
      await clickFirst('#subscription-image-viewer [data-subscription-action="close-image-viewer"]', "image viewer close");
    }
  }

  await clickFirst('[data-subscription-action="open-add-subscription"]', "add subscription button");
  await expectVisible("#subscription-form.is-open", "add subscription modal");
  createdSubscriptionName = `E2E Smoke ${Date.now()}`;
  await page.locator("#name").fill(createdSubscriptionName);
  await page.locator("#price").fill("1.23");
  await page.locator("#start_date").fill(new Date().toISOString().slice(0, 10));
  const nextDate = new Date(Date.now() + 31 * 24 * 60 * 60 * 1000).toISOString().slice(0, 10);
  await page.locator("#next_payment").fill(nextDate);
  await Promise.all([
    page.waitForLoadState("networkidle", { timeout: 15000 }).catch(() => null),
    page.locator("#save-button").click(),
  ]);
  await page.locator("#subscription-form.is-open").waitFor({ state: "detached", timeout: 15000 }).catch(async () => {
    const stillOpen = await page.locator("#subscription-form.is-open").count();
    if (stillOpen > 0) {
      throw new Error("add subscription modal did not close after save");
    }
  });

  const createdCard = page.locator(".subscription-container", { hasText: createdSubscriptionName }).first();
  await createdCard.waitFor({ state: "visible", timeout: 15000 });
  createdSubscriptionId = await createdCard.getAttribute("data-id") || "";

  for (const columns of [1, 2, 3]) {
    const selector = `[data-subscription-action="set-display-columns"][data-columns="${columns}"]`;
    if (await page.locator(selector).count()) {
      await page.locator(selector).click();
      await page.waitForLoadState("domcontentloaded", { timeout: 15000 }).catch(() => null);
      await expectVisible("#subscriptions", `subscriptions after ${columns}-column switch`);
    }
  }

  await page.evaluate(() => document.body.classList.add("dynamic-wallpaper-enabled"));
  const immersiveButton = page.locator("#page-immersive-toggle");
  if (await immersiveButton.count()) {
    await immersiveButton.click({ trial: true });
  }

  await cleanupCreatedSubscription();
  createdSubscriptionId = "";
  console.log("PASS: subscriptions browser E2E smoke completed.");
  await browser.close();
  process.exit(0);
} catch (error) {
  await cleanupCreatedSubscription();
  await browser.close();
  console.error(`FAIL: ${error.message || error}`);
  process.exit(1);
}
