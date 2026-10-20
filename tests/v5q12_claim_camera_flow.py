"""Regression for the real failed flow: host ledger -> claim-scoped proof -> live cameras -> saved photo."""
import asyncio
from pathlib import Path
from playwright.async_api import async_playwright
import v5q6_proof_browser as f
ROOT=Path(__file__).resolve().parents[1]
SID=f.SID;CID=f.CID
DEVICE_MOCK=r'''
window.__mediaUploads=[];
Object.defineProperty(navigator,'mediaDevices',{configurable:true,value:{
 getUserMedia:async()=>new MediaStream(),
 enumerateDevices:async()=>[
 {kind:'videoinput',deviceId:'front-usb',label:'USB Front Camera'},
 {kind:'videoinput',deviceId:'side-usb',label:'USB Side Camera'}],addEventListener:()=>{} }});
HTMLMediaElement.prototype.play=function(){return Promise.resolve();};
Object.defineProperty(HTMLVideoElement.prototype,'videoWidth',{get:()=>640});
Object.defineProperty(HTMLVideoElement.prototype,'videoHeight',{get:()=>360});
HTMLCanvasElement.prototype.getContext=function(){return {drawImage(){}}};
HTMLCanvasElement.prototype.toBlob=function(cb){cb(new Blob(['image-bytes'],{type:'image/jpeg'}));};
const __originalFetch=fetch;
window.fetch=async(u,o={})=>{
 if(String(u).includes('claim-media.php?action=upload')){
  const form=o.body;const details={session:form.get('session'),claim:form.get('claim'),kind:form.get('kind')};
  window.__mediaUploads.push(details);
  return new Response(JSON.stringify({ok:true,clip:{id:'e'.repeat(32),claimId:details.claim,title:'USB Front Camera',kind:'photo'}}),{status:201});
 }
 if(String(u).includes('claim-media.php?action=list'))return new Response(JSON.stringify({ok:true,clips:[]}));
 return __originalFetch(u,o);
};
'''

async def run():
 checks=[]; errors=[]
 def ck(name,value): checks.append((name,bool(value)))
 async with async_playwright() as pw:
  browser=await pw.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox'])
  page=await browser.new_page(viewport={'width':1280,'height':900})
  page.set_default_timeout(4500)
  page.on('pageerror',lambda e:errors.append(str(e)))
  # The actual public game view strips claim IDs; our existing tests did NOT reflect that.
  mock=f.MOCK.replace('claims:__claims,slots:', 'claims:__claims.map(({id,prizeId,...c})=>c),slots:')
  html=f.html_page('game.php',(ROOT/'public/game.js').read_text(),mock)
  await page.set_content(html,wait_until='domcontentloaded')
  await page.wait_for_function("document.querySelector('#claim-list a[href*=proof]')!==null")
  href=await page.locator('#claim-list a[href*=proof]').first.get_attribute('href')
  ck('host uses authenticated claim ID, not undefined',f'claim={CID}' in href)
  ck('host links to exact game session',f'session={SID}' in href)
  # Follow exactly the generated link and run proof setup as in the browser.
  params=href.partition('?')[2]
  script=(ROOT/'public/proof.js').read_text().replace('new URL(location.href).searchParams',f"new URLSearchParams('{params}')")
  page2=await browser.new_page(viewport={'width':1280,'height':900})
  page2.set_default_timeout(4500)
  page2.on('pageerror',lambda e:errors.append(str(e)))
  content=f.html_page('proof.php',DEVICE_MOCK+(ROOT/'public/proof-sequence.js').read_text()+'\n'+(ROOT/'public/claim-capture.js').read_text()+'\n'+script,mock)
  await page2.set_content(content,wait_until='domcontentloaded')
  await page2.wait_for_function("document.querySelector('#proof-prize-title').textContent==='Camera Prize'")
  ck('clicked claim is loaded',await page2.locator('#proof-prize-title').inner_text()=='Camera Prize')
  ck('camera enable is actionable',await page2.locator('#camera-enable').is_enabled())
  ck('return targets originating game',await page2.locator('#proof-return').get_attribute('href')=='game.php?session='+SID)
  await page2.locator('#proof-camera-settings summary').click()
  await page2.locator('#camera-enable').click()
  await page2.wait_for_function("document.querySelectorAll('.camera-source video:not([hidden])').length===2")
  ck('two camera preview tiles show live',await page2.locator('.camera-source video:not([hidden])').count()==2)
  ck('previews attached to media streams',await page2.evaluate("[...document.querySelectorAll('.camera-source video')].every(v=>v.srcObject instanceof MediaStream)"))
  ck('preview requires no capture consent',not await page2.locator('#capture-consent').is_checked())
  await page2.locator('#capture-photo').click()
  ck('capture blocked before consent', 'consent' in (await page2.locator('#capture-feedback').inner_text()).lower())
  await page2.evaluate("LuckyCapture.phoneAction('proof-consent-on')")
  await page2.locator('#capture-photo').click()
  await page2.wait_for_function('window.__mediaUploads.length === 2')
  uploads=await page2.evaluate('window.__mediaUploads')
  ck('saved photos tied to clicked claim', all(x['claim']==CID and x['session']==SID and x['kind']=='photo' for x in uploads))
  ck('no JS runtime errors',not errors)
  await browser.close()
 for name,good in checks:print(('PASS ' if good else 'FAIL ')+name,flush=True)
 if errors:print('JS exceptions:',errors)
 print(f'Claim proof camera end-to-end: {sum(g for _,g in checks)}/{len(checks)}')
 if not all(g for _,g in checks):raise SystemExit(1)
if __name__=='__main__':asyncio.run(run())
