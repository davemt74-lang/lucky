"""Isolated real Chromium tests of reconnect, state display and remote animations.
Browser mocks stand in for PHP/MySQL; actual deployed HTTPS integration remains required.
"""
import asyncio,sys
from pathlib import Path
from playwright.async_api import async_playwright
sys.path.insert(0,str(Path(__file__).parent))
import v5p_pairing as fixture

HOST_OVERRIDE="""<script>
window.mockView={id:'a'.repeat(32),game:'spin-wheel',status:'active',version:1,spinCount:0,
 slots:[{tier:'tier1',name:'Test Gift',value:1000,pending:null}],claims:[],pendingBonus:false,lastSpin:null};
const priorFetch=window.fetch;
window.fetch=async (url,opts={})=>{
 let action=new URL(String(url),'https://example.test/').searchParams.get('action');
 if(action==='last'||action==='session'){
  let response={ok:true,view:window.mockView,session:{game:'spin-wheel',claims:window.mockView.claims.map(c=>({...c,url:'',image:''})),pools:{bonus:{prizes:[]}},odds:{bonusChance:0}},viewerToken:'c'.repeat(48)};
  return new Response(JSON.stringify({ok:true,data:response}),{status:200});
 }
 return priorFetch(url,opts);
};
</script>"""
PHONE_OVERRIDE="""<script>
window.testApproved=true;
window.mockPhone={id:'a'.repeat(32),game:'spin-wheel',status:'active',version:3,spinCount:2,claimedCount:1,claimedCents:1000,
 remaining:7,pendingBonus:false,lastPrize:'Test Gift',spinning:false,canStart:false,canSpin:true};
const priorFetch=window.fetch;
window.fetch=async (url,opts={})=>{
 const action=new URL(String(url),'https://example.test/').searchParams.get('action');
 if(action==='status')return new Response(JSON.stringify({ok:true,status:'paired',deviceName:'Living Room Phone',game:window.mockPhone}));
 if(action==='command'){
  const cmd=JSON.parse(opts.body).command;
  if(cmd==='pause'){window.mockPhone.status=window.mockPhone.status==='active'?'paused':'active';window.mockPhone.canSpin=window.mockPhone.status==='active';}
  return new Response(JSON.stringify({ok:true,accepted:true,applied:true,effect:cmd==='spin'?'award':'pause',game:window.mockPhone}));
 }
 return priorFetch(url,opts);
};
</script>"""

def html(name,extra):
    return fixture.make_html(name).replace('</head>',extra+'</head>',1)
async def main():
 checks=[]
 def ok(t,v): checks.append((t,bool(v)))
 async with async_playwright() as pw:
  browser=await pw.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox'])
  ctx=await browser.new_context(viewport={'width':1280,'height':800},reduced_motion='reduce')
  page=await ctx.new_page();errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
  # Stub only the document navigation to inspect the win proof route in an isolated about:blank fixture.
  host_html=html('game',HOST_OVERRIDE).replace("location.assign('proof.php?session='","window.__captureProof('proof.php?session='")
  host_html=host_html.replace('</head>','<script>window.__captureProof=u=>window.__proofRoute=u;</script></head>',1)
  await page.set_content(host_html,wait_until='domcontentloaded')
  await page.wait_for_timeout(200)
  ok('late desktop join sees active game', 'ACTIVE GAME' in (await page.locator('#game-state').inner_text()))
  await page.evaluate('''() => {mockView.version=2;mockView.spinCount=1;
    mockView.lastSpin={type:'award',spinCount:1,index:0,slices:[{name:'Test Gift',cents:1000,tier:'tier1',kind:'prize'}],at:new Date().toISOString()};
    mockView.claims=[{id:'b'.repeat(32),name:'Test Gift',tier:'tier1',cents:1000}];}
  ''')
  await page.wait_for_timeout(1900)
  ok('phone-awarded claim does not bypass prize acceptance',await page.evaluate('!window.__proofRoute'))
  ok('host shows claimed prize after phone spin', 'Test Gift' in await page.locator('#claim-list').inner_text())
  ok('host remains active and spin-again is available',await page.locator('#spin-game').is_enabled())
  ok('host sync produced no browser exception',not errors)
  await ctx.close()
  ctx=await browser.new_context(viewport={'width':390,'height':844},reduced_motion='reduce')
  page=await ctx.new_page();errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
  markup=html('controller',PHONE_OVERRIDE)
  markup=markup.replace('<base href="https://example.test/">','<base href="https://example.test/controller.php?invite='+fixture.ID+'&key='+fixture.SECRET+'">')
  await page.set_content(markup,wait_until='domcontentloaded')
  await page.locator('#phone-name').fill('Living Room Phone')
  await page.locator('#phone-join-form button').click()
  await page.wait_for_timeout(1900)
  ok('late phone join sees current spin count', '2 spins' in await page.locator('#phone-game-state').inner_text())
  ok('phone reflects currently claimed prize', 'Test Gift' in await page.locator('#phone-game-state').inner_text())
  ok('start disabled in active game',await page.locator('[data-phone=start]').is_disabled())
  ok('spin enabled in active game',await page.locator('[data-phone=spin]').is_enabled())
  await page.evaluate("mockPhone.status='paused';mockPhone.canSpin=false")
  await page.wait_for_timeout(2000)
  ok('remote pause disables spin',await page.locator('[data-phone=spin]').is_disabled())
  ok('remote pause changes pause button to resume','Resume' in await page.locator('[data-phone=pause]').inner_text())
  await page.locator('[data-phone=pause]').click();await page.wait_for_timeout(120)
  ok('phone applies resume, not merely queued', 'Game pause' in await page.locator('#phone-status').inner_text())
  ok('resume re-enables spin',await page.locator('[data-phone=spin]').is_enabled())
  ok('phone mobile layout no overflow',await page.evaluate('document.documentElement.scrollWidth <= innerWidth + 1'))
  ok('phone state JS produced no browser exception',not errors)
  await browser.close()
 for t,v in checks:print(('PASS' if v else 'FAIL'),t)
 print('V5P.1 sync browser:',sum(v for _,v in checks),'/',len(checks))
 if not all(v for _,v in checks):raise SystemExit(1)
if __name__=='__main__':asyncio.run(main())
