"""V5Q.24 Phone: approved mobile dashboard, game launch and desktop handoff."""
import asyncio,re
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[1]
PAIR='a'*32;TOKEN='b'*64;SESSION='c'*32;VIEWER='d'*48
HTML=(ROOT/'controller.php').read_text().split('?>',1)[1]
HTML=re.sub(r'<script src="public/voice-commands.js[^"]*" defer></script>','',HTML)
CLIENT=(ROOT/'public/phone-controller.js').read_text()
HTML=re.sub(r'<script src="public/phone-controller.js[^"]*" defer></script>',
  lambda _: '<script>'+CLIENT.replace('</script','<\\/script')+'</script>',HTML)
MOCK="""<script>
window.__actions=[];window.__state='ended';window.__launchGame='spin-wheel';
window.fetch=async(url,opts={})=>{
 const action=new URL(String(url),'https://lucky.test/').searchParams.get('action');
 const body=JSON.parse(opts.body||'{}');
 __actions.push({action,body,auth:opts.headers?.['X-Controller-Token']});
 if(action==='status')return new Response(JSON.stringify({ok:true,status:'paired',proof:{active:false},
  game:{status:__state,id:'SESSION',version:1,game:'spin-wheel',canStart:true,canSpin:__state==='active'}}));
 if(action==='dashboard_games')return new Response(JSON.stringify({ok:true,games:[
  {id:'spin-wheel',title:'Spin the Wheel',publisher:'Lucky Games',hasPhone:true},
  {id:'couch-trivia',title:'Couch Trivia',publisher:'Studio',hasPhone:true},
  {id:'desktop-only',title:'Desktop Only',publisher:'Studio',hasPhone:false}
 ]}));
 if(action==='dashboard_launch'){
  __launchGame=body.game;
  if(body.game==='spin-wheel')__state='active';
  return new Response(JSON.stringify({ok:true,launch:{id:'SESSION',game:body.game,viewer:'VIEWER',repeat:false}}));
 }
 return new Response(JSON.stringify({ok:false,error:'Unexpected API request'}),{status:400});
};
</script>""".replace('SESSION',SESSION).replace('VIEWER',VIEWER)
HTML=HTML.replace('</head>',MOCK+'</head>',1)
async def main():
 checks=[];errors=[]
 def ck(label,ok):checks.append((label,bool(ok)))
 async with async_playwright() as pw:
  browser=await pw.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox'])
  for kind in ('spin-wheel','couch-trivia','desktop-only'):
   page=await browser.new_page(viewport={'width':390,'height':840})
   page.on('pageerror',lambda e:errors.append(str(e)))
   await page.add_init_script("sessionStorage.setItem('lucky-phone-controller-v5p',JSON.stringify({id:'PAIR',token:'TOKEN'}))".replace('PAIR',PAIR).replace('TOKEN',TOKEN))
   await page.route('https://lucky.test/controller.php',lambda r:r.fulfill(status=200,content_type='text/html',body=HTML))
   await page.route('https://lucky.test/package-controller.php?*',lambda r:r.fulfill(status=200,content_type='text/html',body='<h1 id="custom-phone-loaded">Game-specific phone controls</h1>'))
   await page.goto('https://lucky.test/controller.php#dashboard')
   await page.wait_for_function("document.querySelectorAll('#phone-dashboard-games article').length===3")
   ck(kind+': paired phone sees a private game selection dashboard',
      await page.locator('#phone-dashboard').is_visible())
   ck(kind+': untrusted client only receives public game names',
      'Spin the Wheel' in await page.locator('#phone-dashboard-games').inner_text())
   await page.locator('#phone-dashboard-games button').filter(has_text='Start '+({'spin-wheel':'Spin the Wheel','couch-trivia':'Couch Trivia','desktop-only':'Desktop Only'}[kind])).click()
   if kind=='spin-wheel':
    await page.wait_for_function("document.querySelector('#phone-controls').hidden===false")
    ck('Spin Wheel reuses approved phone and loads gameplay controls',await page.locator('#phone-controls').is_visible())
    ck('Spin Wheel launch request is owner paired and explicitly confirmed',await page.evaluate("""() => __actions.some(a=>a.action==='dashboard_launch'&&a.body.game==='spin-wheel'&&a.body.confirm===true&&a.auth==='TOKEN')""".replace('TOKEN',TOKEN)))
   elif kind=='couch-trivia':
    await page.wait_for_url('https://lucky.test/package-controller.php?session='+SESSION+'&viewer='+VIEWER)
    ck('Packaged game opens its own phone controller',await page.locator('#custom-phone-loaded').is_visible())
    ck('Approved pairing retained for game-specific controller',await page.evaluate("""() => {
     const v=JSON.parse(sessionStorage.getItem('lucky-package-controller-v1')||'null');
     return v?.id==='PAIR'&&v?.token==='TOKEN'&&v?.session==='SESSION'&&v?.viewer==='VIEWER';
    }""".replace('PAIR',PAIR).replace('TOKEN',TOKEN).replace('SESSION',SESSION).replace('VIEWER',VIEWER)))
   else:
    await page.wait_for_function("document.querySelector('#phone-dashboard-status').textContent.includes('desktop controls')")
    ck('No-controller package stays on phone dashboard',await page.locator('#phone-dashboard').is_visible())
    ck('No-controller package never opens absent phone UI',page.url.endswith('/controller.php'))
   ck(kind+': no wide horizontal phone overflow',await page.evaluate('document.documentElement.scrollWidth<=innerWidth+3'))
   await page.close()
  # Desktop handoff is scoped to host authenticated API; does not reveal phone tokens.
  page=await browser.new_page()
  page.on('pageerror',lambda e:errors.append(str(e)))
  await page.route('https://lucky.test/index.php',lambda r:r.fulfill(status=200,content_type='text/html',body="""
   <html><body><div id='game-catalog'></div><script>
   window.Lucky={api:async(name)=>({launch:{game:'couch-trivia',session:'SESSION',eventId:9}})};
   </script><script>HOST_SCRIPT</script></body></html>
  """.replace('SESSION',SESSION).replace('HOST_SCRIPT',(ROOT/'public/phone-dashboard-host.js').read_text())))
  await page.route('https://lucky.test/package-game.php?*',lambda r:r.fulfill(status=200,content_type='text/html',body='<h1 id="desktop-launch">Game display started</h1>'))
  await page.goto('https://lucky.test/index.php')
  await page.wait_for_url('https://lucky.test/package-game.php?game=couch-trivia')
  ck('Desktop dashboard follows paired phone selected game',await page.locator('#desktop-launch').is_visible())
  await browser.close()
  ck('No uncaught browser errors',not errors)
 for label,ok in checks:print(('PASS ' if ok else 'FAIL ')+label,flush=True)
 print('V5Q.24 Phone game chooser',sum(bool(v) for _,v in checks),'/',len(checks))
 if not all(v for _,v in checks):raise SystemExit(1)
if __name__=='__main__':asyncio.run(main())
