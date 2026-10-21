"""Test actual Video Gallery JS DOM behavior and desktop responsive layout."""
import asyncio
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[1]
SID='a'*32
READY='b'*32
QUEUED='c'*32
FAILED='d'*32
CSS=(ROOT/'public/platform.css').read_text()
HTML='''<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head><body class="admin-studio-page">
<div class="admin-console-workspace"><section id="tab-videos" class="surface platform-admin-panel video-gallery-admin">
<div class="card-title-row"><h2>Video gallery</h2><button id="video-gallery-refresh">Refresh videos</button></div>
<div class="video-gallery-tabs"><button class="selected" data-video-filter="ready">Finished movies</button><button data-video-filter="processing">Processing</button><button data-video-filter="failed">Failed</button><button data-video-filter="all">All</button></div>
<p id="video-gallery-status"></p><div id="video-gallery-list" class="video-gallery-grid"></div></section>
<dialog id="video-gallery-player" class="lucky-video-gallery-dialog"><div class="video-gallery-player-shell"><div class="video-gallery-player-head"><strong id="video-gallery-player-title"></strong><button id="video-gallery-player-close">Close</button></div><video id="video-gallery-player-video" controls></video></div></dialog></div>
</body></html>'''
MOCK='''window.__requests=[];
window.fetch=async (url,opts={})=>{
 const action=new URL(String(url),'http://127.0.0.1/').searchParams.get('action');__requests.push({action,body:opts.body||null,csrf:opts.headers?.['X-CSRF-Token']||null});
 if(action==='me')return new Response(JSON.stringify({ok:true,csrf:'VALID-CSRF'}));
 if(action==='gallery')return new Response(JSON.stringify({ok:true,renders:[
 {id:'READY',session:'SESSION',game:'spin-wheel',status:'ready',available:true,profile:'standard',bytes:123456,createdAt:'2026-10-21 15:00:00',finishedAt:'2026-10-21 15:01:00',play:'api/montage.php?action=play&id=READY',download:'api/montage.php?action=download&id=READY'},
 {id:'QUEUED',session:'SESSION',game:'spin-wheel',status:'queued',available:false,createdAt:'2026-10-21 15:02:00'},
 {id:'FAILED',session:'SESSION',game:'spin-wheel',status:'failed',available:false,error:'Worker error',createdAt:'2026-10-21 15:03:00'}]}));
 if(action==='queue_current')return new Response(JSON.stringify({ok:true,render:{id:'QUEUED',status:'queued'},metrics:{clipCount:2}}));
 return new Response(JSON.stringify({ok:false,error:action}),{status:400});
};'''.replace('SESSION',SID).replace('READY',READY).replace('QUEUED',QUEUED).replace('FAILED',FAILED)
async def main():
  checks=[];errors=[]
  def check(label,ok):checks.append((label,bool(ok)))
  async with async_playwright() as p:
   browser=await p.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox'])
   for width in [1440,768,390]:
    page=await browser.new_page(viewport={'width':width,'height':850})
    page.on('pageerror',lambda e:errors.append(str(e)))
    await page.set_content(HTML)
    await page.add_style_tag(content=CSS)
    await page.evaluate(MOCK)
    await page.add_script_tag(content=(ROOT/'public/video-gallery.js').read_text())
    await page.locator('#video-gallery-refresh').click()
    await page.locator('.video-gallery-card').first.wait_for()
    check(f'{width}px shows only ready export in default tab',await page.locator('.video-gallery-card').count()==1)
    check(f'{width}px displays a server-provided play action',await page.locator('.video-gallery-actions button').inner_text()=='▶ Watch')
    await page.locator('.video-gallery-actions button').click()
    check(f'{width}px opens centered player',await page.locator('#video-gallery-player').is_visible())
    check(f'{width}px player points to authorized server playback', await page.locator('#video-gallery-player-video').get_attribute('src')=='api/montage.php?action=play&id='+READY)
    await page.locator('#video-gallery-player-close').click()
    check(f'{width}px close stops and clears playback',await page.locator('#video-gallery-player').is_hidden() and await page.locator('#video-gallery-player-video').get_attribute('src') is None)
    await page.locator('[data-video-filter="processing"]').click()
    check(f'{width}px processing tab shows only queued renders',await page.locator('.video-gallery-card').count()==1 and ('queued' in (await page.locator('.video-gallery-state').inner_text()).lower()))
    await page.locator('[data-video-filter="failed"]').click()
    check(f'{width}px failed tab shows clear render errors',await page.locator('.video-gallery-error').inner_text()=='Worker error')
    await page.locator('[data-video-filter="all"]').click()
    check(f'{width}px All tab includes finished, processing, failed',await page.locator('.video-gallery-card').count()==3)
    result=await page.evaluate("LuckyVideoGallery.queueCurrent('"+SID+"')")
    check(f'{width}px queue sends authenticated administrator request', result['render']['status']=='queued' and await page.evaluate("__requests.some(x=>x.action==='queue_current'&&x.csrf==='VALID-CSRF')"))
    await page.close()
   await browser.close()
  for label,ok in checks:print(('PASS' if ok else 'FAIL')+' '+label)
  print(f'V5Q26B browser: {sum(x for _,x in checks)}/{len(checks)}, JS errors: {errors}')
  if errors or not all(ok for _,ok in checks):raise SystemExit(1)
if __name__=='__main__':asyncio.run(main())
