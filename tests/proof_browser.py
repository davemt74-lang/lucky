"""Isolated Chromium test with two simulated USB video sources; not a live hardware certification."""
import asyncio,sys
from pathlib import Path
from playwright.async_api import async_playwright
sys.path.insert(0,str(Path(__file__).parent))
from v5p_pairing import make_html,ID
MOCK = '''<script>
window.__uploads=[];window.__stops=0;window.__countdowns=[];
Object.defineProperty(navigator,'mediaDevices',{configurable:true,value:{
 getUserMedia:async()=>new MediaStream(),
 enumerateDevices:async()=>[{kind:'videoinput',deviceId:'camera-A',label:'USB Front Cam'},{kind:'videoinput',deviceId:'camera-B',label:'USB Side Cam'}],
 addEventListener:()=>{}
}});
HTMLMediaElement.prototype.play=async()=>{};
class FakeRecorder{
 static isTypeSupported(){return true;}
 constructor(stream,{mimeType}={}){this.state='inactive';this.mimeType=mimeType||'video/webm';}
 start(){this.state='recording';}
 stop(){if(this.state==='inactive')return;this.state='inactive';window.__stops++;queueMicrotask(()=>{this.ondataavailable?.({data:new Blob(['mock video proof'],{type:'video/webm'})});this.onstop?.();});}
}
window.MediaRecorder=FakeRecorder;
const original=fetch;
window.fetch=async(url,opts={})=>{
 const action=new URL(String(url),'https://example.test/').searchParams.get('action');
 if(action==='upload'){
  const obj={};opts.body.forEach((value,key)=>obj[key]=value instanceof Blob?'binary':value);__uploads.push(obj);
  return new Response(JSON.stringify({ok:true,clip:{id:'x',kind:'video'}}),{status:201});
 }
 if(action==='list')return new Response(JSON.stringify({ok:true,clips:__uploads.map((u,i)=>({id:String(i),claimId:u.claim,title:u.title,sourceLabel:u.sourceLabel,kind:u.kind}))}));
 return original(url,opts);
};
</script>'''

def ck(arr,msg,ok):arr.append((msg,bool(ok)))
async def main():
 out=[];errs=[]
 async with async_playwright() as pw:
  b=await pw.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox'])
  ctx=await b.new_context(viewport={'width':1440,'height':900},reduced_motion='reduce')
  pg=await ctx.new_page();pg.on('pageerror',lambda e:errs.append(str(e)))
  html=make_html('game').replace('</head>',MOCK+'</head>',1)
  await pg.set_content(html,wait_until='domcontentloaded');await pg.wait_for_timeout(300)
  await pg.evaluate('''() => {LuckyProofSequence.wait=(ms,signal)=>new Promise((resolve,reject)=>setTimeout(()=>signal?.aborted?reject(Error('Capture stopped.')):resolve(),20));const oldRun=LuckyProofSequence.run;LuckyProofSequence.run=(plan,opts)=>oldRun(plan,{...opts,sleep:LuckyProofSequence.wait});
    new MutationObserver(()=>__countdowns.push(document.getElementById('proof-countdown').textContent)).observe(document.getElementById('proof-countdown'),{childList:true,characterData:true,subtree:true});
    document.getElementById('win-dialog').showModal();
    LuckyCapture.show('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa','bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb','Mystery Prize',[{label:'Hero front',seconds:3},{label:'Audience reaction',seconds:3}]);
    document.getElementById('capture-consent').checked=true;
  }''')
  await pg.locator('#camera-enable').click();await pg.wait_for_timeout(300)
  ck(out,'two USB inputs detected',await pg.locator('.camera-source').count()==2)
  ck(out,'proof angles equal checked active cameras',await pg.locator('.proof-angle-chip').count()==2)
  ck(out,'per-prize proof names displayed', 'Hero front' in await pg.locator('#proof-angle-plan').inner_text())
  await pg.locator('.camera-source [data-camera-name]').first.fill('Living room front');await pg.locator('.camera-source [data-camera-name]').first.press('Tab')
  ck(out,'user labels physical USB source', 'Living room front' in await pg.locator('#proof-angle-plan').inner_text())
  await pg.locator('#proof-rotate').click();await pg.wait_for_function('window.__uploads.length === 2',timeout=5000)
  ck(out,'separate clip uploaded for each angle',await pg.evaluate('__uploads.length===2'))
  ck(out,'proof clips ordered 1 then 2',await pg.evaluate('__uploads.map(x=>x.angleIndex).join(",")==="1,2"'))
  ck(out,'angle label and camera name saved',await pg.evaluate('__uploads[0].angleLabel==="Hero front" && __uploads[0].sourceLabel==="Living room front" && __uploads[1].angleLabel==="Audience reaction"'))
  ck(out,'five-second countdown displayed',await pg.evaluate('__countdowns.includes("5")&&__countdowns.includes("1")'))
  ck(out,'camera previews/recordings stopped after each take',await pg.evaluate('__stops===2'))
  ck(out,'editor includes captured clips', '2 captured clips' in await pg.locator('#capture-clip-list').inner_text())
  await pg.locator('[data-camera-select]').last.uncheck()
  ck(out,'one active camera means one proof angle',await pg.locator('.proof-angle-chip').count()==1)
  ck(out,'no page exceptions',not errs)
  await pg.screenshot(path='/mnt/data/Lucky-Game-Hub-V5Q2-proof-preview.png',full_page=True)
  await ctx.close();await b.close()
 for label,p in out:print(('PASS ' if p else 'FAIL ')+label)
 print('Proof browser:',sum(p for _,p in out),'/',len(out))
 if errs:print('JS_ERRORS',errs)
 if not all(p for _,p in out):raise SystemExit(1)
if __name__=='__main__':asyncio.run(main())
