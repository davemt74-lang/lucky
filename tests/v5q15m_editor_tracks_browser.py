"""V5Q.15M: T1 and B1 are real, editable, independently persisted timeline layers."""
import asyncio,sys
from pathlib import Path
from playwright.async_api import async_playwright
sys.path.insert(0,str(Path(__file__).parent))
import v5q3_editor_browser as fixture
SONG='d'*32
async def main():
 checks=[];errors=[]
 def ok(name,condition):checks.append((name,bool(condition)))
 async with async_playwright() as pw:
  b=await pw.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox'])
  p=await b.new_page(viewport={'width':1440,'height':900},reduced_motion='reduce')
  p.set_default_timeout(6500);p.on('pageerror',lambda e:errors.append(str(e)))
  await p.set_content(fixture.html,wait_until='domcontentloaded')
  await p.wait_for_function("document.querySelectorAll('.editor-clip').length===2 && document.querySelector('#timeline-music-select').options.length===2")
  await p.evaluate("""() => {
   const fetchOrig=window.fetch;window.demo.layersSaved=null;
   window.fetch=async(url,opts={})=>{
    const act=new URL(String(url),'https://example.test').searchParams.get('action');
    if(act==='save_project')window.demo.layersSaved=JSON.parse(opts.body).layers;
    return fetchOrig(url,opts);
   };
  }""")
  ok('T1 and B1 timeline lanes exist',await p.locator('#editor-titles-lane,#editor-background-lane').count()==2)
  ok('T1 and B1 source controls available',await p.locator('#timeline-add-title,#timeline-apply-music').count()==2)
  await p.locator('[data-studio-pane="titles"]').click()
  await p.locator('#timeline-title-text').fill('Grand Prize winner')
  await p.locator('#timeline-title-start').fill('1.0')
  await p.locator('#timeline-title-duration').fill('3.0')
  await p.locator('[data-timed-title-style="headline"]').click()
  await p.locator('#timeline-add-title').click()
  ok('timed T1 block rendered independently from V1',await p.locator('.studio-title-block').count()==1 and await p.locator('.editor-clip').count()==2)
  await p.locator('[data-studio-pane="audio"]').click()
  await p.locator('#timeline-music-select').select_option(SONG)
  await p.locator('#timeline-music-start').fill('0.5')
  await p.locator('#timeline-music-end').fill('8.5')
  await p.locator('#timeline-music-offset').fill('0.2')
  await p.locator('#timeline-music-volume').fill('25')
  await p.locator('#timeline-apply-music').click()
  ok('B1 shows uploaded song as independent block',await p.locator('.studio-background-block').count()==1)
  await p.locator('#editor-save-project').click()
  await p.wait_for_function('demo.layersSaved!==null')
  ok('title start, duration and position persist',await p.evaluate("""() => demo.layersSaved.titles[0].startMs===1000&&demo.layersSaved.titles[0].durationMs===3000&&demo.layersSaved.titles[0].position==='top'"""))
  ok('music source, trimmed start/end, and gain persist',await p.evaluate("""() => demo.layersSaved.background.startMs===500&&demo.layersSaved.background.endMs===8500&&demo.layersSaved.background.trackId==='d'.repeat(32)&&demo.layersSaved.background.volume===25"""))
  await p.locator('#open-render-dialog').click()
  await p.locator('#render-queue').click()
  await p.wait_for_function('demo.queued!==null')
  ok('render queue carries independent text and music layer snapshot',await p.evaluate("""() => demo.queued.layers.titles.length===1&&demo.queued.layers.background.trackId==='d'.repeat(32)"""))
  await p.locator('#close-render-dialog').click()
  await p.locator('[data-studio-pane="titles"]').click()
  await p.locator('#timeline-delete-title').click()
  ok('delete T1 title preserves original footage',await p.locator('.studio-title-block').count()==0 and await p.locator('.editor-clip').count()==2)
  await p.locator('[data-studio-pane="audio"]').click()
  await p.locator('#timeline-clear-music').click()
  ok('remove B1 soundtrack leaves media library intact',await p.locator('.studio-background-block').count()==0 and await p.locator('#timeline-music-select option').count()==2)
  await p.close();await b.close()
 ok('no unhandled JavaScript error',not errors)
 for name,value in checks:print(('PASS ' if value else 'FAIL ')+name,flush=True)
 if errors:print(errors,flush=True)
 print('T1/B1 editor:',sum(x for _,x in checks),'/',len(checks),flush=True)
 if not all(x for _,x in checks):raise SystemExit(1)
if __name__=='__main__':asyncio.run(main())
