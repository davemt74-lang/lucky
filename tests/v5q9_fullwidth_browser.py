"""Host full-width layout and prize-value balance contracts (V5Q.9)."""
import asyncio, sys
from pathlib import Path
from playwright.async_api import async_playwright
sys.path.insert(0, str(Path(__file__).parent))
from v5q6_proof_browser import MOCK, html_page, BASE

JS=(BASE/'public/game.js').read_text()+'\n'+(BASE/'public/console.js').read_text()
# The mock already has a $12.50 Tier1 claim. Remaining configured prize
# values: $30.00 Tier1, $12.50 Tier2, $5.00 Bonus = $47.50.
MOCK_BALANCE=MOCK.replace("tier1:{prizes:[]},bonus:{prizes:[]}", "tier1:{prizes:[{id:'p1',cents:1250},{id:'p3',cents:3000}]},tier2:{prizes:[{id:'p2',cents:1250}]},bonus:{prizes:[{id:'b1',cents:500}]}")
checks=[];errs=[]
def ck(name, truth):checks.append((name,bool(truth)))

async def main():
  async with async_playwright() as p:
    b=await p.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox'])
    for width in (375,768,1280,1920):
      page=await b.new_page(viewport={'width':width,'height':900},reduced_motion='reduce')
      page.on('pageerror',lambda e: errs.append(str(e)))
      markup=html_page('game.php', JS, MOCK_BALANCE)
      # Include the host-page shell and header, which shellStart renders in PHP.
      markup=markup.replace('<body>', '''<body class="game-fullwidth"><div class="shell"><header class="header"><a class="brand" href="index.php">LUCKY GAMES</a><nav class="nav" aria-label="Main navigation"><a href="index.php">Games</a><a href="game.php">Host Game</a></nav></header><main>''').replace('</body>','</main></div></body>')
      await page.set_content(markup,wait_until='domcontentloaded')
      await page.wait_for_timeout(260)
      text=await page.locator('#session-totals').inner_text()
      ck(f'{width} money displayed correctly',text=='1 claimed · $12.50 awarded · $47.50 available to win')
      ck(f'{width} no quick links above wheel',await page.locator('.host-compact-actions').count()==0)
      ck(f'{width} game menu still holds editing tools',await page.locator('#console-menu #open-game-editor').count()==1)
      ck(f'{width} no horizontal overflow',await page.evaluate('document.documentElement.scrollWidth<=innerWidth+2'))
      if width>=1100:
        widths=await page.evaluate("""() => ({head:document.querySelector('.header').getBoundingClientRect().width,
          shell:document.querySelector('.shell').getBoundingClientRect().width,
          main:document.querySelector('.host-workspace-main').getBoundingClientRect(),
          aside:document.querySelector('.host-claims-column').getBoundingClientRect()})""")
        ck(f'{width} header spans browser',widths['head']>=width-70)
        ck(f'{width} claim list right of wheel',widths['aside']['left']>=widths['main']['right'])
      else:
        ck(f'{width} claimed list visible',await page.locator('.host-claims-column').is_visible())
      if width==1280:
        await page.evaluate("document.querySelector('#host-tab-game').focus()")
        ck('phone D-pad switches tabs',await page.evaluate("LuckyHostRemote('right').then(()=>document.querySelector('#host-tab-music').getAttribute('aria-selected')==='true')"))
        ck('menu can be opened without quick links',await page.evaluate("LuckyHostRemote('menu').then(()=>document.querySelector('#console-menu').open)"))
      await page.close()
    await b.close()
  for label,valid in checks:print(('PASS ' if valid else 'FAIL ')+label,flush=True)
  if errs:print('JS errors:',errs)
  print(f'V5Q9 acceptance: {sum(v for _,v in checks)}/{len(checks)}',flush=True)
  if errs or not all(v for _,v in checks):raise SystemExit(1)

if __name__=='__main__':asyncio.run(main())
