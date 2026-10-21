"""V5Q.24 Chromium acceptance of sandboxed host SDK capability RPC.
The existing game host, client SDK, and server contract are exercised without
installing a second consumer game.
"""
import asyncio
from pathlib import Path
from playwright.async_api import async_playwright
from v5q22g_package_browser import PAGE as OLD_PAGE,SID,TOKEN
ROOT=Path(__file__).resolve().parents[1]
SDK=(ROOT/'sdk/lucky-game.js').read_text()
HTML=OLD_PAGE.replace(
 "  if(action==='start'){",
 """  if(action==='plugin_capability'){
   if(!__view||__view.view.status!=='active')throw Error('Session not active');
   if(options.body.id!=='SESSION')throw Error('Wrong SDK session');
   const name=options.body.operation;
   const opValue=name==='players.add'?{id:'alice',name:'Alice'}:
     name==='scores.add'?{playerId:'alice',score:100}:
     name==='storage.get'?{key:'level',value:5,revision:2}:
     {status:'saved'};
   const plugin={players:{alice:{id:'alice',name:'Alice'}},scores:{alice:100},round:1,turn:'alice',timers:{}};
   const view={...__view.view,version:__view.view.version+1,plugin,
    status:name==='session.save'?'saved':__view.view.status};
   __view={...__view,view};
   return {result:{capability:{operation:name,value:opValue,version:view.version},view}};
  }
  if(action==='start'){""".replace('SESSION',SID)
)
FRAME='''<!doctype html><html><body><h2>SDK fixture</h2>
<script>SDK_SOURCE</script><script>
window.completed=[];window.sdkErrors=[];
window.runSdk=async()=>{
 try{
  const player=await LuckyGameSDK.players.add('alice','Alice');
  completed.push(['player',player]);
  const score=await LuckyGameSDK.scores.add('alice',100);
  completed.push(['score',score]);
  const storage=await LuckyGameSDK.storage.get('level');
  completed.push(['storage',storage]);
 }catch(e){sdkErrors.push(e.message)}
};
</script></body></html>'''.replace('SDK_SOURCE',SDK.replace('</script','<\\/script'))
async def main():
 checks=[];exceptions=[]
 def ck(name,valid):checks.append((name,bool(valid)))
 async with async_playwright() as pw:
  browser=await pw.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox'])
  p=await browser.new_page()
  p.on('pageerror',lambda e:exceptions.append(str(e)))
  await p.route('https://lucky.test/package-game.php?game=ci-sdk',
                lambda r:r.fulfill(status=200,content_type='text/html',body=HTML))
  await p.route('https://lucky.test/package-view.php?*',
                lambda r:r.fulfill(status=200,content_type='text/html',body=FRAME,
                  headers={'Content-Security-Policy':"sandbox allow-scripts; default-src 'none'; script-src 'unsafe-inline'; connect-src 'none'; form-action 'none'"}))
  await p.route('https://lucky.test/index.php',
                lambda r:r.fulfill(status=200,content_type='text/html',body='<h1>Games</h1>'))
  await p.goto('https://lucky.test/package-game.php?game=ci-sdk')
  await p.locator('#pkg-start').click()
  frame=p.frame_locator('#package-frame')
  await frame.locator('h2').wait_for()
  await p.wait_for_function("document.querySelector('#package-frame').contentWindow!==null")
  await frame.locator('body').evaluate('() => window.runSdk()')
  await p.wait_for_function("__calls.filter(x=>x.action==='plugin_capability').length===3",timeout=7000)
  ck('Sandboxed game can request three typed capabilities',
     await p.evaluate("__calls.filter(x=>x.action==='plugin_capability').length===3"))
  ck('All writes use exact persisted session and expected version',
     await p.evaluate("""() => __calls.filter(x=>x.action==='plugin_capability')
       .every(x=>x.options.body.id==='SESSION' && Number.isInteger(x.options.body.expectedVersion)
       && typeof x.options.body.idempotencyKey==='string')""".replace('SESSION',SID)))
  ck('Player mutation persisted by authenticated host',
     await p.evaluate("""() => __calls.some(x=>x.action==='plugin_capability'&&x.options.body.operation==='players.add'
       &&x.options.body.args.name==='Alice')"""))
  ck('Score mutation uses server-authoritative capability',
     await p.evaluate("""() => __calls.some(x=>x.action==='plugin_capability'&&x.options.body.operation==='scores.add'
       &&x.options.body.args.delta===100)"""))
  ck('Host returns scoped storage revision',
     await frame.locator('body').evaluate("() => completed.some(x=>x[0]==='storage'&&x[1].revision===2)"))
  ck('Package game receives state plugin scoreboard',
     await frame.locator('body').evaluate("() => LuckyGameSDK.getState().plugin?.scores?.alice===100"))
  ck('Sandbox has null origin',
     await frame.locator('body').evaluate('() => origin==="null"'))
  ck('All SDK operations resolved without errors',
     await frame.locator('body').evaluate('() => sdkErrors.length===0 && completed.length===3'))
  before=await p.evaluate("__calls.length")
  await p.evaluate("""() => window.postMessage({type:'lucky:capability',
    requestId:'sdk-fake-xxxxxxxx',operation:'scores.add',args:{playerId:'alice',delta:50}},'*')""")
  await p.wait_for_timeout(100)
  ck('Message from wrong origin/source cannot invoke SDK API',
     await p.evaluate("__calls.length")==before)
  ck('No unhandled Chromium script errors',not exceptions)
  await browser.close()
 for label,passed in checks:print(('PASS ' if passed else 'FAIL ')+label,flush=True)
 print('V5Q.24 Chromium SDK:',sum(int(p) for _,p in checks),'/',len(checks))
 if not all(p for _,p in checks):raise SystemExit(1)
if __name__=='__main__':asyncio.run(main())
