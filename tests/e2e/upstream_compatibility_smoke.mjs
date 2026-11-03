#!/usr/bin/env node
// Uses only the explicitly seeded disposable upstream fixture. Never production credentials.
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import {chromium} from 'playwright';
const base = process.env.WALLOS_UPSTREAM_TEST_URL || 'http://127.0.0.1:18284';
const fixture = JSON.parse(await fs.readFile(process.env.WALLOS_UPSTREAM_FIXTURE_FILE,'utf8'));
const key = JSON.parse(await fs.readFile(process.env.WALLOS_AGENT_TEST_KEY_FILE,'utf8')).token;
const browser = await chromium.launch({headless:true});
let checks=0;
const check=(ok,label)=>{assert(ok,label);checks++;console.log('[OK]',label);};
try {
  const context = await browser.newContext({serviceWorkers:'block'});
  await context.addCookies([{name:'PHPSESSID',value:fixture.sessions['1'].id,url:base}]);
  const tool=async(name,args={})=>{
    const r=await context.request.post(base+'/api/agent/v1/index.php',{headers:{Authorization:'Bearer '+key},data:{tool:name,arguments:args}});
    assert.equal(r.status(),200,name); const body=await r.json(); assert(body.success,name);return body.result;
  };
  assert.equal((await tool('wallos_context')).account.username,'agent-ui-test','Refusing mutations outside disposable fixture');
  const request_id=()=>`upstream-browser-${crypto.randomUUID()}`;
  const payload='</script><script>window.__xss=1</script><img src=x onerror=window.__xss=2> & "';
  const refs={};
  for(const kind of ['categories','household','payment_methods']) refs[kind]=(await tool('wallos_references_save',{kind,data:{name:payload},request_id:request_id()})).reference.id;
  const today=new Date().toLocaleDateString('en-CA',{timeZone:'Asia/Shanghai'});
  const subscription=(await tool('wallos_subscriptions_create',{data:{name:payload,price:19.91,currency_id:100,start_date:today,next_payment:today,cycle:3,frequency:1,category_id:refs.categories,payer_user_id:refs.household,payment_method_id:refs.payment_methods,auto_renew:true},request_id:request_id()})).subscription;
  await tool('wallos_price_rules_set',{id:subscription.id,expected_version:subscription.version,rules:[{rule_type:'first_n_cycles',price:7.23,currency_id:101,max_cycles:1}],request_id:request_id()});
  const page=await context.newPage(); const errors=[];page.on('pageerror',e=>errors.push(e.message));
  for(const path of ['subscriptions.php','stats.php']) {
    await page.goto(`${base}/${path}`,{waitUntil:'networkidle'});
    check(await page.evaluate(()=>typeof window.__xss==='undefined'),path+' does not execute stored HTML/script labels');
    check(await page.locator('img[src="x"]').count()===0,path+' renders foreign markup as text');
    for(const selector of ['[data-categoryid]','[data-memberid]','[data-paymentid]']) {
      const labels=await page.locator(selector).allTextContents();
      if(selector==='[data-paymentid]' && labels.length===0) continue; // Statistics has no payment filter.
      check(labels.some(t=>t.trim()===payload),path+' preserves '+selector+' text without double encoding');
    }
  }
  await page.goto(base+'/calendar.php',{waitUntil:'networkidle'});
  const tile=page.locator(`[data-subscription-id="${subscription.id}"]`);
  check(await tile.count()>0,'custom calendar renders the new MCP subscription');
  check((await tile.first().locator('.calendar-subscription-price').textContent()).includes('7.23'),'calendar uses promotional price in its effective USD currency');
  check(await tile.first().locator('.calendar-subscription-countdown').count()===1,'custom renewal countdown remains present');
  check((await tile.first().locator('.calendar-subscription-renewal-type').textContent()).trim().length>0,'calendar displays automatic renewal mode');
  check(await page.evaluate(()=>typeof window.__xss==='undefined'),'calendar names remain inert');
  await page.goto(base+'/settings.php#settings-display',{waitUntil:'networkidle'});
  const toggle=page.locator('#screenshotprivacymode');
  assert.equal(await toggle.isChecked(),false,'fixture must start outside privacy mode');
  await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),toggle.click({force:true})]);
  await page.goto(base+'/calendar.php',{waitUntil:'networkidle'});
  check(!(await page.locator('.calendar-subscription-name').allTextContents()).some(t=>t.includes(payload)),'privacy mode masks the new calendar name');
  check(!(await page.locator('.calendar-subscription-price').allTextContents()).some(t=>t.includes('7.23')),'privacy mode masks the effective promotional amount');
  await page.goto(base+'/settings.php#settings-display',{waitUntil:'networkidle'});
  await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),page.locator('#screenshotprivacymode').click({force:true})]);
  check(errors.length===0,'no browser exceptions in protected render paths: '+errors.join('; '));
  console.log(`PASS: ${checks} upstream browser XSS/calendar checks.`);
} finally {await browser.close();}
