#!/usr/bin/env node
// Real preview/apply UI against a disposable fixture and local mock AI only.
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import {execFileSync} from 'node:child_process';
import {chromium} from 'playwright';

const base = (process.env.WALLOS_UPSTREAM_TEST_URL || 'http://127.0.0.1:18284').replace(/\/$/, '');
const fixture = JSON.parse(await fs.readFile(process.env.WALLOS_UPSTREAM_FIXTURE_FILE, 'utf8'));
const dbFile = process.env.WALLOS_FEATURE_TEST_DB;
assert.equal(fixture.username, 'agent-ui-test', 'Refuse non-fixture credentials');
assert(dbFile, 'WALLOS_FEATURE_TEST_DB must point at the disposable fixture DB');
function database(statements) {
  return JSON.parse(execFileSync('python3', ['-c', `
import json,sqlite3,sys
db=sqlite3.connect(sys.argv[1],timeout=15);db.row_factory=sqlite3.Row
assert db.execute('SELECT username FROM user WHERE id=1').fetchone()[0]=='agent-ui-test','Refuse non-fixture database'
assert db.execute('SELECT username FROM user WHERE id=2').fetchone()[0]=='upstream-test-2','Require two disposable accounts'
results=[]
for item in json.loads(sys.argv[2]):
 c=db.execute(item['sql'],item.get('params',[]))
 results.append([dict(row) for row in c.fetchall()] if c.description else c.lastrowid)
db.commit();db.close();print(json.dumps(results))
`, dbFile, JSON.stringify(statements)], {encoding:'utf8'}));
}
const sql = (query, params=[]) => ({sql:query,params});
let saved, created, context;
let checks = 0;
const check = (ok,label) => { assert(ok,label);checks++;console.log('[OK]',label); };
const readNames = () => database([sql('SELECT id,name,"order" FROM categories WHERE user_id=1 ORDER BY id')])[0];
const browser = await chromium.launch({headless:true});
try {
  saved = database([
    sql('SELECT * FROM ai_settings WHERE user_id=1'),
    sql('SELECT id,name,"order" FROM categories WHERE user_id=1 ORDER BY id'),
    sql('SELECT local_webhook_notifications_allowlist,allow_standard_users_local_webhooks FROM admin LIMIT 1'),
    sql('SELECT screenshot_privacy_mode FROM settings WHERE user_id=1'),
    sql('SELECT language FROM user WHERE id=1'),
  ]);
  const privateName='CATEGORY-PRIVATE-'+crypto.randomUUID();
  const allowlist=[saved[2][0].local_webhook_notifications_allowlist,'127.0.0.1:18192'].filter(Boolean).join(',');
  const inserted=database([
    sql('UPDATE admin SET local_webhook_notifications_allowlist=?,allow_standard_users_local_webhooks=0',[allowlist]),
    sql('UPDATE settings SET screenshot_privacy_mode=0 WHERE user_id=1'),
    sql("UPDATE user SET language='zh_cn' WHERE id=1"),
    sql('INSERT INTO categories(name,user_id) VALUES(?,1)',[privateName]),
  ]);
  created=inserted[3];
  context=await browser.newContext({serviceWorkers:'block'});
  await context.addCookies([{name:'PHPSESSID',value:fixture.sessions['1'].id,url:base}]);
  const page=await context.newPage();
  const errors=[];
  page.on('pageerror',error=>errors.push(error.message));
  const csrf=fixture.sessions['1'].csrf;
  const api=async(path,data)=>{
    const response=await context.request.post(base+path,{headers:{'X-CSRF-Token':csrf},data});
    return {status:response.status(),body:await response.json()};
  };
  const configured=await api('/endpoints/ai/save_settings.php',{
    ai_enabled:true,ai_type:'openai-compatible',api_key:'fixture-browser-category-key',
    ollama_host:'http://127.0.0.1:18192/v1',model:'literal',ai_run_schedule:'manual',
  });
  assert(configured.body.success,JSON.stringify(configured.body));
  const before=readNames();
  await page.goto(base+'/settings.php#settings-categories',{waitUntil:'networkidle'});
  const start=page.locator('#ai-category-translate');
  const panel=page.locator('#ai-category-preview');
  const apply=page.locator('#ai-category-apply');
  const inputs=page.locator('#categories input[name="category"]');
  check(await start.isVisible()&&await start.isEnabled(),'AI translation entry is visible and clickable');
  check(await panel.isHidden()&&await inputs.count()===before.filter(row=>row.id!==1).length,'preview starts hidden and existing categories remain editable');
  await start.click();
  await panel.waitFor({state:'visible'});
  check(await page.locator('#ai-category-preview-rows tr').count()===await inputs.count(),'preview displays every owned category and proposed name');
  check(JSON.stringify(readNames())===JSON.stringify(before),'generating a preview performs no category writes');
  check(await page.locator('#ai-category-preview img').count()===0&&!(await page.evaluate(()=>window.aiCategoryInjected)),'HTML-looking model output remains literal text and executes no handler');
  check((await page.locator('#ai-category-preview-rows').innerText()).includes('<img src=x'),'preview renders HTML-looking labels as visible text');
  check(await apply.isVisible()&&await apply.isEnabled(),'explicit apply control is visible and enabled after preview');
  await page.locator('#ai-category-cancel').click();
  check(await panel.isHidden()&&JSON.stringify(readNames())===JSON.stringify(before),'cancel dismisses preview without writing');
  await start.click();
  await panel.waitFor({state:'visible'});
  const appliedResponse=page.waitForResponse(response=>response.url().endsWith('/endpoints/ai/translate_categories.php')&&response.request().postDataJSON().action==='apply');
  await apply.focus();
  await page.keyboard.press('Enter');
  assert.equal((await appliedResponse).status(),200);
  await panel.waitFor({state:'hidden'});
  const after=readNames();
  check(JSON.stringify(after)!==JSON.stringify(before),'keyboard confirmation applies translated category names');
  check((await inputs.first().inputValue()).startsWith('<img src=x')&&!(await page.evaluate(()=>window.aiCategoryInjected)),'applied label displays literal HTML-looking text in the existing input');
  await start.click();
  await panel.waitFor({state:'visible'});
  await inputs.first().fill('UNSAVED BROWSER EDIT');
  await apply.click();
  check(await panel.isHidden()&&JSON.stringify(readNames())===JSON.stringify(after),'unsaved page edits block apply and preserve saved names');
  await page.reload({waitUntil:'networkidle'});
  await start.click();
  await panel.waitFor({state:'visible'});
  database([sql('UPDATE categories SET name=? WHERE id=? AND user_id=1',[privateName,created])]);
  const conflictResponse=page.waitForResponse(response=>response.url().endsWith('/endpoints/ai/translate_categories.php')&&response.request().postDataJSON().action==='apply');
  await apply.click();
  assert.equal((await conflictResponse).status(),409);
  await panel.waitFor({state:'hidden'});
  check(readNames().find(row=>row.id===created).name===privateName,'concurrent category changes survive rejected browser apply');
  await page.reload({waitUntil:'networkidle'});
  await start.click();
  await panel.waitFor({state:'visible'});
  await page.evaluate(()=>window.WallosScreenshotPrivacy.setEnabled(true));
  check(await panel.isHidden()&&await inputs.count()===0,'enabling screenshot privacy immediately clears preview and real category inputs');
  const privacy=await api('/endpoints/settings/screenshot_privacy_mode.php',{value:true});
  assert(privacy.body.success);
  await page.reload({waitUntil:'networkidle'});
  const privateHtml=await page.content();
  check(!privateHtml.includes(privateName)&&!privateHtml.includes('window.aiCategoryInjected'),'screenshot-mode page HTML contains no real category or model label text');
  check(await start.isDisabled()&&await inputs.count()===0,'screenshot-mode controls visibly block category translation');
  const blocked=await api('/endpoints/ai/translate_categories.php',{action:'preview'});
  check(blocked.status===403&&blocked.body.code==='screenshot_privacy_mode_blocked','browser cannot retrieve real category labels through translation in screenshot mode');
  check(errors.length===0,'no browser runtime errors during preview/apply/cancel/privacy flows');
} finally {
  await browser.close();
  if(saved){
    const restore=[sql('DELETE FROM ai_settings WHERE user_id=1')];
    for(const row of saved[0]){
      const keys=Object.keys(row);
      restore.push(sql('INSERT INTO ai_settings('+keys.join(',')+') VALUES('+keys.map(()=>'?').join(',')+')',Object.values(row)));
    }
    for(const row of saved[1])restore.push(sql('UPDATE categories SET name=?,"order"=? WHERE id=? AND user_id=1',[row.name,row.order,row.id]));
    if(created)restore.push(sql('DELETE FROM categories WHERE id=? AND user_id=1',[created]));
    restore.push(sql('UPDATE admin SET local_webhook_notifications_allowlist=?,allow_standard_users_local_webhooks=?',[saved[2][0].local_webhook_notifications_allowlist,saved[2][0].allow_standard_users_local_webhooks]));
    restore.push(sql('UPDATE settings SET screenshot_privacy_mode=? WHERE user_id=1',[saved[3][0].screenshot_privacy_mode]));
    restore.push(sql('UPDATE user SET language=? WHERE id=1',[saved[4][0].language]));
    database(restore);
  }
}
console.log(`PASS: ${checks} real category translation browser checks.`);
