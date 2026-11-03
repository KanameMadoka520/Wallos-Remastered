#!/usr/bin/env node
// Requires a separately issued account-2 key in the disposable fixture only.
// Account 1 settings/data are never modified by this test.
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import { chromium } from 'playwright';
const base = process.env.WALLOS_UPSTREAM_TEST_URL || 'http://127.0.0.1:18284';
const fixture = JSON.parse(await fs.readFile(process.env.WALLOS_UPSTREAM_FIXTURE_FILE, 'utf8'));
const key = JSON.parse(await fs.readFile(process.env.WALLOS_BULK_OTHER_KEY_FILE, 'utf8')).token;
const browser = await chromium.launch({ headless: true });
let checks = 0;
const check = (ok, label) => { assert(ok, label); checks++; console.log('[OK]', label); };
let originalPrivacy;
let csrf;
let context;
const requestId = () => 'bulk-privacy-' + crypto.randomUUID();
try {
  context = await browser.newContext({ serviceWorkers: 'block' });
  await context.addCookies([{ name: 'PHPSESSID', value: fixture.sessions['2'].id, url: base }]);
  const tool = async (name, args = {}) => {
    const response = await context.request.post(base + '/api/agent/v1/index.php', { headers: { Authorization: 'Bearer ' + key }, data: { tool: name, arguments: args } });
    const body = await response.json();
    assert.equal(response.status(), 200, name + ': ' + JSON.stringify(body));
    assert(body.success);
    return body.result;
  };
  assert.equal((await tool('wallos_context')).account.username, 'upstream-test-2', 'Refusing privacy test outside disposable account 2');
  const page = await context.newPage();
  await page.goto(base + '/settings.php#settings-display', { waitUntil: 'networkidle' });
  originalPrivacy = await page.locator('#screenshotprivacymode').isChecked();
  csrf = await page.evaluate(() => window.csrfToken);
  assert(csrf);
  const created = (await tool('wallos_subscriptions_create', {
    data: { name: 'Private bulk fixture secret name', price: 12, currency_id: 200, start_date: '2026-11-01', next_payment: '2026-11-01', cycle: 3, frequency: 1, category_id: 200, payer_user_id: 200, payment_method_id: 200 }, request_id: requestId(),
  })).subscription;
  const enabled = await context.request.post(base + '/endpoints/settings/screenshot_privacy_mode.php', { headers: { 'X-CSRF-Token': csrf }, data: { value: true } });
  assert((await enabled.json()).success);
  await page.goto(base + '/subscriptions.php', { waitUntil: 'networkidle' });
  check(await page.locator('#subscription-bulk-toolbar').count() === 0, 'privacy mode omits bulk controls and real reference options');
  check(!(await page.locator('body').innerText()).includes('Private bulk fixture secret name'), 'privacy mode does not reveal real selected record name');
  const inspect = await context.request.post(base + '/endpoints/subscriptions/bulkedit.php', { headers: { 'X-CSRF-Token': csrf }, data: { action: 'inspect', ids: [created.id] } });
  check(inspect.status() === 403 && (await inspect.json()).code === 'screenshot_privacy_mode_blocked', 'privacy mode blocks backend browser inspect');
  const update = await context.request.post(base + '/endpoints/subscriptions/bulkedit.php', { headers: { 'X-CSRF-Token': csrf }, data: { action: 'update', arguments: { targets: [{ id: created.id, expected_version: created.version }], changes: { notify: true }, request_id: requestId() } } });
  check(update.status() === 403 && (await update.json()).code === 'screenshot_privacy_mode_blocked', 'privacy mode blocks backend browser writes');
  const allowed = (await tool('wallos_subscriptions_bulk_update', { targets: [{ id: created.id, expected_version: created.version }], changes: { notify: true }, request_id: requestId() })).subscriptions[0];
  check(!!allowed.notify && allowed.name === created.name, 'same-account MCP retains independent scoped access in screenshot mode');
  await tool('wallos_subscriptions_trash', { id: allowed.id, expected_version: allowed.version, confirm_id: allowed.id, request_id: requestId() });
} finally {
  if (context && csrf && originalPrivacy !== undefined) {
    const restored = await context.request.post(base + '/endpoints/settings/screenshot_privacy_mode.php', { headers: { 'X-CSRF-Token': csrf }, data: { value: originalPrivacy } });
    check((await restored.json()).success, 'account-2 privacy setting restored');
  }
  await browser.close();
}
console.log(`PASS: ${checks} bulk privacy checks.`);
