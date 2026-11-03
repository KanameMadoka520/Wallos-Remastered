#!/usr/bin/env node
// Requires the disposable nginx/PHP-FPM fixture and its temporary session.
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
const {chromium} = await import(process.env.WALLOS_PLAYWRIGHT_MODULE || 'playwright');
const base = process.env.WALLOS_AGENT_UI_TEST_URL || 'http://127.0.0.1:18284';
const browser = await chromium.launch({headless: true, executablePath: process.env.WALLOS_CHROMIUM_EXECUTABLE, args: ['--no-sandbox']});
let checks = 0;
const check = (ok, label) => { assert(ok, label); checks++; console.log(`[OK] ${label}`); };
try {
  const context = await browser.newContext({viewport:{width:1280,height:900},serviceWorkers:'block'});
  await context.addCookies([{name:'PHPSESSID',value:'wallos-agent-ui-fixture',url:base}]);
  const page = await context.newPage();
  const errors=[]; page.on('pageerror', e=>errors.push(e.message));
  await page.goto(`${base}/agent.php?set_language=zh_cn`); await page.waitForSelector('[data-agent-keys] article, [data-agent-keys] p');
  check((await page.locator('h1').textContent()).includes('Agent 接入'), 'Chinese access page renders');
  const permissionControlsVisible = () => page.locator('input[name="scopes[]"]').evaluateAll(inputs => inputs.every(input => {
    const style = getComputedStyle(input);
    const box = input.getBoundingClientRect();
    return Number(style.opacity) >= 0.9 && style.visibility === 'visible' && box.width >= 16 && box.height >= 16;
  }));
  check(await permissionControlsVisible(), 'permission checkboxes are visibly rendered despite global checkbox styles');
  check(await page.locator('input[value="subscriptions:read"]').isChecked(), 'read scope selected by default');
  check(!(await page.locator('input[value="subscriptions:write"]').isChecked()), 'write scope requires explicit choice');
  await page.locator('input[value="subscriptions:write"]').check();
  check(await page.locator('input[value="subscriptions:write"]').isChecked(), 'write checkbox can be selected with a real pointer click');
  await page.locator('input[value="subscriptions:write"]').focus();
  await page.keyboard.press('Space');
  check(!(await page.locator('input[value="subscriptions:write"]').isChecked()), 'write checkbox can be changed with the keyboard');
  const keyName = `<img src=x onerror=alert(1)> ${Date.now()}`;
  await page.locator('input[name="name"]').fill(keyName);
  await page.locator('[data-agent-create] button[type=submit]').click();
  await page.locator('[data-agent-secret-panel]').waitFor({state:'visible'});
  const token=await page.locator('[data-agent-secret]').inputValue();
  check(/^wls_[a-f0-9]{64}$/.test(token),'key shown once with 256-bit random secret');
  await page.waitForFunction(name=>document.querySelector('[data-agent-keys]').textContent.includes(name), keyName);
  check(await page.locator('[data-agent-keys] img').count()===0,'malicious key name rendered as text');
  const api = await context.request.post(`${base}/api/agent/mcp.php`,{headers:{Authorization:`Bearer ${token}`,Accept:'application/json, text/event-stream'},data:{jsonrpc:'2.0',id:1,method:'tools/list'}});
  const tools=(await api.json()).result.tools;
  check(tools.length===6 && tools.every(t=>t.annotations.readOnlyHint),'read-only key discovers only authorized tools');
  const forbidden=await context.request.post(`${base}/api/agent/v1/index.php`,{headers:{Authorization:`Bearer ${token}`},data:{tool:'wallos_budgets_update',arguments:{expected_version:'0'.repeat(64),changes:{budget:1},request_id:'ui-forbidden-001'}}});
  check(forbidden.status()===403,'read-only UI key cannot bypass catalog and write');
  await page.locator('[data-agent-hide]').click();
  check((await page.locator('[data-agent-secret]').inputValue())==='','hide erases secret from input');
  await page.reload(); await page.waitForFunction(name=>document.querySelector('[data-agent-keys]').textContent.includes(name), keyName);
  check((await page.locator('[data-agent-secret]').inputValue())==='','reload cannot retrieve full key');
  const missingCsrf=await context.request.post(`${base}/endpoints/agent/keys.php`,{form:{action:'create',name:'csrf-test',days:'30','scopes[]':'subscriptions:read'}});
  check(missingCsrf.status()===400,'key management requires CSRF even with login cookie');
  const cookiesOnly=await context.request.post(`${base}/api/agent/mcp.php`,{data:{jsonrpc:'2.0',id:2,method:'ping'}});
  check(cookiesOnly.status()===401,'login cookie cannot grant MCP access');
  page.once('dialog',d=>d.accept());
  await page.locator('[data-agent-keys] article').filter({hasText:keyName}).getByRole('button').click();
  await page.waitForFunction(name=>Array.from(document.querySelectorAll('[data-agent-keys] article')).some(el=>el.textContent.includes(name)&&el.textContent.includes('已撤销')), keyName);
  const revoked=await context.request.post(`${base}/api/agent/mcp.php`,{headers:{Authorization:`Bearer ${token}`},data:{jsonrpc:'2.0',id:3,method:'ping'}});
  check(revoked.status()===401,'UI revocation immediately rejects new MCP requests');
  const createKey = async (name, preset) => {
    await page.locator(`[data-agent-preset="${preset}"]`).click();
    await page.locator('input[name="name"]').fill(name);
    await page.locator('[data-agent-create] button[type=submit]').click();
    await page.waitForFunction(name => document.querySelector('[data-agent-keys]').textContent.includes(name), name);
    return page.locator('[data-agent-secret]').inputValue();
  };
  const rpc = async (key, method, params) => {
    const response = await context.request.post(`${base}/api/agent/mcp.php`, {
      headers: {Authorization: `Bearer ${key}`, Accept: 'application/json, text/event-stream'},
      data: {jsonrpc: '2.0', id: 'ui-write-check', method, ...(params ? {params} : {})},
    });
    assert.equal(response.status(), 200);
    const body = await response.json();
    assert(!body.error, body.error?.message);
    assert(!body.result.isError, 'MCP business operation must succeed');
    return body.result;
  };
  const writeName = `ui-read-write-${Date.now()}`;
  const writeKey = await createKey(writeName, 'write');
  check(await page.locator('input[name="scopes[]"]').evaluateAll(inputs => inputs.every(input => input.checked === !input.value.endsWith(':delete'))), 'read/edit preset selects reads and writes without deletion');
  const identity = (await rpc(writeKey, 'tools/call', {name: 'wallos_context', arguments: {}})).structuredContent;
  assert.equal(identity.account.username, 'agent-ui-test', 'refuse mutations outside disposable UI fixture');
  const writeTools = (await rpc(writeKey, 'tools/list')).tools.map(tool => tool.name);
  check(writeTools.includes('wallos_subscriptions_create') && writeTools.includes('wallos_subscriptions_update') && !writeTools.includes('wallos_subscriptions_trash') && !writeTools.includes('wallos_payments_delete'), 'UI read/edit key exposes writes and withholds deletion on the MCP server');
  const created = (await rpc(writeKey, 'tools/call', {name: 'wallos_subscriptions_create', arguments: {
    request_id: `ui-create-${Date.now()}`,
    data: {name: 'Disposable UI permission test', price: 10, currency_id: 100, start_date: '2026-11-03', next_payment: '2026-11-03', cycle: 3, frequency: 1, category_id: 100, payment_method_id: 100, payer_user_id: 100},
  }})).structuredContent.subscription;
  check(created.id > 0 && created.price === 10, 'key created through the read/edit UI preset can actually create via MCP');
  const noDelete = await context.request.post(`${base}/api/agent/v1/index.php`, {
    headers: {Authorization: `Bearer ${writeKey}`},
    data: {tool: 'wallos_subscriptions_trash', arguments: {id: created.id, confirm_id: created.id, expected_version: created.version, request_id: `ui-denied-delete-${Date.now()}`}},
  });
  check(noDelete.status() === 403, 'read/edit UI key cannot delete even when the tool name is known');
  const allName = `ui-full-access-${Date.now()}`;
  const allKey = await createKey(allName, 'all');
  check(await page.locator('input[name="scopes[]"]').evaluateAll(inputs => inputs.every(input => input.checked)), 'all-permissions preset explicitly selects every scope including deletion');
  check((await rpc(allKey, 'tools/list')).tools.length === 20, 'full-access key created in the UI discovers all 20 MCP tools');
  const changed = (await rpc(allKey, 'tools/call', {name: 'wallos_subscriptions_update', arguments: {
    id: created.id, expected_version: created.version, changes: {price: 42}, request_id: `ui-update-${Date.now()}`,
  }})).structuredContent.subscription;
  check(changed.price === 42, 'full-access UI key can modify a subscription via MCP');
  const trashed = (await rpc(allKey, 'tools/call', {name: 'wallos_subscriptions_trash', arguments: {
    id: changed.id, confirm_id: changed.id, expected_version: changed.version, request_id: `ui-trash-${Date.now()}`,
  }})).structuredContent.subscription;
  check(trashed.lifecycle_status === 'trashed', 'full-access UI key can move the test subscription to the recycle bin via MCP');
  for (const name of [writeName, allName]) {
    page.once('dialog', dialog => dialog.accept());
    await page.locator('[data-agent-keys] article').filter({hasText: name}).getByRole('button').click();
    await page.waitForFunction(name => Array.from(document.querySelectorAll('[data-agent-keys] article')).some(el => el.textContent.includes(name) && el.textContent.includes('已撤销')), name);
  }
  for (const language of ['en','zh_cn']) {
    await page.goto(`${base}/agent.php?set_language=${language}`);
    await page.waitForSelector('[data-agent-keys] article');
    check(!(await page.locator('body').textContent()).includes('[i18n String Missing]'),`${language} has no missing translations`);
    check((await page.locator('h1').textContent()).includes(language==='en'?'Agent access':'Agent 接入'),`${language} title localized`);
    await page.setViewportSize({width:390,height:844});
    check(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth+1),`${language} narrow-screen layout has no horizontal overflow`);
    check(await permissionControlsVisible(), `${language} permission controls remain visible on a narrow screen`);
    await page.locator('[data-agent-preset="read"]').click();
    check(await page.locator('input[name="scopes[]"]').evaluateAll(inputs => inputs.every(input => input.checked === input.value.endsWith(':read'))), `${language} read-only preset clears write and delete scopes`);
    await page.locator('input[value="subscriptions:write"]').check();
    check(await page.locator('input[value="subscriptions:write"]').isChecked(), `${language} mobile write checkbox responds to a pointer click`);
  }
  await fs.mkdir('/tmp/wallos-agent-ui-artifacts',{recursive:true});
  await page.waitForFunction(()=>!document.documentElement.classList.contains('wallos-page-transition-loading'));
  await page.waitForFunction(()=>Number(getComputedStyle(document.getElementById('wallos-page-transition')).opacity)<0.01);
  await page.screenshot({path:'/tmp/wallos-agent-ui-artifacts/access-mobile.png',fullPage:true});
  for (const colorScheme of ['light', 'dark']) {
    await page.emulateMedia({colorScheme});
    await page.reload();
    await page.waitForSelector('[data-agent-keys] article');
    check(await page.locator('#dark-theme').evaluate((link, dark) => link.disabled === !dark, colorScheme === 'dark'), `${colorScheme} theme stylesheet is applied`);
    for (const width of [320, 1280]) {
      await page.setViewportSize({width, height:900});
      check(await permissionControlsVisible(), `${colorScheme} permission controls are visible at ${width}px`);
      check(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), `${colorScheme} permission form fits ${width}px`);
    }
    await page.waitForFunction(() => !document.documentElement.classList.contains('wallos-page-transition-loading'));
    await page.waitForFunction(() => Number(getComputedStyle(document.getElementById('wallos-page-transition')).opacity) < 0.01);
    await page.locator('[data-agent-create]').screenshot({path:`/tmp/wallos-agent-ui-artifacts/permissions-${colorScheme}.png`});
  }
  check(errors.length===0,`no browser runtime errors: ${errors.join('; ')}`);
  console.log(`PASS: ${checks} browser agent-access checks.`);
} finally { await browser.close(); }
