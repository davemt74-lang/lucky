"""V5Q.26D: realistic controller navigation and responsive states with host-owned media."""
import asyncio,re
from pathlib import Path
from playwright.async_api import async_playwright
BASE=Path(__file__).resolve().parents[1]
HTML=(BASE/'controller.php').read_text().split('?>',1)[1]
JS=(BASE/'public/phone-controller.js').read_text()
CSS=(BASE/'public/platform.css').read_text()
HTML=re.sub(r'<script src="public/voice-commands.js[^>]+></script>','',HTML)
HTML=re.sub(r'<script src="public/phone-controller.js[^>]+></script>',lambda _:'<script>'+JS.replace('</script','<\\/script')+'</script>',HTML)
HTML=HTML.replace('</head>','<base href="https://lucky.test/"><style>'+CSS+'</style></head>',1)
MOCK=r"""<script>
window.__paired=false;window.__status='active';window.__pending=false;window.__proof=false;
window.__phase='live';window.__movie=false;window.__requests=[];
window.fetch=async(url,opts={})=>{
 const action=new URL(String(url),'https://lucky.test/').searchParams.get('action');
 __requests.push(action);
 if(action==='status')return new Response(JSON.stringify({ok:true,status:__paired?'paired':'pending',
  moviePreview:__movie?{session:'dddddddddddddddddddddddddddddddd',state:'paused'}:null,
  proof:__proof?{active:true,claimId:'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',phase:__phase,consent:true,reviewCount:0}:{active:false},
  game:{id:'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',status:__status,canStart:false,canSpin:__status==='active',
    spinning:false,remaining:4,spinCount:2,claimedCount:1,
    offer:__pending?{status:'pending',claimId:'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',name:'Game Night Prize',cents:1500,tier:'tier1',description:'Prize details',image:'',videoUrl:''}:null}
 }));
 if(action==='dashboard_games')return new Response(JSON.stringify({ok:true,games:[{id:'spin-wheel',title:'Spin the Wheel',publisher:'Lucky Games',hasPhone:true}]}));
 return new Response(JSON.stringify({ok:true}));
};
</script>"""
HTML=HTML.replace('</head>',MOCK+'</head>',1)
async def main():
 results=[];errors=[]
 def verify(name,good):results.append((name,bool(good)))
 async with async_playwright() as pw:
  browser=await pw.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox'])
  page=await browser.new_page(viewport={'width':390,'height':844},reduced_motion='reduce')
  page.on('pageerror',lambda e:errors.append(str(e)))
  await page.evaluate("""() => {
   const entries=new Map([['lucky-phone-controller-v5p',JSON.stringify({
    id:'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',token:'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc'
   })]]);
   Object.defineProperty(window,'sessionStorage',{configurable:true,value:{
    setItem:(k,v)=>entries.set(k,v),getItem:k=>entries.get(k)||null,removeItem:k=>entries.delete(k)
   }});
  }""")
  await page.set_content(HTML,wait_until='domcontentloaded')
  await page.locator('#phone-wait').wait_for(state='visible',timeout=5000)
  verify('Bottom navigation hidden before approval',await page.locator('#phone-bottom-nav').is_hidden())
  await page.evaluate('__paired=true')
  await page.locator('#phone-gameplay-shell').wait_for(state='visible',timeout=5000)
  verify('Connected state shows persistent navigation',await page.locator('#phone-bottom-nav').is_visible())
  verify('Game selected, home unavailable while game active',await page.locator('#phone-bottom-nav [data-phone-view=game]').get_attribute('aria-current')=='page' and await page.locator('#phone-bottom-nav [data-phone-view=home]').is_disabled())
  verify('Wheel rendering is decorative',await page.locator('.phone-wheel-visual[aria-hidden=true]').count()==1)
  await page.locator('#phone-menu-toggle').click()
  verify('Accessible local menu opens',await page.locator('#phone-quick-menu').is_visible())
  verify('Cannot bypass active game via menu',await page.locator('#phone-quick-menu [data-phone-view=home]').is_disabled())
  await page.locator('#phone-menu-close').click()
  await page.evaluate("__pending=true")
  await page.locator('#phone-prize-offer').wait_for(state='visible',timeout=5000)
  verify('Prize tab active during decision',await page.locator('#phone-bottom-nav [data-phone-view=prize]').get_attribute('aria-current')=='page')
  verify('Dashboard and camera not accessible during claim',await page.locator('#phone-bottom-nav [data-phone-view=home]').is_disabled() and await page.locator('#phone-bottom-nav [data-phone-view=camera]').is_disabled())
  verify('Prize card preserves name, tier and value',await page.locator('#phone-offer-name').inner_text()=='Game Night Prize' and await page.locator('#phone-offer-value').inner_text()=='$15.00')
  await page.evaluate("__proof=true;__pending=false;__phase='countdown'")
  await page.locator('#phone-proof-live-banner[data-phase=countdown]').wait_for(state='visible',timeout=5000)
  verify('Camera tab active during proof countdown',await page.locator('#phone-bottom-nav [data-phone-view=camera]').get_attribute('aria-current')=='page')
  verify('Countdown is not marked recording', 'countdown' in (await page.locator('#phone-proof-live-title').inner_text()).lower())
  await page.evaluate("__phase='recording'")
  await page.locator('#phone-proof-live-banner[data-phase=recording]').wait_for(state='visible',timeout=5000)
  verify('REC badge only when host recording', 'REC' in await page.locator('#phone-proof-live-title').inner_text())
  await page.evaluate("__movie=true;__proof=false")
  await page.locator('#phone-movie').wait_for(state='visible',timeout=5000)
  verify('Movie remote retains Play Pause Stop',await page.locator('#phone-movie .phone-movie-buttons button').count()==3)
  verify('Movie prevents navigation to another game',await page.locator('#phone-bottom-nav [data-phone-view=home]').is_disabled() and await page.locator('#phone-bottom-nav [data-phone-view=game]').is_disabled())
  await page.evaluate("__movie=false;__status='ended'")
  await page.locator('#phone-dashboard').wait_for(state='visible',timeout=5000)
  verify('Finished game returns to dashboard',await page.locator('#phone-bottom-nav [data-phone-view=home]').get_attribute('aria-current')=='page')
  for width in (360,390,768):
   await page.set_viewport_size({'width':width,'height':844})
   verify(f'{width}px fits without horizontal overflow',await page.evaluate('document.documentElement.scrollWidth<=innerWidth+3'))
  verify('No uncaught JS errors',not errors)
  await browser.close()
 for name,yes in results:print(('PASS ' if yes else 'FAIL ')+name,flush=True)
 print('V5Q26D Controller:',sum(y for _,y in results),'/',len(results),flush=True)
 if errors:print('Errors:',errors)
 if not all(yes for _,yes in results):raise SystemExit(1)
if __name__=='__main__':asyncio.run(main())
