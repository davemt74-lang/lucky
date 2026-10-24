"""V5Q.15P: toolbox navigation, resizable divider, and source-media preservation."""
import asyncio
from playwright.async_api import async_playwright
import v5q3_editor_browser as fixture

async def main():
 checks=[]; errors=[]
 def ok(name,v): checks.append((name,bool(v)))
 async with async_playwright() as pw:
  browser=await pw.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox'])
  for width in (1440,375):
   page=await browser.new_page(viewport={'width':width,'height':900},reduced_motion='reduce')
   page.set_default_timeout(7000);page.on('pageerror',lambda e:errors.append(str(e)))
   await page.set_content(fixture.html,wait_until='domcontentloaded')
   await page.wait_for_function("document.querySelectorAll('.studio-media-item').length===2")
   ok(f'{width}: seven toolbox categories including AI Editor',await page.locator('[data-studio-pane]').count()==7)
   ok(f'{width}: media bin defaults open',await page.locator('#studio-pane-media').is_visible() and await page.locator('.studio-media-item').count()==2)
   ok(f'{width}: only current pane visible',await page.locator('.studio-pane:visible').count()==1)
   for key,control in [('filters','[data-look-preset="mono"]'),('overlays','#editor-overlay-text'),('templates','[data-template-preset="film"]'),('titles','#timeline-title-text'),('audio','#timeline-music-select'),('media','#editor-media-bin')]:
    await page.locator('[data-studio-pane="'+key+'"]').click()
    ok(f'{width}: {key} content loads',await page.locator(control).is_visible() and await page.locator('.studio-pane:visible').count()==1)
   await page.locator('[data-studio-pane="media"]').focus()
   await page.keyboard.press('ArrowDown')
   ok(f'{width}: keyboard opens AI Editor next',await page.locator('[data-studio-pane="ai"]').get_attribute('aria-pressed')=='true')
   await page.keyboard.press('ArrowDown')
   ok(f'{width}: keyboard tool navigation',await page.locator('[data-studio-pane="filters"]').get_attribute('aria-pressed')=='true')
   ok(f'{width}: no document horizontal overflow',await page.evaluate('document.documentElement.scrollWidth<=innerWidth+3'))
   if width==1440:
    await page.locator('[data-studio-pane="media"]').click()
    before=await page.locator('#studio-library').bounding_box()
    drag=await page.locator('#studio-library-resizer').bounding_box()
    await page.mouse.move(drag['x']+drag['width']/2,drag['y']+drag['height']/2)
    await page.mouse.down();await page.mouse.move(drag['x']+drag['width']/2+100,drag['y']+drag['height']/2,steps=8);await page.mouse.up()
    after=await page.locator('#studio-library').bounding_box()
    ok('pointer drag widens editor toolbox',after['width']>=before['width']+75)
    await page.locator('#studio-library-resizer').focus();await page.keyboard.press('ArrowLeft')
    await page.wait_for_function("(previous) => document.querySelector('#studio-library').getBoundingClientRect().width < previous-1",arg=after['width'])
    smaller=await page.locator('#studio-library').bounding_box()
    ok('keyboard divider width change',smaller['width']<after['width'])
    await page.locator('#studio-library-resizer').dblclick()
    await page.wait_for_function("Math.abs(document.querySelector('#studio-library').getBoundingClientRect().width - 440) < 5")
    reset=await page.locator('#studio-library').bounding_box()
    ok('double click resets width',abs(reset['width']-440)<5)
    await page.locator('.studio-media-add').first.click()
    ok('media source can still be added to timeline',await page.locator('.editor-clip').count()==3)
    await page.locator('[data-studio-pane="overlays"]').click()
    ok('tool selection updates panel ARIA',await page.locator('[data-studio-pane="overlays"]').get_attribute('aria-pressed')=='true')
   await page.close()
  await browser.close()
 ok('no browser errors',not errors)
 for name,passed in checks: print(('PASS ' if passed else 'FAIL ')+name,flush=True)
 if errors: print('Browser errors:',errors,flush=True)
 print('V5Q15P:',sum(v for _,v in checks),'/',len(checks),flush=True)
 if not all(v for _,v in checks): raise SystemExit(1)
if __name__=='__main__': asyncio.run(main())
