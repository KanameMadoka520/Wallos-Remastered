#!/usr/bin/env node
// Only disposable fixtures. Vendor delivery is deliberately not simulated as success.
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import vm from 'node:vm';
import { chromium } from 'playwright';
const base = process.env.WALLOS_UPSTREAM_TEST_URL || 'http://127.0.0.1:18284';
const fixture = JSON.parse(await fs.readFile(process.env.WALLOS_UPSTREAM_FIXTURE_FILE, 'utf8'));
const token = JSON.parse(await fs.readFile(process.env.WALLOS_AGENT_TEST_KEY_FILE, 'utf8')).token;
const browser = await chromium.launch({ headless: true });
let checks=0;
function check(ok,label){assert(ok,label);checks++;console.log('[OK]',label);}
try {
  const context=await browser.newContext();
  const ownLogin=await context.request.post(base+'/login.php',{form:{username:fixture.username,password:fixture.password}});
  assert(ownLogin.ok());
  const bootstrap=await context.newPage();await bootstrap.goto(base+'/settings.php',{waitUntil:'networkidle'});
  const ownCsrf=await bootstrap.evaluate(()=>window.csrfToken);await bootstrap.close();
  assert(ownCsrf,'own session CSRF available');
  const identity=await context.request.post(base+'/api/agent/v1/index.php',{headers:{Authorization:'Bearer '+token},data:{tool:'wallos_context',arguments:{}}});
  assert.equal((await identity.json()).result.account.username,'agent-ui-test','Refusing mutations outside disposable account');
  const endpoint=base+'/endpoints/notifications/webpush.php';
  const request=async(data,csrf=ownCsrf,ctx=context)=>ctx.request.post(endpoint,{headers:{'X-CSRF-Token':csrf},data});
  const status=async()=>{const r=await request({action:'status'});assert.equal(r.status(),200);return r.json();};
  const before=await status();
  for(const device of before.devices.filter(d=>d.device_name==='<img src=x onerror="window.__pushXss=1">'||d.device_name==='Fixture laptop')) {
    await request({action:'remove',id:Number(device.id)});
  }
  await request({action:'save',enabled:0,show_details:0});
  check(before.success && typeof before.public_key==='string' && before.public_key.length===87,'authenticated status exposes VAPID public key');
  check(!JSON.stringify(before).includes('private_key')&&!JSON.stringify(before).includes('device_hash'),'status omits all private key and device capability material');
  check((await request({action:'save',enabled:1,show_details:0},'invalid')).status()===400,'CSRF required for push configuration');
  const anonymous=await browser.newContext();
  check((await request({action:'status'},'',anonymous)).status()===401,'anonymous cannot read devices');
  check((await context.request.get(endpoint)).status()===405,'push mutations/status require POST');
  const forbidden={endpoint:'https://127.0.0.1/private',keys:{p256dh:'x',auth:'x'}};
  check((await request({action:'register',device_name:'Unsafe',subscription:forbidden})).status()===400,'browser endpoint blocks private/invalid subscription');
  const point='BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4';
  const subscription={endpoint:'https://fcm.googleapis.com/fcm/send/wallos-fixture-'+crypto.randomUUID(),keys:{p256dh:point,auth:'BTBZMqHH6r4Tts7J_aSIgg'}};
  const label='<img src=x onerror="window.__pushXss=1">';
  const registered=await request({action:'register',device_name:label,subscription});
  assert.equal(registered.status(),200,await registered.text());
  const added=await registered.json(); const device=added.devices.find(d=>d.device_name===label);
  check(device && device.current,'device registration binds current browser');
  const cookie=(await context.cookies()).find(c=>c.name==='wallos_push_device');
  check(cookie && cookie.httpOnly && cookie.sameSite==='Lax','device capability cookie HttpOnly and SameSite');
  const other=await browser.newContext();
  await other.addCookies([{name:'PHPSESSID',value:fixture.sessions['2'].id,url:base}]);
  check((await request({action:'remove',id:Number(device.id)},fixture.sessions['2'].csrf,other)).status()===404,'other account cannot revoke device');
  const otherStatus=await request({action:'status'},fixture.sessions['2'].csrf,other);
  check(!(await otherStatus.json()).devices.some(d=>d.id===device.id),'other account cannot list device');
  const page=await context.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.goto(base+'/settings.php#settings-notifications',{waitUntil:'networkidle'});
  await page.locator('#webpush-settings header').click();
  await page.locator('#webpush-devices li').filter({hasText:label}).waitFor();
  check(await page.locator('#webpush-enabled').isChecked(),'UI reflects channel enabled by device enrollment');
  check(!await page.locator('#webpush-details').isChecked(),'UI details default off');
  check(await page.locator('#webpush-subscribe').isVisible(),'browser can see device enrollment control');
  check(await page.locator('#webpush-devices img').count()===0 && await page.evaluate(()=>!window.__pushXss),'device name markup stays inert text');
  await page.locator('label[for="webpush-details"]').click();
  check(await page.locator('#webpush-details').isChecked(),'visible checkbox label toggles details with mouse');
  await page.locator('#webpush-save').click();
  await page.waitForFunction(()=>document.getElementById('webpush-details').checked);
  await page.waitForTimeout(150);
  check(Number((await status()).settings.show_details)===1,'visible UI saves explicit lock-screen details option');
  const row=page.locator('#webpush-devices li').filter({hasText:label});
  page.once('dialog',d=>d.accept('Fixture laptop'));
  await row.getByRole('button',{name:/Rename|重命名|重新命名/}).click();
  await page.locator('#webpush-devices li').filter({hasText:'Fixture laptop'}).waitFor();
  check((await status()).devices.some(d=>d.device_name==='Fixture laptop'),'visible rename persists through authenticated endpoint');
  check(errors.length===0,'no browser exceptions: '+errors.join(';'));
  await page.goto(base+'/logout.php',{waitUntil:'networkidle'});
  // A separate session for the same account can verify logout revoked the
  // enrollment rather than merely deleting a client cookie.
  const verifier=await browser.newContext();
  const login=await verifier.request.post(base+'/login.php',{form:{username:fixture.username,password:fixture.password}});
  assert(login.ok());
  const verifierPage=await verifier.newPage();await verifierPage.goto(base+'/settings.php',{waitUntil:'networkidle'});
  await verifierPage.waitForFunction(()=>document.getElementById('webpush-devices')?.textContent.trim());
  check(!(await verifierPage.locator('#webpush-devices').textContent()).includes('Fixture laptop'),'logout revokes server binding');
  // Restore account notification preferences through the normal CSRF-aware UI.
  await verifierPage.evaluate(async (settings)=>{
    const response=await window.WallosApi.postJson('endpoints/notifications/webpush.php',{action:'save',enabled:Number(settings.enabled),show_details:Number(settings.show_details)});
    if(!response.success)throw new Error('Restore failed');
  },before.settings);
  await anonymous.close();await other.close();await verifier.close();await context.close();
  // Evaluate worker event behavior against browser interfaces, without changing
  // its real fetch/cache policy or pretending to deliver a remote push.
  const listeners={};const notifications=[];const opened=[];
  const worker={location:{origin:'https://example.com'},registration:{scope:'https://example.com/wallos/',showNotification:async(title,options)=>notifications.push({title,options})},addEventListener:(type,fn)=>listeners[type]=fn,clients:{matchAll:async()=>[],openWindow:async url=>opened.push(url)}};
  vm.runInNewContext(await fs.readFile(new URL('../../service-worker.js',import.meta.url),'utf8'),{self:worker,URL,fetch:()=>{throw Error('Unexpected network');},caches:{}});
  let pending;listeners.push({data:{json:()=>({body:'Fixture reminder',url:'https://evil.example/',tag:'wallos-renewal-42'})},waitUntil:p=>pending=p});await pending;
  check(notifications[0].title==='Wallos'&&notifications[0].options.data.url==='https://example.com/wallos/index.php','push uses fixed same-installation destination');
  listeners.notificationclick({notification:{close(){}},waitUntil:p=>pending=p});await pending;
  check(opened[0]==='https://example.com/wallos/index.php','notification click enters authenticated index');
  check(typeof listeners.fetch==='function'&&typeof listeners.activate==='function','existing cache/privacy events remain installed');
  console.log(`PASS: ${checks} Web Push browser/API/worker checks. Real vendor delivery is not part of this fixture.`);
} finally { await browser.close(); }
