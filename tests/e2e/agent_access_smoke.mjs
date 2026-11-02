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
  await page.goto(`${base}/agent.php`); await page.waitForSelector('[data-agent-keys] article, [data-agent-keys] p');
  check((await page.locator('h1').textContent()).includes('Agent 接入'), 'Chinese access page renders');
  check(await page.locator('input[value="subscriptions:read"]').isChecked(), 'read scope selected by default');
  check(!(await page.locator('input[value="subscriptions:write"]').isChecked()), 'write scope requires explicit choice');
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
  for (const language of ['en','zh_cn']) {
    await page.goto(`${base}/agent.php?set_language=${language}`);
    await page.waitForSelector('[data-agent-keys] article');
    check(!(await page.locator('body').textContent()).includes('[i18n String Missing]'),`${language} has no missing translations`);
    check((await page.locator('h1').textContent()).includes(language==='en'?'Agent access':'Agent 接入'),`${language} title localized`);
    await page.setViewportSize({width:390,height:844});
    check(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth+1),`${language} narrow-screen layout has no horizontal overflow`);
  }
  await fs.mkdir('/tmp/wallos-agent-ui-artifacts',{recursive:true});
  await page.waitForFunction(()=>!document.documentElement.classList.contains('wallos-page-transition-loading'));
  await page.waitForFunction(()=>Number(getComputedStyle(document.getElementById('wallos-page-transition')).opacity)<0.01);
  await page.screenshot({path:'/tmp/wallos-agent-ui-artifacts/access-mobile.png',fullPage:true});
  check(errors.length===0,`no browser runtime errors: ${errors.join('; ')}`);
  console.log(`PASS: ${checks} browser agent-access checks.`);
} finally { await browser.close(); }
