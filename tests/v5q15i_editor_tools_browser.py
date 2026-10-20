"""Video studio V5Q15I: slide-out inspector, context tools, trim handles and voiceover."""
import asyncio,sys
from pathlib import Path
from playwright.async_api import async_playwright
sys.path.insert(0,str(Path(__file__).parent))
import v5q3_editor_browser as editor
ID='f'*32
async def main():
 results=[];errors=[]
 def ck(name,v):results.append((name,bool(v)))
 async with async_playwright() as pw:
  b=await pw.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox'])
  p=await b.new_page(viewport={'width':1440,'height':900},reduced_motion='reduce')
  p.set_default_timeout(6500);p.on('pageerror',lambda e:errors.append(str(e)))
  await p.goto('about:blank')
  await p.evaluate("""() => {
   Object.defineProperty(navigator,'mediaDevices',{configurable:true,value:{getUserMedia:async()=>({getTracks:()=>[{stop(){}}]})}});
   window.MediaRecorder=class{
    static isTypeSupported(t){return t.startsWith('audio/webm')}
    constructor(stream){this.mimeType='audio/webm';this.state='inactive'}
    start(){this.state='recording'}
    stop(){this.state='inactive';this.ondataavailable?.({data:new Blob(['mock microphone bytes'],{type:'audio/webm'})});this.onstop?.()}
   };
  }""")
  await p.set_content(editor.html,wait_until='domcontentloaded')
  await p.wait_for_function("document.querySelectorAll('.editor-clip').length===2")
  ck('back link first, replacing studio logo',await p.locator('.studio-topbar > :first-child').get_attribute('id')=='editor-back-game' and await p.locator('.studio-identity').count()==0)
  ck('Auto cut and Session claims absent',await p.locator('#editor-auto,#editor-session-claims').count()==0)
  ck('inspector starts closed with wide monitor',await p.locator('#clip-inspector').get_attribute('aria-hidden')=='true' and await p.locator('.studio-monitor').is_visible())
  await p.locator('#editor-open-inspector').click()
  ck('header opens slide-out inspector',await p.locator('#clip-inspector').evaluate('(el)=>el.classList.contains("is-open") && !el.inert'))
  await p.locator('#editor-close-inspector').click()
  ck('inspector can close without discarding timeline',await p.locator('.editor-clip').count()==2)
  row=p.locator('.editor-clip').first
  await row.click(button='right')
  ck('right-click clip actions exposed',await p.locator('#clip-context-menu').is_visible() and await p.get_by_role('menuitem',name='🗑 Delete clip from timeline').count()==1)
  await p.get_by_role('menuitem',name='🔇 Mute audio').click()
  ck('mute updates selected video',await row.evaluate('(el)=>el.classList.contains("is-muted")'))
  await row.click(button='right')
  await p.get_by_role('menuitem',name='♫ Unmute audio').click()
  await row.click(button='right')
  await p.get_by_role('menuitem',name='↔ Detach audio to A1').click()
  ck('detach creates editable A1 audio state',await row.evaluate('(el)=>el.classList.contains("is-detached")') and 'Detached' in await p.locator('.studio-audio-cell').first.inner_text())
  left=await p.locator('.editor-clip').first.locator('.editor-trim-handle.start').bounding_box()
  await p.mouse.move(left['x']+left['width']/2,left['y']+left['height']/2)
  await p.mouse.down();await p.mouse.move(left['x']+left['width']/2+35,left['y']+left['height']/2,steps=5);await p.mouse.up()
  ck('left handle changes clip In point',await p.evaluate("document.querySelector('#editor-trim-start').value !== '0.0'"))
  right=await p.locator('.editor-clip').first.locator('.editor-trim-handle.end').bounding_box()
  await p.mouse.move(right['x']+right['width']/2,right['y']+right['height']/2)
  await p.mouse.down();await p.mouse.move(right['x']+right['width']/2-30,right['y']+right['height']/2,steps=5);await p.mouse.up()
  ck('right handle changes clip Out point',await p.evaluate("document.querySelector('#editor-trim-end').value !== '0.0'"))
  await p.locator('.editor-clip').first.click(button='right')
  await p.get_by_role('menuitem',name='● Record audio').click()
  ck('record audio opens microphone modal',await p.locator('#editor-audio-dialog').is_visible())
  await p.evaluate("""() => {
   const original=window.fetch;window.__recordUpload={};
   window.fetch=async(url,options={})=>{
    if(String(url).includes('action=upload')){
     window.__recordUpload={session:options.body.get('session'),claim:options.body.get('claim')};
     media.push({id:'e'.repeat(32),claimId:'f'.repeat(32),kind:'audio',title:'Studio voiceover',
      durationMs:3000,trimStartMs:0,trimEndMs:0,photoDurationMs:4000,src:'https://example.test/audio.webm'});
     return new Response(JSON.stringify({ok:true,clip:{id:'e'.repeat(32),claimId:'f'.repeat(32),kind:'audio'}}));
    }
    return original(url,options);
   };
  }""")
  await p.locator('#editor-audio-start').click()
  ck('recording shows microphone active', 'Recording audio' in await p.locator('#editor-audio-status').inner_text())
  await p.locator('#editor-audio-stop').click()
  await p.wait_for_function("document.querySelector('#editor-audio-save').disabled===false")
  await p.locator('#editor-audio-save').click()
  await p.wait_for_function("document.querySelector('#editor-audio-dialog').open===false")
  ck('mic audio upload scoped to exact claim',await p.evaluate("window.__recordUpload.claim==='f'.repeat(32)"))
  ck('recorded voiceover shown on A1', 'Voiceover attached' in await p.locator('.studio-audio-cell').first.inner_text())
  await p.locator('#editor-save-project').click()
  await p.wait_for_function("demo.saved!==null")
  ck('mute/detach, in/out and voiceover persisted',await p.evaluate("""() => demo.saved[0].audioMode==='detached' && demo.saved[0].trimStartMs>0 &&
    demo.saved[0].trimEndMs>0 && demo.saved[0].voiceoverId==='e'.repeat(32)"""))
  await p.locator('.editor-clip').first.click(button='right')
  await p.get_by_role('menuitem',name='🗑 Delete clip from timeline').click()
  ck('Delete clip removes only timeline instance',await p.locator('.editor-clip').count()==1 and await p.locator('.studio-media-item').count()==3)
  ck('no JS exceptions',not errors)
  await b.close()
 for name,v in results:print(('PASS ' if v else 'FAIL ')+name,flush=True)
 if errors:print('JS errors:',errors,flush=True)
 print('V5Q15I editor:',sum(v for _,v in results),'/',len(results),flush=True)
 if not all(v for _,v in results):raise SystemExit(1)
if __name__=='__main__':asyncio.run(main())
