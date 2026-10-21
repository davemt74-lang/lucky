"""Camera management UI test against actual admin markup and scripts."""
import asyncio,re
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[1]
ADMIN=(ROOT/'admin.php').read_text()
CAMERA_SECTION=ADMIN.split('<section id="tab-cameras"',1)[1].split('<section id="tab-intro"',1)[0]
BODY='<nav><button id="open-camera-admin" type="button">Cameras</button></nav><section id="tab-cameras"'+CAMERA_SECTION
SCRIPTS=''.join('<script>'+ (ROOT/'public'/p).read_text().replace('</script','<\\/script')+'</script>' for p in ('camera-profiles.js','camera-admin.js'))
MOCK="""<script>
window.__calls=0;window.__stopped=0;
Object.defineProperty(navigator,'mediaDevices',{configurable:true,value:{
 getUserMedia:async()=>{window.__calls++;const stream=new MediaStream();stream.getTracks=()=>[{stop:()=>window.__stopped++}];return stream;},
 enumerateDevices:async()=>[...['Front USB','Side USB','Overhead','Wide Room','Spare'].map((label,i)=>({kind:'videoinput',deviceId:'device'+i,label}))]
}});
</script>"""
HTML='<html><head><base href="http://127.0.0.1/"></head><body>'+BODY+MOCK+SCRIPTS+'</body></html>'
async def main():
 checks=[];errors=[]
 def ck(s,v):checks.append((s,bool(v)))
 async with async_playwright() as playwright:
  browser=await playwright.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox'])
  page=await browser.new_page(viewport={'width':1200,'height':840})
  page.on('pageerror',lambda e:errors.append(str(e)))
  await page.set_content(HTML,wait_until='domcontentloaded');await page.evaluate("""() => {document.querySelector('#tab-cameras').hidden=false;Object.defineProperty(window,'localStorage',{configurable:true,value:{items:{},getItem(k){return this.items[k]??null},setItem(k,v){this.items[k]=v}}})}""")
  ck('Main admin includes its own Cameras section', 'data-tab="cameras"' in ADMIN and await page.locator('#tab-cameras').count()==1)
  ck('No camera access occurs before Detect is clicked',await page.evaluate('window.__calls')==0)
  await page.locator('#global-camera-detect').click(timeout=4000)
  await page.wait_for_function("document.querySelectorAll('.global-camera-card').length===5",timeout=5000)
  ck('All five detected cameras get individual admin cards',await page.locator('.global-camera-card').count()==5)
  ck('Every card has name, selection and preview control',await page.locator('.global-camera-card input[type=checkbox]').count()==5 and await page.locator('.global-camera-card [data-preview]').count()==5 and await page.locator('.global-camera-card input[type=text]').count()==5)
  ck('Detection releases browser permission probe',await page.evaluate('window.__stopped')>=1)
  ck('No camera previews opened automatically by Admin',await page.evaluate('window.__calls')==1)
  for id in ('device0','device1'):
   await page.locator('.global-camera-card[data-camera-id="'+id+'"] [data-shared-camera]').check()
  await page.locator('.global-camera-card[data-camera-id="device1"] input[type=text]').fill('Side Door')
  await page.locator('.global-camera-card[data-camera-id="device1"] input[type=text]').dispatch_event('change')
  await page.locator('#global-camera-save').click()
  ck('Admin saves two defaults for every game on this origin',await page.evaluate("LuckyCameraProfiles.shared().join(',')==='device0,device1'"))
  ck('Game profile inherits shared defaults',await page.evaluate("LuckyCameraProfiles.selected('spin-wheel').includes('device1') && LuckyCameraProfiles.selected('other-game').includes('device1')"))
  ck('Admin camera alias persists to game capture',await page.evaluate("LuckyCameraProfiles.getAliases().device1==='Side Door'"))
  await page.locator('.global-camera-card[data-camera-id="device0"] [data-preview]').click(timeout=4000)
  await page.wait_for_function("document.querySelector('.global-camera-card[data-camera-id=device0] [data-preview]').getAttribute('aria-pressed')==='true'",timeout=4000)
  ck('Admin can privately preview an individual camera',await page.locator('.global-camera-card[data-camera-id="device0"] [data-preview]').get_attribute('aria-pressed')=='true')
  await page.locator('.global-camera-card[data-camera-id="device0"] [data-preview]').click(timeout=4000)
  ck('Stopping preview releases video stream',await page.evaluate('window.__stopped')>=2)
  for id in ('device2','device3'):
   await page.locator('.global-camera-card[data-camera-id="'+id+'"] [data-shared-camera]').check()
  await page.locator('.global-camera-card[data-camera-id="device4"] [data-shared-camera]').click()
  ck('Attempt to select fifth camera is rejected',await page.locator('.global-camera-card [data-shared-camera]:checked').count()==4)
  ck('Save button does not require a live game session',await page.locator('#global-camera-save').is_enabled())
  ck('No JavaScript exceptions',not errors)
  await page.close()
  small=await browser.new_page(viewport={'width':375,'height':812})
  await small.set_content(HTML,wait_until='domcontentloaded');await small.evaluate("document.querySelector('#tab-cameras').hidden=false")
  ck('Camera Admin page fits small mobile screen',await small.evaluate('document.documentElement.scrollWidth<=innerWidth+2'))
  await browser.close()
 for s,v in checks:print(('PASS ' if v else 'FAIL ')+s,flush=True)
 print('V5Q25D Admin camera UI:',sum(v for _,v in checks),'/',len(checks),flush=True)
 if not all(v for _,v in checks):raise SystemExit(1)
if __name__=='__main__':asyncio.run(main())
