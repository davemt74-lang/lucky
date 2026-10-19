"""V5Q.6 isolated browser coverage for prize proof revisions and game UI."""
import asyncio,re
from pathlib import Path
from playwright.async_api import async_playwright
BASE=Path(__file__).resolve().parents[1];SID='c'*32;CID='d'*32
CSS=(BASE/'public/style.css').read_text()+'\n'+(BASE/'public/platform.css').read_text()
def page_markup(file,script,mock):
 text=(BASE/file).read_text().split('?>',1)[1].split('<?php shellEnd()',1)[0]
 text=text.replace('<?php pairingWidget(true); ?>','<section class="phone-pair-panel">Connect phone</section>')
 text=re.sub(r'<script\b[^>]*>.*?</script>','',text,flags=re.S|re.I)
 return '<html><head><style>'+CSS+'</style><script>'+mock+'</script></head><body>'+text+'<script>'+script+'</script></body></html>'
MOCK="""
window.__claims=[{id:'CID',prizeId:'p1',name:'Camera Prize',tier:'tier1',cents:1250,at:'2026-10-19T12:00:00Z'}];
window.LuckyWheel=class{setSlices(){} async spinTo(){}};
window.Lucky={$:id=>document.getElementById(id),money:n=>'$'+(n/100).toFixed(2),getMe:async()=>({csrf:'test'}),
note:(id,s)=>{const e=document.getElementById(id);if(e)e.textContent=s;},
api:async(a)=>a==='last'?{data:{view:{id:'SID',status:'active',version:1,claims:__claims,slots:[],spinCount:0},session:{id:'SID',claims:__claims,pools:{tier1:{prizes:[]},bonus:{prizes:[]}}}}}:{session:{id:'SID',claims:__claims,pools:{tier1:{prizes:[{id:'p1',proofAngles:[]}]}}}},
loadTracks:async()=>[],bindMusic:()=>{},beep:()=>{},speak:()=>{},pref:{musicOn:false},idempotency:()=>'x'};
window.LuckyCapture={show:(...a)=>window.__captureArgs=a};
window.fetch=async(url,opts={})=>{const u=new URL(String(url),'https://example.test/');
if(u.pathname.endsWith('claim-proof.php')){
 if(opts.method==='POST'){window.__post=JSON.parse(opts.body);return new Response(JSON.stringify({ok:true,status:{needsRevision:false,latestRevision:1}}));}
 if(u.searchParams.get('action')==='summary')return new Response(JSON.stringify({ok:true,states:{'CID':{needsRevision:true,latestRevision:1}}}));
 return new Response(JSON.stringify({ok:true,status:{needsRevision:true,requestNote:'Reshoot',latestRevision:1},clipCount:1,revisions:[{id:'r',number:1,at:'2026-10-19',note:'Original',clips:[{title:'USB Camera',kind:'video',src:'api/claim-media.php?action=stream&id=x'}]}]}));}
return new Response(JSON.stringify({ok:true,clips:[]}));};
""".replace('SID',SID).replace('CID',CID)
async def run():
 checks=[];errors=[]
 def check(label,truth):checks.append((label,bool(truth)))
 async with async_playwright() as p:
  browser=await p.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox'])
  for width in [390,1280]:
   page=await browser.new_page(viewport={'width':width,'height':820},reduced_motion='reduce')
   page.on('pageerror',lambda e:errors.append(str(e)))
   await page.set_content(page_markup('game.php',(BASE/'public/game.js').read_text(),MOCK))
   await page.wait_for_timeout(300)
   check('claims lead wheel '+str(width),await page.evaluate("Boolean(document.querySelector('.game-claims-top').compareDocumentPosition(document.querySelector('.platform-game-layout')) & Node.DOCUMENT_POSITION_FOLLOWING)"))
   check('edit proof linked '+str(width),await page.locator('#claim-list a[href*="proof.php"]').count()==1)
   check('needs revision displayed '+str(width),await page.locator('.proof-revision-needed').count()==1)
   check('phones hidden by default '+str(width),await page.locator('#host-panel-phones').is_hidden())
   await page.locator('#host-tab-phones').click()
   check('phones visible when selected '+str(width),await page.locator('#host-panel-phones').is_visible())
   check('music hidden in phones tab '+str(width),await page.locator('#host-panel-music').is_hidden())
   await page.locator('#host-tab-phones').press('ArrowLeft')
   check('arrow returns to music '+str(width),await page.locator('#host-panel-music').is_visible())
   check('no overflow '+str(width),await page.evaluate('document.documentElement.scrollWidth<=innerWidth+1'))
   await page.close()
  page=await browser.new_page(viewport={'width':390,'height':844})
  page.on('pageerror',lambda e:errors.append(str(e)))
  script=(BASE/'public/proof.js').read_text().replace('new URL(location.href).searchParams',"new URLSearchParams('session="+SID+"&claim="+CID+"')")
  await page.set_content(page_markup('proof.php',script,MOCK))
  await page.wait_for_timeout(300)
  check('claim loaded',await page.locator('#proof-prize-title').inner_text()=='Camera Prize')
  check('baseline snapshot requested',await page.evaluate("(window.__post||{}).claim==='"+CID+"'"))
  check('capture scoped to claim',await page.evaluate("(window.__captureArgs||[])[1]==='"+CID+"'"))
  check('history available',await page.locator('.proof-revision-item').count()==1)
  await page.locator('#proof-note').fill('New angle recording')
  await page.locator('#proof-save').click()
  await page.wait_for_timeout(100)
  check('revision note submitted',await page.evaluate("(window.__post||{}).note==='New angle recording'"))
  check('proof responsive',await page.evaluate('document.documentElement.scrollWidth<=innerWidth+1'))
  await browser.close()
 check('no JS errors',not errors)
 for label,value in checks:print(('PASS ' if value else 'FAIL ')+label)
 if errors:print('JavaScript exceptions',errors)
 print('V5Q.6 browser:',sum(v for _,v in checks),'/',len(checks))
 if not all(v for _,v in checks):raise SystemExit(1)
if __name__=='__main__':asyncio.run(run())
