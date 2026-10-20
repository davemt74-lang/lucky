"""V5Q.15Q: visual tools affect selected V1 clip immediately; T1 lives above video."""
import asyncio
from playwright.async_api import async_playwright
import v5q3_editor_browser as fixture

async def main():
 checks=[];errors=[]
 def ok(name,p):checks.append((name,bool(p)))
 async with async_playwright() as pw:
  browser=await pw.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox'])
  page=await browser.new_page(viewport={'width':1440,'height':900},reduced_motion='reduce')
  page.set_default_timeout(8000)
  page.on('pageerror',lambda e:errors.append(str(e)))
  await page.set_content(fixture.html,wait_until='domcontentloaded')
  await page.wait_for_function("document.querySelectorAll('.editor-clip').length===2")
  ok('clip selection does not force slide-out inspector',await page.locator('#clip-inspector').get_attribute('aria-hidden')=='true')
  ok('timed text timeline physically above video V1',await page.evaluate("""() => {
    const a=document.querySelector('.studio-titles-lane'),b=document.querySelector('#editor-timeline');
    return !!(a.compareDocumentPosition(b)&Node.DOCUMENT_POSITION_FOLLOWING);
  }"""))
  await page.locator('.editor-clip-select').first.click()
  ok('timeline clip opens live monitor preview',await page.locator('#editor-preview video').count()==1)
  await page.locator('[data-studio-pane="filters"]').click()
  ok('six filter cards replace visible dropdown',await page.locator('[data-look-preset]').count()==6 and await page.locator('#editor-look').is_hidden())
  await page.locator('[data-look-preset="mono"]').click()
  ok('filter card directly applies grayscale to preview',await page.locator('#editor-preview video').evaluate("el=>el.style.filter.includes('grayscale')"))
  ok('chosen filter card active',await page.locator('[data-look-preset="mono"]').get_attribute('aria-pressed')=='true')
  await page.locator('[data-studio-pane="overlays"]').click()
  ok('overlay style cards available',await page.locator('[data-overlay-style]').count()>=4 and await page.locator('#editor-overlay-position').is_hidden())
  await page.locator('#editor-overlay-text').fill('Winner reveal')
  await page.locator('[data-overlay-style="headline"]').click()
  ok('text style card immediately adds preview caption',await page.locator('#editor-preview .studio-monitor-caption').inner_text()=='Winner reveal')
  ok('overlay positioned on top',await page.locator('#editor-preview .studio-monitor-caption').get_attribute('data-position')=='top')
  await page.locator('#editor-save-project').click()
  await page.wait_for_function('demo.saved !== null')
  ok('filtered clip and overlay persisted',await page.evaluate("""() => demo.saved[0].lookPreset==='mono' && demo.saved[0].overlayText==='Winner reveal' && demo.saved[0].overlayPosition==='top'"""))
  await page.locator('[data-studio-pane="templates"]').click()
  ok('template starter cards displayed',await page.locator('[data-template-preset]').count()==5 and await page.locator('#editor-template').is_hidden())
  await page.locator('#editor-new-template').click()
  ok('new template opens separate toolbox pane',await page.locator('#studio-pane-template-create').is_visible() and await page.locator('#editor-template-name').is_visible() and await page.locator('#studio-pane-templates').is_hidden())
  await page.locator('#editor-cancel-template').click()
  ok('return to template gallery',await page.locator('#studio-pane-templates').is_visible())
  await page.locator('[data-studio-pane="titles"]').click()
  ok('timed title style cards displayed',await page.locator('[data-timed-title-style]').count()>=4)
  await page.locator('[data-timed-title-style="headline"]').click()
  await page.locator('#timeline-title-text').fill('Timed title')
  await page.locator('#timeline-title-start').fill('0')
  await page.locator('#timeline-title-duration').fill('2')
  await page.locator('#timeline-add-title').click()
  ok('T1 timed title block works above V1',await page.locator('#editor-titles-lane .studio-title-block').count()==1)
  await page.locator('.editor-clip-select').first.click()
  ok('selected V1 preview shows active T1 title',await page.locator('#editor-preview .studio-timeline-preview-title').count()==1)
  await page.evaluate("""() => {
    const original=window.fetch;
    window.fetch=async(...args)=>{
      if(String(args[0]).includes('action=save_project'))return new Response(JSON.stringify({ok:false,error:'Database unavailable'}),{status:409,headers:{'Content-Type':'application/json'}});
      return original(...args);
    };
  }""")
  await page.locator('[data-studio-pane="filters"]').click()
  await page.locator('[data-look-preset="vivid"]').click()
  baseline=await page.evaluate("demo.calls.filter(x=>x==='save_project').length")
  await page.locator('#open-render-dialog').click()
  await page.locator('#render-queue').click()
  await page.wait_for_function("(n)=>demo.calls.filter(x=>x==='save_project').length>n",arg=baseline)
  await page.wait_for_function("document.querySelector('#render-feedback')?.textContent?.includes('Could not save')")
  ok('failed project save blocks MP4 queue',await page.evaluate("demo.queued===null && !demo.calls.includes('queue')"))
  ok('export dialog visibly reports failed save',await page.locator('#render-feedback').is_visible() and 'Could not save' in await page.locator('#render-feedback').inner_text())
  await page.locator('#close-render-dialog').click()
  ok('no browser errors',not errors)
  # The TV/desktop wheel stage stays clear while fallback host controls remain reachable.
  from pathlib import Path
  import re
  root=Path(__file__).resolve().parents[1]
  fragment=(root/'game.php').read_text().split('?>',1)[1].split('<?php shellEnd()',1)[0]
  fragment=re.sub(r'<script[^>]*></script>','',fragment,flags=re.I)
  style=(root/'public/platform.css').read_text()
  host=await browser.new_page(viewport={'width':1280,'height':900})
  await host.set_content('<html><head><style>'+style+'</style></head><body class="game-fullwidth">'+fragment+'</body></html>')
  ok('wheel has no surrounding game-state text',await host.locator('#host-panel-game #game-state').count()==0)
  ok('wheel has no visible host control buttons beneath it',await host.locator('#host-panel-game .platform-control-row').count()==0)
  ok('host controls remain in Game menu',await host.locator('#console-menu .platform-control-row button').count()==4)
  ok('postgame Review highlights moved to Game menu',await host.locator('#console-menu #postgame-review').count()==1)
  ok('prize announcement anchored inside wheel',await host.locator('.platform-wheel-wrap #game-message').count()==1)
  await host.locator('#game-message').evaluate("(el)=>{el.dataset.highlight='true';el.textContent='Grand prize!';}")
  ok('winning result remains visible on wheel',await host.locator('#game-message').is_visible())
  await host.close()
  await browser.close()
 for name,value in checks:print(('PASS ' if value else 'FAIL ')+name,flush=True)
 if errors:print('JS errors:',errors,flush=True)
 print('V5Q15Q:',sum(v for _,v in checks),'/',len(checks),flush=True)
 if not all(v for _,v in checks):raise SystemExit(1)
if __name__=='__main__':asyncio.run(main())
