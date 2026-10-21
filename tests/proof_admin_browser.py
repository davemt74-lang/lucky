"""Per-prize camera setup in the new admin prize-edit modal."""
import asyncio
from playwright.async_api import async_playwright
from v5q25_admin_modals_browser import HTML
CAMERAS = """<script>
Object.defineProperty(navigator,'mediaDevices',{configurable:true,value:{
 getUserMedia:async()=>new MediaStream(),
 enumerateDevices:async()=>[{kind:'videoinput',deviceId:'front',label:'USB Front Cam'},{kind:'videoinput',deviceId:'back',label:'USB Rear Cam'}]
}});
</script>"""
async def main():
 out=[];errors=[]
 def ck(name,value):out.append((name,bool(value)))
 async with async_playwright() as pw:
  browser=await pw.chromium.launch(headless=True,args=['--no-sandbox'])
  page=await browser.new_page(viewport={'width':1366,'height':900})
  page.on('pageerror',lambda e:errors.append(str(e)))
  await page.set_content(HTML.replace('</head>',CAMERAS+'</head>',1),wait_until='domcontentloaded')
  await page.locator('.prize-catalog-card').first.wait_for(timeout=6000)
  ck('Proof settings hidden until prize editor opens',await page.locator('#prize-edit-angles').is_hidden())
  await page.locator('.prize-catalog-edit').first.click()
  ck('Prize edit modal opens',await page.locator('#prize-editor-modal').is_visible())
  await page.locator('#prize-edit-detect-cameras').click()
  await page.locator('.prize-edit-angle').nth(1).wait_for(timeout=4000)
  ck('Two camera angles detected',await page.locator('.prize-edit-angle').count()==2)
  ck('Camera names populate labels','USB Front Cam' in await page.locator('.prize-edit-angle input').first.input_value())
  await page.locator('.prize-edit-angle input').first.fill('Prize Hero Closeup')
  await page.locator('.prize-edit-angle input[type=number]').first.fill('11')
  await page.locator('.prize-edit-angle input[type=number]').first.press('Tab')
  await page.locator('#prize-edit-save').click()
  await page.wait_for_function("document.querySelector('#prize-editor-modal').open===false",timeout=5000)
  ck('Prize angle saved',await page.evaluate("__package.config.pools.tier1.prizes[0].proofAngles[0].label==='Prize Hero Closeup'"))
  ck('Per-angle recording time saved',await page.evaluate("__package.config.pools.tier1.prizes[0].proofAngles[0].seconds===11"))
  ck('No runtime errors',not errors)
  await browser.close()
 for name,ok in out:print(('PASS ' if ok else 'FAIL ')+name,flush=True)
 print('Proof admin:',sum(ok for _,ok in out),'/',len(out))
 if errors:print('Errors:',errors)
 if not all(ok for _,ok in out):raise SystemExit(1)
if __name__=='__main__':asyncio.run(main())
