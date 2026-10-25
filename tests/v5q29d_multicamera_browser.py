"""Multiple approved phones may voluntarily arm before host-driven coordinated capture."""
import asyncio
from pathlib import Path
from playwright.async_api import async_playwright
import v5q28b_phone_capture_browser as fixture

def check(name,ok):
 print(('PASS ' if ok else 'FAIL ')+name,flush=True)
 if not ok:raise AssertionError(name)

async def main():
 async with async_playwright() as pw:
  browser=await pw.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox'])
  page=await browser.new_page(viewport={'width':375,'height':850})
  errors=[];page.on('pageerror',lambda exc:errors.append(str(exc)))
  await page.evaluate('''(() => {
   window.__starts=0;window.__stops=0;window.__tracksStopped=0;
   Object.defineProperty(navigator,'mediaDevices',{configurable:true,value:{
    getUserMedia:async()=>{const stream=new MediaStream();stream.getTracks=()=>[{stop:()=>__tracksStopped++}];return stream;}
   }});
   HTMLMediaElement.prototype.play=async()=>{};
   class FakeRecorder {
    static isTypeSupported=()=>true;
    constructor(stream,opts){this.state='inactive';this.mimeType=opts?.mimeType||'video/webm';}
    start(){this.state='recording';__starts++;}
    stop(){if(this.state!=='recording')return;this.state='inactive';__stops++;
     this.ondataavailable?.({data:new Blob(['a recorded phone take'],{type:'video/webm'})});
     this.onstop?.();
    }
   }
   window.MediaRecorder=FakeRecorder;
  })();''')
  await page.evaluate("""() => {const row=JSON.stringify({id:'c'.repeat(32),token:'d'.repeat(64)});const map=new Map([['lucky-phone-controller-v5p',row]]);Object.defineProperty(window,'sessionStorage',{configurable:true,value:{getItem:k=>map.get(k)||null,setItem:(k,v)=>map.set(k,v),removeItem:k=>map.delete(k)}});}""")
  await page.set_content(fixture.html,wait_until='domcontentloaded')
  await page.locator('#phone-proof-controls').wait_for(state='visible',timeout=5500)
  await page.evaluate('__allow=true;__consent=true')
  await page.wait_for_function("!document.getElementById('phone-capture-open').disabled")
  await page.locator('#phone-capture-open').click()
  await page.locator('[data-phone-capture-mode="video"]').click()
  check('Multicamera requires a deliberate phone Arm gesture',await page.locator('#phone-capture-arm').is_visible())
  check('Host has not started a recording when camera opens',await page.evaluate('__starts===0'))
  await page.locator('#phone-capture-arm').click()
  await page.wait_for_function("document.getElementById('phone-capture-status').textContent.includes('armed')")
  check('Armed phone has a live stream but is not recording',await page.evaluate("__starts===0&&!document.getElementById('phone-capture-disarm').hidden"))
  await page.evaluate("__phase='countdown'")
  await page.wait_for_function("document.getElementById('phone-capture-status').textContent.includes('countdown')",timeout=3000)
  check('Countdown does not start recording prematurely',await page.evaluate('__starts===0'))
  await page.evaluate("__phase='recording'")
  await page.wait_for_function('window.__starts===1',timeout=3000)
  check('Host recording triggers exactly one armed phone recording',await page.evaluate("__starts===1&&!document.getElementById('phone-capture-stop').hidden"))
  await page.wait_for_timeout(750)
  check('Repeated host polls do not create duplicate phone takes',await page.evaluate('__starts===1'))
  await page.evaluate("__phase='processing'")
  await page.wait_for_function("__stops===1 && !document.getElementById('phone-capture-send').hidden",timeout=3500)
  check('Host stop produces a reviewable video, not automatic upload',await page.evaluate('__stops===1 && __uploads.length===0'))
  check('Stream tracks close after capturing a take',await page.evaluate('__tracksStopped>=1'))
  await page.locator('#phone-capture-send').click()
  await page.wait_for_function('window.__uploads.length===1',timeout=3000)
  values=await page.evaluate("Object.fromEntries(__uploads[0].entries)")
  check('Coordinated video is submitted with existing pairing and prize permissions',values.get('kind')=='video' and values.get('session')=='a'*32 and values.get('claim')=='b'*32)
  await page.evaluate("__phase='live'")
  await page.wait_for_function("!document.getElementById('phone-capture-arm').disabled",timeout=3000)
  await page.locator('#phone-capture-arm').click()
  await page.wait_for_function("document.getElementById('phone-capture-status').textContent.includes('armed')")
  await page.evaluate("__allow=false")
  await page.wait_for_function("!document.getElementById('phone-capture-dialog').open",timeout=3000)
  check('Revoking host camera permission disarms without recording',await page.evaluate('__starts===1&&__tracksStopped>=2'))
  check('No browser exceptions during synchronized capture',not errors)
  await browser.close()
  print('V5Q.29D COORDINATED MULTICAMERA BROWSER CERTIFICATION PASSED',flush=True)

if __name__=='__main__':asyncio.run(main())
