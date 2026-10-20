"""Editor V5Q.15J media grid, compact timeline header and visual editing regressions."""
import asyncio,sys
from pathlib import Path
from playwright.async_api import async_playwright
sys.path.insert(0,str(Path(__file__).parent))
import v5q3_editor_browser as editor
async def main():
 results=[];errors=[]
 def ok(label,v):results.append((label,bool(v)))
 async with async_playwright() as pw:
  b=await pw.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox'])
  for width in [1440,375]:
   page=await b.new_page(viewport={'width':width,'height':870},reduced_motion='reduce')
   page.set_default_timeout(6000)
   page.on('pageerror',lambda e:errors.append(str(e)))
   await page.set_content(editor.html,wait_until='domcontentloaded')
   await page.wait_for_function("document.querySelectorAll('.studio-media-item').length===2")
   ok(f'{width}px monitor title row removed',await page.locator('.studio-monitor-panel .studio-panel-head').count()==0)
   ok(f'{width}px playback footer removed',await page.locator('.studio-monitor-panel .studio-playback').count()==0)
   ok(f'{width}px Play/Stop in timeline header',await page.locator('.studio-sequence-head #editor-play').is_visible() and await page.locator('.studio-sequence-head #editor-stop').is_visible())
   ok(f'{width}px Render/Export in timeline header only',await page.locator('.studio-sequence-head #open-render-dialog').is_visible() and await page.locator('.studio-topbar #open-render-dialog').count()==0)
   ok(f'{width}px compact media add buttons',await page.locator('.studio-media-add').all_text_contents()==['+','+'])
   ok(f'{width}px actual media thumbnails',await page.locator('.studio-media-item video.studio-media-preview').count()==1 and await page.locator('.studio-media-item img.studio-media-preview').count()==1)
   ok(f'{width}px media cards wrap in grid',await page.locator('#editor-media-bin').evaluate("(el)=>getComputedStyle(el).display==='grid' && getComputedStyle(el).gridTemplateColumns.split(' ').length>=1"))
   ok(f'{width}px creative tool panels present',await page.locator('#studio-filters-section,#studio-titles-section,#studio-templates-section').count()==3)
   ok(f'{width}px no document-wide horizontal overflow',await page.evaluate('document.documentElement.scrollWidth<=innerWidth+3'))
   if width==1440:
    dimensions=await page.evaluate("""() => {
      const bin=document.querySelector('.studio-bin').getBoundingClientRect();
      const monitor=document.querySelector('.studio-monitor-panel').getBoundingClientRect();
      return {bin:bin.width,monitor:monitor.width};
    }""")
    ok('media bin is significantly wider',dimensions['bin']>=330 and dimensions['monitor']>dimensions['bin'])
    await page.locator('.editor-clip-select').first.click()
    await page.locator('#editor-close-inspector').click()
    await page.locator('#editor-look').select_option('mono')
    await page.locator('#editor-apply-look').click()
    ok('filter applied to selected timeline clip',await page.locator('#editor-look').input_value()=='mono')
    await page.locator('#studio-titles-section summary').click()
    await page.locator('#editor-overlay-text').fill("Grand Prize: Winner's!")
    await page.locator('#editor-overlay-position').select_option('top')
    await page.locator('#editor-overlay-size').select_option('large')
    await page.locator('#editor-apply-overlay').click()
    await page.locator('#editor-save-project').click()
    await page.wait_for_function('demo.saved!==null')
    ok('filter and title saved in timeline project',await page.evaluate("""() =>
      demo.saved[0].lookPreset==='mono' && demo.saved[0].overlayText==="Grand Prize: Winner's!" &&
      demo.saved[0].overlayPosition==='top' && demo.saved[0].overlaySize==='large'"""))
    await page.locator('#studio-templates-section summary').click()
    await page.locator('#editor-template').select_option('film')
    await page.locator('#editor-template-all').click()
    await page.locator('#editor-save-project').click()
    await page.wait_for_function("demo.saved?.every(c=>c.lookPreset==='cinematic')")
    ok('starter template applies look to all clips',await page.evaluate("demo.saved.every(c=>c.lookPreset==='cinematic')"))
    await page.locator('#open-render-dialog').click()
    ok('Render/Export dialog opens from timeline',await page.locator('#render-dialog').is_visible())
    await page.locator('#close-render-dialog').click()
   await page.close()
  await b.close()
 ok('no browser exceptions',not errors)
 for name,pass_ in results:print(('PASS' if pass_ else 'FAIL'),name,flush=True)
 if errors:print('Browser errors:',errors,flush=True)
 print('V5Q.15J:',sum(v for _,v in results),'/',len(results),flush=True)
 if not all(v for _,v in results):raise SystemExit(1)
if __name__=='__main__':asyncio.run(main())
