"""Saved video previews on the game claims sidebar, refreshed by re-recording."""
import asyncio
from playwright.async_api import async_playwright
from v5q6_proof_browser import BASE, MOCK, SID, CID, html_page
OTHER='b'*32;FIRST='1'*32;RETAKE='2'*32;PHOTO='3'*32;WRONG='4'*32
EXTRA="""
window.__claims.push({id:'OTHER',prizeId:'p2',name:'Grand Prize',tier:'tier2',cents:3750,at:'2026-10-19T12:20:00Z'});
window.__mediaClips=[
 {id:'FIRST',claimId:'CLAIM',sessionId:'SESSION',kind:'video',at:'2026-10-19T12:05:00Z'},
 {id:'PHOTO',claimId:'OTHER',sessionId:'SESSION',kind:'photo',at:'2026-10-19T12:15:00Z'},
 {id:'WRONG',claimId:'OTHER',sessionId:'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee',kind:'video'}
];
window.__origFetch=window.fetch;
window.fetch=async(url,opts={})=>{
 if(String(url).includes('claim-media.php?action=list'))return new Response(JSON.stringify({ok:true,clips:__mediaClips}));
 return __origFetch(url,opts);
};
""".replace('SESSION',SID).replace('CLAIM',CID).replace('OTHER',OTHER).replace('FIRST',FIRST).replace('PHOTO',PHOTO).replace('WRONG',WRONG)
async def main():
 checks=[];exceptions=[]
 def ok(label,value):checks.append((label,bool(value)))
 async with async_playwright() as pw:
  browser=await pw.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox'])
  for width in (390,1280):
   pg=await browser.new_page(viewport={'width':width,'height':850},reduced_motion='reduce')
   pg.on('pageerror',lambda e:exceptions.append(str(e)))
   await pg.set_content(html_page('game.php',(BASE/'public/game.js').read_text(),MOCK+'\n'+EXTRA),wait_until='domcontentloaded')
   row='[data-claim-id="'+CID+'"]'
   await pg.wait_for_function("""() => !!document.querySelector('[data-claim-id="CLAIM"] video.claim-video-thumbnail')""".replace('CLAIM',CID),timeout=8000)
   ok(f'{width} thumbnail for claim with recorded video',await pg.locator(row+' video').count()==1)
   ok(f'{width} thumbnail uses authenticated clip stream',await pg.locator(row+' video').get_attribute('src')=='api/claim-media.php?action=stream&id='+FIRST)
   ok(f'{width} photo-only or wrong-session media not used as thumbnail',await pg.locator('[data-claim-id="'+OTHER+'"] video').count()==0)
   ok(f'{width} proof edit still links to same claim',CID in await pg.locator(row+' a[href*="proof.php"]').get_attribute('href'))
   await pg.evaluate("""() => {
    window.__firstThumbnail=document.querySelector('[data-claim-id="CLAIM"] video');
    window.__version++;
   }""".replace('CLAIM',CID))
   await pg.wait_for_timeout(1300)
   ok(f'{width} game polling preserves loaded video element',await pg.evaluate("""document.querySelector('[data-claim-id="CLAIM"] video')===window.__firstThumbnail""".replace('CLAIM',CID)))
   await pg.evaluate("""() => {
    window.__mediaClips.push({id:'RETAKE',claimId:'CLAIM',sessionId:'SESSION',kind:'video',at:'2026-10-19T13:00:00Z'});
    window.dispatchEvent(new Event('focus'));
   }""".replace('RETAKE',RETAKE).replace('CLAIM',CID).replace('SESSION',SID))
   await pg.wait_for_function("""() => document.querySelector('[data-claim-id="CLAIM"] video')?.getAttribute('src')?.endsWith('RETAKE')""".replace('CLAIM',CID).replace('RETAKE',RETAKE),timeout=7000)
   ok(f'{width} newer re-recording replaces thumbnail',await pg.locator(row+' video').get_attribute('src')=='api/claim-media.php?action=stream&id='+RETAKE)
   ok(f'{width} responsive claims sidebar remains present',await pg.locator('.host-claims-column').is_visible())
   await pg.close()
  await browser.close()
 ok('no JavaScript exceptions',not exceptions)
 for title,result in checks:print(('PASS ' if result else 'FAIL ')+title,flush=True)
 if exceptions:print('Exceptions:',exceptions,flush=True)
 if not all(r for _,r in checks):raise SystemExit(1)
if __name__=='__main__':asyncio.run(main())
