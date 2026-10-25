"""V5Q.29A: editor history is non-destructive, the ruler maps onto V1, and sound has a real canvas lane."""
import asyncio,sys
from pathlib import Path
from playwright.async_api import async_playwright
sys.path.insert(0,str(Path(__file__).parent))
import v5q3_editor_browser as fixture

async def main():
 checks=[];errors=[]
 def check(name,value):checks.append((name,bool(value)))
 async with async_playwright() as pw:
  browser=await pw.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox'])
  page=await browser.new_page(viewport={'width':1365,'height':900},reduced_motion='reduce')
  page.set_default_timeout(5000);page.on('pageerror',lambda e:errors.append(str(e)))
  await page.set_content(fixture.html,wait_until='domcontentloaded')
  await page.wait_for_function("document.querySelectorAll('.editor-clip').length===2")
  check('initial history is clean',await page.locator('#editor-undo').is_disabled() and await page.locator('#editor-redo').is_disabled())
  check('snapping is enabled but optional',await page.locator('#editor-snap-toggle').is_checked())
  check('time ruler follows actual clip widths',await page.evaluate("""() => {
   const ticks=[...document.querySelectorAll('.studio-ruler-tick')];
   return ticks.length>=3 && Math.abs(parseFloat(ticks[1].style.left)-80)<2 && Math.abs(parseFloat(ticks[2].style.left)-160)<2;
  }"""))
  await page.locator('.studio-media-add').first.click()
  check('adding footage creates an undoable edit',await page.locator('.editor-clip').count()==3 and await page.locator('#editor-undo').is_enabled())
  await page.locator('#editor-undo').click()
  check('undo restores exact V1 clip count',await page.locator('.editor-clip').count()==2 and await page.locator('#editor-redo').is_enabled())
  await page.locator('#editor-redo').click()
  check('redo restores footage without duplicate source',await page.locator('.editor-clip').count()==3)
  await page.keyboard.press('Control+z')
  check('Ctrl+Z reverses timeline changes',await page.locator('.editor-clip').count()==2)
  await page.keyboard.press('Control+Shift+z')
  check('Ctrl+Shift+Z replays timeline changes',await page.locator('.editor-clip').count()==3)
  await page.locator('[data-studio-pane="titles"]').click()
  await page.locator('#timeline-title-text').fill('Congratulations!')
  await page.locator('#timeline-add-title').click()
  check('timed title participates in edit history',await page.locator('.studio-title-block').count()==1)
  await page.locator('#editor-undo').click()
  check('undo removes title without touching original clips',await page.locator('.studio-title-block').count()==0 and await page.locator('.editor-clip').count()==3)
  await page.locator('#editor-redo').click()
  check('redo returns title with timeline intact',await page.locator('.studio-title-block').count()==1)
  await page.locator('[data-studio-pane="audio"]').click()
  await page.locator('#timeline-music-select').select_option('d'*32)
  await page.locator('#timeline-music-end').fill('10.0')
  await page.locator('#timeline-apply-music').click()
  check('background music lane has waveform canvas',await page.locator('.studio-background-block canvas.studio-waveform').count()==1)
  await page.locator('#editor-undo').click()
  check('soundtrack is undoable without deleting audio-library options',await page.locator('.studio-background-block').count()==0 and await page.locator('#timeline-music-select option').count()==2)
  await page.locator('#editor-save-project').click()
  await page.wait_for_function('demo.saved !== null')
  check('saved project keeps the three source references',await page.evaluate("demo.saved.length===3 && demo.saved[0].id==='a'.repeat(32)"))
  await page.close();await browser.close()
 check('no uncaught editor exceptions',not errors)
 for name,result in checks:print(('PASS ' if result else 'FAIL ')+name,flush=True)
 if errors:print(errors,flush=True)
 print('V5Q.29A browser:',sum(v for _,v in checks),'/',len(checks),flush=True)
 if not all(v for _,v in checks):raise SystemExit(1)
if __name__=='__main__':asyncio.run(main())
