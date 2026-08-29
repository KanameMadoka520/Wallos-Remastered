#!/usr/bin/env node

import fs from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";

let chromium;
try {
  ({ chromium } = await import("playwright"));
} catch (error) {
  console.error("SKIP: Playwright is not installed. Run `npm install` before this E2E check.");
  process.exit(77);
}

const testDirectory = path.dirname(fileURLToPath(import.meta.url));
const repositoryRoot = path.resolve(testDirectory, "../..");
const runtimeSource = await fs.readFile(path.join(repositoryRoot, "scripts/screenshot-privacy.js"), "utf8");
const stylesheetSource = await fs.readFile(path.join(repositoryRoot, "styles/screenshot-privacy.css"), "utf8");

function assert(condition, message) {
  if (!condition) throw new Error(message);
}

assert(!runtimeSource.includes(".innerHTML"), "privacy runtime must not inject HTML strings");
assert(runtimeSource.includes("MutationObserver"), "privacy runtime must cover dynamically inserted views");
assert(runtimeSource.includes("stopImmediatePropagation"), "privacy runtime must block sensitive actions during capture");
assert(stylesheetSource.includes("wallos-screenshot-privacy-enabled"), "privacy stylesheet must include its fail-closed root state");

const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
const page = await context.newPage();

try {
  await page.setContent(`<!doctype html>
    <html class="wallos-screenshot-privacy-enabled">
      <head><meta charset="utf-8"><title>Screenshot privacy fixture</title></head>
      <body>
        <input id="real-input" value="Real plan must remain writable">
        <textarea id="real-textarea">Real notes must remain writable</textarea>

        <div id="subscriptions">
          <article class="subscription" data-subscription-id="41" data-name="Real Stream">
            <div class="subscription-main">
              <span class="logo"><svg viewBox="0 0 10 10"><circle cx="5" cy="5" r="5"></circle></svg></span>
              <span class="name">Real Stream</span>
              <span class="next"><span class="next-value">2026-09-12</span></span>
              <span class="price"><span class="value">$91.27 <span class="original_price">($111.11)</span></span></span>
              <span class="payment_method"><img id="real-subscription-image" src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==" alt="Real Stream"></span>
            </div>
            <div class="subscription-secondary">
              <span class="name">Real Stream</span>
              <span class="payer_user"><i></i>Real Person</span>
              <span class="category"><i></i>Real Category</span>
              <span class="url"><a href="https://private.example.test/account">open</a></span>
            </div>
            <div class="subscription-notes-content"><strong>Private:</strong> Real private notes</div>
            <button id="edit-action" data-subscription-action="open-edit-subscription">Edit</button>
            <button id="pages-action" data-subscription-action="open-pages-manager">Pages</button>
            <button id="history-export-action" data-subscription-action="export-payment-history">Export history</button>
          </article>
        </div>

        <div class="dashboard-subscriptions-list">
          <article class="subscription-item dashboard-subscription-trigger" data-subscription-id="42" aria-label="Real Music">
            <p class="subscription-item-title">Real Music</p>
            <img class="subscription-item-logo" src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==" alt="Real Music">
            <div class="subscription-item-info">
              <p class="subscription-item-date">September 20</p>
              <p class="subscription-item-price">¥88.80</p>
            </div>
          </article>
          <article class="subscription-item thin"><p class="subscription-item-title">Monthly cost</p><p class="subscription-item-value">$712.34</p></article>
        </div>

        <section class="budget-visualizer">
          <div class="budget-visualizer-figures"><strong>$712.34</strong><span>/ $999.00</span></div>
          <span class="budget-visualizer-segment" title="Real category: $712.34"></span>
          <div class="budget-visualizer-legend-item"><span>Real category</span><strong>$712.34</strong></div>
        </section>

        <div class="calendar-monthly-stats"><div class="statistics"><div class="statistic"><span>$812.22</span><div class="title">Total</div></div></div></div>
        <div class="calendar-subscription-title">Real Calendar Plan</div>
        <div id="subscriptionModal" class="is-open"><div id="subscriptionModalContent"><div class="modal-header"><h3>Real Calendar Plan</h3></div><div class="subscription-info"><p><strong>Price:</strong> $17.00</p></div></div></div>

        <section id="stats-overview"><div class="statistics">
          <div class="statistic"><span>$1200.99</span><div class="title">Yearly</div></div>
          <div class="statistic short"><span>$300.00</span><div class="title">Most expensive</div><div class="subtitle">Real Expensive Plan</div></div>
        </div></section>
        <section id="stats-graphs"><div class="graphs"><section class="graph"><header>Budget ($5000.00)<div class="sub-header">Real totals $1234.00</div></header><canvas id="private-chart"></canvas></section></div></section>

        <ul><li class="ai-recommendation-item" data-id="9"><div class="ai-recommendation-header"><h3><span>1.</span> Real AI title</h3></div><p class="collapsible">Real AI description</p><p class="ai-recommendation-savings">Save $82.00 <span><a href="#">delete</a></span></p></li></ul>

        <section id="metric-explanation-modal" class="is-open"><div id="metric-explanation-formula">Real formula $44</div><div id="metric-explanation-summary"><strong>Real summary</strong></div><div id="metric-explanation-items"><article>Real item</article></div></section>

        <section class="subscription-recycle-bin-modal"><span class="section-count-badge">12</span><article class="subscription-trash-card" data-subscription-id="88"><div class="subscription-trash-card-header"><div><h3>Deleted Real Plan</h3><p>Deleted: 2026-08-01</p></div><img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw=="></div><div class="subscription-trash-card-meta"><p><strong>Price:</strong> $43.20</p><p><strong>Next:</strong> 2026-09-01</p></div></article></section>

        <section id="subscription-details" class="subscription-details is-open"><span id="details-logo"><span>R</span></span><h3 id="details-name">Real Detail Plan</h3><span id="details-price">$55.12</span><dd id="details-next-payment">2026-10-01</dd><dd id="details-category">Real Detail Category</dd><dd id="details-payer">Real Detail Person</dd><dd id="details-payment-name">Real Bank</dd><img id="details-payment-icon" src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw=="><span id="details-notes">Real detail notes</span><a id="details-url-button" href="https://private.example.test/details">URL</a></section>
      </body>
    </html>`);

  await page.addStyleTag({ content: stylesheetSource });
  const visibilityBeforeRuntime = await page.locator("#subscriptions .subscription").evaluate((element) => getComputedStyle(element).visibility);
  assert(visibilityBeforeRuntime === "hidden", "fail-closed CSS did not hide an unmasked subscription card");
  const budgetVisibilityBeforeRuntime = await page.locator(".budget-visualizer").evaluate((element) => getComputedStyle(element).visibility);
  assert(budgetVisibilityBeforeRuntime === "hidden", "fail-closed CSS did not hide unmasked budget figures");

  await page.evaluate(() => {
    window.WallosScreenshotPrivacyConfig = {
      enabled: true,
      seed: "fixture-seed",
      labels: {
        names: ["Demo Alpha", "Demo Beta", "Demo Gamma"],
        descriptions: ["Safe demo description"],
        aiTitles: ["Safe AI title"],
        blockedMessage: "Privacy action blocked",
        currency: "$",
      },
    };
    window.fixtureActionCount = 0;
    window.fixtureBlockedEvents = [];
    for (const id of ["edit-action", "pages-action", "history-export-action"]) {
      document.getElementById(id).addEventListener("click", () => { window.fixtureActionCount += 1; });
    }
    window.addEventListener("wallos:screenshot-privacy-blocked", (event) => {
      window.fixtureBlockedEvents.push(event.detail);
    });
  });
  await page.addScriptTag({ content: runtimeSource });
  await page.waitForFunction(() => window.WallosScreenshotPrivacy?.isEnabled?.() === true);

  const masked = await page.evaluate(() => ({
    rootEnabled: document.documentElement.classList.contains("wallos-screenshot-privacy-enabled"),
    subscriptionMasked: document.querySelector("#subscriptions .subscription").classList.contains("wallos-privacy-masked"),
    subscriptionVisible: getComputedStyle(document.querySelector("#subscriptions .subscription")).visibility,
    name: document.querySelector("#subscriptions .subscription-main > .name").textContent.trim(),
    price: document.querySelector("#subscriptions .price .value").childNodes[0].nodeValue.trim(),
    image: document.getElementById("real-subscription-image").getAttribute("src"),
    url: document.querySelector("#subscriptions .url a").getAttribute("href"),
    notesVisible: document.querySelector(".subscription-notes-content").innerText.trim(),
    dashboardName: document.querySelector(".dashboard-subscription-trigger .subscription-item-title").textContent.trim(),
    dashboardValue: document.querySelector(".subscription-item.thin .subscription-item-value").textContent.trim(),
    calendarName: document.querySelector(".calendar-subscription-title").textContent.trim(),
    calendarStat: document.querySelector(".calendar-monthly-stats .statistic > span").textContent.trim(),
    statsValue: document.querySelector("#stats-overview .statistic > span").textContent.trim(),
    mostExpensive: document.querySelector("#stats-overview .statistic.short .subtitle").textContent.trim(),
    chartCanvasDisplay: getComputedStyle(document.getElementById("private-chart")).display,
    chartBars: document.querySelectorAll(".wallos-privacy-chart-bar").length,
    aiTitle: document.querySelector(".ai-recommendation-header h3").innerText.trim(),
    metricSummary: document.getElementById("metric-explanation-summary").innerText.trim(),
    trashName: document.querySelector(".subscription-trash-card h3").textContent.trim(),
    detailsName: document.getElementById("details-name").textContent.trim(),
    detailsUrl: document.getElementById("details-url-button").getAttribute("href"),
    budgetFigure: document.querySelector(".budget-visualizer-figures strong").textContent.trim(),
    budgetLegend: document.querySelector(".budget-visualizer-legend-item strong").textContent.trim(),
    budgetSegmentTitle: document.querySelector(".budget-visualizer-segment").getAttribute("title"),
    input: document.getElementById("real-input").value,
    textarea: document.getElementById("real-textarea").value,
  }));

  assert(masked.rootEnabled && masked.subscriptionMasked && masked.subscriptionVisible === "visible", "subscription card did not leave the fail-closed state after masking");
  assert(masked.name !== "Real Stream" && masked.price !== "$91.27", "subscription name or price was not masked");
  assert(masked.image.startsWith("data:image/svg+xml"), "subscription image did not become an in-memory SVG");
  assert(masked.url === "#", "private subscription URL remained actionable");
  assert(masked.notesVisible === "Safe demo description", "rich subscription notes were not replaced safely");
  assert(masked.dashboardName !== "Real Music" && masked.dashboardValue !== "$712.34", "dashboard subscription or amount leaked");
  assert(masked.calendarName !== "Real Calendar Plan" && masked.calendarStat !== "$812.22", "calendar content leaked");
  assert(masked.statsValue !== "$1200.99" && masked.mostExpensive !== "Real Expensive Plan", "statistics content leaked");
  assert(masked.chartCanvasDisplay === "none" && masked.chartBars === 8, "real chart canvas was not replaced with synthetic geometry");
  assert(masked.aiTitle.includes("Safe AI title") && masked.metricSummary !== "Real summary", "AI or metric modal content leaked");
  assert(masked.trashName !== "Deleted Real Plan" && masked.detailsName !== "Real Detail Plan" && masked.detailsUrl === "#", "recycle bin or details modal leaked");
  assert(masked.budgetFigure !== "$712.34" && masked.budgetLegend !== "$712.34" && !masked.budgetSegmentTitle.includes("$712.34"), "budget visualizer leaked a real amount");
  assert(masked.input === "Real plan must remain writable" && masked.textarea === "Real notes must remain writable", "form control values were modified");

  const stableName = masked.name;
  await page.waitForTimeout(80);
  assert(await page.locator("#subscriptions .subscription-main > .name").textContent() === stableName, "observer repeatedly changed an already masked value");

  for (const id of ["edit-action", "pages-action", "history-export-action"]) {
    await page.locator(`#${id}`).click({ force: true });
  }
  const blocked = await page.evaluate(() => ({ count: window.fixtureActionCount, events: window.fixtureBlockedEvents }));
  assert(blocked.count === 0 && blocked.events.length === 3, "capture-phase guard did not block every sensitive action");
  assert(blocked.events.every((entry) => entry.message === "Privacy action blocked"), "blocked action used the wrong configured message");

  await page.evaluate(() => {
    const card = document.createElement("article");
    card.className = "subscription";
    card.dataset.subscriptionId = "99";
    const main = document.createElement("div");
    main.className = "subscription-main";
    const name = document.createElement("span");
    name.className = "name";
    name.textContent = "Dynamic Real Plan";
    const price = document.createElement("span");
    price.className = "price";
    const value = document.createElement("span");
    value.className = "value";
    value.textContent = "$777.77";
    price.appendChild(value);
    main.append(name, price);
    card.appendChild(main);
    document.getElementById("subscriptions").appendChild(card);
  });
  await page.waitForFunction(() => {
    const card = document.querySelector('[data-subscription-id="99"]');
    return card?.classList.contains("wallos-privacy-masked")
      && card.querySelector(".name")?.textContent !== "Dynamic Real Plan";
  });

  const apiContract = await page.evaluate(() => {
    const original = { id: 123, name: "API Real", price: 99.5, notes: "API secret", logo: "real.png", url: "https://private.test" };
    const chart = [{ label: "API Real", y: 99.5 }];
    const sanitized = window.WallosScreenshotPrivacy.sanitizeSubscription(original, "fixture");
    const sanitizedChart = window.WallosScreenshotPrivacy.sanitizeChartData(chart, "fixture-chart", "USD");
    return {
      original,
      chart,
      sanitized,
      sanitizedChart,
      deterministic: window.WallosScreenshotPrivacy.fakeName("same") === window.WallosScreenshotPrivacy.fakeName("same"),
      icon: window.WallosScreenshotPrivacy.fakeIcon("same"),
    };
  });
  assert(apiContract.original.name === "API Real" && apiContract.original.price === 99.5, "sanitizeSubscription mutated its source object");
  assert(apiContract.sanitized.name !== apiContract.original.name && apiContract.sanitized.price !== apiContract.original.price, "sanitizeSubscription did not create synthetic fields");
  assert(apiContract.chart[0].label === "API Real" && apiContract.chart[0].y === 99.5, "sanitizeChartData mutated its source array");
  assert(apiContract.sanitizedChart[0].label !== "API Real" && apiContract.sanitizedChart[0].y !== 99.5, "sanitizeChartData did not replace labels and values");
  assert(apiContract.deterministic && apiContract.icon.startsWith("data:image/svg+xml"), "fake helpers are not deterministic data-only outputs");

  await page.evaluate(() => window.WallosScreenshotPrivacy.setEnabled(false));
  const restored = await page.evaluate(() => ({
    rootEnabled: document.documentElement.classList.contains("wallos-screenshot-privacy-enabled"),
    name: document.querySelector("#subscriptions .subscription-main > .name").textContent.trim(),
    price: document.querySelector("#subscriptions .price .value").childNodes[0].nodeValue.trim(),
    image: document.getElementById("real-subscription-image").getAttribute("src"),
    url: document.querySelector("#subscriptions .url a").getAttribute("href"),
    notes: document.querySelector(".subscription-notes-content").innerText.trim(),
    dynamicName: document.querySelector('[data-subscription-id="99"] .name').textContent.trim(),
    chartPlaceholder: document.querySelector(".wallos-privacy-chart-placeholder"),
    chartCanvasDisplay: getComputedStyle(document.getElementById("private-chart")).display,
    input: document.getElementById("real-input").value,
    textarea: document.getElementById("real-textarea").value,
  }));
  assert(!restored.rootEnabled, "privacy root class remained after disabling");
  assert(restored.name === "Real Stream" && restored.price === "$91.27", "original subscription text was not restored");
  assert(restored.image.startsWith("data:image/gif"), "original image source was not restored");
  assert(restored.url === "https://private.example.test/account", "original URL was not restored");
  assert(restored.notes.includes("Real private notes") && restored.dynamicName === "Dynamic Real Plan", "rich or dynamically inserted content was not restored");
  assert(restored.chartPlaceholder === null && restored.chartCanvasDisplay !== "none", "synthetic chart did not restore cleanly");
  assert(restored.input === "Real plan must remain writable" && restored.textarea === "Real notes must remain writable", "form controls changed during restore");

  await page.locator("#edit-action").click();
  assert(await page.evaluate(() => window.fixtureActionCount) === 1, "sensitive action remained blocked after disabling privacy mode");

  console.log("PASS: screenshot privacy DOM masking, dynamic updates, action guards, APIs, and restoration are safe.");
} finally {
  await browser.close();
}
