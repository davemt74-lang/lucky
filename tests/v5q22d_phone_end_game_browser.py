"""V5Q.22D: synchronized phone End Game -> host dashboard, readable prize ledger."""
import asyncio
from playwright.async_api import async_playwright
from v5q6_proof_browser import BASE, html_page, SID

MOCK = r"""
window.__state='active';window.__version=1;window.__failEnd=false;
window.__claims=[
 {id:'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',prizeId:'p1',
  name:'Grand Prize — Very Long Collectible Album Bundle and Merchandise Pack',
  tier:'tier1',cents:3750,at:'2026-10-20T10:00:00Z'},
 {id:'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',prizeId:'p2',
  name:'Gift Card',tier:'tier2',cents:2000,at:'2026-10-20T10:01:00Z'}];
window.__events=[];
window.confirm=()=>true;
window.LuckyWheel=class {constructor(){this.slices=[]}setSlices(items){this.slices=items}async spinTo(){}};
window.Lucky={
 $:id=>document.getElementById(id),
 money:n=>'$'+(Number(n||0)/100).toFixed(2),
 note:(id,msg)=>{const el=document.getElementById(id);if(el)el.textContent=msg;},
 api:async(action,options={})=>{
  __events.push(action);
  const game={view:{id:'SESSION',status:__state,version:__version,
      claims:__claims,slots:[],spinCount:0,spinPending:false,lastSpin:null,
      pendingBonus:false},
    session:{id:'SESSION',claims:__claims,pools:{
       tier1:{prizes:[]},tier2:{prizes:[]},bonus:{prizes:[]}},
       odds:{bonusChance:0}},viewerToken:'c'.repeat(48)};
  // JSON-like independent HTTP payloads, never shared JS state objects.
  const copy=JSON.parse(JSON.stringify(game));
  if(action==='last')return {data:copy};
  if(action==='session')return copy;
  if(action==='end'){
   if(__failEnd)throw Error('Game end rejected.');
   __state='ended';__version++;return {result:{event:{type:'ended'}}};
  }
  if(action==='prize_offer')return {offer:null};
  if(action==='prize_packages')return {packages:[],selected:null};
  if(action==='tracks')return {tracks:[]};
  return {};
 },
 loadTracks:async()=>[],bindMusic:()=>{},beep:()=>{},speak:()=>{},
 pref:{musicOn:false,musicVolume:55},
 idempotency:()=> 'idempotency-key-123456',volume:()=>55,stopMusic:()=>{}};
window.fetch=async(url)=>{
 const action=new URL(String(url),'https://example.test/').searchParams.get('action');
 if(action==='list')return new Response(JSON.stringify({ok:true,clips:[]}));
 if(action==='summary')return new Response(JSON.stringify({ok:true,states:{}}));
 return new Response(JSON.stringify({ok:true}));
};
""".replace('SESSION',SID)
SCRIPT=(BASE/'public/game.js').read_text()
def markup():
 html=html_page('game.php',SCRIPT,MOCK)
 return html.replace('<body>','<body class="game-fullwidth">',1)

async def main():
 checks=[];errors=[]
 def check(message,yes):checks.append((message,bool(yes)))
 async with async_playwright() as pw:
  browser=await pw.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox'])
  for width in (390,1100,1440):
   page=await browser.new_page(viewport={'width':width,'height':900},reduced_motion='reduce')
   page.set_default_timeout(5500)
   page.on('pageerror',lambda exc:errors.append(str(exc)))
   await page.route('https://example.test/game.php',lambda route:route.fulfill(status=200,content_type='text/html',body=markup()))
   await page.route('https://example.test/index.php',lambda route:route.fulfill(status=200,content_type='text/html',body='<h1 id="game-dashboard">Game library</h1>'))
   await page.goto('https://example.test/game.php',wait_until='domcontentloaded')
   await page.wait_for_function("document.querySelectorAll('#claim-list .claim-proof-row').length===2")
   row=page.locator('#claim-list .claim-proof-row').first
   check(f'{width}: both claims have a media placeholder',await page.locator('.claim-proof-placeholder').count()==2)
   check(f'{width}: both prizes show amount and tier',await page.locator('#claim-list .claim-proof-meta').count()==2)
   check(f'{width}: both entries have an Edit Proof action',await page.locator('#claim-list .claim-proof-row a[href*="proof.php"]').count()==2)
   shape=await row.evaluate("""row=>{
    const box=row.getBoundingClientRect(),media=row.querySelector('.claim-proof-media').getBoundingClientRect(),
     info=row.querySelector('.claim-proof-info').getBoundingClientRect(),button=row.querySelector('.btn').getBoundingClientRect();
    return {box:{left:box.left,right:box.right},media:{left:media.left,right:media.right},
      info:{left:info.left,right:info.right},button:{left:button.left,right:button.right},
      doc:document.documentElement.scrollWidth,viewport:innerWidth};
   }""")
   check(f'{width}: media and text occupy separate consistent columns',
     shape['media']['right']<shape['info']['left'] and shape['info']['right']<=shape['box']['right']+1)
   check(f'{width}: Edit Proof action stays in text column',
     shape['button']['left']>=shape['info']['left']-1 and shape['button']['right']<=shape['box']['right']+1)
   check(f'{width}: no sideways page overflow',shape['doc']<=shape['viewport']+2)
   check(f'{width}: right-column header stays readable',await page.locator('#game-claims-title').is_visible())
   # Phone committed End Game server-side; host sees it on the next game poll.
   await page.evaluate("window.__state='ended';window.__version++")
   await page.wait_for_url('https://example.test/index.php',timeout=5000)
   check(f'{width}: remote End Game returns desktop to main library',await page.locator('#game-dashboard').count()==1)
   await page.close()
  page=await browser.new_page(viewport={'width':1280,'height':780},reduced_motion='reduce')
  page.on('pageerror',lambda exc:errors.append(str(exc)))
  await page.route('https://example.test/game.php',lambda route:route.fulfill(status=200,content_type='text/html',body=markup()))
  await page.route('https://example.test/index.php',lambda route:route.fulfill(status=200,content_type='text/html',body='<h1 id="game-dashboard">Game library</h1>'))
  await page.goto('https://example.test/game.php')
  await page.wait_for_function("document.querySelectorAll('#claim-list .claim-proof-row').length===2")
  await page.evaluate("window.__failEnd=true;document.querySelector('#end-game').click()")
  await page.wait_for_timeout(300)
  check('failed host End Game does not navigate',page.url.endswith('/game.php'))
  check('failed host End Game reports error', 'Game end rejected' in await page.locator('#game-error').inner_text())
  await page.evaluate("window.__failEnd=false;document.querySelector('#end-game').click()")
  await page.wait_for_url('https://example.test/index.php',timeout=5000)
  check('host End Game also returns to main library',await page.locator('#game-dashboard').count()==1)
  await page.close()
  historic=await browser.new_page()
  historic.on('pageerror',lambda exc:errors.append(str(exc)))
  historic_markup=markup().replace("window.__state='active';window.__version=1;","window.__state='ended';window.__version=20;")
  await historic.route('https://example.test/game.php',lambda route:route.fulfill(status=200,content_type='text/html',body=historic_markup))
  await historic.goto('https://example.test/game.php')
  await historic.wait_for_timeout(1400)
  check('historic ended session can be inspected without navigation',historic.url.endswith('/game.php'))
  check('no uncaught browser errors',not errors)
  await browser.close()
 for label,passed in checks:print(('PASS ' if passed else 'FAIL ')+label,flush=True)
 print('V5Q.22D:',sum(int(p) for _,p in checks),'/',len(checks))
 if not all(p for _,p in checks):raise SystemExit(1)

if __name__=='__main__':asyncio.run(main())
