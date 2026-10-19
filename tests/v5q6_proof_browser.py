"""V5Q.6 browser contracts for top claims, tab controls and claim-level proof history."""
import asyncio,re
from pathlib import Path
from playwright.async_api import async_playwright
BASE=Path(__file__).resolve().parents[1]
SID='c'*32;CID='d'*32
CSS=(BASE/'public/style.css').read_text()+'\n'+(BASE/'public/platform.css').read_text()
def html_page(filename,js,mock):
 markup=(BASE/filename).read_text().split('?>',1)[1].split('<?php shellEnd()',1)[0]
 markup=markup.replace('<?php pairingWidget(true); ?>','<section class="phone-pair-panel">Connect a phone</section>')
 markup=re.sub(r'<script\b[^>]*>.*?</script>', '',markup,flags=re.S|re.I)
 return '<!doctype html><html><head><style>'+CSS+'</style><script>'+mock+'</script></head><body>'+markup+'<script>'+js+'</script></body></html>'
MOCK='''
window.__errors=[];
window.__claims=[{id:'CLAIM_ID',prizeId:'p1',name:'Camera Prize',tier:'tier1',cents:1250,at:'2026-10-19T12:00:00Z'}];
window.__state='active';window.__version=1;
window.LuckyWheel=class {setSlices(){} async spinTo(){}};
window.Lucky={ $:id=>document.getElementById(id),money:n=>'$'+(n/100).toFixed(2),dollars:n=>(n/100).toFixed(2),
 note:(id,s)=>{const n=document.getElementById(id);if(n)n.textContent=s;},getMe:async()=>({csrf:'test'}),
 api:async(a)=>{if(a==='last')return {data:{view:{id:'SESSION_ID',status:__state,version:__version,claims:__claims,slots:[],spinCount:0},session:{id:'SESSION_ID',pools:{tier1:{prizes:[]},bonus:{prizes:[]}},claims:__claims}}};if(a==='session')return {session:{id:'SESSION_ID',claims:__claims,pools:{tier1:{prizes:[{id:'p1',proofAngles:[]}]}}},view:{id:'SESSION_ID',status:'active',version:1}};return {};},
 loadTracks:async()=>[],bindMusic:()=>{},beep:()=>{},speak:()=>{},pref:{musicOn:false},idempotency:()=>'k'};
window.LuckyCapture={show:(...a)=>window.__captureArgs=a,release:()=>{}};
const nativeFetch=window.fetch.bind(window);
window.fetch=async (input,opts={})=>{const url=new URL(String(input),'https://example.test/');if(url.pathname.endsWith('claim-proof.php')){
 if(opts.method==='POST'){window.__post=JSON.parse(opts.body);return new Response(JSON.stringify({ok:true,status:{needsRevision:false,latestRevision:1}}),{headers:{'Content-Type':'application/json'}});}
 if(url.searchParams.get('action')==='summary')return new Response(JSON.stringify({ok:true,states:{'CLAIM_ID':{needsRevision:true,latestRevision:1}}}));
 return new Response(JSON.stringify({ok:true,status:{needsRevision:true,requestNote:'Needs a clearer angle',latestRevision:1},clipCount:1,revisions:[{id:'r',number:1,at:'2026-10-19',note:'Original proof',clips:[{title:'Hero view',kind:'video',src:'api/claim-media.php?action=stream&id=x'}]}]}));}
 return nativeFetch(input,opts);};
'''.replace('CLAIM_ID',CID).replace('SESSION_ID',SID)
async def main():
 tests=[];errors=[]
 def ok(label,val):tests.append((label,bool(val)))
 async with async_playwright() as pw:
  browser=await pw.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox'])
  for width in [390,1280]:
   page=await browser.new_page(viewport={'width':width,'height':850},reduced_motion='reduce')
   page.set_default_timeout(4000)
   page.on('pageerror',lambda err:errors.append(str(err)))
   await page.set_content(html_page('game.php',(BASE/'public/game.js').read_text(),MOCK),wait_until='domcontentloaded')
   await page.wait_for_timeout(400)
   ok(f'{width} claims in right workspace column',await page.locator('.host-claims-column .game-claims-side').count()==1)
   ok(f'{width} prize Edit proof available',await page.locator('#claim-list a[href*="proof.php"]').count()==1)
   ok(f'{width} revision request badge',await page.locator('#claim-list .proof-revision-needed').count()==1)
   ok(f'{width} game tab selected by default',await page.locator('#host-tab-game').get_attribute('aria-selected')=='true')
   ok(f'{width} phone connector tab restored',await page.locator('#host-panel-phones').count()==1)
   await page.locator('#host-tab-game').press('ArrowRight')
   ok(f'{width} arrow selects music tab',await page.locator('#host-panel-music').is_visible())
   await page.locator('#host-tab-music').press('ArrowRight')
   ok(f'{width} arrows navigate to phone connector tab',await page.locator('#host-panel-phones').is_visible())
   await page.locator('#host-tab-phones').press('ArrowRight')
   ok(f'{width} arrows wrap back to game tab',await page.locator('#host-panel-game').is_visible())
   ok(f'{width} no horizontal overflow',await page.evaluate('document.documentElement.scrollWidth <= innerWidth+1'))
   await page.close()
  page=await browser.new_page(viewport={'width':390,'height':844})
  page.set_default_timeout(4000)
  page.on('pageerror',lambda err:errors.append(str(err)))
  proof_script=(BASE/'public/proof.js').read_text().replace('new URL(location.href).searchParams', f"new URLSearchParams('session={SID}&claim={CID}')")
  markup=html_page('proof.php',proof_script,MOCK)
  await page.set_content(markup,wait_until='domcontentloaded')
  await page.wait_for_timeout(350)
  ok('claim context loaded on proof page',await page.locator('#proof-prize-title').inner_text()=='Camera Prize')
  ok('baseline created idempotently',await page.evaluate("window.__post?.claim==='"+CID+"'"))
  ok('capture controls receive exact claim',await page.evaluate("window.__captureArgs?.[1]==='"+CID+"'"))
  ok('revision history shown',await page.locator('.proof-revision-item').count()==1)
  await page.locator('#proof-history-disclosure > summary').click()
  await page.locator('#proof-note').fill('Reshot front angle')
  await page.locator('#proof-save').click()
  await page.wait_for_timeout(100)
  ok('save sent with revision note',await page.evaluate("window.__post?.note==='Reshot front angle'"))
  ok('proof view has no horizontal overflow',await page.evaluate('document.documentElement.scrollWidth <= innerWidth+1'))
  await browser.close()
 ok('no uncaught JS exceptions',not errors)
 for label,v in tests:print(('PASS' if v else 'FAIL'),label)
 if errors:print('Browser errors:',errors)
 print('V5Q6 browser:',sum(x for _,x in tests),'/',len(tests))
 if not all(v for _,v in tests):raise SystemExit(1)
if __name__=='__main__':asyncio.run(main())
