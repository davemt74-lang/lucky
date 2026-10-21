"""V5Q.15K: four global-header host tabs, Live Display, viewport-sized wheel."""
import asyncio,sys
from pathlib import Path
from playwright.async_api import async_playwright
sys.path.insert(0,str(Path(__file__).parent))
import v5q6_proof_browser as game
ROOT=Path(__file__).resolve().parents[1]
SCRIPT=(ROOT/'public/game.js').read_text()
MOCK=game.MOCK+"""
const __origHostApi=window.Lucky.api;
window.Lucky.api=async (...args)=>{
 const reply=await __origHostApi(...args);
 if(args[0]==='current'&&reply.data)reply.data.viewerToken='test-token';
 return reply;
};
"""
def markup():
 html=game.html_page('game.php',SCRIPT,MOCK)
 return html.replace('<head>','<head><base href="https://lucky.test/">').replace('<body>','''<body class="game-fullwidth"><div class="shell">
 <header class="header"><a class="brand" href="index.php">LUCKY GAMES</a>
 <nav class="nav" aria-label="Main navigation"><a href="index.php">Games</a><a href="shop.php">Shop</a><a href="devices.php">Devices</a><a href="catalog-admin.php">Products</a></nav>
 <details class="account-menu"><summary>Host avatar</summary><div class="account-dropdown"><a href="admin.php">Admin Studio</a><a href="account.php">Account settings</a></div></details></header><main>''').replace('</body>','</main><footer class="footer">Lucky Games</footer></div></body>')
async def main():
 checks=[];errors=[]
 def check(label,passed):checks.append((label,bool(passed)))
 async with async_playwright() as pw:
  browser=await pw.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox'])
  for width,height in ((390,844),(768,900),(1280,580),(1280,720),(1440,620),(1440,900),(1920,640),(1920,1080)):
   page=await browser.new_page(viewport={'width':width,'height':height},reduced_motion='reduce')
   page.set_default_timeout(6000)
   page.on('pageerror',lambda e:errors.append(str(e)))
   await page.set_content(markup(),wait_until='domcontentloaded')
   await page.wait_for_function("document.querySelector('.header>.host-header-tabs')!==null")
   await page.wait_for_timeout(160)
   check(f'{width}x{height}: all four tabs are inside the header',await page.locator('.header .host-main-tabs [role=tab]').count()==4)
   check(f'{width}x{height}: no duplicate tabs in game canvas',await page.locator('.host-workspace .host-main-tabs').count()==0)
   check(f'{width}x{height}: no horizontal scrolling',await page.evaluate('document.documentElement.scrollWidth<=innerWidth+2'))
   geom=await page.evaluate("""() => {
    const r=s=>document.querySelector(s).getBoundingClientRect();
    return {wheel:r('.platform-wheel-wrap'),nav:r('.header>.nav'),tabs:r('.header>.host-header-tabs'),account:r('.header>.account-menu'),
     bodyScroll:document.documentElement.scrollHeight,visible:innerHeight,docWidth:document.documentElement.scrollWidth};
   }""")
   check(f'{width}x{height}: spin wheel completely visible within viewport',geom['wheel']['bottom']<=height-20 and geom['wheel']['width']<=width-12)
   check(f'{width}x{height}: main menu right-aligned ahead of account',geom['nav']['right']<=geom['account']['right']+2)
   if width>=1061:
    check(f'{width}x{height}: no page vertical scroll',geom['bodyScroll']<=height+4)
    wheel=await page.evaluate("""() => {
      const box=document.querySelector('.host-workspace-panels'),ring=document.querySelector('.platform-wheel-wrap');
      return {scrollHeight:box.scrollHeight,clientHeight:box.clientHeight,
              overflow:getComputedStyle(box).overflowY,active:box.classList.contains('is-wheel-active'),
              wheelBottom:ring.getBoundingClientRect().bottom,panelBottom:box.getBoundingClientRect().bottom,
              size:ring.getBoundingClientRect().height};
    }""")
    check(f'{width}x{height}: no game panel vertical scrollbar',
      wheel['active'] and wheel['overflow']=='hidden' and wheel['scrollHeight']<=wheel['clientHeight']+2)
    check(f'{width}x{height}: wheel fits actual panel height',
      wheel['wheelBottom']<=wheel['panelBottom']-12 and wheel['size']>=120)
   await page.wait_for_function("document.querySelector('#display-game-status').textContent==='Active' && document.querySelector('#share-display').hidden===false",timeout=6000)
   await page.locator('#host-tab-display').click()
   check(f'{width}x{height}: live display fourth tab opens',await page.locator('#host-panel-display').is_visible() and await page.locator('#host-panel-game').is_hidden())
   check(f'{width}x{height}: audience display link available',await page.locator('#share-display').is_visible() and 'display.php' in await page.locator('#viewer-link').input_value())
   check(f'{width}x{height}: live status shows active game',await page.locator('#display-game-status').inner_text()=='Active')
   check(f'{width}x{height}: prize claims column remains visible',await page.locator('.host-claims-column').is_visible())
   await page.locator('#host-tab-game').click()
   check(f'{width}x{height}: reselecting game restores wheel',await page.locator('#host-panel-game').is_visible())
   if width>=1061:
    await page.wait_for_timeout(70)
    check(f'{width}x{height}: Game tab restores scroll-free stage',await page.evaluate("""() => {
       const p=document.querySelector('.host-workspace-panels');
       return p.classList.contains('is-wheel-active')&&getComputedStyle(p).overflowY==='hidden';
    }"""))
   await page.close()
  await browser.close()
 check('no uncaught JavaScript exceptions',not errors)
 layout=(ROOT/'lib/Layout.php').read_text()
 account=(ROOT/'lib/AccountUi.php').read_text()
 check('global main navigation no longer exposes Admin Studio',"'Admin Studio</a>'" not in layout)
 check('Admin Studio moved into user profile dropdown','href="admin.php">⚙ Admin Studio' in account)
 for label,p in checks:print(('PASS ' if p else 'FAIL ')+label,flush=True)
 if errors:print('Exceptions:',errors,flush=True)
 print('V5Q15K',sum(x for _,x in checks),'/',len(checks),flush=True)
 if not all(x for _,x in checks):raise SystemExit(1)
if __name__=='__main__':asyncio.run(main())
