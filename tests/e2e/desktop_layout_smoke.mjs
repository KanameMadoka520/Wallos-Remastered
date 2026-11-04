#!/usr/bin/env node
// Desktop visual geometry; authenticated pages only, no business writes.
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import {chromium} from 'playwright';
const base=(process.env.WALLOS_BASE_URL||'http://127.0.0.1:18284').replace(/\/$/,'');
const browser=await chromium.launch({headless:true});
const context=await browser.newContext({serviceWorkers:'block',reducedMotion:'reduce'});
if(process.env.WALLOS_UI_SESSION_FILE){
 const auth=JSON.parse(await fs.readFile(process.env.WALLOS_UI_SESSION_FILE,'utf8'));
 await context.addCookies([{name:'PHPSESSID',value:auth.session,url:base}]);
} else {
 assert(process.env.WALLOS_TEST_USERNAME&&process.env.WALLOS_TEST_PASSWORD,'Set a dedicated UI session file or test login.');
 const login=await context.request.post(base+'/login.php',{form:{username:process.env.WALLOS_TEST_USERNAME,password:process.env.WALLOS_TEST_PASSWORD}});
 assert(login.ok(),'Fixture login failed');
}
const page=await context.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
let checks=0;const check=(value,label)=>{assert(value,label);checks++;console.log('[OK]',label);};
async function navigate(path){await page.goto(base+path,{waitUntil:'networkidle'});await page.waitForTimeout(400);assert(!page.url().includes('login.php'),'Authenticated session required');}
const viewports=[{width:1366,height:768},{width:1440,height:900},{width:1920,height:1080}];
try{
 for(const viewport of viewports){
  await page.setViewportSize(viewport);
  await navigate('/stats.php');
  const metrics=await page.locator('.statistic').evaluateAll(cards=>cards.filter(card=>card.querySelector('span')).map(card=>{
   const value=card.querySelector(':scope > span');if(!value)return null;
   const number=value.getBoundingClientRect(),bounds=card.getBoundingClientRect(),button=card.querySelector('.metric-explanation-trigger')?.getBoundingClientRect();
   const text=document.createRange();text.selectNodeContents(value);const ink=text.getBoundingClientRect();
   return {fits:ink.left>=bounds.left+5&&ink.right<=bounds.right-5,clear:!button||number.bottom<=button.top+1||number.top>=button.bottom||number.right<=button.left||number.left>=button.right};
  }).filter(Boolean));
  check(metrics.length>0&&metrics.every(m=>m.fits),`${viewport.width}: complete metric values stay inside cards`);
  check(metrics.every(m=>m.clear),`${viewport.width}: metric help controls do not overlap values`);
  await page.locator('.statistic > span').first().evaluate(el=>{el.textContent='CN¥999,999,999.99';});
  check(await page.locator('.statistic').first().evaluate(card=>{const range=document.createRange();range.selectNodeContents(card.querySelector(':scope > span'));const r=range.getBoundingClientRect(),c=card.getBoundingClientRect();return r.left>=c.left+4&&r.right<=c.right-4;}),`${viewport.width}: large currency values remain readable`);
  await page.locator('.metric-explanation-trigger').first().click();
  check(await page.locator('#metric-explanation-modal').evaluate(el=>{const r=el.getBoundingClientRect();return r.width>600&&r.left>=0&&r.right<=innerWidth;}),`${viewport.width}: metric details retain the dedicated wide layout`);
  await page.keyboard.press('Escape');
  await page.evaluate(()=>openMetricExplanationModal({items:[{name:'AT&T <img src=x onerror=alert(1)>',monthly_equivalent:12}],currency_code:'USD'}));
  check(await page.locator('#metric-explanation-items').evaluate(el=>el.textContent.includes('AT&T <img')&&el.querySelector('img')===null),`${viewport.width}: decoded metric labels remain text and cannot inject markup`);
  await page.keyboard.press('Escape');
  await page.locator('[data-page-nav-link="stats-graphs"]').click();
  await page.waitForFunction(()=>document.querySelector('[data-page-nav-link="stats-graphs"]')?.getAttribute('aria-current')==='location');
  check(true,`${viewport.width}: chart section navigation follows the reading position`);
  await navigate('/calendar.php');
  const tiles=page.locator('.calendar-subscription-title');
  check(await tiles.count()>0,`${viewport.width}: calendar fixture contains subscription tiles`);
  await tiles.first().locator('.calendar-subscription-name').evaluate(el=>{el.textContent='A long subscription title repeated for desktop layout '.repeat(12);});
  check(await tiles.first().locator('.calendar-subscription-name').evaluate(el=>el.getBoundingClientRect().height<=parseFloat(getComputedStyle(el).lineHeight)*2+2),`${viewport.width}: long calendar names occupy at most two lines`);
  check(await tiles.first().evaluate(el=>!!el.title&&el.getAttribute('role')==='button'&&el.tabIndex===0),`${viewport.width}: compact tiles retain full labels and keyboard access`);
  const metadata=await tiles.first().locator('.calendar-subscription-meta').evaluate(el=>{
   const style=getComputedStyle(el),bounds=el.getBoundingClientRect();
   return style.display==='flex'&&bounds.height<=(parseFloat(style.lineHeight)||parseFloat(style.fontSize)*1.4)*2+3&&el.scrollWidth<=el.clientWidth+1;
  });
  check(metadata,`${viewport.width}: calendar price and renewal mode wrap compactly for the account language`);
  check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),`${viewport.width}: calendar has no page width overflow`);
  await tiles.first().focus();await page.keyboard.press('Enter');
  await page.locator('#subscriptionModal.is-open .close-modal').waitFor();
  check(await page.locator('#subscriptionModal .modal-header').evaluate(el=>{
   const name=el.querySelector('h3');name.textContent='A long service name with a complete billing description '.repeat(4);
   const close=el.querySelector('.close-modal').getBoundingClientRect(), bounds=el.getBoundingClientRect();
   const text=document.createRange();text.selectNodeContents(name);const ink=text.getBoundingClientRect();
   return close.right<=bounds.right+6&&ink.right<=close.left+1&&el.querySelector('button[aria-label]')!==null;
  }),`${viewport.width}: long calendar detail headings keep the close button clear`);
  await page.keyboard.press('Escape');
  check(!await page.locator('#subscriptionModal').evaluate(el=>el.classList.contains('is-open'))&&await tiles.first().evaluate(el=>document.activeElement===el),`${viewport.width}: Escape closes calendar details and returns keyboard focus`);
  await navigate('/settings.php#settings-categories');
  await page.waitForFunction(()=>document.querySelector('[data-page-nav-link="settings-categories"]')?.getAttribute('aria-current')==='location');
  check(true,`${viewport.width}: category navigation stays current after a long notification section`);
  await page.locator('[data-page-nav-link="settings-payments"]').click();
  await page.waitForFunction(()=>document.querySelector('[data-page-nav-link="settings-payments"]')?.getAttribute('aria-current')==='location');
  check(true,`${viewport.width}: payment navigation matches the visible section`);
  await page.locator('[data-page-nav-link="settings-experimental"]').click();
  await page.waitForFunction(()=>document.querySelector('[data-page-nav-link="settings-experimental"]')?.getAttribute('aria-current')==='location');
  check(await page.locator('[data-page-nav-link="settings-experimental"]').evaluate(el=>{const e=el.getBoundingClientRect(),n=el.closest('.page-nav').getBoundingClientRect();return e.top>=n.top-1&&e.bottom<=n.bottom+1;}),`${viewport.width}: active navigation remains reachable inside the sidebar`);
  await navigate('/admin.php#admin-backup');
  const backup=page.locator('[data-page-nav-link="admin-backup"]');
  if(await backup.count()){
   await backup.click();await page.waitForTimeout(700);
   check(await backup.evaluate(el=>{const r=el.getBoundingClientRect(),nav=el.closest('.page-nav').getBoundingClientRect();return el.getAttribute('aria-current')==='location'&&r.top>=nav.top-1&&r.bottom<=nav.bottom+1&&nav.bottom<=innerHeight;}),`${viewport.width}: final admin sidebar entry stays inside the screen`);
  }
 }
 check(errors.length===0,'No browser exceptions in desktop statistics/calendar/navigation');
 console.log(`PASS: ${checks} desktop layout and navigation checks.`);
}catch(error){
 if(process.env.WALLOS_E2E_ARTIFACT_DIR){await fs.mkdir(process.env.WALLOS_E2E_ARTIFACT_DIR,{recursive:true});await page.screenshot({path:process.env.WALLOS_E2E_ARTIFACT_DIR+'/desktop-layout-failed.png'}).catch(()=>{});}
 throw error;
}finally{await browser.close();}
