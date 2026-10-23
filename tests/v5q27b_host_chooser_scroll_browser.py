"""V5Q.27B: game package selection remains reachable on viewport-locked desktop layouts."""
import asyncio
from pathlib import Path
from playwright.async_api import async_playwright

ROOT=Path(__file__).resolve().parents[1]
CSS='\n'.join((ROOT/'public'/name).read_text() for name in ('style.css','platform.css'))
CARDS=''.join('<button type="button" class="host-package-choice"><span class="host-package-cover-fallback">✦</span><strong>Prize Package '+str(i)+'</strong></button>' for i in range(1,5))
HTML=(
 '<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>'+CSS+'</style></head>'
 '<body class="game-fullwidth game-no-live-session"><div class="shell"><header class="header">Lucky Games</header>'
 '<main><div class="host-workspace"><section class="host-workspace-main"><div class="host-workspace-panels is-wheel-active">'
 '<div role="tabpanel" id="host-panel-game"><section class="surface host-package-chooser" id="host-prize-package-chooser">'
 '<h2>Choose your prize package</h2><p>Select a cover or use the phone arrows.</p>'
 '<div class="host-package-choice-cards">'+CARDS+'</div><p>Selected: Prize Package 1</p>'
 '<button type="button" id="host-package-choice-start" class="btn btn-primary">Play selected package</button>'
 '</section><section class="surface platform-wheel-card" hidden></section></div>'
 '</div></section></div></main></div></body></html>'
)

async def main():
 checks=[]
 def check(label,result):checks.append((label,bool(result)))
 async with async_playwright() as pw:
  browser=await pw.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox'])
  for width,height in [(1920,912),(1280,650),(1080,580)]:
   page=await browser.new_page(viewport={'width':width,'height':height},reduced_motion='reduce')
   await page.set_content(HTML,wait_until='domcontentloaded')
   panel=page.locator('.host-workspace-panels')
   check(f'{width}x{height}: chooser owns vertical scrolling',await panel.evaluate("(el)=>getComputedStyle(el).overflowY==='auto'"))
   await page.locator('#host-package-choice-start').scroll_into_view_if_needed()
   visible=await page.evaluate("""() => {
    const pane=document.querySelector('.host-workspace-panels').getBoundingClientRect(),
      button=document.querySelector('#host-package-choice-start').getBoundingClientRect();
    return button.top>=pane.top-2 && button.bottom<=pane.bottom+2 && button.bottom<=innerHeight+2;
   }""")
   check(f'{width}x{height}: Play button remains reachable',visible)
   check(f'{width}x{height}: cover art is height-bounded',await page.locator('.host-package-cover-fallback').first.evaluate("(el)=>el.getBoundingClientRect().height<=380.5"))
   await page.evaluate("document.body.classList.remove('game-no-live-session')")
   check(f'{width}x{height}: active wheel remains locked',await panel.evaluate("(el)=>getComputedStyle(el).overflowY==='hidden'"))
   await page.close()
  await browser.close()
 for label,ok in checks:print(('PASS ' if ok else 'FAIL ')+label,flush=True)
 print('HOST CHOOSER SCROLL',sum(ok for _,ok in checks),'/',len(checks),flush=True)
 if not all(ok for _,ok in checks):raise SystemExit(1)

if __name__=='__main__':asyncio.run(main())
