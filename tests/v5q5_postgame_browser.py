"""V5Q.5 isolated browser checks for session-specific post-game review controls."""
import asyncio,re
from pathlib import Path
from playwright.async_api import async_playwright
BASE=Path(__file__).resolve().parents[1]
markup=(BASE/'game.php').read_text().split('?>',1)[1].split('<?php shellEnd()',1)[0]
markup=re.sub(r'<script\b[^>]*>.*?</script>', '',markup,flags=re.S|re.I)
css=(BASE/'public/style.css').read_text()+'\n'+(BASE/'public/platform.css').read_text()
script=(BASE/'public/game.js').read_text()
SID='c'*32
mock='''<script>
window.__gameStatus='ended';window.__version=1;
window.LuckyWheel=class{constructor(){this.slices=[]}setSlices(x){this.slices=x}async spinTo(){}};
window.Lucky={ $:id=>document.getElementById(id),money:n=>'$'+(n/100).toFixed(2),
note:(id,text)=>{document.getElementById(id).textContent=text;},
api:async(a)=>{if(a==='last')return{data:{view:{id:'SESSION_ID',status:__gameStatus,version:__version,claims:[],slots:[],spinCount:0,endedAt:'2026-10-19T00:00:00Z'},session:{pools:{bonus:{prizes:[]}},claims:[]}}};return {};},
loadTracks:async()=>[],bindMusic:()=>{}, beep:()=>{},speak:()=>{},pref:{musicOn:false},idempotency:()=> 't'};
</script>'''.replace('SESSION_ID',SID)
html='<html><head><base href="https://example.test/"><style>'+css+'</style>'+mock+'</head><body>'+markup+'<script>'+script+'</script></body></html>'
async def main():
 tests=[];errors=[]
 def ok(name,value):tests.append((name,bool(value)))
 async with async_playwright() as pw:
  browser=await pw.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox'])
  for width in [390,1280]:
   page=await browser.new_page(viewport={'width':width,'height':820},reduced_motion='reduce')
   page.on('pageerror',lambda err:errors.append(str(err)))
   await page.set_content(html,wait_until='domcontentloaded')
   await page.wait_for_timeout(200)
   ok(f'{width}px completed game exposes Review highlights in Game menu',await page.locator('#postgame-review').evaluate('(e)=>!e.hidden'))
   ok(f'{width}px review is moved inside Game menu',await page.locator('#console-menu #postgame-review').count()==1)
   ok(f'{width}px link targets exact game session',await page.locator('#review-highlight').get_attribute('href')=='editor.php?session='+SID)
   await page.evaluate("__gameStatus='paused';__version++")
   await page.wait_for_timeout(1100)
   ok(f'{width}px paused game hides review',await page.locator('#postgame-review').evaluate('(e)=>e.hidden'))
   await page.evaluate("__gameStatus='ended';__version++")
   await page.wait_for_timeout(1100)
   ok(f'{width}px remotely ended game exposes menu review',await page.locator('#postgame-review').evaluate('(e)=>!e.hidden'))
   await page.close()
  await browser.close()
 ok('No script exceptions',not errors)
 for name,value in tests:print(('PASS ' if value else 'FAIL ')+name)
 if errors:print('JS exceptions',errors)
 print('POSTGAME UI',sum(x for _,x in tests),'/',len(tests))
 if not all(x for _,x in tests):raise SystemExit(1)
if __name__=='__main__':asyncio.run(main())
