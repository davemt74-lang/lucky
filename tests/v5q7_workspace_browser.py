"""V5Q.7: responsive tabbed host, claimed sidebar, full-screen editor, approved-phone UI D-pad."""
import asyncio,sys
from pathlib import Path
from playwright.async_api import async_playwright
sys.path.insert(0,str(Path(__file__).parent))
import v5q6_proof_browser as old
import v5q3_editor_browser as editor
BASE=Path(__file__).resolve().parents[1]
GAME_JS=(BASE/'public/game.js').read_text()
CONSOLE_JS=(BASE/'public/console.js').read_text()
checks=[]; errors=[]
def ok(label,value): checks.append((label,bool(value)))
async def main():
 async with async_playwright() as pw:
  browser=await pw.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox'])
  for width in (375,1280):
   page=await browser.new_page(viewport={'width':width,'height':900},reduced_motion='reduce')
   page.on('pageerror',lambda e:errors.append(str(e)))
   await page.set_content(old.html_page('game.php',GAME_JS+'\n'+CONSOLE_JS,old.MOCK),wait_until='domcontentloaded')
   await page.wait_for_timeout(300)
   ok(f'{width}: game default tab',await page.locator('#host-panel-game').is_visible() and await page.locator('#host-tab-game').get_attribute('aria-selected')=='true')
   ok(f'{width}: claims in separate right-column',await page.locator('.host-claims-column .game-claims-side #claim-list a').count()==1)
   ok(f'{width}: claims persist when changing tabs',await page.locator('.host-claims-column').is_visible())
   if width==1280:
    pos=await page.evaluate("({w:document.querySelector('.host-workspace-main').getBoundingClientRect(),c:document.querySelector('.host-claims-column').getBoundingClientRect()})")
    ok('desktop: claimed sidebar is on the right',pos['c']['left']>pos['w']['right'])
   await page.evaluate("document.getElementById('host-tab-game').focus()")
   ok(f'{width}: phone arrow navigates to music',await page.evaluate("LuckyHostRemote('right').then(()=>document.querySelector('#host-tab-music').getAttribute('aria-selected')==='true')"))
   ok(f'{width}: phone arrow navigates to phones',await page.evaluate("LuckyHostRemote('right').then(()=>document.querySelector('#host-tab-phones').getAttribute('aria-selected')==='true')"))
   ok(f'{width}: game wheel hidden on phones tab',await page.locator('#host-panel-game').is_hidden())
   ok(f'{width}: claimed sidebar still present on phones tab',await page.locator('.host-claims-column #claim-list a').is_visible())
   ok(f'{width}: phone selects game tab',await page.evaluate("document.getElementById('host-tab-game').focus();LuckyHostRemote('select').then(()=>document.querySelector('#host-tab-game').getAttribute('aria-selected')==='true')"))
   ok(f'{width}: phone focus skips hidden tab controls',await page.evaluate("LuckyConsole.navigable().every(el=>!el.closest('[hidden]'))"))
   ok(f'{width}: no horizontal overflow',await page.evaluate('document.documentElement.scrollWidth <= innerWidth + 2'))
   if width==1280:await page.screenshot(path='/mnt/data/Lucky-Game-Hub-V5Q7-host-preview.png',full_page=True)
   await page.close()
  for width in (375,1440):
   page=await browser.new_page(viewport={'width':width,'height':900},reduced_motion='reduce')
   page.on('pageerror',lambda e:errors.append(str(e)))
   await page.set_content(editor.html.replace('</body>','<script>'+CONSOLE_JS+'</script></body>'),wait_until='domcontentloaded')
   await page.wait_for_timeout(280)
   ok(f'{width}: editor has compact session toolbar',await page.locator('.studio-session').is_visible())
   ok(f'{width}: editor omits page-intro',await page.locator('.page-intro').count()==0)
   ok(f'{width}: timeline and inspector usable',await page.locator('#editor-timeline').is_visible() and await page.locator('#editor-form').is_visible())
   ok(f'{width}: editor full width',await page.evaluate("document.querySelector('.media-editor-studio').getBoundingClientRect().width >= innerWidth-30"))
   ok(f'{width}: no horizontal overflow',await page.evaluate('document.documentElement.scrollWidth <= innerWidth + 2'))
   ok(f'{width}: editor phone navigation callback',await page.evaluate("window.LuckyHostRemote('down').then(x=>x===true)"))
   if width==1440:await page.screenshot(path='/mnt/data/Lucky-Game-Hub-V5Q7-editor-preview.png',full_page=True)
   await page.close()
  await browser.close()
 for label,passed in checks:print(('PASS' if passed else 'FAIL'),label)
 if errors:print('JS errors',errors)
 print('V5Q7 acceptance',sum(x for _,x in checks),'/',len(checks))
 if errors or not all(v for _,v in checks):raise SystemExit(1)
if __name__=='__main__':asyncio.run(main())
