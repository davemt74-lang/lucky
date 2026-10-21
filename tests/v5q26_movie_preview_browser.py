"""End-to-end isolated browser acceptance for the host movie overlay and paired phone remote."""
import asyncio,re
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[1]
SID='c'*32;PAIR='a'*32;TOKEN='b'*64
PHONE=(ROOT/'controller.php').read_text().split('?>',1)[1]
PHONE=re.sub(r'<script src="public/[^\"]+" defer></script>','',PHONE)
PHONE=PHONE.replace('</body>', '''<script>
Object.defineProperty(window,'sessionStorage',{configurable:true,value:{values:{'lucky-phone-controller-v5p':JSON.stringify({id:'PAIR',token:'TOKEN'})},getItem(k){return this.values[k]||null},setItem(k,v){this.values[k]=v},removeItem(k){delete this.values[k]}}});
window.__commands=[];window.__movie='playing';
window.fetch=async (url,opts={})=>{
 const action=new URL(String(url),'http://127.0.0.1/').searchParams.get('action');
 const body=JSON.parse(opts.body||'{}');
 if(action==='status')return new Response(JSON.stringify({ok:true,status:'paired',deviceName:'Test Phone',game:{status:'ready',packages:[]},proof:{active:false},moviePreview:__movie==='stopped'?null:{session:'SESSION',state:__movie,revision:2}}));
 if(action==='movie_command'){
  __commands.push({verb:body.verb,session:body.session,nonce:body.nonce,token:opts.headers['X-Controller-Token']});
  __movie=body.verb==='play'?'playing':body.verb==='pause'?'paused':'stopped';
  return new Response(JSON.stringify({ok:true,movie:{session:'SESSION',state:__movie,revision:3}}));
 }
 if(action==='dashboard_games')return new Response(JSON.stringify({ok:true,games:[]}));
 return new Response(JSON.stringify({ok:false,error:'Unexpected '+action}),{status:400});
};</script><script>PHONE_SCRIPT</script></body>'''.replace('SESSION',SID).replace('PAIR',PAIR).replace('TOKEN',TOKEN).replace('PHONE_SCRIPT',(ROOT/'public/phone-controller.js').read_text().replace('</script','<\\/script')))
# Extract the editor's server-side PHP wrapper, preserve the real markup, and load its JS with mocked API dependencies.
EDITOR=(ROOT/'editor.php').read_text().split('?>',1)[1]
EDITOR=re.sub(r'<script src="[^\"]+" defer></script>','',EDITOR)
EDITOR=re.sub(r'<\?php[^?]*\?>','',EDITOR)
IMG='data:image/svg+xml,'+'%3Csvg%20xmlns=%22http://www.w3.org/2000/svg%22%20width=%2240%22%20height=%2240%22%3E%3Crect%20width=%2240%22%20height=%2240%22%20fill=%22%232f4581%22/%3E%3C/svg%3E'
MOCK='''<script>
window.__movie='stopped';window.__revision=0;window.__calls=[];
window.Lucky={$:id=>document.getElementById(id),getMe:async()=>({csrf:'test'}), loadTracks:async()=>[],
 api:async(a)=>{if(a==='sessions')return {sessions:[{id:'SESSION',game:'spin-wheel',status:'ended',createdAt:new Date().toISOString()}]};return {}}};
window.fetch=async(url,opts={})=>{
 const action=new URL(String(url),'http://127.0.0.1/').searchParams.get('action');__calls.push(action);
 if(action==='list'&&String(url).includes('claim-media.php'))return new Response(JSON.stringify({ok:true,clips:[{id:'MEDIA',instanceId:'i-1',claimId:'CLAIM',kind:'photo',title:'Photo A',photoDurationMs:2800,src:'IMAGE',durationMs:0},{id:'SECOND',instanceId:'i-2',claimId:'CLAIM',kind:'photo',title:'Photo B',photoDurationMs:1300,src:'IMAGE',durationMs:0}]}));
 if(action==='project')return new Response(JSON.stringify({ok:true,project:null,layers:{titles:[{id:'t1',text:'Winner!',startMs:0,durationMs:5500,position:'center',size:'large'}],background:null}}));
 if(action==='list')return new Response(JSON.stringify({ok:true,renders:[]}));
 if(action==='templates')return new Response(JSON.stringify({ok:true,templates:[]}));
 if(action==='preview_open'){__movie='playing';__revision++;return new Response(JSON.stringify({ok:true,movie:{state:__movie,session:'SESSION',revision:__revision}}));}
 if(action==='preview_heartbeat')return new Response(JSON.stringify({ok:true,movie:__movie==='stopped'?null:{state:__movie,session:'SESSION',revision:__revision}}));
 if(action==='preview_close'){__movie='stopped';__revision++;return new Response(JSON.stringify({ok:true,movie:null}));}
 return new Response(JSON.stringify({ok:true,claims:[],renders:[],tracks:[]}));
};
</script>'''.replace('SESSION',SID).replace('MEDIA','d'*32).replace('SECOND','f'*32).replace('CLAIM','e'*32).replace('IMAGE',IMG)
EDITOR=EDITOR.replace('</section>\n<script', '</section>\n<script')
EDITOR=EDITOR+MOCK+'<script>'+ (ROOT/'public/editor.js').read_text().replace('</script','<\\/script')+'</script>'
async def main():
 checks=[];errors=[]
 def ck(s,v):checks.append((s,bool(v)))
 async with async_playwright() as p:
  browser=await p.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox'])
  phone=await browser.new_page(viewport={'width':390,'height':844})
  phone.on('pageerror',lambda e:errors.append('phone '+str(e)))
  # custom in-page mock sessionStorage is initialized before phone application JS
  await phone.set_content(PHONE,wait_until='domcontentloaded')
  await phone.locator('#phone-movie').wait_for(state='visible',timeout=8000)
  ck('Approved phone replaces ordinary game controls with movie remote', await phone.locator('#phone-controls').is_hidden() and await phone.locator('#phone-dashboard').is_hidden())
  ck('Dedicated Play Pause Stop buttons are all visible',await phone.locator('.phone-movie-buttons button:visible').count()==3)
  await phone.locator('#phone-movie-pause').click()
  await phone.wait_for_function("__commands.some(x=>x.verb==='pause')",timeout=4000)
  ck('Pause command carries pair token and exact movie session',await phone.evaluate("__commands[0].session==='SESSION'&&__commands[0].token==='TOKEN'&&/^[a-f0-9]{32}$/.test(__commands[0].nonce)".replace('SESSION',SID).replace('TOKEN',TOKEN)))
  await phone.wait_for_function("document.querySelector('#phone-movie-state').textContent.includes('paused')",timeout=5000)
  await phone.locator('#phone-movie-play').click()
  await phone.wait_for_function("__commands.some(x=>x.verb==='play')",timeout=4000)
  ck('Phone sends a separate Play command',await phone.evaluate("__commands.map(x=>x.verb).join(',')==='pause,play'"))
  await phone.locator('#phone-movie-stop').click()
  await phone.wait_for_function("__movie==='stopped'",timeout=4000)
  await phone.wait_for_function("document.querySelector('#phone-dashboard').hidden===false",timeout=4000)
  ck('Stop closes movie control and returns phone to dashboard',await phone.locator('#phone-dashboard').is_visible())
  ck('Separate unique nonces prevent repeated movie commands', await phone.evaluate('new Set(__commands.map(x=>x.nonce)).size===3'))
  # Actual video studio markup and JS, including a photo segment and title from the timeline.
  host=await browser.new_page(viewport={'width':1440,'height':900})
  host.on('pageerror',lambda e:errors.append('host '+str(e)))
  await host.set_content('<html><head><base href="http://127.0.0.1/"></head><body>'+EDITOR+'</body></html>',wait_until='domcontentloaded')
  await host.locator('#editor-open-movie-preview').wait_for(timeout=7000)
  await host.wait_for_function("document.querySelector('#editor-count').textContent.includes('2')",timeout=7000)
  ck('Full timeline is loaded into video studio before preview',await host.locator('#editor-count').inner_text()=='2 segments')
  await host.locator('#editor-open-movie-preview').click()
  await host.locator('#movie-preview-dialog').wait_for(state='visible',timeout=6000)
  ck('Movie opens full-screen centered dialog with isolated video screen', await host.locator('#movie-preview-screen').is_visible() and await host.locator('#render-dialog').is_hidden())
  await host.wait_for_function("document.querySelector('#movie-preview-screen img')!==null",timeout=6000)
  ck('Timeline preview shows first clip and timed title',await host.locator('#movie-preview-screen img').count()==1 and await host.locator('#movie-preview-screen .studio-timeline-preview-title').count()==1)
  await host.evaluate("window.__movie='paused';window.__revision++")
  await host.wait_for_function("document.querySelector('#movie-preview-status').textContent.includes('paused')",timeout=5000)
  ck('Host obeys phone Pause during a photo segment',await host.locator('#movie-preview-status').inner_text()=='Movie paused · Use Play on your phone')
  await host.evaluate("window.__movie='playing';window.__revision++")
  await host.wait_for_function("document.querySelector('#movie-preview-status').textContent.includes('Playing')",timeout=5000)
  ck('Host obeys phone Play', 'Playing' in await host.locator('#movie-preview-status').inner_text())
  await host.wait_for_function("document.querySelector('#editor-caption').textContent.includes('2/2: Photo B')",timeout=8000)
  ck('Full preview advances to second assembled timeline segment',await host.locator('#movie-preview-screen img').count()==1)
  await host.locator('#movie-preview-close').click()
  ck('Close releases modal and writes server stop',await host.locator('#movie-preview-dialog').is_hidden() and await host.evaluate('__calls.includes("preview_close")'))
  ck('No JavaScript exceptions',not errors)
  await browser.close()
 for label,ok in checks:print(('PASS' if ok else 'FAIL')+' '+label,flush=True)
 print('V5Q26 Movie preview: ',sum(v for _,v in checks),'/',len(checks),'JS errors:',errors)
 if not all(x for _,x in checks):raise SystemExit(1)
if __name__=='__main__':asyncio.run(main())
