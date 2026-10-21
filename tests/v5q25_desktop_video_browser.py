"""V5Q.25 desktop video remote playback, including claim proof and private upload route."""
import asyncio,re
from pathlib import Path
from playwright.async_api import async_playwright
from v5q25_package_live_browser import HTML as GAME_HTML
ROOT=Path(__file__).resolve().parents[1]
SID='c'*32;CLAIM='d'*32;VID='e'*32
URL=f'api/index.php?action=prize_video&id={VID}'
async def main():
 checks=[];errors=[]
 def ck(label,value):checks.append((label,bool(value)))
 async with async_playwright() as pw:
  browser=await pw.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox'])
  page=await browser.new_page(viewport={'width':1280,'height':820})
  page.on('pageerror',lambda e:errors.append(str(e)))
  await page.set_content(GAME_HTML,wait_until='domcontentloaded')
  await page.locator('#host-package-choice-cards button').first.wait_for()
  await page.evaluate('window.LuckyHostRemote("select")')
  await page.wait_for_function('document.querySelector("#host-prize-package-chooser").hidden')
  await page.evaluate("""() => {
   const old=window.Lucky.api;
   window.Lucky.api=async (action,opts) => {
    if(action==='prize_offer')return {offer:{claimId:'CLAIM',status:'pending',videoUrl:'URL'}};
    if(action==='session')return {session:{claims:[{id:'CLAIM',name:'Pizza Party',videoUrl:'URL'}]}};
    return old(action,opts);
   };
  }""".replace('CLAIM',CLAIM).replace('URL',URL))
  await page.evaluate("window.LuckyHostRemote('prize-video-play')")
  await page.locator('#host-prize-video video').wait_for(state='attached',timeout=4000)
  ck('Phone Play Video displays prize video on desktop',await page.locator('#host-prize-video video').count()==1)
  ck('Host preview uses uploaded private video stream',VID in await page.locator('#host-prize-video video').get_attribute('src'))
  ck('Video appears in main Live Display tab',await page.locator('#host-panel-display').is_visible())
  await page.locator('#host-prize-video-close').click()
  ck('Closing the desktop preview removes playback element',await page.locator('#host-prize-video video').count()==0)
  await page.close()

  # Proof screen has a separate host receiver after accepted prize route.
  page=await browser.new_page(viewport={'width':1280,'height':820})
  page.on('pageerror',lambda e:errors.append(str(e)))
  raw=(ROOT/'proof.php').read_text()
  inner=raw.split('?>',1)[1].split('<script src="public/client.js"',1)[0]
  mock="""<script>
   Object.defineProperty(window,'sessionStorage',{configurable:true,value:{getItem:key=>key==='lucky-proof-origin'?JSON.stringify({session:'SID',claim:'CLAIM',at:Date.now()}):null,setItem:()=>{},removeItem:()=>{}}});
   window.Lucky={getMe:async()=>({csrf:'test'}),api:async action=>{
    if(action==='session')return {session:{claims:[{id:'CLAIM',name:'Pizza Party',tier:'tier1',cents:1000,videoUrl:'URL'}],pools:{tier1:{prizes:[]}}}};
    return {ok:true};},money:c=>'$'+(c/100).toFixed(2)};
   window.LuckyCapture={show:()=>{},isBusy:()=>false,reviewCount:()=>0,phase:()=> 'live',consent:()=>true};
   window.fetch=async()=>new Response(JSON.stringify({ok:true,status:{latestRevision:0,needsRevision:false},revisions:[],clipCount:0}));
  </script>""".replace('CLAIM',CLAIM).replace('SID',SID).replace('URL',URL)
  js=(ROOT/'public/proof.js').read_text().replace('</script','<\\/script')
  body='<html><head><base href="https://lucky.test/"></head><body>'+mock+inner+'<script>'+js+'</script></body></html>'
  await page.set_content(body,wait_until='domcontentloaded')
  try:
   await page.wait_for_function("document.querySelector('#capture-prize-label')?.textContent==='Pizza Party'",timeout=2500)
  except Exception as e:
   print('DEBUG proof',await page.evaluate("() => ({tag:document.querySelector('#capture-prize-label')?.textContent,errors:document.querySelector('#proof-state')?.textContent,stored:sessionStorage.getItem('lucky-proof-origin'),init:typeof window.LuckyHostRemote,url:location.href})"), 'err',errors,flush=True)
   raise
  await page.evaluate("window.LuckyHostRemote('prize-video-play')")
  await page.locator('#proof-prize-video video').wait_for(state='attached',timeout=4000)
  ck('Accepted prize can play video on desktop Edit Proof screen',await page.locator('#proof-prize-video').is_visible())
  await page.evaluate("window.dispatchEvent(new CustomEvent('lucky:proof-phase',{detail:{phase:'recording'}}))")
  ck('Proof recording stops prize video playback',await page.locator('#proof-prize-video').is_hidden())
  ck('No uncaught desktop browser errors',not errors)
  await browser.close()
 for name,v in checks:print(('PASS ' if v else 'FAIL ')+name,flush=True)
 print('V5Q25 desktop video:',sum(v for _,v in checks),'/',len(checks),flush=True)
 if not all(v for _,v in checks):raise SystemExit(1)
if __name__=='__main__':asyncio.run(main())
