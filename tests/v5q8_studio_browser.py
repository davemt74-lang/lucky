"""Browser contract for genuinely different editing workspace and host chrome."""
import asyncio,sys
from pathlib import Path
from playwright.async_api import async_playwright
sys.path.insert(0,str(Path(__file__).parent))
import v5q3_editor_browser as editor
import v5q6_proof_browser as game
BASE=Path(__file__).resolve().parents[1]
checks=[]
def ok(label,value):checks.append((label,bool(value)))
async def run():
 async with async_playwright() as pw:
  browser=await pw.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox'])
  for width in (375,1440):
   page=await browser.new_page(viewport={'width':width,'height':900},reduced_motion='reduce')
   page.set_default_timeout(5000)
   await page.set_content(editor.html,wait_until='domcontentloaded',timeout=12000)
   await page.wait_for_timeout(260)
   ok(f'{width}: no document title block',await page.locator('.page-intro').count()==0)
   ok(f'{width}: media bin contains sources',await page.locator('.studio-media-item').count()==2)
   ok(f'{width}: preview and clip inspector',await page.locator('.studio-monitor').is_visible() and await page.locator('#editor-open-inspector').is_visible())
   ok(f'{width}: horizontal time ruler and sequence lane',await page.locator('.studio-ruler-tick').count()>0 and await page.locator('.studio-track .editor-clip').count()==2)
   ok(f'{width}: audio lane mirrors sequence',await page.locator('.studio-audio-cell').count()==2)
   ok(f'{width}: no horizontal document overflow',await page.evaluate('document.documentElement.scrollWidth <= innerWidth + 2'))
   if width==1440:
    positions=await page.evaluate('''() => {let el=sel=>document.querySelector(sel).getBoundingClientRect();return {bin:el('.studio-bin').left,mon:el('.studio-monitor-panel').left,ins:el('.studio-inspector').left,timeline:el('.studio-sequence').top,monitorBottom:el('.studio-monitor-panel').bottom}}''')
    ok('desktop source bin / monitor / inspector arranged left-to-right',positions['bin']<positions['mon']<positions['ins'])
    ok('desktop timeline is below preview',positions['timeline']>=positions['monitorBottom'])
    await page.screenshot(path='/mnt/data/Lucky-Game-Hub-V5Q8-editor-preview.png',full_page=True,timeout=12000)
    await page.locator('[data-media-filter="photo"]').click(timeout=5000)
    ok('media bin filter isolates photos',await page.locator('.studio-media-item').count()==1)
    await page.locator('.studio-media-add').click(timeout=5000)
    ok('media bin reinserts source non-destructively',await page.locator('.studio-track .editor-clip').count()==3)
    ok('source bin originals retained',await page.locator('#editor-bin-count').inner_text()=='2 files')
    await page.locator('#editor-save-project').click(timeout=5000)
    await page.wait_for_timeout(60)
    ok('source-bin insertion persisted in edit plan',await page.evaluate('demo.saved?.length === 3'))
   await page.close()
  host=await browser.new_page(viewport={'width':1280,'height':850})
  await host.set_content(game.html_page('game.php',(BASE/'public/game.js').read_text()+'\n'+(BASE/'public/console.js').read_text(),game.MOCK),wait_until='domcontentloaded',timeout=12000)
  await host.wait_for_timeout(260)
  ok('old GAME 1 LIVE HOST header removed',await host.get_by_text('GAME 1 · LIVE HOST').count()==0)
  ok('game tabs still present',await host.locator('#host-tab-game').is_visible())
  ok('compact edit video link preserved',await host.locator('#open-game-editor').count()==1)
  ok('claimed sidebar still present',await host.locator('.host-claims-column').count()==1)
  await host.close();await browser.close()
 for label,val in checks:print(('PASS' if val else 'FAIL'),label,flush=True)
 print('V5Q8',sum(v for _,v in checks),'/',len(checks),flush=True)
 if not all(x for _,x in checks):raise SystemExit(1)
if __name__=='__main__':asyncio.run(run())
