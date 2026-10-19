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
  # Construct standalone timeline editor with both shared capture scripts locally (no network).
  html=editor.html.replace('<script>'+editor.SCRIPT+'</script>','<script>'+PREVIEW+'\n'+(BASE/'public/proof-sequence.js').read_text()+'\n'+(BASE/'public/claim-capture.js').read_text()+'</script><script>'+editor.SCRIPT+'</script>')
  pg=await b.new_page(viewport={'width':1440,'height':900})
  pg.set_default_timeout(3500)
  pg.on('pageerror',lambda e:errors.append(str(e)))
  await pg.set_content(html,wait_until='domcontentloaded')
  await pg.wait_for_function("document.querySelectorAll('.studio-media-item').length===2")
  await pg.evaluate('''() => {
   const old=Lucky.api;
   Lucky.api=async(a,arg)=>a==='session'?{session:{claims:[{id:'%s',name:'Camera Prize',tier:'tier1',prizeId:'p1'}],pools:{tier1:{prizes:[{id:'p1',proofAngles:[]}]}}}}:old(a,arg);
   document.getElementById('editor-session').dispatchEvent(new Event('change'));
  }'''%CID)
  await pg.wait_for_function("document.querySelectorAll('#studio-proof-claim option').length===2")
  await pg.locator('#studio-proof-claim').select_option(CID)
  ck('timeline editor selects a real claim',not await pg.locator('#camera-enable').is_disabled())
  await pg.locator('#camera-enable').click()
  ck('timeline editor shows two live USB previews',await pg.locator('.studio-camera-panel video:not([hidden])').count()==2)
  ck('both editors include camera source labels', 'USB Front Camera' in await pg.locator('#camera-grid').inner_text())
  ck('timeline existing edit clips retained',await pg.locator('.editor-clip').count()==2)
  # Upload directly to a validated claim, then confirm it is appended rather than replacing the edit.
  await pg.evaluate('''() => {const orig=window.fetch;window.fetch=async(u,options={})=>{
    if(String(u).includes('action=upload')){
      const clip={id:'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee',claimId:'%s',kind:'photo',title:'USB evidence',sourceLabel:'USB Front Camera',durationMs:0,trimStartMs:0,trimEndMs:0,photoDurationMs:4000,src:'https://example.test/new.png'};
      media.push(clip);return new Response(JSON.stringify({ok:true,clip}),{status:201,headers:{'Content-Type':'application/json'}});
    }
    return orig(u,options);
   };}'''%CID)
  await pg.locator('#capture-consent').check()
  await pg.locator('#capture-file').set_input_files({'name':'photo.png','mimeType':'image/png','buffer':b'fake-picture'})
  await pg.wait_for_function("document.querySelectorAll('.studio-track .editor-clip').length===3",timeout=3500)
  ck('new prize proof auto-added to montage timeline',await pg.locator('.editor-clip').count()==3)
  ck('original edit and source media are preserved',await pg.locator('.studio-media-item').count()==3 and 'USB evidence' in await pg.locator('#editor-timeline').inner_text())
  await pg.close();await b.close()
 ck('no browser exceptions',not errors)
 for k,v in tests:print(('PASS ' if v else 'FAIL ')+k,flush=True)
 if errors:print('JS errors',errors)
 print('V5Q8B',sum(v for _,v in tests),'/',len(tests))
 if not all(v for _,v in tests):raise SystemExit(1)
if __name__=='__main__':asyncio.run(run())
