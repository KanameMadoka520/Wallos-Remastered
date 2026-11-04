#!/usr/bin/env node
// Only seed disposable integration records; never authenticate a real account.
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import { chromium } from 'playwright';

const base = process.env.WALLOS_UPSTREAM_TEST_URL || 'http://127.0.0.1:18284';
const fixture = JSON.parse(await fs.readFile(process.env.WALLOS_UPSTREAM_FIXTURE_FILE, 'utf8'));
const key = JSON.parse(await fs.readFile(process.env.WALLOS_AGENT_TEST_KEY_FILE, 'utf8')).token;
const browser = await chromium.launch({ headless: true });
let checks = 0;
const check = (condition, label) => { assert(condition, label); checks++; console.log('[OK]', label); };
const requestId = () => `desktop-subscriptions-${crypto.randomUUID()}`;

try {
  const context = await browser.newContext({ serviceWorkers: 'block' });
  await context.addCookies([{ name: 'PHPSESSID', value: fixture.sessions['1'].id, url: base }]);
  const tool = async (name, args = {}) => {
    const response = await context.request.post(base + '/api/agent/v1/index.php', {
      headers: { Authorization: 'Bearer ' + key }, data: { tool: name, arguments: args },
    });
    assert.equal(response.status(), 200, name);
    const body = await response.json();
    assert(body.success, name);
    return body.result;
  };
  assert.equal((await tool('wallos_context')).account.username, 'agent-ui-test', 'Refuse writes outside disposable fixture');
  const pageRef = (await tool('wallos_references_save', {
    kind: 'subscription_pages', data: { name: 'Desktop geometry ' + crypto.randomUUID().slice(0, 8) }, request_id: requestId(),
  })).reference.id;
  for (let i = 0; i < 9; i++) {
    await tool('wallos_subscriptions_create', {
      data: { name: `Desktop subscription ${i} ` + 'Long service and billing plan '.repeat(i % 3 + 1),
        price: 12, currency_id: 100, start_date: '2026-11-01', next_payment: '2026-11-01',
        cycle: 3, frequency: 1, category_id: 100, payer_user_id: 100, payment_method_id: 100,
        subscription_page_id: pageRef }, request_id: requestId(),
    });
  }
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', (error) => errors.push(error.message));
  const settled = async () => {
    await page.waitForFunction(() => window.WallosSubscriptionsReady && !document.documentElement.classList.contains('wallos-page-transition-loading'));
    await page.waitForTimeout(350);
  };
  const noOverlap = async () => page.locator('#subscriptions > .subscription-container').evaluateAll((items) => {
    const rects = items.filter((item) => item.getClientRects().length).map((item) => item.querySelector('.subscription').getBoundingClientRect());
    return rects.every((a, index) => rects.slice(index + 1).every((b) =>
      Math.min(a.right, b.right) - Math.max(a.left, b.left) <= 1 ||
      Math.min(a.bottom, b.bottom) - Math.max(a.top, b.top) <= 1));
  });
  for (const [width, height] of [[1366, 768], [1440, 900], [1920, 1080]]) {
    await page.setViewportSize({ width, height });
    await page.goto(`${base}/subscriptions.php?subscription_page=${pageRef}`, { waitUntil: 'networkidle' });
    await settled();
    check(await page.locator('#subscription-bulk-toolbar').evaluate((element) => {
      const summary = element.querySelector('summary').getBoundingClientRect();
      const tabs = document.querySelector('#subscription-page-tabs').getBoundingClientRect();
      return !element.open && Math.abs(summary.top + summary.height / 2 - tabs.top - tabs.height / 2) < 8;
    }), `${width}: collapsed batch entry shares the page toolbar row`);
    for (const columns of [1, 2, 3]) {
      await page.locator(`[data-subscription-columns="${columns}"]`).click();
      await settled();
      check(await noOverlap(), `${width}/${columns}: normal subscription cards do not overlap`);
      await page.locator('#subscription-bulk-toolbar summary').click();
      await page.waitForFunction(() => document.body.classList.contains('subscription-bulk-mode'));
      await page.waitForTimeout(100);
      check(await noOverlap(), `${width}/${columns}: batch selection reflows the masonry cards`);
      check(await page.locator('#subscription-bulk-panel').evaluate((element) => {
        const rect = element.getBoundingClientRect();
        const toolbar = document.querySelector('.subscription-page-toolbar').getBoundingClientRect();
        return rect.width >= toolbar.width - 2 && rect.left >= 0 && rect.right <= innerWidth + 1;
      }), `${width}/${columns}: expanded batch controls use a full toolbar row`);
      await page.locator('#subscription-bulk-toolbar summary').click();
      await page.waitForFunction(() => !document.body.classList.contains('subscription-bulk-mode'));
      await page.waitForTimeout(100);
      check(await noOverlap(), `${width}/${columns}: closing batch selection restores card layout`);
    }
    await page.evaluate(() => addSubscription());
    await page.locator('#payer_user-picker-toggle').scrollIntoViewIfNeeded();
    check(await page.locator('#payer_user-picker-toggle').evaluate((element) => {
      const payer = element.getBoundingClientRect();
      const method = document.querySelector('#payment_method').getBoundingClientRect();
      return payer.height === 50 && Math.abs(payer.top - method.top) < 1 && Math.abs(payer.bottom - method.bottom) < 1;
    }), `${width}: compact payer and payment method align`);
    await page.locator('#category-picker-toggle').scrollIntoViewIfNeeded();
    await page.locator('#category-picker-toggle').focus();
    await page.keyboard.press('ArrowDown');
    check(await page.locator('#category-picker-panel').evaluate((element) => {
      const rect = element.getBoundingClientRect();
      return !element.hidden && rect.top >= 0 && rect.bottom <= innerHeight && rect.left >= 0 && rect.right <= innerWidth;
    }), `${width}: category choices open within the desktop viewport`);
    await page.keyboard.press('Escape');
    check(await page.locator('#category-picker-panel').isHidden(), `${width}: keyboard closes the category panel`);
    await page.goto(`${base}/subscriptions.php?subscription_page=${pageRef}`, { waitUntil: 'networkidle' });
    await settled();
    await page.locator('[data-subscription-action="open-recycle-bin"]').click();
    check(await page.locator('#subscription-recycle-bin-modal').evaluate((element) => {
      const rect = element.getBoundingClientRect();
      return rect.width > 600 && rect.left >= 0 && rect.right <= innerWidth;
    }), `${width}: recycle bin retains its dedicated wide layout`);
    await page.goto(`${base}/subscriptions.php?subscription_page=${pageRef}`, { waitUntil: 'networkidle' });
    await settled();
    await page.locator('.subscription [data-subscription-action="open-payment-modal"]').first().click();
    await page.locator('#subscription-payment-modal').waitFor({ state: 'visible' });
    check(await page.locator('#subscription-payment-modal').evaluate((element) => {
      const rect = element.getBoundingClientRect();
      const form = element.querySelector('form').getBoundingClientRect();
      const controls = Array.from(element.querySelectorAll('input:not([type="hidden"]),select,textarea'));
      return rect.width > 500 && rect.left >= 0 && rect.right <= innerWidth && rect.top >= 0 && rect.bottom <= innerHeight &&
        controls.every((control) => { const box = control.getBoundingClientRect(); return box.left >= form.left - 1 && box.right <= form.right + 1; });
    }), `${width}: payment dialog and form controls fit their intended width`);
    check(await page.locator('#subscription-payment-note').evaluate((element) => element.getBoundingClientRect().height === 128), `${width}: payment note uses a compact resizable height`);
    check(await page.locator('#subscription-payment-modal').evaluate((element) => {
      const title = element.querySelector('header h3');
      const close = element.querySelector('header .close-form').getBoundingClientRect();
      const range = document.createRange();
      range.selectNodeContents(title);
      return Array.from(range.getClientRects()).every((box) => box.right <= close.left || box.top >= close.bottom || box.bottom <= close.top);
    }), `${width}: long payment title leaves room for its close button`);
    await page.locator('#subscription-payment-modal [data-subscription-action="close-payment-modal"]').first().click();
    await page.locator('.subscription [data-subscription-action="open-payment-history"]').first().click();
    await page.locator('#subscription-payment-history-modal').waitFor({ state: 'visible' });
    check(await page.locator('#subscription-payment-history-modal').evaluate((element) => {
      const rect = element.getBoundingClientRect();
      return rect.width > 650 && rect.left >= 0 && rect.right <= innerWidth && rect.top >= 0 && rect.bottom <= innerHeight && element.scrollWidth <= element.clientWidth + 1;
    }), `${width}: payment history retains its wider layout without page overflow`);
  }
  check(errors.length === 0, 'no subscription browser exceptions: ' + errors.join('; '));
  console.log(`PASS: ${checks} desktop subscription checks.`);
} finally {
  await browser.close();
}
