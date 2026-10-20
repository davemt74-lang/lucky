"""V5Q.15N: save and reuse multi-track template without copying another game's media."""
import asyncio,sys
from pathlib import Path
from playwright.async_api import async_playwright
sys.path.insert(0,str(Path(__file__).parent))
import v5q3_editor_browser as fixture
async def main():
 checks=[];errors=[]
 def ok(label,v):checks.append((label,bool(v)))
 async with async_playwright() as pw:
  browser=await pw.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox'])
  p=await browser.new_page(viewport={'width':1440,'height':900},reduced_motion='reduce')
  p.set_default_timeout(8000);p.on('pageerror',lambda e:errors.append(str(e)))
  await p.set_content(fixture.html,wait_until='domcontentloaded')
  await p.wait_for_function("document.querySelectorAll('.editor-clip').length===2")
  await p.evaluate("""() => {
   const native=window.fetch;window.demo.templates=[];window.demo.savedTemplatePayload=null;
   window.fetch=async(url,opts={})=>{
    const action=new URL(String(url),'https://example.test/').searchParams.get('action');
    if(action==='templates')return new Response(JSON.stringify({ok:true,templates:demo.templates}));
    if(action==='save_template'){
     const body=JSON.parse(opts.body);demo.savedTemplatePayload=body.template;
     const row={id:'f'.repeat(32),name:body.name,template:body.template};demo.templates.push(row);
     return new Response(JSON.stringify({ok:true,template:row}));
    }
    if(action==='delete_template'){demo.templates=[];return new Response(JSON.stringify({ok:true,deleted:true}));}
    return native(url,opts);
   };
  }""")
  await p.locator('.editor-clip-select').first.click()
  
  await p.locator('[data-studio-pane="filters"]').click()
  await p.locator('[data-look-preset="warm"]').click()
  await p.locator('#editor-apply-look').click()
  await p.locator('[data-studio-pane="titles"]').click()
  await p.locator('#timeline-title-text').fill('Big Winner!')
  await p.locator('#timeline-title-start').fill('1')
  await p.locator('#timeline-title-duration').fill('3')
  await p.locator('#timeline-add-title').click()
  await p.locator('[data-studio-pane="audio"]').click()
  await p.locator('#timeline-music-select').select_option('d'*32)
  await p.locator('#timeline-music-end').fill('9')
  await p.locator('#timeline-apply-music').click()
  await p.locator('[data-studio-pane="templates"]').click()
  await p.locator('#editor-new-template').click()
  await p.locator('#editor-template-name').fill('Home console recap')
  await p.locator('#editor-save-template').click()
  await p.wait_for_function('demo.savedTemplatePayload!==null')
  ok('template stores look/title/background',await p.evaluate("""() => demo.savedTemplatePayload.looks[0]==='warm'
    && demo.savedTemplatePayload.titles.length===1 && demo.savedTemplatePayload.background.trackId==='d'.repeat(32)"""))
  ok('template contains no media clip references',await p.evaluate("""() => !JSON.stringify(demo.savedTemplatePayload).includes('a'.repeat(32))"""))
  await p.locator('[data-studio-pane="titles"]').click()
  await p.locator('#timeline-delete-title').click()
  await p.locator('[data-studio-pane="audio"]').click()
  await p.locator('#timeline-clear-music').click()
  await p.locator('[data-studio-pane="filters"]').click()
  await p.locator('[data-look-preset="none"]').click()
  await p.locator('#editor-apply-look').click()
  await p.locator('[data-studio-pane="templates"]').click()
  await p.locator('[data-saved-template="'+'f'*32+'"]').click()
  ok('saved video look reapplied to current clips',await p.locator('#editor-look').input_value()=='warm')
  ok('relative T1 title recreated with independent instance ID',await p.locator('.studio-title-block').count()==1)
  ok('B1 background song restored from library',await p.locator('.studio-background-block').count()==1)
  ok('recorded video source clips remain untouched',await p.locator('.editor-clip').count()==2 and await p.locator('.studio-media-item').count()==2)
  await p.locator('#editor-save-project').click();await p.wait_for_function('demo.saved!==null')
  ok('template-applied source sequence saves normally',await p.evaluate('demo.saved.length===2 && demo.saved[0].lookPreset==="warm"'))
  p.once('dialog',lambda d:asyncio.create_task(d.accept()))
  await p.locator('#editor-delete-template').click()
  await p.wait_for_function('demo.templates.length===0')
  ok('deleting template preserves edit and sources',await p.locator('.editor-clip').count()==2)
  await p.close();await browser.close()
 ok('no JS errors',not errors)
 for label,v in checks:print(('PASS ' if v else 'FAIL ')+label,flush=True)
 if errors:print(errors,flush=True)
 print('Reusable templates:',sum(v for _,v in checks),'/',len(checks),flush=True)
 if not all(v for _,v in checks):raise SystemExit(1)
if __name__=='__main__':asyncio.run(main())
