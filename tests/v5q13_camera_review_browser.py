"""V5Q.13: camera-first UI, pre-game permission, video review before upload, phone proof accordion."""
import asyncio, sys
from pathlib import Path
from playwright.async_api import async_playwright
sys.path.insert(0,str(Path(__file__).parent))
from v5q6_proof_browser import html_page, MOCK, SID, CID, BASE
from v5p_pairing import make_html, ID, SECRET
MOCK_CAM=r'''
window.__uploads=[];window.__savedClips=[];window.__permissionCalls=0;
Object.defineProperty(navigator,'mediaDevices',{configurable:true,value:{
 getUserMedia:async()=>{window.__permissionCalls++;return new MediaStream()},
 enumerateDevices:async()=>[{kind:'videoinput',deviceId:'cam-one',label:'USB Front'}],addEventListener:()=>{} }});
HTMLMediaElement.prototype.play=function(){return Promise.resolve()};
Object.defineProperty(HTMLVideoElement.prototype,'videoWidth',{get:()=>640});Object.defineProperty(HTMLVideoElement.prototype,'videoHeight',{get:()=>360});
window.MediaRecorder=class{
 static isTypeSupported(){return true}
 constructor(stream){this.stream=stream;this.state='inactive';this.mimeType='video/webm'}
 start(){this.state='recording'}
 stop(){this.state='inactive';this.ondataavailable?.({data:new Blob(['video-bytes'],{type:'video/webm'}),size:11});this.onstop?.()}
};
const __origFetch=window.fetch;
window.fetch=async(url,opts={})=>{
 const u=String(url);
 if(u.includes('claim-media.php?action=upload')){__uploads.push({session:opts.body.get('session'),claim:opts.body.get('claim'),kind:opts.body.get('kind')});const clip={id:'e'.repeat(32),claimId:opts.body.get('claim'),title:'clip',kind:'video',src:'api/claim-media.php?action=stream&id='+('e'.repeat(32))};__savedClips.push(clip);return new Response(JSON.stringify({ok:true,clip}));}
 if(u.includes('claim-media.php?action=list'))return new Response(JSON.stringify({ok:true,clips:__savedClips}));
 if(u.includes('controller.php?action=proof_state'))return new Response(JSON.stringify({ok:true,active:true}));
 return __origFetch(url,opts);
};
'''
async def main():
 results=[];errors=[]
 def test(name,v):results.append((name,bool(v)))
 async with async_playwright() as pw:
  browser=await pw.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox'])
  page=await browser.new_page(viewport={'width':1280,'height':900})
  page.set_default_timeout(6000);page.on('pageerror',lambda e:errors.append(str(e)))
  # mock game script needed to establish exact claim context; do not depend on a DB fixture
  script=MOCK_CAM+(BASE/'public/proof-sequence.js').read_text()+'\n'+(BASE/'public/claim-capture.js').read_text()+'\n'+(BASE/'public/game.js').read_text()
  markup=html_page('game.php',script,MOCK.replace("window.__state='active'","window.__state='ended'"))
  await page.set_content(markup,wait_until='domcontentloaded')
  await page.wait_for_function('window.LuckyCapture && document.querySelector("#start-game")')
  await page.evaluate("document.querySelector('#console-menu').showModal()")
  await page.locator('#start-game').click()
  await page.evaluate("document.querySelector('#console-menu').close()")
  await page.wait_for_function('window.__permissionCalls>0')
  test('Start Game requests camera permission before prize claim',await page.evaluate('window.__permissionCalls>0'))
  # Prize proof is always shown on proof.php, not embedded in game.php.
  await page.evaluate(f"window.__proofLink='proof.php?session={SID}&claim={CID}&from=game&mode=win'")
  await page.close()
  page=await browser.new_page(viewport={'width':1280,'height':900});page.set_default_timeout(6000);page.on('pageerror',lambda e:errors.append(str(e)))
  proof_script=(BASE/'public/proof.js').read_text().replace('new URL(location.href).searchParams',f"new URLSearchParams('session={SID}&claim={CID}&from=game&mode=win')")
  script=MOCK_CAM+(BASE/'public/proof-sequence.js').read_text()+'\n'+(BASE/'public/claim-capture.js').read_text()+'\n'+proof_script
  markup=html_page('proof.php',script,MOCK.replace("src:'api/claim-media.php?action=stream&id=x'","id:'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee',src:'api/claim-media.php?action=stream&id=eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee'"))
  await page.set_content(markup,wait_until='domcontentloaded')
  await page.wait_for_function('window.LuckyCapture?.hasClaim()===true')
  await page.locator('#proof-camera-settings summary').click()
  await page.locator('#camera-enable').click()
  await page.wait_for_function('document.querySelectorAll(".camera-source video:not([hidden])").length===1')
  test('live camera display is first in popup',await page.evaluate("document.querySelector('.proof-editor-layout').children.length===2"))
  test('consent checkbox is not displayed on desktop',await page.locator('#capture-consent').is_hidden())
  test('one desktop claim station with history',await page.locator('.proof-history-panel').count()==1)
  test('live camera feed attached',await page.evaluate("document.querySelector('.camera-source video').srcObject instanceof MediaStream"))
  await page.evaluate("LuckyCapture.phoneAction('proof-consent-on')")
  await page.locator('#capture-video').click()
  await page.wait_for_function('document.querySelector("#capture-stop").hidden===false')
  await page.locator('#capture-stop').click()
  await page.wait_for_function('document.querySelectorAll("#capture-review-list .review-tile").length===1')
  test('review replaces recording tools',await page.locator('#capture-recording-tools').is_hidden())
  test('Play / Save / Re-record / Delete shown',await page.locator('#capture-review-list button').count()==4)
  test('unapproved video never uploaded',await page.evaluate('__uploads.length===0'))
  await page.evaluate("LuckyCapture.phoneAction('proof-play')")
  test('phone Play targets video review',await page.locator('#capture-review-list video[controls]').count()==1)
  await page.evaluate("LuckyCapture.phoneAction('proof-delete')")
  test('Delete discards without upload',await page.evaluate('window.__uploads.length===0'))
  test('Delete restores capture buttons',await page.locator('#capture-recording-tools').is_visible())
  await page.locator('#capture-video').click();await page.locator('#capture-stop').click()
  await page.wait_for_function('LuckyCapture.reviewCount()===1')
  await page.evaluate("LuckyCapture.phoneAction('proof-save')")
  await page.wait_for_function('__uploads.length===1')
  test('Save uploads exact claim',await page.evaluate(f"__uploads[0].claim==='{CID}' && __uploads[0].session==='{SID}'"))
  test('Saved recorded video remains in main viewer',await page.locator('.proof-video-stage #capture-review video').is_visible() and await page.locator('#camera-grid').is_hidden())
  await page.wait_for_function("document.querySelectorAll('#capture-clip-list .proof-saved-clip').length===1")
  test('saved recording appears as clickable video in right column',await page.locator('#capture-clip-list .proof-saved-clip video').count()==1)
  await page.locator('#capture-review-list button').filter(has_text='Live camera').click()
  await page.locator('#capture-clip-list .proof-saved-clip').click()
  test('right clip loads into single main video player',await page.locator('#capture-review-list video').is_visible() and await page.locator('#camera-grid').is_hidden())
  await page.locator('#proof-history-disclosure > summary').click()
  await page.locator('.proof-revision-item > summary').first.click()
  await page.locator('.proof-load-clip').first.click()
  test('revision clip loads into main playback instead of new tab',await page.locator('#capture-review-list video').is_visible() and await page.evaluate("document.querySelector('#capture-review-list video').getAttribute('src').includes('id=eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee')"))

  await page.close();await browser.close()
  browser=await pw.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox'])
  await page.close();await browser.close()
 test('no JS exceptions',not errors)
 for name,ok in results:print(('PASS' if ok else 'FAIL'),name,flush=True)
 print('V5Q13 camera review:',sum(bool(v) for _,v in results),'/',len(results))
 if errors:print('JS exceptions:',errors)
 if not all(ok for _,ok in results):raise SystemExit(1)
if __name__=='__main__':asyncio.run(main())
