#!/usr/bin/env node
// UI-only fixtures: never send a real AI request, notification, or business write.
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import { chromium } from 'playwright';

const base = (process.env.WALLOS_BASE_URL || 'http://127.0.0.1:18286').replace(/\/$/, '');
const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({ serviceWorkers: 'block', reducedMotion: 'reduce' });
if (process.env.WALLOS_UI_SESSION_FILE) {
  const auth = JSON.parse(await fs.readFile(process.env.WALLOS_UI_SESSION_FILE, 'utf8'));
  await context.addCookies([{ name: 'PHPSESSID', value: auth.session, url: base }]);
} else {
  assert(process.env.WALLOS_TEST_USERNAME && process.env.WALLOS_TEST_PASSWORD, 'Set a dedicated UI session file or test login.');
  const response = await context.request.post(`${base}/login.php`, { form: {
    username: process.env.WALLOS_TEST_USERNAME, password: process.env.WALLOS_TEST_PASSWORD,
  } });
  assert(response.ok(), 'Fixture login failed.');
}
const page = await context.newPage();
const errors = [];
page.on('pageerror', error => errors.push(error.message));
const devices = Array.from({ length: 20 }, (_, index) => ({ id: index + 1,
  device_name: `Desktop device ${index + 1}: ${'A long device label '.repeat(6)}`, current: false }));
await page.route('**/endpoints/notifications/webpush.php', route => {
  assert.equal(JSON.parse(route.request().postData()).action, 'status', 'Only read-only Web Push status may be requested.');
  return route.fulfill({ json: { success: true, settings: { enabled: 0, show_details: 0 }, devices, public_key: '' } });
});
const image = 'https://raw.githubusercontent.com/selfhst/icons/main/png/github.png';
const icons = Array.from({ length: 24 }, (_, index) => ({ image, name: `Icon ${index + 1} with a long description` }));
await page.route('**/endpoints/payments/search.php?*', route => route.fulfill({ json: { results: icons } }));
await page.route('**/endpoints/logos/search.php?*', route => route.fulfill({ json: { results: icons } }));
await page.route('**/endpoints/logos/icon_search.php', route => route.fulfill({ json: {
  success: true, results: icons, page: 1, total: 25, has_more: true,
} }));
await page.route('**/endpoints/ai/translate_categories.php', async route => {
  assert.equal(JSON.parse(route.request().postData()).action, 'preview', 'Only mocked preview generation may be requested.');
  const translations = await page.locator('#categories [data-categoryid]').evaluateAll(rows => rows.map(row => ({
    id: Number(row.dataset.categoryid), before: row.querySelector('input[name="category"]').value,
    name: 'A long proposed translation for preview '.repeat(6),
  })));
  return route.fulfill({ json: { success: true, translations, token: 'ui-only-preview', language_name: 'Preview language' } });
});
await page.route(image, route => route.fulfill({ contentType: 'image/png', body: Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+cX1kAAAAASUVORK5CYII=', 'base64') }));
let checks = 0;
function check(value, label) { assert(value, label); checks++; console.log(`[OK] ${label}`); }
async function navigate(path, theme) {
  await page.goto(base + path, { waitUntil: 'networkidle' });
  assert(!page.url().includes('login.php'), 'Authenticated session required.');
  await page.waitForFunction(() => {
    const overlay = document.getElementById('wallos-page-transition');
    return !overlay || Number(getComputedStyle(overlay).opacity) < 0.01;
  });
  await page.evaluate(themeName => {
    document.getElementById('dark-theme').disabled = themeName !== 'dark';
    document.body.classList.remove('dark', 'light');
    document.body.classList.add(themeName);
  }, theme);
}
async function snapshot(name) {
  if (!process.env.WALLOS_E2E_ARTIFACT_DIR) return;
  await fs.mkdir(process.env.WALLOS_E2E_ARTIFACT_DIR, { recursive: true });
  await page.screenshot({ path: `${process.env.WALLOS_E2E_ARTIFACT_DIR}/settings-${name}.png` });
}
async function popupFits(selector) {
  return page.locator(selector).evaluate(el => {
    const box = el.getBoundingClientRect();
    let fits = box.left >= 10 && box.top >= 10 && box.right <= innerWidth - 10 && box.bottom <= innerHeight - 10;
    for (let parent = el.parentElement; parent && parent !== document.body; parent = parent.parentElement) {
      const style = getComputedStyle(parent), bounds = parent.getBoundingClientRect();
      if (/auto|scroll|hidden|clip/.test(style.overflowX)) fits &&= box.left >= bounds.left && box.right <= bounds.right;
      if (/auto|scroll|hidden|clip/.test(style.overflowY)) fits &&= box.top >= bounds.top && box.bottom <= bounds.bottom;
    }
    return fits;
  });
}
async function checkPopup(kind, label) {
  const selector = kind === 'payment' ? '#icon-search-results' : '#logo-search-results';
  const popup = page.locator(selector);
  await popup.locator('.logo-catalog-result').first().waitFor();
  check(await popupFits(selector), `${label}: ${kind} logo popup fits viewport and clipping ancestors`);
  await popup.evaluate(el => {
    let container = el.parentElement;
    while (container && container !== document.body) {
      if (/auto|scroll/.test(getComputedStyle(container).overflowY) && container.scrollHeight > container.clientHeight) {
        container.scrollTop += 80;
        return;
      }
      container = container.parentElement;
    }
    window.scrollBy(0, 80);
  });
  await page.waitForTimeout(100);
  check(await popupFits(selector), `${label}: ${kind} popup follows its scrolling container`);
  check(await popup.locator('input[type="search"]').evaluate(el => el.getBoundingClientRect().height >= 44), `${label}: ${kind} logo query has a usable themed field`);
  await popup.locator('[data-logo-catalog-source]').selectOption('selfhst');
  await page.waitForFunction(selector => !document.querySelector(selector + ' .logo-catalog-pagination').hidden, selector);
  const images = popup.locator('.logo-catalog-images');
  check(await images.evaluate(el => el.scrollHeight > el.clientHeight && el.clientHeight >= 50), `${label}: ${kind} results scroll without hiding controls`);
  await images.evaluate(el => { el.scrollTop = el.scrollHeight; });
  check(await popup.locator('.logo-catalog-pagination').evaluate(el => {
    const b = el.getBoundingClientRect(), p = el.closest('.logo-catalog-popup').getBoundingClientRect();
    return b.top >= p.top && b.bottom <= p.bottom;
  }), `${label}: ${kind} pagination remains inside the popup`);
  await snapshot(`${label}-${kind}-logo`);
  await popup.locator('input[type="search"]').press('Escape');
  check(!await popup.evaluate(el => el.classList.contains('is-open')), `${label}: ${kind} logo Escape closes the popup`);
  if (label === '1366x768-light') {
    await page.locator(kind === 'payment' ? '#icon-search-button' : '#logo-search-button').click();
    await popup.locator('.close-logo-search, .close-icon-search').press('Enter');
    check(!await popup.evaluate(el => el.classList.contains('is-open')), `${label}: ${kind} close control works with a keyboard`);
  }
}
try {
  for (const viewport of [{ width: 1366, height: 768 }, { width: 1440, height: 900 }, { width: 1920, height: 1080 }]) {
    await page.setViewportSize(viewport);
    for (const theme of ['light', 'dark']) {
      const label = `${viewport.width}x${viewport.height}-${theme}`;
      await navigate('/settings.php', theme);
      await page.locator('#ai-category-translation').scrollIntoViewIfNeeded();
      check(await page.locator('#ai-category-translate').evaluate(el => el.classList.contains('button') && el.getBoundingClientRect().height >= 44), `${label}: AI category action uses the visible theme button`);
      await snapshot(`${label}-categories`);
      await page.locator('#ai-category-translate').click();
      await page.locator('#ai-category-preview').waitFor({ state: 'visible' });
      check(await page.locator('.ai-category-preview-scroll').evaluate(el => el.scrollWidth <= el.clientWidth && el.clientHeight <= 420), `${label}: long translation previews wrap inside their scroll area`);
      await page.locator('#ai-category-cancel').scrollIntoViewIfNeeded();
      await snapshot(`${label}-category-preview`);
      await page.locator('#ai-category-cancel').click();
      check(await page.locator('#ai-category-preview').isHidden(), `${label}: translation preview can be cancelled without applying`);
      await page.locator('#settings-ai').scrollIntoViewIfNeeded();
      check(await page.locator('#settings-ai .form-group-inline button:visible').evaluateAll(buttons => buttons.every(button => {
        const box = button.getBoundingClientRect();
        const line = document.createRange(); line.selectNodeContents(button);
        return box.height <= 54 && line.getBoundingClientRect().width <= box.width;
      })), `${label}: AI provider controls keep horizontal labels`);
      check(await page.locator('#ai_ollama_host').evaluate(input => {
        const visible = getComputedStyle(input.closest('.form-group-inline')).display !== 'none';
        return !visible || input.getBoundingClientRect().width >= 150;
      }), `${label}: AI provider URL remains usable beside its test button`);
      await snapshot(`${label}-ai`);
      await page.locator('#webpush-settings header').click();
      await page.waitForFunction(() => {
        const panel = document.querySelector('#webpush-settings .account-notification-section-settings');
        return panel.classList.contains('is-open') && panel.scrollHeight <= panel.clientHeight + 1;
      });
      await page.locator('#webpush-settings').scrollIntoViewIfNeeded();
      await page.waitForFunction(() => document.querySelectorAll('#webpush-devices .webpush-device').length === 20);
      check(await page.locator('.webpush-device-controls').evaluate(el => {
        const bounds = el.getBoundingClientRect();
        return [...el.querySelectorAll('button')].every(button => {
          const b = button.getBoundingClientRect();
          return b.width >= 90 && b.height >= 40 && b.left >= bounds.left && b.right <= bounds.right;
        }) && el.querySelector('input').getBoundingClientRect().width >= 150;
      }), `${label}: Web Push input and actions stay readable`);
      check(await page.locator('#webpush-devices').evaluate(el => {
        const box = el.getBoundingClientRect(), panel = el.closest('.account-notification-section-settings').getBoundingClientRect();
        return el.scrollHeight > el.clientHeight && box.bottom <= panel.bottom && el.scrollWidth <= el.clientWidth;
      }), `${label}: twenty Web Push devices scroll inside their expanded panel`);
      await page.locator('#webpush-devices').evaluate(el => { el.scrollTop = el.scrollHeight; });
      await page.locator('#webpush-devices .webpush-device:last-child button:last-child').scrollIntoViewIfNeeded();
      await snapshot(`${label}-webpush`);
      await page.locator('#paymentname').fill('github');
      await page.locator('#icon-search-button').click();
      await checkPopup('payment', label);
      await navigate('/subscriptions.php', theme);
      await page.locator('[data-subscription-action="open-add-subscription"]:visible').first().click();
      await page.locator('#name').fill('github');
      await page.locator('#logo-search-button').click();
      await checkPopup('subscription', label);
    }
  }
  check(errors.length === 0, 'No browser exceptions in desktop settings and logo components');
  console.log(`PASS: ${checks} desktop component checks; real AI, notification sends and business writes were not invoked.`);
} catch (error) {
  await snapshot('desktop-components-failed').catch(() => {});
  throw error;
} finally { await browser.close(); }
