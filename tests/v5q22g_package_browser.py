"""V5Q.22G opaque sandbox and shared session runtime browser contract."""
import asyncio
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[1]
JS=(ROOT/'public/package-game.js').read_text()
SID='a'*32
TOKEN='b'*48
FRAME='''<!doctype html><html><body>
<h1>Trivia game</h1><button data-game-input="answer" data-game-value="A">Answer A</button>
<script>
window.gameStates=[];
window.addEventListener('message',event=>{
 if(event.data?.type==='lucky:state'){gameStates.push(event.data.state);}
});
document.querySelector('[data-game-input]').onclick=()=>{
 parent.postMessage({type:'lucky:input',name:'answer',value:'A'},'*');
};
</script>
</body></html>'''
PAGE='''<!doctype html><html><head><base href="https://lucky.test/"></head><body>
<p id="package-status">Loading</p>
<button id="pkg-start">Start game</button><button id="pkg-pause" disabled>Pause</button>
<button id="pkg-save" disabled>Save session</button><button id="pkg-end" disabled>End game</button>
<iframe id="package-frame" sandbox="allow-scripts" hidden></iframe>
<script>
window.__view=null;window.__calls=[];window.__rejectEnd=false;
window.confirm=()=>true;
window.Lucky={
 idempotency:()=>Math.random().toString(36).slice(2).padEnd(16,'x'),
 api:async(action,options={})=>{
  __calls.push({action,options});
  if(action==='last')return {data:__view};
  if(action==='session')return __view;
  if(action==='start'){
   __view={view:{id:'SESSION',status:'active',version:1,packageVersion:'1.0.0'},
     session:{id:'SESSION',packageVersion:'1.0.0'},viewerToken:'VIEWER'};
   return {data:__view};
  }
  if(action==='end'&&__rejectEnd)throw Error('End rejected');
  if(['game_action','pause','resume','end'].includes(action)){
   const newView={...__view.view,status:action==='end'?'ended':action==='pause'?'paused':
      action==='resume'?'active':__view.view.status,version:__view.view.version+1};
   if(action==='game_action')newView.lastInput={type:'input',name:options.body.payload.name,
     value:options.body.payload.value,version:newView.version};
   __view={...__view,view:newView};return {result:{view:newView}};
  }
  if(action==='save_session')return {result:{view:{...__view.view,status:'saved'}}};
  return {};
 }
};
</script><script>JS_CONTENT</script></body></html>'''
PAGE=PAGE.replace('SESSION',SID).replace('VIEWER',TOKEN).replace('JS_CONTENT',JS.replace('</script','<\\/script'))
async def main():
 checks=[];errors=[]
 def ck(name,condition):checks.append((name,bool(condition)))
 async with async_playwright() as pw:
  browser=await pw.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox'])
  p=await browser.new_page(viewport={'width':1280,'height':840})
  p.on('pageerror',lambda e:errors.append(str(e)))
  await p.route('https://lucky.test/package-game.php?game=couch-trivia',lambda route:route.fulfill(status=200,content_type='text/html',body=PAGE))
  await p.route('https://lucky.test/package-view.php?*',lambda route:route.fulfill(status=200,content_type='text/html',
   headers={'Content-Security-Policy':"sandbox allow-scripts; default-src 'none'; script-src 'unsafe-inline'; connect-src 'none'; form-action 'none'"},
   body=FRAME))
  await p.route('https://lucky.test/index.php',lambda route:route.fulfill(status=200,content_type='text/html',body='<h1>Game dashboard</h1>'))
  await p.goto('https://lucky.test/package-game.php?game=couch-trivia')
  ck('Start is available before a session',await p.locator('#pkg-start').is_enabled())
  await p.locator('#pkg-start').click()
  await p.wait_for_function("document.getElementById('package-frame').hidden===false")
  iframe=p.frame_locator('#package-frame')
  await iframe.locator('[data-game-input=answer]').wait_for()
  ck('Packaged game is iframe sandbox without same-origin',await p.locator('#package-frame').get_attribute('sandbox')=='allow-scripts')
  ck('Packaged UI received persisted session version',await iframe.locator('body').evaluate('() => window.gameStates.length>=1'))
  ck('Pakaged frame has an opaque origin',await iframe.locator('body').evaluate('() => window.origin==="null"'))
  await iframe.locator('[data-game-input=answer]').click()
  await p.wait_for_function("__calls.some(c=>c.action==='game_action')")
  ck('Game input reaches authenticated host API',await p.evaluate("""() => __calls.some(c=>c.action==='game_action'
   &&c.options.body.verb==='input'&&c.options.body.payload.name==='answer'&&c.options.body.payload.value==='A')"""))
  await p.locator('#pkg-pause').click()
  await p.wait_for_function("__calls.some(c=>c.action==='pause')")
  ck('Shared lifecycle pauses game',await p.locator('#pkg-pause').inner_text()=='Resume')
  await p.locator('#pkg-pause').click()
  await p.wait_for_function("__calls.some(c=>c.action==='resume')")
  await p.evaluate('__rejectEnd=true')
  await p.locator('#pkg-end').click()
  await p.wait_for_timeout(100)
  ck('Failed End does not redirect',p.url.endswith('package-game.php?game=couch-trivia'))
  await p.evaluate('__rejectEnd=false')
  await p.locator('#pkg-end').click()
  await p.wait_for_url('https://lucky.test/index.php',timeout=6000)
  ck('Successful End returns to main game dashboard',await p.locator('h1').inner_text()=='Game dashboard')
  ck('No browser exceptions',not errors)
  await browser.close()
 for label,passed in checks:print(('PASS ' if passed else 'FAIL ')+label,flush=True)
 print('V5Q.22G Browser',sum(1 for _,v in checks if v),'/',len(checks))
 if not all(x for _,x in checks):raise SystemExit(1)
if __name__=='__main__':asyncio.run(main())
