"""V5Q.15L: verify pointer/keyboard trim values persist through project Save."""
import asyncio,sys
from pathlib import Path
from playwright.async_api import async_playwright
sys.path.insert(0,str(Path(__file__).parent))
import v5q3_editor_browser as fixture
async def main():
 results=[];errors=[]
 def check(name,passed):results.append((name,bool(passed)))
 async with async_playwright() as pw:
  b=await pw.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox'])
  for width in (1440,780):
   p=await b.new_page(viewport={'width':width,'height':900},reduced_motion='reduce')
   p.set_default_timeout(6000);p.on('pageerror',lambda e:errors.append(str(e)))
   await p.set_content(fixture.html,wait_until='domcontentloaded')
   await p.wait_for_function("document.querySelectorAll('.editor-clip').length===2")
   row=p.locator('.editor-clip').first
   check(f'{width}: row is not HTML draggable',await row.get_attribute('draggable')=='false')
   check(f'{width}: clip has dedicated drag grip',await row.locator('.editor-clip-grip').get_attribute('draggable')=='true')
   start=row.locator('.editor-trim-handle.start')
   rect=await start.bounding_box()
   await p.mouse.move(rect['x']+rect['width']/2,rect['y']+rect['height']/2)
   await p.mouse.down()
   await p.mouse.move(rect['x']+rect['width']/2+32,rect['y']+rect['height']/2,steps=8)
   check(f'{width}: drag updates In field live',await p.locator('#editor-trim-start').input_value()=='2.0')
   await p.mouse.up()
   check(f'{width}: pointer trim commits exact In 2.0 seconds',await p.locator('#editor-trim-start').input_value()=='2.0')
   check(f'{width}: clip label shows new range','In 2.0s' in await p.locator('.editor-clip .mini-label').first.inner_text() or 'TRIM' not in await p.locator('.editor-clip .mini-label').first.inner_text())
   end=p.locator('.editor-clip').first.locator('.editor-trim-handle.end')
   rect=await end.bounding_box()
   await p.mouse.move(rect['x']+rect['width']/2,rect['y']+rect['height']/2)
   await p.mouse.down();await p.mouse.move(rect['x']+rect['width']/2-16,rect['y']+rect['height']/2,steps=7);await p.mouse.up()
   check(f'{width}: end trim commits Out 9.0 seconds',await p.locator('#editor-trim-end').input_value()=='9.0')
   end=p.locator('.editor-clip').first.locator('.editor-trim-handle.end')
   await end.focus();await end.press('Shift+ArrowLeft')
   check(f'{width}: keyboard trim adjusts Out by one second',await p.locator('#editor-trim-end').input_value()=='8.0')
   await p.locator('#editor-save-project').click()
   await p.wait_for_function('demo.saved!==null')
   check(f'{width}: project save persists both source trim points',await p.evaluate('demo.saved[0].trimStartMs===2000 && demo.saved[0].trimEndMs===8000'))
   check(f'{width}: independent second clip unchanged',await p.evaluate("demo.saved[1].trimStartMs===0 && demo.saved[1].trimEndMs===0"))
   await p.close()
  await b.close()
 check('no JavaScript runtime errors',not errors)
 for name,passed in results:print(('PASS ' if passed else 'FAIL ')+name,flush=True)
 if errors:print(errors,flush=True)
 print('Trim verification:',sum(v for _,v in results),'/',len(results),flush=True)
 if not all(v for _,v in results):raise SystemExit(1)
if __name__=='__main__':asyncio.run(main())
