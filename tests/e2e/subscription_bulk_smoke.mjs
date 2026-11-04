#!/usr/bin/env node
// Mutates only records explicitly created in the disposable feature fixture.
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import { chromium } from 'playwright';

const base = process.env.WALLOS_UPSTREAM_TEST_URL || 'http://127.0.0.1:18284';
const fixture = JSON.parse(await fs.readFile(process.env.WALLOS_UPSTREAM_FIXTURE_FILE, 'utf8'));
const key = JSON.parse(await fs.readFile(process.env.WALLOS_AGENT_TEST_KEY_FILE, 'utf8')).token;
const browser = await chromium.launch({ headless: true });
let checks = 0;
const check = (value, label) => { assert(value, label); checks++; console.log('[OK]', label); };
const requestId = () => `bulk-browser-${crypto.randomUUID()}`;
try {
  const context = await browser.newContext({ serviceWorkers: 'block' });
  await context.addCookies([{ name: 'PHPSESSID', value: fixture.sessions['1'].id, url: base }]);
  const tool = async (name, args = {}) => {
    const response = await context.request.post(base + '/api/agent/v1/index.php', {
      headers: { Authorization: 'Bearer ' + key }, data: { tool: name, arguments: args },
    });
    const body = await response.json();
    assert.equal(response.status(), 200, name + ': ' + JSON.stringify(body));
    assert(body.success, name);
    return body.result;
  };
  assert.equal((await tool('wallos_context')).account.username, 'agent-ui-test', 'Refusing writes outside disposable fixture');
  const pageRef = (await tool('wallos_references_save', { kind: 'subscription_pages', data: { name: 'Bulk fixture ' + crypto.randomUUID().slice(0, 8) }, request_id: requestId() })).reference.id;
  const category = (await tool('wallos_references_save', { kind: 'categories', data: { name: 'Bulk browser category' }, request_id: requestId() })).reference.id;
  const ids = [];
  for (const name of ['Bulk one', 'Bulk two', '<img src=x onerror=window.bulkXss=1> Bulk three']) {
    const row = (await tool('wallos_subscriptions_create', {
      data: { name, price: 12, currency_id: 100, start_date: '2026-11-01', next_payment: '2026-11-01', cycle: 3, frequency: 1,
        category_id: 100, payer_user_id: 100, payment_method_id: 100, subscription_page_id: pageRef }, request_id: requestId(),
    })).subscription;
    ids.push(row.id);
  }
  const get = async (id) => (await tool('wallos_subscriptions_get', { id })).subscription;
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', (error) => errors.push(error.message));
  const url = `${base}/subscriptions.php?subscription_page=${pageRef}`;
  await page.goto(url, { waitUntil: 'networkidle' });
  await page.locator('#subscription-bulk-toolbar summary').click();
  await page.locator('.subscription-bulk-selection input').first().waitFor({ state: 'visible' });
  check(await page.locator('.subscription-bulk-selection input:visible').count() === 3, 'current page displays usable selection checkboxes');
  const firstCheckbox = page.locator('.subscription-bulk-selection input').first();
  await firstCheckbox.focus();
  await page.keyboard.press('Space');
  check(await firstCheckbox.isChecked(), 'keyboard Space selects a real checkbox');
  await page.locator('#subscription-bulk-clear').click();
  check(await page.locator('.subscription-bulk-selection input:checked').count() === 0, 'clear selection unchecks keyboard selection');
  await page.locator('#subscription-bulk-select-page').click();
  check((await page.locator('#subscription-bulk-count').textContent()).includes('3'), 'current-page selection displays exact count');
  check(await page.locator('.subscription-bulk-selection input:checked').count() === 3, 'select current page checks exactly current records');
  await page.locator('#subscription-bulk-action').selectOption('days');
  await page.locator('#subscription-bulk-days').fill('9');
  await page.locator('#subscription-bulk-preview-button').click();
  await page.locator('#subscription-bulk-preview').waitFor({ state: 'visible' });
  check(await page.locator('#subscription-bulk-targets li').count() === 3, 'preview names every selected target');
  check((await get(ids[0])).notify_days_before === 0, 'browser preview leaves real data unchanged');
  check(await page.evaluate(() => typeof window.bulkXss === 'undefined'), 'preview renders untrusted subscription names as text');

  // Simulate a lost response AFTER the application has committed the request.
  let originalPayload;
  await page.route('**/endpoints/subscriptions/bulkedit.php', async (route) => {
    const payload = route.request().postDataJSON();
    if (payload.action === 'update' && payload.arguments?.dry_run === false && !originalPayload) {
      originalPayload = payload;
      const response = await route.fetch();
      assert.equal(response.status(), 200);
      await route.abort('failed');
    } else await route.continue();
  });
  await page.locator('#subscription-bulk-apply').click();
  await page.locator('#subscription-bulk-retry-hint').waitFor({ state: 'visible' });
  check((await get(ids[0])).notify_days_before === 9, 'simulated lost response follows a committed batch');
  check(await page.locator('#subscription-bulk-action').isDisabled(), 'uncertain response preserves operation and prevents changing arguments');
  let retryPayload;
  page.on('request', (request) => {
    if (request.url().endsWith('/endpoints/subscriptions/bulkedit.php')) {
      const payload = request.postDataJSON();
      if (payload.action === 'update' && payload.arguments?.dry_run === false) retryPayload = payload;
    }
  });
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.locator('#subscription-bulk-apply').click()]);
  check(JSON.stringify(originalPayload) === JSON.stringify(retryPayload), 'retry sends exactly the original request ID and arguments');
  check((await get(ids[2])).notify_days_before === 9, 'safe retry preserves committed result');
  await page.unroute('**/endpoints/subscriptions/bulkedit.php');

  await page.locator('#subscription-bulk-toolbar summary').click();
  await page.locator('#subscription-bulk-select-page').click();
  await page.locator('#subscription-bulk-action').selectOption('notifyOn');
  await page.locator('#subscription-bulk-preview-button').click();
  await page.locator('#subscription-bulk-preview').waitFor({ state: 'visible' });
  const current = await get(ids[1]);
  await tool('wallos_subscriptions_update', { id: current.id, expected_version: current.version, changes: { notes: 'Concurrent edit after batch preview' }, request_id: requestId() });
  await page.locator('#subscription-bulk-apply').click();
  await page.locator('#subscription-bulk-error').waitFor({ state: 'visible' });
  check(await page.locator('#subscription-bulk-preview').isHidden(), 'conflict requires a new preview and explicit review');
  check(!(await get(ids[0])).notify && !(await get(ids[2])).notify, 'conflict leaves every other target unchanged');
  await page.locator('#subscription-bulk-action').selectOption('categories');
  await page.locator('#subscription-bulk-categories-picker-toggle').click();
  await page.locator(`#subscription-bulk-categories-picker-panel input[value="${category}"]`).check();
  await page.locator('#subscription-bulk-categories-picker-panel input[value="100"]').check();
  await page.keyboard.press('Escape');
  await page.locator('#subscription-bulk-preview-button').click();
  await page.locator('#subscription-bulk-preview').waitFor({ state: 'visible' });
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.locator('#subscription-bulk-apply').click()]);
  check((await get(ids[0])).category_ids.includes(category) && (await get(ids[2])).category_ids.length === 2, 'browser batch supports complete multi-category replacement');

  await page.setViewportSize({ width: 390, height: 844 });
  await page.locator('#subscription-bulk-toolbar summary').click();
  check(await page.locator('#subscription-bulk-panel').evaluate((element) => {
    const rect = element.getBoundingClientRect();
    return rect.left >= 0 && rect.right <= window.innerWidth + 1 && element.scrollWidth <= element.clientWidth + 1;
  }), 'bulk toolbar fits a narrow viewport');
  await page.locator('#subscription-bulk-select-page').click();
  await page.locator('#search').fill('Bulk one');
  await page.waitForFunction(() => Array.from(document.querySelectorAll('.subscription[data-subscription-id]')).filter((element) => element.getClientRects().length > 0).length === 1);
  check(await page.locator('.subscription-bulk-selection input:checked').count() === 0, 'search refresh clears old selection');
  await page.locator('#search').fill('');
  await page.waitForFunction(() => Array.from(document.querySelectorAll('.subscription[data-subscription-id]')).filter((element) => element.getClientRects().length > 0).length === 3);
  await page.locator('#subscription-bulk-select-page').click();
  await page.locator('#subscription-bulk-action').selectOption('trash');
  await page.locator('#subscription-bulk-preview-button').click();
  await page.locator('#subscription-bulk-preview').waitFor({ state: 'visible' });
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.locator('#subscription-bulk-apply').click()]);
  check((await get(ids[0])).lifecycle_status === 'trashed' && (await get(ids[2])).lifecycle_status === 'trashed', 'browser bulk delete uses the existing recycle bin');

  // CSRF and session are required even for inspect; a Bearer key alone cannot
  // turn this browser endpoint into another MCP entry point.
  const noCsrf = await context.request.post(base + '/endpoints/subscriptions/bulkedit.php', { data: { action: 'inspect', ids } });
  check(noCsrf.status() === 400 && (await noCsrf.json()).code === 'invalid_csrf', 'browser endpoint rejects missing CSRF');
  check(errors.length === 0, 'no browser exceptions: ' + errors.join('; '));
  console.log(`PASS: ${checks} bulk browser checks.`);
} finally {
  await browser.close();
}
