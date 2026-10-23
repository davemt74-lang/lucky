"""V5Q.27D: mobile control layout, approved claims, ordered proof edit requests."""
import asyncio,re
from pathlib import Path
from playwright.async_api import async_playwright

ROOT=Path(__file__).resolve().parents[1]
html=(ROOT/'controller.php').read_text().split('?>',1)[1]
js=(ROOT/'public/phone-controller.js').read_text()
css=(ROOT/'public/platform.css').read_text()
html=re.sub(r'<script src="public/voice-commands.js[^>]+></script>','',html)
html=re.sub(r'<script src="public/phone-controller.js[^>]+></script>',lambda _:'<script>'+js.replace('</script','<\\/script')+'</script>',html)
html=html.replace('</head>','<base href="https://lucky.test/"><style>'+css+'</style></head>',1)
stub=r'''<script>
window.__claims=[];window.__posted=[];window.__ended=false;
window.fetch=async(url,opts={})=>{
 const action=new URL(String(url),'https://lucky.test/').searchParams.get('action');
 if(action==='status')return new Response(JSON.stringify({ok:true,status:'paired',claims:__claims,
  claimSession:'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
  game:{id:'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',status:__ended?'ended':'active',canSpin:!__ended,
    canStart:__ended,spinCount:2,claimedCount:__claims.length,remaining:2,offer:null},
  proof:{active:false},delivery:'executed'}));
 if(action==='command'){const v=JSON.parse(opts.body);__posted.push(v);
  return new Response(JSON.stringify({ok:true,accepted:true,applied:false,commandId:99}));}
 if(action==='dashboard_games')return new Response(JSON.stringify({ok:true,games:[{id:'spin-wheel',title:'Spin the Wheel',hasPhone:true}]}));
 return new Response(JSON.stringify({ok:true}));
};
</script>'''
html=html.replace('</head>',stub+'</head>',1)

async def main():
 checks=[];errors=[]
 def check(title,ok):checks.append((title,bool(ok)))
 async with async_playwright() as pw:
  browser=await pw.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox'])
  page=await browser.new_page(viewport={'width':375,'height':780},reduced_motion='reduce')
  page.on('pageerror',lambda exc:errors.append(str(exc)))
  await page.evaluate("""() => {const data=JSON.stringify({id:'a'.repeat(32),token:'c'.repeat(64)});
   const values=new Map([['lucky-phone-controller-v5p',data]]);
   Object.defineProperty(window,'sessionStorage',{configurable:true,value:{
    getItem:k=>values.get(k)||null,setItem:(k,v)=>values.set(k,v),removeItem:k=>values.delete(k)} });} """)
  await page.set_content(html,wait_until='domcontentloaded')
  await page.locator('#phone-game-state').wait_for(state='visible',timeout=5500)
  check('No header, logo, top hamburger',await page.locator('.phone-controller-top,.phone-brand,#phone-menu-toggle').count()==0)
  check('Only Start, Menu and End below stats',await page.evaluate("""() => {
    const s=document.querySelector('#phone-game-state'), b=document.querySelector('.phone-game-actions');
    return !!(s.compareDocumentPosition(b)&Node.DOCUMENT_POSITION_FOLLOWING)
      && [...b.querySelectorAll('[data-phone]')].map(x=>x.dataset.phone).join(',')==='start,menu,end';} """))
  check('Main game has no Back/Select/Pause',await page.locator('#phone-gameplay-shell [data-phone=back],#phone-gameplay-shell [data-phone=select],#phone-gameplay-shell [data-phone=pause]').count()==0)
  await page.locator('#phone-bottom-nav [data-phone-view=more]').click()
  check('Connected status moved into More with green style',await page.locator('#phone-quick-menu #phone-connection-state[data-state=connected]').is_visible() and await page.locator('#phone-connection-state').evaluate("e=>getComputedStyle(e).color==='rgb(169, 255, 213)'"))
  check('Claims disabled without accepted prizes',await page.locator('#phone-menu-tab-claims').is_disabled() and await page.locator('#phone-quick-menu [data-phone-view=prize]').is_disabled())
  await page.evaluate("""() => {__claims=[
   {id:'b'.repeat(32),name:'First Prize',tier:'tier1',cents:1250,hasVideo:true},
   {id:'d'.repeat(32),name:'<img src=x onerror=alert(1)>',tier:'tier2',cents:2599,hasVideo:false}];} """)
  await page.locator('#phone-menu-tab-claims').wait_for(state='enabled',timeout=4500)
  await page.locator('#phone-menu-tab-claims').click()
  check('Claims tab enabled and ordered',await page.locator('#phone-claimed-prizes > li').count()==2 and await page.locator('.phone-claim-info strong').all_inner_texts()==['First Prize','<img src=x onerror=alert(1)>'])
  check('Actual cent values and proof status', '$12.50' in await page.locator('#phone-claimed-prizes > li').nth(0).inner_text() and '$25.99' in await page.locator('#phone-claimed-prizes > li').nth(1).inner_text() and 'No video yet' in await page.locator('#phone-claimed-prizes > li').nth(1).inner_text())
  check('Escaped claim name, no injected HTML',await page.locator('#phone-claimed-prizes img').count()==0)
  await page.locator('#phone-claimed-prizes button').nth(0).click()
  await page.wait_for_function("__posted.some(x=>x.command==='proof-edit')",timeout=3000)
  check('Edit action is scoped to exact persisted claim',await page.evaluate("__posted.some(x=>x.command==='proof-edit'&&x.nonce.startsWith('b'.repeat(32)+'_')&&x.nonce.length<=80)"))
  await page.wait_for_timeout(800)
  await page.locator('#phone-bottom-nav [data-phone-view=prize]').click()
  check('Prize button reopens ordered claims',await page.locator('#phone-menu-panel-claims').is_visible())
  await page.locator('#phone-menu-close').click()
  await page.evaluate('__ended=true')
  await page.locator('#phone-dashboard').wait_for(state='visible',timeout=4500)
  await page.locator('#phone-bottom-nav [data-phone-view=more]').click()
  check('Accepted claims remain available after game ends',await page.locator('#phone-menu-tab-claims').is_enabled())
  await page.evaluate('__claims=[]')
  await page.locator('#phone-menu-tab-claims').wait_for(state='disabled',timeout=4500)
  check('No stale claims after server clears history',await page.locator('#phone-claimed-prizes > li').count()==0)
  for width in (320,375,430):
   await page.set_viewport_size({'width':width,'height':780})
   check(f'{width}px has no horizontal overflow',await page.evaluate('document.documentElement.scrollWidth<=innerWidth+3'))
  check('No unhandled JS exception',not errors)
  await browser.close()
 for label,ok in checks:print(('PASS ' if ok else 'FAIL ')+label,flush=True)
 if errors:print('Page errors:',errors)
 print(f'V5Q.27D phone claims: {sum(y for _,y in checks)}/{len(checks)}',flush=True)
 if not all(y for _,y in checks):raise SystemExit(1)

if __name__=='__main__':asyncio.run(main())
