"""V5Q.23A browser fault injection: lost command response, nonce replay,
temporary offline status, refresh/reconnect, and End Game state convergence."""
import asyncio
from playwright.async_api import async_playwright
from v5q22h_phone_browser import HTML as SOURCE, FRAME, SID, VIEWER
HTML=SOURCE.replace(
 "window.confirm=()=>true;window.__approved=false;window.__revoked=false;window.__commands=[];",
 "window.confirm=()=>true;window.__approved=false;window.__revoked=false;window.__commands=[];window.__dropOnce=true;window.__offline=false;window.__applied=new Set();"
)
HTML=HTML.replace(
 " if(action==='package_status'){",
 " if(action==='package_status'){if(__offline)throw new TypeError('Wi-Fi temporarily disconnected');"
)
HTML=HTML.replace(
 "  if(input.verb==='input')__game={",
 "  if(__applied.has(input.nonce))return new Response(JSON.stringify({ok:true,repeat:true,game:__game}));\n"
 "  __applied.add(input.nonce);\n"
 "  if(input.verb==='input')__game={"
)
HTML=HTML.replace(
 "  return new Response(JSON.stringify({ok:true,applied:true,game:__game}));",
 "  if(input.verb==='input'&&__dropOnce){__dropOnce=false;throw new TypeError('Wi-Fi interrupted after command commit');}\n"
 "  return new Response(JSON.stringify({ok:true,applied:true,game:__game}));"
)
# Mock the independent HomeServer database across a page reload. The phone
# page may refresh, but the server's session and nonce ledger must not reset.
HTML=HTML.replace("window.__approved=false;","window.__approved=sessionStorage.getItem('cert-approved')==='yes';")
HTML=HTML.replace("window.__commands=[];","window.__commands=JSON.parse(sessionStorage.getItem('cert-commands')||'[]');")
HTML=HTML.replace("window.__dropOnce=true;","window.__dropOnce=sessionStorage.getItem('cert-dropped')!=='yes';")
HTML=HTML.replace("window.__applied=new Set();","window.__applied=new Set(JSON.parse(sessionStorage.getItem('cert-applied')||'[]'));")
HTML=HTML.replace(
 "window.__game={id:'"+SID+"',game:'couch-trivia',status:'active',version:1,packageVersion:'1.0.0'};",
 "window.__game=JSON.parse(sessionStorage.getItem('cert-game')||JSON.stringify({id:'"+SID+"',game:'couch-trivia',status:'active',version:1,packageVersion:'1.0.0'}));"
)
HTML=HTML.replace(
 "  if(__applied.has(input.nonce))return",
 "  sessionStorage.setItem('cert-commands',JSON.stringify(__commands));\n"
 "  if(__applied.has(input.nonce))return"
)
HTML=HTML.replace(
 "  __applied.add(input.nonce);",
 "  __applied.add(input.nonce);sessionStorage.setItem('cert-applied',JSON.stringify([...__applied]));"
)
HTML=HTML.replace(
 "  if(input.verb==='input'&&__dropOnce){__dropOnce=false;throw",
 "  sessionStorage.setItem('cert-game',JSON.stringify(__game));\n"
 "  if(input.verb==='input'&&__dropOnce){__dropOnce=false;sessionStorage.setItem('cert-dropped','yes');throw"
)

async def main():
 checks=[];errors=[]
 def verify(name,passed):checks.append((name,bool(passed)))
 async with async_playwright() as pw:
  browser=await pw.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox'])
  page=await browser.new_page(viewport={'width':390,'height':844})
  page.on('pageerror',lambda e:errors.append(str(e)))
  await page.route('https://lucky.test/package-controller.php?*',
   lambda route:route.fulfill(status=200,content_type='text/html',body=HTML))
  await page.route('https://lucky.test/package-view.php?*',
   lambda route:route.fulfill(status=200,content_type='text/html',
     headers={'Content-Security-Policy':"sandbox allow-scripts; default-src 'none'; script-src 'unsafe-inline'; connect-src 'none'"},
     body=FRAME))
  await page.goto('https://lucky.test/package-controller.php?session='+SID+'&viewer='+VIEWER)
  await page.locator('#package-phone-name').fill('Recovery Phone')
  await page.locator('#package-phone-join-form button').click()
  await page.wait_for_function("document.getElementById('package-phone-wait').hidden===false")
  await page.evaluate("window.__approved=true;sessionStorage.setItem('cert-approved','yes')")
  await page.wait_for_function("document.getElementById('package-phone-play').hidden===false")
  await page.frame_locator('#package-phone-frame').locator('#buzz').click()
  await page.wait_for_function("document.getElementById('package-phone-retry').hidden===false")
  verify('Dropped response exposes explicit retry',await page.locator('#package-phone-retry').is_visible())
  verify('Lost response happened after exactly one persisted input',await page.evaluate("__game.version===2&&__commands.filter(c=>c.verb==='input').length===1"))
  verify('Pending command blocks another accidental input',await page.locator('#package-phone-pause').is_disabled())
  verify('Retry details persist across refresh',await page.evaluate("sessionStorage.getItem('lucky-package-controller-v1-pending')!==null"))
  await page.reload()
  await page.wait_for_function("document.getElementById('package-phone-play').hidden===false")
  verify('Reconnected phone restores pending retry after refresh',await page.locator('#package-phone-retry').is_visible())
  await page.locator('#package-phone-retry').click()
  await page.wait_for_function("document.getElementById('package-phone-retry').hidden===true")
  nonces=await page.evaluate("() => __commands.filter(c=>c.verb==='input').map(c=>c.nonce)")
  verify('Retry uses identical nonce instead of generating another action',len(nonces)==1 or len(nonces)==2 and nonces[0]==nonces[1])
  verify('Original game action is not applied twice',await page.evaluate("__game.version===2"))
  verify('Pending retry cleared only after server acknowledgement',await page.evaluate("sessionStorage.getItem('lucky-package-controller-v1-pending')===null"))
  await page.evaluate("window.__offline=true")
  await page.wait_for_function("document.getElementById('package-phone-wait').hidden===false",timeout=5000)
  verify('Offline phone stops presenting active controls',await page.locator('#package-phone-play').is_hidden())
  await page.evaluate("window.__offline=false")
  await page.wait_for_function("document.getElementById('package-phone-play').hidden===false",timeout=5000)
  verify('Reconnected phone reopens its own game controller',await page.frame_locator('#package-phone-frame').locator('#buzz').count()==1)
  await page.locator('#package-phone-end').click()
  await page.wait_for_function("document.getElementById('package-phone-finished').hidden===false")
  verify('End Game closes controller after authoritative state',await page.locator('#package-phone-frame').is_hidden())
  verify('Network fault did not create unhandled browser exception',not errors)
  await browser.close()
 for label,result in checks:print(('PASS ' if result else 'FAIL ')+label,flush=True)
 print('V5Q.23A Wi-Fi and replay browser certification:',sum(1 for _,yes in checks if yes),'/',len(checks))
 if not all(value for _,value in checks):raise SystemExit(1)
if __name__=='__main__':asyncio.run(main())
