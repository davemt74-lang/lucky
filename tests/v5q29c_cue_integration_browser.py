"""Real Video Studio editor integration: marker operations are saved only on user choice."""
import asyncio,sys
from pathlib import Path
from playwright.async_api import async_playwright
sys.path.insert(0,str(Path(__file__).parent))
import v5q3_editor_browser as fixture

def check(label,ok):
 print(('PASS ' if ok else 'FAIL ')+label,flush=True)
 if not ok:raise AssertionError(label)

async def main():
 async with async_playwright() as pw:
  browser=await pw.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox'])
  page=await browser.new_page();errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
  await page.set_content(fixture.html,wait_until='domcontentloaded')
  await page.wait_for_function("document.querySelectorAll('.editor-clip').length===2")
  current=await page.evaluate("window.LuckyEditorAgent.snapshot().analyzable[0]")
  check('Snapshot gives source and instance identity',current['id']=='a'*32 and current['instanceId'])
  await page.evaluate("id=>window.LuckyEditorAgent.applyCue(id,1500,'split')",current['instanceId'])
  check('Direct marker split creates two non-destructive timeline segments',await page.locator('.editor-clip').count()==3)
  check('Original upload remains available',await page.locator('#editor-media-bin').get_by_text('Smile',exact=False).count()>0)
  check('No save_project request after marker action',await page.evaluate("demo.saved===null"))
  await page.locator('#editor-undo').click()
  check('Undo fully restores pre-split video timeline',await page.locator('.editor-clip').count()==2)
  await page.locator('#editor-redo').click()
  check('Redo restores split independently of the source file',await page.locator('.editor-clip').count()==3)
  await page.evaluate("id=>window.LuckyEditorAgent.applyCue(id,300,'in')",current['instanceId'])
  check('Set In uses exact source timestamp',await page.evaluate("window.LuckyEditorAgent.snapshot().edits[0].trimStartMs===300"))
  await page.locator('#editor-save-project').click()
  await page.wait_for_function('demo.saved !== null')
  check('Only explicit Save persists cue edits',await page.evaluate("demo.saved[0].trimStartMs===300 && demo.saved[0].trimEndMs===1500"))
  check('No uncaught editor exceptions',not errors)
  await browser.close()
  print('V5Q.29C END-TO-END NON-DESTRUCTIVE CUE EDITS PASS',flush=True)

if __name__=='__main__':asyncio.run(main())
