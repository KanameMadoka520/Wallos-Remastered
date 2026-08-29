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
  serviceWorkers: "block",
});
const page = await context.newPage();

let createdSubscriptionId = "";
let createdSubscriptionName = "";
let createdSubscriptionPageIds = [];
let createdSubscriptionPageNames = [];
let originalPreferences = null;
let paginationFilters = [];
let paginationEmptyFilter = "";
let expectedPaginationConsoleErrors = 0;
const documentNavigationRequests = [];

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
    if (
      expectedPaginationConsoleErrors > 0
      && text === "Failed to load resource: the server responded with a status of 503 (Service Unavailable)"
    ) {
      expectedPaginationConsoleErrors -= 1;
      return;
    }
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
    const isExpectedPaginationFailure = status === 503
      && response.headers()["x-wallos-e2e-expected-failure"] === "1"
      && responseUrl.includes("/endpoints/subscriptions/get.php");
    if ((status >= 500 || (isEndpoint && status >= 400)) && !isExpectedPaginationFailure) {
      diagnostics.failedResponses.push(`HTTP ${status} ${formatUrl(responseUrl)}`);
    }
  });

  targetPage.on("dialog", async (dialog) => {
    await dialog.dismiss().catch(() => {});
  });
}

page.on("request", (request) => {
  if (
    request.isNavigationRequest()
    && request.frame() === page.mainFrame()
    && request.resourceType() === "document"
  ) {
    documentNavigationRequests.push(request.url());
  }
});

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

  for (let index = 0; index < count; index += 1) {
    const candidate = locator.nth(index);
    if (await candidate.isVisible()) {
      await candidate.click();
      return;
    }
  }

  throw new Error(`${label} has no visible match: ${selector}`);
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
  const tabs = page.locator("#subscription-page-tabs");
  if (await tabs.isVisible()) {
    await expectVisible("#subscription-page-tabs", "subscription page tabs");
  } else {
    await expectVisible(
      '.empty-page [data-subscription-action="open-add-subscription"]',
      "empty-account add subscription action",
    );
  }
  await page.locator("#subscription-page-loading-overlay.is-visible").waitFor({ state: "hidden", timeout: 15000 }).catch(() => null);
}

async function readSubscriptionCardLayoutHealth() {
  await page.evaluate(async () => {
    if (document.fonts?.ready) {
      await Promise.race([
        document.fonts.ready.catch(() => null),
        new Promise((resolve) => window.setTimeout(resolve, 2000)),
      ]);
    }
    await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)));
  });

  return page.evaluate(() => {
    const cards = Array.from(document.querySelectorAll("#subscriptions .subscription-container[data-id]"))
      .filter((card) => getComputedStyle(card).display !== "none")
      .map((card) => ({
        id: card.dataset.id || "",
        gridRowEnd: card.style.gridRowEnd,
        rect: card.getBoundingClientRect(),
      }));
    const invalidSpans = cards
      .filter((card) => !/^span\s+\d+$/.test(card.gridRowEnd))
      .map((card) => card.id);
    const overlaps = [];

    for (let left = 0; left < cards.length; left += 1) {
      for (let right = left + 1; right < cards.length; right += 1) {
        const first = cards[left];
        const second = cards[right];
        const overlapX = Math.min(first.rect.right, second.rect.right) - Math.max(first.rect.left, second.rect.left);
        const overlapY = Math.min(first.rect.bottom, second.rect.bottom) - Math.max(first.rect.top, second.rect.top);
        if (overlapX > 1 && overlapY > 1) {
          overlaps.push(`${first.id}:${second.id}`);
        }
      }
    }

    return {
      cardCount: cards.length,
      invalidSpans,
      overlaps,
    };
  });
}

async function assertSubscriptionCardsDoNotOverlap(label) {
  const layout = await readSubscriptionCardLayoutHealth();
  if (layout.cardCount > 0 && (layout.invalidSpans.length > 0 || layout.overlaps.length > 0)) {
    throw new Error(`${label} layout is invalid: ${JSON.stringify(layout)}`);
  }
  return layout;
}

async function waitForSubscriptionPageFilter(filterValue, timeout = 15000) {
  await page.waitForFunction((expectedFilter) => {
    const tabs = document.getElementById("subscription-page-tabs");
    const activeTab = tabs?.querySelector('.subscription-page-tab.is-active[aria-pressed="true"]');
    return window.WallosSubscriptionPages?.getCurrentFilter?.() === expectedFilter
      && activeTab?.dataset?.filter === expectedFilter
      && tabs?.getAttribute("aria-busy") !== "true";
  }, String(filterValue), { timeout });
}

async function selectSubscriptionPage(filterValue, options = {}) {
  const normalizedFilter = String(filterValue);
  const currentFilter = await page.evaluate(() => window.WallosSubscriptionPages?.getCurrentFilter?.() || "all");
  if (currentFilter === normalizedFilter && !options.force) {
    return null;
  }

  const responsePromise = page.waitForResponse((response) => {
    const url = new URL(response.url());
    return url.pathname.endsWith("/endpoints/subscriptions/get.php")
      && url.searchParams.get("format") === "json"
      && url.searchParams.get("subscription_page") === normalizedFilter;
  }, { timeout: 15000 });

  await page.locator(
    `#subscription-page-tabs [data-subscription-action="select-page-filter"][data-filter="${normalizedFilter}"]`,
  ).click();
  const response = await responsePromise;
  if (!response.ok()) {
    throw new Error(`subscription fragment returned HTTP ${response.status()} for ${normalizedFilter}`);
  }

  await waitForSubscriptionPageFilter(normalizedFilter);
  return response;
}

async function ensurePaginationTestPages(minimumCount = 2) {
  let state = await page.evaluate(() => window.WallosSubscriptionPages?.getState?.() || { pages: [] });
  const missingCount = Math.max(0, minimumCount - (state.pages?.length || 0));

  for (let index = 0; index < missingCount; index += 1) {
    const pageName = `E2E Pagination ${Date.now()} ${index}`;
    createdSubscriptionPageNames.push(pageName);
    const result = await page.evaluate(async (name) => {
      const headers = { "Content-Type": "application/json" };
      if (window.csrfToken) {
        headers["X-CSRF-Token"] = window.csrfToken;
      }

      const response = await fetch("endpoints/subscriptionpages.php", {
        method: "POST",
        headers,
        credentials: "same-origin",
        body: JSON.stringify({ action: "create", name }),
      });
      const data = await response.json();
      if (!response.ok || !data.success) {
        throw new Error(data.message || `Unable to create pagination fixture (${response.status})`);
      }
      return data;
    }, pageName);

    const createdPage = result.pages.find((candidate) => candidate.name === pageName);
    if (!createdPage?.id) {
      throw new Error(`created pagination fixture was not returned: ${pageName}`);
    }
    createdSubscriptionPageIds.push(String(createdPage.id));
    await page.evaluate((payload) => window.WallosSubscriptionPages.applyPayload(payload), result);
  }

  state = await page.evaluate(() => window.WallosSubscriptionPages?.getState?.() || { pages: [] });
  return state.pages;
}

async function createEmptyPaginationTestPage() {
  const pageName = `E2E Empty Pagination ${Date.now()}`;
  createdSubscriptionPageNames.push(pageName);
  const result = await page.evaluate(async (name) => {
    const headers = { "Content-Type": "application/json" };
    if (window.csrfToken) {
      headers["X-CSRF-Token"] = window.csrfToken;
    }

    const response = await fetch("endpoints/subscriptionpages.php", {
      method: "POST",
      headers,
      credentials: "same-origin",
      body: JSON.stringify({ action: "create", name }),
    });
    const data = await response.json();
    if (!response.ok || !data.success) {
      throw new Error(data.message || `Unable to create empty pagination fixture (${response.status})`);
    }
    return data;
  }, pageName);

  const createdPage = result.pages.find((candidate) => candidate.name === pageName);
  if (!createdPage?.id) {
    throw new Error(`empty pagination fixture was not returned: ${pageName}`);
  }
  createdSubscriptionPageIds.push(String(createdPage.id));
  await page.evaluate((payload) => window.WallosSubscriptionPages.applyPayload(payload), result);
  return createdPage;
}

async function cleanupCreatedSubscriptionPages() {
  if (createdSubscriptionPageIds.length === 0 && createdSubscriptionPageNames.length === 0) {
    return;
  }

  const trackedPageIds = [...createdSubscriptionPageIds];
  const trackedPageNames = [...createdSubscriptionPageNames];
  await page.evaluate(async ({ ids, names }) => {
    const headers = { "Content-Type": "application/json" };
    if (window.csrfToken) {
      headers["X-CSRF-Token"] = window.csrfToken;
    }

    const listResponse = await fetch("endpoints/subscriptionpages.php", {
      credentials: "same-origin",
      cache: "no-store",
    });
    const listData = await listResponse.json();
    if (!listResponse.ok || !listData.success) {
      throw new Error(listData.message || `Unable to enumerate pagination fixtures (${listResponse.status})`);
    }

    const trackedIds = new Set(ids.map(Number).filter((pageId) => pageId > 0));
    const resolvedIds = new Set();
    for (const subscriptionPage of listData.pages || []) {
      if (trackedIds.has(Number(subscriptionPage.id)) || names.includes(subscriptionPage.name)) {
        resolvedIds.add(Number(subscriptionPage.id));
      }
    }

    for (const pageId of resolvedIds) {
      const response = await fetch("endpoints/subscriptionpages.php", {
        method: "POST",
        headers,
        credentials: "same-origin",
        body: JSON.stringify({ action: "delete", page_id: Number(pageId) }),
      });
      const data = await response.json();
      if (!response.ok || !data.success) {
        throw new Error(data.message || `Unable to delete pagination fixture ${pageId} (${response.status})`);
      }
    }
  }, { ids: trackedPageIds, names: trackedPageNames });

  createdSubscriptionPageIds = [];
  createdSubscriptionPageNames = [];
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
  if (!createdSubscriptionId && !createdSubscriptionName) {
    return;
  }

  const cleanedSubscriptionId = await page.evaluate(async ({ subscriptionId, subscriptionName }) => {
    const headers = { "Content-Type": "application/json" };
    if (window.csrfToken) {
      headers["X-CSRF-Token"] = window.csrfToken;
    }

    let resolvedId = Number(subscriptionId || 0);
    if (resolvedId <= 0 && subscriptionName) {
      const listResponse = await fetch("endpoints/subscriptions/get.php?format=json&subscription_page=all", {
        credentials: "same-origin",
        cache: "no-store",
      });
      const listData = await listResponse.json();
      if (!listResponse.ok || !listData.success || typeof listData.html !== "string") {
        throw new Error(listData.message || `Unable to locate subscription fixture (${listResponse.status})`);
      }
      const template = document.createElement("template");
      template.innerHTML = listData.html;
      const subscription = Array.from(template.content.querySelectorAll(".subscription[data-name]"))
        .find((candidate) => candidate.dataset.name === subscriptionName);
      resolvedId = Number(subscription?.dataset.id || subscription?.closest(".subscription-container")?.dataset.id || 0);
    }

    if (resolvedId <= 0) {
      return 0;
    }

    await fetch("endpoints/subscription/delete.php", {
      method: "POST",
      headers,
      credentials: "same-origin",
      body: JSON.stringify({ id: resolvedId }),
    }).catch(() => null);

    const permanentResponse = await fetch("endpoints/subscription/permanentdelete.php", {
      method: "POST",
      headers,
      credentials: "same-origin",
      body: JSON.stringify({ id: resolvedId }),
    });
    const permanentData = await permanentResponse.json();
    if (!permanentResponse.ok || !permanentData.success) {
      throw new Error(permanentData.message || `Unable to permanently delete subscription fixture ${resolvedId}`);
    }
    return resolvedId;
  }, {
    subscriptionId: createdSubscriptionId,
    subscriptionName: createdSubscriptionName,
  });

  if (cleanedSubscriptionId > 0 || !createdSubscriptionId) {
    createdSubscriptionId = "";
    createdSubscriptionName = "";
  }
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

  await step("warm-cache first subscription render has no card overlap", async () => {
    await page.waitForLoadState("load", { timeout: 15000 });
    await page.goto(`${baseUrl}/index.php`, { waitUntil: "domcontentloaded" });
    const desktopNavigationButton = page.locator(".dropbtn").first();
    if (await desktopNavigationButton.isVisible()) {
      await desktopNavigationButton.hover();
    }
    const subscriptionLinks = page.locator('a[href*="subscriptions.php"]');
    let visibleSubscriptionLink = null;
    for (let index = 0; index < await subscriptionLinks.count(); index += 1) {
      const candidate = subscriptionLinks.nth(index);
      if (await candidate.isVisible()) {
        visibleSubscriptionLink = candidate;
        break;
      }
    }
    if (!visibleSubscriptionLink) {
      throw new Error("dashboard has no visible subscriptions navigation link");
    }
    await Promise.all([
      page.waitForNavigation({ waitUntil: "domcontentloaded", timeout: 15000 }),
      visibleSubscriptionLink.click(),
    ]);
    await waitForSubscriptionsShell();
    const layout = await assertSubscriptionCardsDoNotOverlap("warm-cache first render");
    if (layout.cardCount < 1) {
      throw new Error("warm-cache layout test requires at least one subscription card");
    }
  });

  await step("back-forward restored subscription page has no card overlap", async () => {
    await page.goto(`${baseUrl}/index.php`, { waitUntil: "domcontentloaded" });
    await page.goBack({ waitUntil: "domcontentloaded", timeout: 15000 });
    await waitForSubscriptionsShell();
    await assertSubscriptionCardsDoNotOverlap("back-forward restored render");
  });

  await step("direct add link waits for subscription modules before opening", async () => {
    await page.goto(`${baseUrl}/subscriptions.php?add=1`, { waitUntil: "domcontentloaded" });
    await expectVisible("#subscription-form.is-open", "direct-link add subscription modal", 15000);
    const modulesReady = await page.evaluate(() => window.WallosSubscriptionsReady === true);
    if (!modulesReady) {
      throw new Error("direct add modal opened before subscription modules were ready");
    }
    await closeModal(
      "#subscription-form",
      '#subscription-form [data-subscription-action="close-add-subscription"]',
      "direct-link add subscription modal",
    );
    await page.goto(`${baseUrl}/subscriptions.php`, { waitUntil: "domcontentloaded" });
    await waitForSubscriptionsShell();
  });

  await step("subscription pages switch without document navigation", async () => {
    const customPages = await ensurePaginationTestPages(2);
    const emptyPage = await createEmptyPaginationTestPage();
    paginationEmptyFilter = String(emptyPage.id);
    const allCustomPages = [...customPages, emptyPage];
    const populatedPages = allCustomPages.filter((subscriptionPage) => Number(subscriptionPage.subscription_count || 0) > 0);
    const racePages = populatedPages.length >= 2 ? populatedPages : allCustomPages;
    paginationFilters = racePages.slice(0, 2).map((subscriptionPage) => String(subscriptionPage.id));
    if (paginationFilters.length < 2) {
      throw new Error("pagination test requires two custom subscription pages");
    }

    await page.evaluate(() => {
      const url = new URL(window.location.href);
      url.searchParams.set("pagination_probe", "preserved");
      history.replaceState(history.state, "", `${url.pathname}${url.search}${url.hash}`);
      window.__wallosPaginationContainer = document.querySelector("#subscriptions");
      window.__wallosPaginationMarker = { alive: true };
    });

    const navigationBaseline = documentNavigationRequests.length;
    const timeOrigin = await page.evaluate(() => performance.timeOrigin);

    for (const filterValue of allCustomPages.map((subscriptionPage) => String(subscriptionPage.id))) {
      await selectSubscriptionPage(filterValue);
      const result = await page.evaluate((expectedFilter) => {
        const activeTab = document.querySelector(
          `#subscription-page-tabs [data-filter="${expectedFilter}"].is-active[aria-pressed="true"]`,
        );
        return {
          currentFilter: window.WallosSubscriptionPages?.getCurrentFilter?.(),
          subscriptionPage: new URL(window.location.href).searchParams.get("subscription_page"),
          probe: new URL(window.location.href).searchParams.get("pagination_probe"),
          cardCount: document.querySelectorAll("#subscriptions .subscription-container[data-id]").length,
          badgeCount: Number(activeTab?.querySelector(".section-count-badge")?.textContent || 0),
          sameContainer: window.__wallosPaginationContainer === document.querySelector("#subscriptions"),
          markerAlive: window.__wallosPaginationMarker?.alive === true,
          timeOrigin: performance.timeOrigin,
        };
      }, filterValue);

      if (result.currentFilter !== filterValue || result.subscriptionPage !== filterValue) {
        throw new Error(`filter ${filterValue} did not synchronize URL, tab state, and content`);
      }
      if (result.probe !== "preserved") {
        throw new Error("pagination discarded an unrelated query parameter");
      }
      if (result.cardCount !== result.badgeCount) {
        throw new Error(`filter ${filterValue} rendered ${result.cardCount} cards but badge says ${result.badgeCount}`);
      }
      if (!result.sameContainer || !result.markerAlive || result.timeOrigin !== timeOrigin) {
        throw new Error("pagination replaced or reloaded the document instead of updating the card fragment");
      }
    }

    if (
      await page.locator("#subscriptions .subscription-container[data-id]").count() !== 0
      || await page.locator("#subscriptions .no-matching-subscriptions").count() !== 1
      || await page.evaluate(() => window.WallosSubscriptionPages?.getCurrentFilter?.()) !== paginationEmptyFilter
    ) {
      throw new Error("the empty custom subscription page did not render its delegated empty state");
    }

    await selectSubscriptionPage("unassigned");
    const unassignedState = await page.evaluate(() => {
      const tab = document.querySelector('#subscription-page-tabs [data-filter="unassigned"]');
      return {
        cardCount: document.querySelectorAll("#subscriptions .subscription-container[data-id]").length,
        badgeCount: Number(tab?.querySelector(".section-count-badge")?.textContent || 0),
        urlFilter: new URL(window.location.href).searchParams.get("subscription_page"),
      };
    });
    if (
      unassignedState.cardCount !== unassignedState.badgeCount
      || unassignedState.urlFilter !== "unassigned"
    ) {
      throw new Error(`Unassigned filter is inconsistent: ${JSON.stringify(unassignedState)}`);
    }

    await selectSubscriptionPage("all");
    const allUrl = new URL(page.url());
    if (allUrl.searchParams.has("subscription_page") || allUrl.searchParams.get("pagination_probe") !== "preserved") {
      throw new Error("All filter did not remove only the subscription_page parameter");
    }
    const allCounts = await page.evaluate(() => {
      const tab = document.querySelector('#subscription-page-tabs [data-filter="all"]');
      return {
        cards: document.querySelectorAll("#subscriptions .subscription-container[data-id]").length,
        badge: Number(tab?.querySelector(".section-count-badge")?.textContent || 0),
      };
    });
    if (allCounts.cards !== allCounts.badge) {
      throw new Error(`All filter rendered ${allCounts.cards} cards but badge says ${allCounts.badge}`);
    }

    let repeatedFilterRequests = 0;
    const countRepeatedRequests = (request) => {
      if (request.url().includes("/endpoints/subscriptions/get.php")) {
        repeatedFilterRequests += 1;
      }
    };
    page.on("request", countRepeatedRequests);
    const historyLength = await page.evaluate(() => history.length);
    await page.locator('#subscription-page-tabs [data-filter="all"]').click();
    await page.waitForTimeout(160);
    page.off("request", countRepeatedRequests);
    if (repeatedFilterRequests !== 0 || await page.evaluate(() => history.length) !== historyLength) {
      throw new Error("reselecting the active subscription page should be a no-op");
    }

    if (documentNavigationRequests.length !== navigationBaseline) {
      throw new Error("a successful subscription page switch issued a document request");
    }
  });

  await step("browser back and forward restore subscription pages without reload", async () => {
    const [firstFilter, secondFilter] = paginationFilters;
    await selectSubscriptionPage(firstFilter);
    await selectSubscriptionPage(secondFilter);
    const navigationBaseline = documentNavigationRequests.length;
    const historyLength = await page.evaluate(() => history.length);
    const timeOrigin = await page.evaluate(() => performance.timeOrigin);

    const backResponse = page.waitForResponse((response) => {
      const url = new URL(response.url());
      return url.pathname.endsWith("/endpoints/subscriptions/get.php")
        && url.searchParams.get("subscription_page") === firstFilter;
    }, { timeout: 15000 });
    await page.evaluate(() => history.back());
    await backResponse;
    await waitForSubscriptionPageFilter(firstFilter);

    const forwardResponse = page.waitForResponse((response) => {
      const url = new URL(response.url());
      return url.pathname.endsWith("/endpoints/subscriptions/get.php")
        && url.searchParams.get("subscription_page") === secondFilter;
    }, { timeout: 15000 });
    await page.evaluate(() => history.forward());
    await forwardResponse;
    await waitForSubscriptionPageFilter(secondFilter);

    if (
      documentNavigationRequests.length !== navigationBaseline
      || await page.evaluate(() => history.length) !== historyLength
      || await page.evaluate(() => performance.timeOrigin) !== timeOrigin
    ) {
      throw new Error("back/forward reloaded the document or created recursive history entries");
    }
  });

  await step("invalid subscription page history is canonicalized without reload", async () => {
    const invalidFilter = "999999999";
    const navigationBaseline = documentNavigationRequests.length;
    const response = page.waitForResponse((candidate) => {
      const url = new URL(candidate.url());
      return url.pathname.endsWith("/endpoints/subscriptions/get.php")
        && url.searchParams.get("subscription_page") === invalidFilter;
    }, { timeout: 15000 });

    await page.evaluate((filterValue) => {
      const url = new URL(window.location.href);
      url.searchParams.set("subscription_page", filterValue);
      history.pushState(history.state, "", `${url.pathname}${url.search}${url.hash}`);
      window.dispatchEvent(new PopStateEvent("popstate", { state: history.state }));
    }, invalidFilter);
    await response;
    await waitForSubscriptionPageFilter("all");

    if (
      new URL(page.url()).searchParams.has("subscription_page")
      || documentNavigationRequests.length !== navigationBaseline
    ) {
      throw new Error("server-normalized subscription page history was not replaced with the canonical All URL");
    }
  });

  await step("rapid subscription page switching keeps the latest response", async () => {
    const [slowFilter, fastFilter] = paginationFilters;
    await selectSubscriptionPage("all");
    const navigationBaseline = documentNavigationRequests.length;
    const endpointPattern = "**/endpoints/subscriptions/get.php**";

    await page.evaluate(() => {
      window.__wallosOriginalAbort = AbortController.prototype.abort;
      AbortController.prototype.abort = function wallosE2ENoopAbort() {};
    });

    await page.route(endpointPattern, async (route) => {
      const url = new URL(route.request().url());
      const filterValue = url.searchParams.get("subscription_page");
      if (filterValue === slowFilter) {
        await new Promise((resolve) => setTimeout(resolve, 320));
      } else if (filterValue === fastFilter) {
        await new Promise((resolve) => setTimeout(resolve, 25));
      }
      await route.continue().catch(() => null);
    });

    try {
      const slowResponse = page.waitForResponse((response) => {
        const url = new URL(response.url());
        return url.pathname.endsWith("/endpoints/subscriptions/get.php")
          && url.searchParams.get("subscription_page") === slowFilter;
      }, { timeout: 15000 });
      const fastResponse = page.waitForResponse((response) => {
        const url = new URL(response.url());
        return url.pathname.endsWith("/endpoints/subscriptions/get.php")
          && url.searchParams.get("subscription_page") === fastFilter;
      }, { timeout: 15000 });

      await page.evaluate(([firstFilter, secondFilter]) => {
        window.WallosSubscriptionPages.selectFilter(firstFilter);
        window.setTimeout(() => window.WallosSubscriptionPages.selectFilter(secondFilter), 12);
      }, [slowFilter, fastFilter]);
      const resolvedFastResponse = await fastResponse;
      const fastPayload = await resolvedFastResponse.json();
      const resolvedSlowResponse = await slowResponse;
      await resolvedSlowResponse.finished();
      await waitForSubscriptionPageFilter(fastFilter);
      await page.waitForTimeout(80);

      const finalState = await page.evaluate(() => ({
        currentFilter: window.WallosSubscriptionPages?.getCurrentFilter?.(),
        activeFilter: document.querySelector(
          '#subscription-page-tabs .subscription-page-tab.is-active[aria-pressed="true"]',
        )?.dataset?.filter,
        urlFilter: new URL(window.location.href).searchParams.get("subscription_page"),
        cardIds: Array.from(document.querySelectorAll("#subscriptions .subscription-container[data-id]"))
          .map((card) => card.dataset.id),
      }));
      const fastCardIds = Array.from(String(fastPayload.html || "").matchAll(
        /class="subscription-container"\s+data-id="(\d+)"/g,
      )).map((match) => match[1]);
      if (
        finalState.currentFilter !== fastFilter
        || finalState.activeFilter !== fastFilter
        || finalState.urlFilter !== fastFilter
        || JSON.stringify(finalState.cardIds) !== JSON.stringify(fastCardIds)
      ) {
        throw new Error(`a stale response overrode the latest filter: ${JSON.stringify(finalState)}`);
      }
      if (documentNavigationRequests.length !== navigationBaseline) {
        throw new Error("rapid AJAX pagination caused a document navigation");
      }
    } finally {
      await page.evaluate(() => {
        if (window.__wallosOriginalAbort) {
          AbortController.prototype.abort = window.__wallosOriginalAbort;
          delete window.__wallosOriginalAbort;
        }
      }).catch(() => null);
      await page.unroute(endpointPattern);
    }
  });

  await step("subscription page state survives a superseding list refresh", async () => {
    const targetFilter = paginationFilters[0];
    await selectSubscriptionPage("all");
    const endpointPattern = "**/endpoints/subscriptions/get.php**";
    const navigationBaseline = documentNavigationRequests.length;
    let targetRequestCount = 0;

    await page.route(endpointPattern, async (route) => {
      const url = new URL(route.request().url());
      if (url.searchParams.get("subscription_page") === targetFilter) {
        targetRequestCount += 1;
        await new Promise((resolve) => setTimeout(resolve, targetRequestCount === 1 ? 260 : 20));
      }
      await route.continue().catch(() => null);
    });

    try {
      await page.evaluate((filterValue) => {
        window.WallosSubscriptionPages.selectFilter(filterValue);
        window.setTimeout(() => fetchSubscriptions(null, null, "filter"), 12);
      }, targetFilter);
      await waitForSubscriptionPageFilter(targetFilter);
      await page.waitForTimeout(300);

      const committedState = await page.evaluate(() => ({
        filter: window.WallosSubscriptionPages?.getCurrentFilter?.(),
        urlFilter: new URL(window.location.href).searchParams.get("subscription_page"),
        loading: document.getElementById("subscription-page-tabs")?.getAttribute("aria-busy"),
      }));
      if (
        targetRequestCount < 2
        || committedState.filter !== targetFilter
        || committedState.urlFilter !== targetFilter
        || committedState.loading === "true"
        || documentNavigationRequests.length !== navigationBaseline
      ) {
        throw new Error(`superseding refresh left pagination half-committed: ${JSON.stringify(committedState)}`);
      }
    } finally {
      await page.unroute(endpointPattern);
    }
  });

  await step("pagination request failure falls back to document navigation", async () => {
    const fallbackFilter = paginationFilters[0];
    const currentFilter = await page.evaluate(() => window.WallosSubscriptionPages?.getCurrentFilter?.() || "all");
    if (currentFilter === fallbackFilter) {
      await selectSubscriptionPage(paginationFilters[1] || "all");
    }
    const endpointPattern = "**/endpoints/subscriptions/get.php**";
    let failureInjected = false;
    const navigationBaseline = documentNavigationRequests.length;

    await page.route(endpointPattern, async (route) => {
      const url = new URL(route.request().url());
      if (!failureInjected && url.searchParams.get("subscription_page") === fallbackFilter) {
        failureInjected = true;
        expectedPaginationConsoleErrors = 1;
        await route.fulfill({
          status: 503,
          contentType: "application/json",
          headers: { "x-wallos-e2e-expected-failure": "1" },
          body: JSON.stringify({ success: false, message: "Expected E2E pagination failure" }),
        });
        return;
      }
      await route.continue();
    });

    try {
      const oldDocument = await page.evaluate(() => {
        window.__wallosFallbackOldDocument = true;
        return { timeOrigin: performance.timeOrigin };
      });
      const navigation = page.waitForNavigation({ waitUntil: "domcontentloaded", timeout: 15000 });

      await page.locator(`#subscription-page-tabs [data-filter="${fallbackFilter}"]`).click();
      const navigationResponse = await navigation;
      if (!navigationResponse || navigationResponse.status() !== 200) {
        throw new Error(`fallback document navigation returned ${navigationResponse?.status() || "no response"}`);
      }
      await waitForSubscriptionsShell();
      await waitForSubscriptionPageFilter(fallbackFilter);
      const newDocument = await page.evaluate(() => ({
        timeOrigin: performance.timeOrigin,
        oldMarkerPresent: window.__wallosFallbackOldDocument === true,
        serverFilter: window.subscriptionPageState?.currentFilter,
      }));
      if (
        newDocument.timeOrigin === oldDocument.timeOrigin
        || newDocument.oldMarkerPresent
        || String(newDocument.serverFilter) !== fallbackFilter
        || documentNavigationRequests.length !== navigationBaseline + 1
        || new URL(documentNavigationRequests.at(-1)).searchParams.get("subscription_page") !== fallbackFilter
      ) {
        throw new Error(`fallback did not commit a fresh server-rendered document: ${JSON.stringify(newDocument)}`);
      }
      if (!failureInjected) {
        throw new Error("the expected fragment failure was not injected");
      }
    } finally {
      expectedPaginationConsoleErrors = 0;
      await page.unroute(endpointPattern);
    }
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

  await step("subscription page interactions are rebound after replacement", async () => {
    const createdFilter = await page.evaluate(() => window.WallosSubscriptionPages?.getCurrentFilter?.() || "all");
    const alternateFilter = paginationFilters.find((filterValue) => filterValue !== createdFilter) || "all";
    await selectSubscriptionPage(alternateFilter);
    await selectSubscriptionPage(createdFilter);

    const card = page.locator(".subscription-container", { hasText: createdSubscriptionName }).first();
    await card.waitFor({ state: "visible", timeout: 15000 });
    const actionButton = card.locator('[data-subscription-action="expand-subscription-actions"]').first();
    if (await actionButton.getAttribute("data-expand-action-bound") !== "1") {
      throw new Error("replacement card did not receive its direct action binding");
    }
    await actionButton.click();
    await card.locator(".actions.is-open").waitFor({ state: "visible", timeout: 10000 });
    await actionButton.click();
    await card.locator(".actions.is-open").waitFor({ state: "hidden", timeout: 10000 });

    const lifecycleState = await page.evaluate(() => ({
      sortableReady: typeof Sortable !== "undefined" && Boolean(Sortable.get(document.querySelector("#subscriptions"))),
      columnClass: Array.from(document.querySelector("#subscriptions")?.classList || [])
        .find((className) => /^subscription-columns-[123]$/.test(className)) || "",
      imageCount: document.querySelectorAll("#subscriptions img").length,
      masonryImagesBound: Array.from(document.querySelectorAll("#subscriptions img"))
        .every((image) => image.dataset.subscriptionMasonryBound === "1"),
      detailLayoutsReady: Array.from(document.querySelectorAll("#subscriptions .subscription-media-gallery"))
        .every((gallery) => gallery.classList.contains("layout-focus") || gallery.classList.contains("layout-grid")),
    }));
    if (
      !lifecycleState.sortableReady
      || !lifecycleState.columnClass
      || (lifecycleState.imageCount > 0 && !lifecycleState.masonryImagesBound)
      || !lifecycleState.detailLayoutsReady
    ) {
      throw new Error(`replacement lifecycle incomplete: ${JSON.stringify(lifecycleState)}`);
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
    await cleanupCreatedSubscriptionPages();
    createdSubscriptionId = "";
  });

  assertNoBrowserRuntimeErrors();
  console.log("PASS: subscriptions browser E2E smoke completed.");
  await browser.close();
  process.exit(0);
} catch (error) {
  const cleanupErrors = [];
  await restorePreferences(originalPreferences).catch((cleanupError) => {
    cleanupErrors.push(`restore preferences: ${cleanupError.message || cleanupError}`);
  });
  await cleanupCreatedSubscription().catch((cleanupError) => {
    cleanupErrors.push(`remove subscription fixture: ${cleanupError.message || cleanupError}`);
  });
  await cleanupCreatedSubscriptionPages().catch((cleanupError) => {
    cleanupErrors.push(`remove pagination fixtures: ${cleanupError.message || cleanupError}`);
  });
  if (cleanupErrors.length > 0) {
    error.message = `${error.message || error}\nCleanup failures:\n- ${cleanupErrors.join("\n- ")}`;
  }
  await writeFailureArtifacts(error);
  await browser.close();
  console.error(`FAIL: ${error.message || error}`);
  process.exit(1);
}
