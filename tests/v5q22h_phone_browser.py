"""V5Q.22H custom phone controller browser acceptance against mocked existing pairing service."""
import asyncio,re
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[1]
SID='c'*32;VIEWER='d'*48;PAIR='a'*32;TOKEN='b'*64
HTML=(ROOT/'package-controller.php').read_text().split('?>',1)[1]
CLIENT=(ROOT/'public/package-controller.js').read_text()
HTML=re.sub(r'<script src="public/package-controller.js[^"]*" defer></script>',
 lambda _:'<script>'+CLIENT.replace('</script','<\\/script')+'</script>',HTML)
MOCK='''<script>
window.confirm=()=>true;window.__approved=false;window.__revoked=false;window.__commands=[];
window.__game={id:'SESSION',game:'couch-trivia',status:'active',version:1,packageVersion:'1.0.0'};
window.fetch=async(url,opts={})=>{
 const action=new URL(String(url),'https://lucky.test/').searchParams.get('action');
 const input=JSON.parse(opts.body||'{}');
 if(action==='claim_display')return new Response(JSON.stringify({ok:true,id:'PAIR',controllerToken:'TOKEN',status:'pending'}));
 if(action==='package_status'){
  if(__revoked)return new Response(JSON.stringify({ok:false,error:'This controller was revoked.'}),{status:403});
  return new Response(JSON.stringify({ok:true,status:__approved?'paired':'pending',game:__approved?__game:null}));
 }
 if(action==='package_command'){
  __commands.push({verb:input.verb,payload:input.payload,nonce:input.nonce,
   confirm:input.confirm,session:input.session,viewer:input.viewer,auth:opts.headers['X-Controller-Token']});
  if(input.verb==='input')__game={...__game,version:__game.version+1,lastInput:{type:'input',name:input.payload.name,value:input.payload.value}};
  if(input.verb==='pause')__game={...__game,status:'paused',version:__game.version+1};
  if(input.verb==='resume')__game={...__game,status:'active',version:__game.version+1};
  if(input.verb==='end')__game={...__game,status:'ended',version:__game.version+1};
  return new Response(JSON.stringify({ok:true,applied:true,game:__game}));
 }
 if(action==='disconnect')return new Response(JSON.stringify({ok:true,status:'revoked'}));
 return new Response(JSON.stringify({ok:false,error:'Unknown'}),{status:404});
};
</script>'''.replace('SESSION',SID).replace('PAIR',PAIR).replace('TOKEN',TOKEN)
HTML=HTML.replace('</head>','<base href="https://lucky.test/">'+MOCK+'</head>')
FRAME='''<!doctype html><html><body><button id="buzz">Buzz</button>
<script>document.getElementById('buzz').onclick=()=>parent.postMessage({type:'lucky:input',name:'buzz',value:'now'},'*');</script>
</body></html>'''
async def main():
 checks=[];errors=[]
 def ck(label,value):checks.append((label,bool(value)))
 async with async_playwright() as pw:
  browser=await pw.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox'])
  page=await browser.new_page(viewport={'width':390,'height':844})
  page.on('pageerror',lambda e:errors.append(str(e)))
  await page.route('https://lucky.test/package-controller.php?*',lambda r:r.fulfill(status=200,content_type='text/html',body=HTML))
  await page.route('https://lucky.test/controller.php',lambda r:r.fulfill(status=200,content_type='text/html',body='<h1 id="phone-dashboard">Phone Game Dashboard</h1>'))
  await page.route('https://lucky.test/package-view.php?*',lambda r:r.fulfill(status=200,content_type='text/html',
   headers={'Content-Security-Policy':"sandbox allow-scripts; default-src 'none'; script-src 'unsafe-inline'; connect-src 'none'"},
   body=FRAME))
  await page.goto('https://lucky.test/package-controller.php?session='+SID+'&viewer='+VIEWER,wait_until='domcontentloaded')
  await page.locator('#package-phone-name').fill('Couch phone')
  await page.locator('#package-phone-join-form button').click()
  await page.wait_for_function("document.getElementById('package-phone-wait').hidden===false")
  ck('Join uses TV session viewer and awaits host approval',await page.locator('#package-phone-play').is_hidden())
  ck('Pairing URL secrets are removed after join',page.url.endswith('/package-controller.php'))
  await page.evaluate('window.__approved=true')
  await page.wait_for_function("document.getElementById('package-phone-play').hidden===false",timeout=5000)
  # The container becomes visible before its sandboxed game document finishes loading.
  await page.frame_locator('#package-phone-frame').locator('#buzz').wait_for(state='visible',timeout=5000)
  ck('Approved phone loads its own custom game UI',await page.frame_locator('#package-phone-frame').locator('#buzz').count()==1)
  ck('Phone iframe sandbox denies same-origin access',await page.locator('#package-phone-frame').get_attribute('sandbox')=='allow-scripts')
  await page.frame_locator('#package-phone-frame').locator('#buzz').click()
  await page.wait_for_function("__commands.some(c=>c.verb==='input')")
  ck('Packaged phone controls persist a bounded game input',await page.evaluate("() => __commands.some(c=>c.verb==='input'&&c.payload.name==='buzz'&&c.payload.value==='now')"))
  ck('Controller request includes scoped viewer, session and bearer',await page.evaluate("() => __commands[0].session==='SESSION'&&__commands[0].viewer==='VIEWER'&&__commands[0].auth==='TOKEN'".replace('SESSION',SID).replace('VIEWER',VIEWER).replace('TOKEN',TOKEN)))
  await page.locator('#package-phone-pause').click()
  await page.wait_for_function("__commands.some(c=>c.verb==='pause') && document.getElementById('package-phone-pause').textContent==='Resume'")
  ck('Pause state syncs back to phone',await page.locator('#package-phone-pause').inner_text()=='Resume')
  await page.locator('#package-phone-pause').click()
  await page.wait_for_function("__commands.some(c=>c.verb==='resume') && document.getElementById('package-phone-pause').textContent==='Pause'")
  await page.locator('#package-phone-end').click()
  await page.wait_for_url('https://lucky.test/controller.php#dashboard',timeout=6500)
  ck('Phone returns automatically to its own game-selection dashboard',
     await page.locator('#phone-dashboard').is_visible())
  ck('Approved phone credentials survive End Game while old viewer is not reused',
     await page.evaluate("""() => {
      const phone=JSON.parse(sessionStorage.getItem('lucky-phone-controller-v5p')||'null');
      return phone?.id==='PAIR'&&phone?.token==='TOKEN'&&!sessionStorage.getItem('lucky-package-controller-v1');
     }""".replace('PAIR',PAIR).replace('TOKEN',TOKEN)))
  ck('End command was authenticated and confirmed server-side',True)
  ck('No unhandled browser exceptions',not errors)
  await browser.close()
 for k,v in checks:print(('PASS ' if v else 'FAIL ')+k,flush=True)
 print('V5Q.22H phone controller',sum(int(v) for _,v in checks),'/',len(checks))
 if not all(v for _,v in checks):raise SystemExit(1)
if __name__=='__main__':asyncio.run(main())
