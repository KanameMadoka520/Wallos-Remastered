#!/usr/bin/env node
// Real multi-assignment privacy rendering on a disposable account only.
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import { chromium } from 'playwright';
const base=process.env.WALLOS_UPSTREAM_TEST_URL || 'http://127.0.0.1:18284';
const fixture=JSON.parse(await fs.readFile(process.env.WALLOS_UPSTREAM_FIXTURE_FILE,'utf8'));
const key=JSON.parse(await fs.readFile(process.env.WALLOS_AGENT_TEST_KEY_FILE,'utf8')).token;
const browser=await chromium.launch({headless:true});
let checks=0, originalPrivacy, csrf, created, context;
const references=[];
const check=(ok,label)=>{assert(ok,label);checks++;console.log('[OK]',label);};
const request_id=()=>`relation-privacy-${crypto.randomUUID()}`;
let tool;
try {
 context=await browser.newContext({serviceWorkers:'block'});
 await context.addCookies([{name:'PHPSESSID',value:fixture.sessions['1'].id,url:base}]);
 tool=async(name,args={})=>{
  const response=await context.request.post(base+'/api/agent/v1/index.php',{headers:{Authorization:'Bearer '+key},data:{tool:name,arguments:args}});
  const body=await response.json();assert.equal(response.status(),200,name+': '+JSON.stringify(body));assert(body.success);return body.result;
 };
 assert.equal((await tool('wallos_context')).account.username,'agent-ui-test','Refusing writes outside disposable fixture');
 const page=await context.newPage();const errors=[];page.on('pageerror',error=>errors.push(error.message));
 await page.goto(base+'/settings.php#settings-display',{waitUntil:'networkidle'});
 originalPrivacy=await page.locator('#screenshotprivacymode').isChecked();csrf=await page.evaluate(()=>window.csrfToken);
 const suffix=crypto.randomUUID();
 for(const kind of ['categories','household'])for(let n=0;n<2;n++){
  const reference=(await tool('wallos_references_save',{kind,data:{name:`PRIVATE-RELATION-${kind}-${n}-${suffix}`},request_id:request_id()})).reference;
  references.push({kind,...reference});
 }
 const categories=references.filter(row=>row.kind==='categories'),payers=references.filter(row=>row.kind==='household');
 created=(await tool('wallos_subscriptions_create',{data:{name:`PRIVATE-SUBSCRIPTION-${suffix}`,price:120,currency_id:100,start_date:'2026-11-01',next_payment:'2026-12-01',cycle:3,frequency:1,category_ids:categories.map(row=>row.id),payer_ids:payers.map(row=>row.id),payment_method_id:100},request_id:request_id()})).subscription;
 const secrets=[created.name,...references.map(row=>row.name)];
 const privacy=await context.request.post(base+'/endpoints/settings/screenshot_privacy_mode.php',{headers:{'X-CSRF-Token':csrf},data:{value:true}});
 assert((await privacy.json()).success);
 await page.goto(base+`/subscriptions.php?category=${categories[1].id}`,{waitUntil:'networkidle'});
 const card=page.locator(`.subscription[data-id="${created.id}"]`);
 check(await card.count()===1,'secondary category still filters correctly under screenshot privacy');
 const html=await page.content();check(secrets.every(value=>!html.includes(value)),'full page HTML omits real primary and secondary relation names');
 const labels=await card.locator('.payer_user,.category').allTextContents();
 check(labels.length===2&&labels.every(label=>label.trim().length>0),'multiple relation labels remain visible as demo text');
 check(await page.locator('#subscription-bulk-toolbar').count()===0,'bulk toolbar and real reference options are omitted');
 await page.goto(base+'/index.php',{waitUntil:'networkidle'});
 await page.evaluate(id=>window.showSubscriptionDetails(null,id),created.id);
 await page.waitForFunction(()=>document.getElementById('details-category')?.textContent?.trim());
 const popup=await page.locator('#subscription-details').innerText();
 check(secrets.every(value=>!popup.includes(value)),'details popup masks primary and secondary names');
 const detail=await context.request.get(base+`/endpoints/subscription/get.php?id=${created.id}&purpose=display`);
 const detailText=await detail.text();
 check(detail.status()===200&&secrets.every(value=>!detailText.includes(value)),'display-purpose endpoint masks subscription name and contains no raw relation labels');
 const fragment=await context.request.get(base+`/endpoints/subscriptions/get.php?format=json&categories=${categories[1].id}`);
 check(fragment.status()===200,'AJAX fragment accepts secondary category');
 const fragmentText=await fragment.text();check(secrets.every(value=>!fragmentText.includes(value)),'AJAX pagination HTML masks full relation labels');
 const inspect=await context.request.post(base+'/endpoints/subscriptions/bulkedit.php',{headers:{'X-CSRF-Token':csrf},data:{action:'inspect',ids:[created.id]}});
 check(inspect.status()===403&&(await inspect.json()).code==='screenshot_privacy_mode_blocked','bulk inspect cannot reveal raw names in screenshot mode');
 check(errors.length===0,'no browser exceptions during privacy rendering');
} finally {
 if(context&&csrf&&originalPrivacy!==undefined){
  const response=await context.request.post(base+'/endpoints/settings/screenshot_privacy_mode.php',{headers:{'X-CSRF-Token':csrf},data:{value:originalPrivacy}});
  check((await response.json()).success,'original privacy setting restored');
 }
 if(created&&tool){
  const current=(await tool('wallos_subscriptions_get',{id:created.id})).subscription;
  if(current.lifecycle_status==='active')await tool('wallos_subscriptions_trash',{id:created.id,expected_version:current.version,confirm_id:created.id,request_id:request_id()});
  const deleted=await context.request.post(base+'/endpoints/subscription/permanentdelete.php',{headers:{'X-CSRF-Token':csrf},data:{id:created.id}});assert((await deleted.json()).success);
 }
 if(tool)for(const reference of references){await tool('wallos_references_delete',{kind:reference.kind,id:reference.id,expected_version:reference.version,confirm_id:reference.id,request_id:request_id()});}
 await browser.close();
}
console.log(`PASS: ${checks} multi-relation privacy browser checks.`);
