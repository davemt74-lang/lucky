"""Browser contract: same-game return, verified claim context and live previews in both editors."""
import asyncio, sys
from pathlib import Path
from playwright.async_api import async_playwright
sys.path.insert(0,str(Path(__file__).parent))
import v5q6_proof_browser as proof
import v5q3_editor_browser as editor
BASE=Path(__file__).resolve().parents[1]
SID=proof.SID; CID=proof.CID
PREVIEW='''
Object.defineProperty(navigator,'mediaDevices',{configurable:true,value:{
 getUserMedia:async()=>new MediaStream(),enumerateDevices:async()=>[
 {kind:'videoinput',deviceId:'cam-one',label:'USB Front Camera'},
 {kind:'videoinput',deviceId:'cam-two',label:'USB Side Camera'}],
 addEventListener:()=>{} }});
HTMLMediaElement.prototype.play=function(){return Promise.resolve();};
'''
async def run():
 tests=[]; errors=[]
 def ck(name,ok):tests.append((name,bool(ok)))
 async with async_playwright() as pw:
  b=await pw.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox'])
  pg=await b.new_page(viewport={'width':1280,'height':850})
  pg.set_default_timeout(3500)
  pg.on('pageerror',lambda e:errors.append(str(e)))
  script=(BASE/'public/proof.js').read_text().replace('new URL(location.href).searchParams',f"new URLSearchParams('session={SID}&claim={CID}&from=game')")
  body=proof.html_page('proof.php',PREVIEW+(BASE/'public/proof-sequence.js').read_text()+'\n'+(BASE/'public/claim-capture.js').read_text()+'\n'+script,proof.MOCK)
  await pg.set_content(body,wait_until='domcontentloaded')
  try:await pg.wait_for_function("document.getElementById('camera-enable').disabled===false",timeout=1400)
  except Exception:
   print('DEBUG',await pg.locator('#proof-errors').inner_text(),await pg.locator('#proof-state').inner_text(),errors,flush=True)
   raise
  ck('proof session selected from clicked prize',await pg.locator('#proof-prize-title').inner_text()=='Camera Prize')
  ck('proof returns to exact originating game session',await pg.locator('#proof-return').get_attribute('href')=='game.php?session='+SID)
  await pg.locator('#proof-camera-settings summary').click()
  await pg.locator('#camera-enable').click()
  ck('two connected USB camera names shown',await pg.locator('.camera-source').count()==2)
  ck('live previews opened by Enable action',await pg.locator('.camera-source video:not([hidden])').count()==2)
  ck('camera preview does not require recording consent',not await pg.locator('#capture-consent').is_checked())
  ck('capture still requires recording consent',await pg.evaluate("document.querySelector('#capture-consent').checked===false"))
  ck('no erroneous missing-claim error', 'No prize claim selected' not in await pg.locator('#capture-feedback').inner_text())
  await pg.close()
  # Live camera capture now belongs exclusively to proof.php; the editor is clean.
  pg=await b.new_page(viewport={'width':1440,'height':900})
  pg.set_default_timeout(3500)
  pg.on('pageerror',lambda e:errors.append(str(e)))
  await pg.set_content(editor.html,wait_until='domcontentloaded')
  await pg.wait_for_function("document.querySelectorAll('.studio-media-item').length===2")
  ck('timeline editor has no duplicate live-camera panel',await pg.locator('.studio-camera-panel').count()==0)
  ck('main video monitor remains available',await pg.locator('#editor-preview').is_visible())
  ck('original edit clips remain intact',await pg.locator('.editor-clip').count()==2)
  ck('render/export moved into timeline header button',await pg.locator('.studio-sequence-head #open-render-dialog').is_visible())
  await pg.locator('#open-render-dialog').click()
  ck('render/export settings shown in modal',await pg.locator('#render-dialog').is_visible() and await pg.locator('#render-queue').is_visible())
  await pg.locator('#close-render-dialog').click()
  ck('modal closes without changing edit',await pg.locator('#render-dialog').is_hidden() and await pg.locator('.editor-clip').count()==2)
  await pg.close();await b.close()
 ck('no browser exceptions',not errors)
 for k,v in tests:print(('PASS ' if v else 'FAIL ')+k,flush=True)
 if errors:print('JS errors',errors)
 print('V5Q8B',sum(v for _,v in tests),'/',len(tests))
 if not all(v for _,v in tests):raise SystemExit(1)
if __name__=='__main__':asyncio.run(run())
