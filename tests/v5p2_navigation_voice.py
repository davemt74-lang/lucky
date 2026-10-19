"""V5P.2 browser acceptance (isolated Chrome with mocked server)."""
import asyncio,sys
from pathlib import Path
from playwright.async_api import async_playwright
sys.path.insert(0,str(Path(__file__).parent))
from v5p_pairing import make_html,ID,SECRET

PHONE_MOCK='''<script>
Object.defineProperty(window,'isSecureContext',{value:true,configurable:true});
window.VoiceTest=class {
 static last=null;
 constructor(){VoiceTest.last=this;this.continuous=false;this.interimResults=false;}
 start(){this.started=true;}
 stop(){this.stopped=true;}
 speak(text){this.onresult?.({results:[{isFinal:true,0:{transcript:text}}]});}
};
window.SpeechRecognition=window.VoiceTest;
window.commands=[];
const priorFetch=window.fetch;
window.fetch=async(url,opts={})=>{
 const action=new URL(String(url),'https://example.test/').searchParams.get('action');
 if(action==='status')return new Response(JSON.stringify({ok:true,status:'paired',deviceName:'Home Phone',game:{status:'active',spinCount:3,claimedCount:3,remaining:4,canSpin:true,canStart:false}}));
 if(action==='command'){const c=JSON.parse(opts.body);commands.push(c);return new Response(JSON.stringify({ok:true,applied:true,effect:c.command==='spin'?'award':'executed',game:{status:'active',spinCount:4,claimedCount:4,remaining:3,canSpin:true,canStart:false}}));}
 return priorFetch(url,opts);
};
</script>'''

def check(out,key,condition):out.append((key,bool(condition)))
async def main():
 tests=[]
 async with async_playwright() as pw:
  browser=await pw.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox'])
  ctx=await browser.new_context(viewport={'width':1080,'height':760},reduced_motion='reduce')
  pg=await ctx.new_page();errors=[];pg.on('pageerror',lambda e:errors.append(str(e)))
  await pg.set_content(make_html('game'),wait_until='domcontentloaded');await pg.wait_for_timeout(180)
  check(tests,'Shared navigator loaded on host',await pg.evaluate('Boolean(window.LuckyConsole)'))
  # Unified proof is a separate desktop page; host arrows focus actual tabs/controls.
  check(tests,'No duplicate in-game proof popup',await pg.locator('#win-dialog').count()==0)
  await pg.locator('#host-tab-game').focus()
  await pg.evaluate("LuckyHostRemote('right')")
  check(tests,'Phone right arrow navigates game controls',await pg.evaluate('document.activeElement.matches("button,a,input,select")'))
  await pg.evaluate("LuckyHostRemote('select')")
  check(tests,'Phone Select activates focused UI',await pg.locator('#host-panel-music').is_visible())
  await pg.keyboard.press('ArrowDown')
  check(tests,'Keyboard arrows focus actionable element',await pg.evaluate('document.activeElement.matches("button,a,input,select")'))
  await pg.evaluate("LuckyHostRemote('menu')")
  check(tests,'Phone Menu opens navigable host menu',await pg.evaluate("document.querySelector('#console-menu').open"))
  await pg.evaluate("LuckyHostRemote('done')")
  check(tests,'Phone Done closes host menu',await pg.evaluate("!document.querySelector('#console-menu').open"))
  check(tests,'Host no JavaScript exceptions',not errors)
  await ctx.close()
  ctx=await browser.new_context(viewport={'width':390,'height':844},reduced_motion='reduce')
  pg=await ctx.new_page();errors=[];pg.on('pageerror',lambda e:errors.append(str(e)))
  html=make_html('controller').replace('</head>',PHONE_MOCK+'</head>',1)
  html=html.replace('<base href="https://example.test/">','<base href="https://example.test/controller.php?invite='+ID+'&key='+SECRET+'">')
  await pg.set_content(html,wait_until='domcontentloaded')
  await pg.locator('#phone-name').fill('Home Phone');await pg.locator('#phone-join-form button').click();await pg.wait_for_timeout(1920)
  check(tests,'Phone connected and controller displayed',await pg.locator('#phone-controls').is_visible())
  check(tests,'Phone no chat field',await pg.locator('#phone-command-input,#phone-command-form').count()==0)
  check(tests,'Phone controller title removed',await pg.get_by_role('heading',name='Phone Controller').count()==0)
  check(tests,'Phone has single microphone control',await pg.locator('#phone-mic-toggle').count()==1)
  await pg.locator('#phone-mic-toggle').click()
  check(tests,'Microphone listening enabled',await pg.locator('#phone-mic-toggle').get_attribute('aria-pressed')=='true')
  await pg.evaluate("VoiceTest.last.speak('done')");await pg.wait_for_timeout(100)
  check(tests,'Spoken Done routes to command',await pg.evaluate("commands.filter(x=>x.command==='done').length===1"))
  await pg.evaluate("VoiceTest.last.speak('spin')");await pg.wait_for_timeout(100)
  check(tests,'Spoken Spin sends server command',await pg.evaluate("commands.some(x=>x.command==='spin')"))
  await pg.evaluate("VoiceTest.last.speak('stop listening')");await pg.wait_for_timeout(70)
  check(tests,'Stop listening voice command toggles microphone off',await pg.locator('#phone-mic-toggle').get_attribute('aria-pressed')=='false')
  check(tests,'Phone status feedback available',await pg.locator('#phone-status').count()==1)
  check(tests,'Phone voice dock fits narrow screen',await pg.evaluate('document.documentElement.scrollWidth<=innerWidth+1'))
  check(tests,'Phone voice and navigation no JavaScript exceptions',not errors)
  await ctx.close();await browser.close()
 for t,passed in tests:print(('PASS' if passed else 'FAIL'),t)
 print('V5P.2 navigation + voice:',sum(x for _,x in tests),'/',len(tests))
 if not all(x for _,x in tests):raise SystemExit(1)
if __name__=='__main__':asyncio.run(main())
