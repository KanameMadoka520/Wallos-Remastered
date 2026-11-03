import { chromium } from 'playwright';
import fs from 'node:fs';
import assert from 'node:assert/strict';

const base = (process.env.WALLOS_BASE_URL || 'http://127.0.0.1:18282').replace(/\/$/, '');
const credentials = process.env.WALLOS_TEST_CREDENTIALS_FILE
  ? JSON.parse(fs.readFileSync(process.env.WALLOS_TEST_CREDENTIALS_FILE, 'utf8')) : {};
const username = process.env.WALLOS_TEST_USERNAME || credentials.username;
const password = process.env.WALLOS_TEST_PASSWORD || credentials.password;
if (!username || !password) throw new Error('Set dedicated WALLOS_TEST_USERNAME / WALLOS_TEST_PASSWORD. This test creates temporary records.');
const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({ serviceWorkers: 'block' });
const page = await context.newPage();
const errors = [];
page.on('pageerror', error => errors.push(error.message));
let subscriptionId;
let paymentId;
const icon = 'https://raw.githubusercontent.com/selfhst/icons/main/png/github.png';
let csrf;
const headers = () => ({ 'X-CSRF-Token': csrf });

try {
  const anonymous = await browser.newContext();
  const denied = await anonymous.request.post(`${base}/endpoints/logos/icon_search.php`, { form: { source: 'selfhst', search: 'github' } });
  assert.equal(denied.status(), 401, 'Anonymous requests must be rejected before network fetches.');
  await anonymous.close();
  await page.goto(`${base}/login.php`);
  await page.locator('#username').fill(username);
  await page.locator('#password').fill(password);
  await Promise.all([page.waitForURL(url => !url.pathname.endsWith('/login.php')), page.locator('input[type="submit"],button[type="submit"]').first().click()]);
  await page.goto(`${base}/subscriptions.php`);
  await page.waitForFunction(() => window.csrfToken && document.querySelector('[data-logo-catalog-source="subscription"]'));
  csrf = await page.evaluate(() => window.csrfToken);
  assert.equal((await context.request.get(`${base}/endpoints/logos/icon_search.php`)).status(), 405);
  assert.equal((await context.request.post(`${base}/endpoints/logos/icon_search.php`, { form: { source: 'selfhst', search: 'github' } })).status(), 400);
  for (const form of [{ source: 'arbitrary', search: 'github' }, { source: 'selfhst', search: 'github', limit: '49' }, { source: 'selfhst', search: 'github', page: '0' }]) {
    assert.equal((await context.request.post(`${base}/endpoints/logos/icon_search.php`, { headers: headers(), form })).status(), 400);
  }
  for (const source of ['selfhst', 'dashboardicons']) {
    const response = await context.request.post(`${base}/endpoints/logos/icon_search.php`, { headers: headers(), form: { source, search: 'github', limit: '1', page: '1' } });
    assert.equal(response.status(), 200, `${source} real upstream index failed.`);
    assert.match(response.headers()['cache-control'], /no-store/);
    const result = await response.json();
    assert.equal(result.results.length, 1);
    assert.equal(result.results[0].provider, source);
    assert.match(result.results[0].image, /^https:\/\/raw\.githubusercontent\.com\//);
  }
  console.log('HTTP authentication, CSRF, bounds and both real upstream catalogs passed.');

  let webQuery;
  await page.route('**/endpoints/logos/search.php?*', route => {
    webQuery = new URL(route.request().url()).searchParams.get('search');
    return route.fulfill({ json: { results: [{ image: icon, name: 'GitHub' }] } });
  });
  await page.locator('[data-subscription-action="open-add-subscription"]:visible').first().click();
  await page.locator('#name').fill('GitHub & test?');
  await page.locator('#logo-search-button').click();
  await page.locator('#logo-search-images .logo-catalog-result').first().waitFor();
  assert.equal(webQuery, 'GitHub & test?', 'Existing web search must encode the query.');
  assert.equal(await page.locator('[data-logo-catalog-source="subscription"]').inputValue(), 'web');
  await page.locator('[data-logo-catalog-query="subscription"]').fill('github');
  await page.locator('[data-logo-catalog-source="subscription"]').selectOption('selfhst');
  await page.waitForFunction(() => document.querySelector('#logo-search-images small')?.textContent === 'selfh.st');
  await page.waitForFunction(() => { const image = document.querySelector('#logo-search-images img'); return image?.complete && image.naturalWidth > 0; });
  assert.equal(await page.locator('#logo-search-images img').first().evaluate(image => image.complete && image.naturalWidth > 0), true, 'Catalog PNG preview must load.');
  await page.locator('#logo-search-images .logo-catalog-result').first().click();
  assert.equal(await page.locator('#logo-url').inputValue(), icon);
  assert.equal(await page.locator('#logo-search-results').evaluate(el => el.classList.contains('is-open')), false);
  const subscriptionName = `E2E Catalog ${Date.now()}`;
  await page.locator('#name').fill(subscriptionName);
  await page.locator('#price').fill('1.23');
  await page.locator('#start_date').fill(new Date().toISOString().slice(0, 10));
  await page.locator('#next_payment').fill(new Date(Date.now() + 86400000 * 30).toISOString().slice(0, 10));
  const saved = page.waitForResponse(response => response.url().endsWith('/endpoints/subscription/add.php') && response.request().method() === 'POST');
  await page.locator('#save-button').click();
  const saveResult = await (await saved).json();
  assert.equal(saveResult.status, 'Success');
  assert.equal(saveResult.logo_warning, undefined, 'Catalog image must pass the existing backend save pipeline.');
  const card = page.locator('.subscription-container', { hasText: subscriptionName }).first();
  await card.waitFor();
  subscriptionId = await card.getAttribute('data-id');
  assert.ok(subscriptionId);
  const storedLogo = card.locator('.logo img').first();
  assert.match(await storedLogo.getAttribute('src'), /^images\/uploads\/logos\//);
  await storedLogo.evaluate(image => image.decode());
  assert.equal(await storedLogo.evaluate(image => image.naturalWidth > 0), true);
  console.log('Subscription catalog selection and existing PNG save pipeline passed.');

  await page.locator('[data-subscription-action="open-add-subscription"]:visible').first().click();
  await page.locator('#name').fill('github');
  await page.locator('#logo-search-button').click();
  await page.waitForFunction(() => document.querySelector('#logo-search-images .logo-catalog-result'));
  // A malicious upstream label stays text; pagination and outages preserve safe selections.
  let failCatalog = false;
  await page.route('**/endpoints/logos/icon_search.php', async route => {
    if (failCatalog) return route.fulfill({ status: 502, json: { success: false, code: 'logo_catalog_unavailable' } });
    const form = new URLSearchParams(route.request().postData());
    const currentPage = Number(form.get('page') || 1);
    return route.fulfill({ json: { success: true, results: [{ name: '<img src=x onerror=window.catalogXss=1>', image: icon }], page: currentPage, total: 25, has_more: currentPage === 1 } });
  });
  await page.locator('[data-logo-catalog-source="subscription"]').selectOption('dashboardicons');
  await page.waitForFunction(() => document.querySelector('.logo-catalog-caption')?.textContent.includes('onerror='));
  assert.equal(await page.locator('.logo-catalog-caption img').count(), 0);
  assert.equal(await page.evaluate(() => window.catalogXss), undefined);
  await page.locator('#logo-search-results .logo-catalog-pagination button').last().click();
  await page.waitForFunction(() => document.querySelector('#logo-search-results .logo-catalog-pagination span')?.textContent === '2');
  assert.equal(await page.locator('#logo-search-results .logo-catalog-pagination button').last().isDisabled(), true);
  failCatalog = true;
  await page.locator('#logo-search-results .logo-catalog-toolbar button').click();
  await page.waitForFunction(() => !document.querySelector('#logo-search-results .logo-catalog-toolbar button').disabled);
  assert.equal(await page.locator('#logo-search-images .logo-catalog-result').count(), 1, 'Outages must retain previous safe results.');
  await page.locator('[data-logo-catalog-source="subscription"]').selectOption('web');
  await page.waitForFunction(() => document.querySelector('#logo-search-images .logo-catalog-caption')?.textContent === 'GitHub');
  await page.evaluate(() => {
    window.WallosScreenshotPrivacy.setEnabled(true);
    document.querySelector('#name').value = 'Private catalog query';
    window.searchLogo();
  });
  assert.equal(await page.locator('#logo-search-results').evaluate(el => el.classList.contains('is-open')), false);
  assert.equal(await page.locator('[data-logo-catalog-query="subscription"]').inputValue(), '', 'Privacy mode must clear the copied search query.');
  assert.equal(await page.locator('#logo-search-images').textContent(), '');
  await page.evaluate(() => window.WallosScreenshotPrivacy.setEnabled(false));
  await page.unroute('**/endpoints/logos/icon_search.php');
  console.log('Safe label rendering, pagination, outage recovery and default web source passed.');

  await page.goto(`${base}/settings.php`);
  await page.waitForFunction(() => document.querySelector('[data-logo-catalog-source="payment"]'));
  csrf = await page.evaluate(() => window.csrfToken);
  await page.route('**/endpoints/payments/search.php?*', route => route.fulfill({ json: { imageUrls: [icon] } }));
  await page.locator('#paymentname').fill('github');
  await page.locator('#icon-search-button').click();
  await page.locator('[data-logo-catalog-source="payment"]').selectOption('selfhst');
  await page.waitForFunction(() => document.querySelector('#icon-search-images small')?.textContent === 'selfh.st');
  await page.locator('#icon-search-images .logo-catalog-result').first().click();
  assert.equal(await page.locator('#icon-url').inputValue(), icon);
  const paymentName = `E2E Catalog Payment ${Date.now()}`;
  await page.locator('#paymentname').fill(paymentName);
  const paymentSaved = page.waitForResponse(response => response.url().endsWith('/endpoints/payments/add.php') && response.request().method() === 'POST');
  await page.locator('#add-payment-button').click();
  assert.equal((await (await paymentSaved).json()).success, true);
  const payment = page.locator('.payments-payment', { hasText: paymentName });
  await payment.waitFor();
  paymentId = await payment.getAttribute('data-paymentid');
  assert.ok(paymentId);
  assert.match(await payment.locator('img').getAttribute('src'), /^images\/uploads\/logos\//);
  await payment.locator('img').evaluate(image => image.decode());
  assert.equal(await payment.locator('img').evaluate(image => image.naturalWidth > 0), true);
  assert.equal(await page.locator('body').evaluate(el => el.scrollWidth <= window.innerWidth + 2), true);
  await page.setViewportSize({ width: 390, height: 844 });
  await page.locator('#paymentname').fill('github');
  await page.locator('#icon-search-button').click();
  const mobilePopup = await page.locator('#icon-search-results').boundingBox();
  assert.ok(mobilePopup && mobilePopup.x >= 0 && mobilePopup.x + mobilePopup.width <= 390, 'Mobile catalog popup must stay inside the viewport.');
  await page.evaluate(() => {
    window.WallosScreenshotPrivacy.setEnabled(true);
    document.querySelector('#paymentname').value = 'Private payment query';
    window.searchPaymentIcon();
  });
  assert.equal(await page.locator('#icon-search-results').evaluate(el => el.classList.contains('is-open')), false);
  assert.equal(await page.locator('[data-logo-catalog-query="payment"]').inputValue(), '');
  await page.evaluate(() => window.WallosScreenshotPrivacy.setEnabled(false));
  assert.deepEqual(errors, []);
  console.log('Payment catalog selection and existing PNG save pipeline passed.');
} finally {
  if (subscriptionId) {
    for (const endpoint of ['delete.php', 'permanentdelete.php']) {
      await context.request.post(`${base}/endpoints/subscription/${endpoint}`, { headers: headers(), data: { id: Number(subscriptionId) } }).catch(() => {});
    }
  }
  if (paymentId) {
    await context.request.post(`${base}/endpoints/payments/delete.php`, { headers: headers(), data: { id: Number(paymentId) } }).catch(() => {});
  }
  await browser.close();
}
