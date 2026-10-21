"""Browser tests: phone commands acknowledge only when acted on, TV/host control listeners, late join state."""
import asyncio,sys
from pathlib import Path
from playwright.async_api import async_playwright
sys.path.insert(0,str(Path(__file__).resolve().parent))
from v5p_pairing import make_html,ID,SECRET,VIEW

HOST = '''<script>
window.events=[];window.acks=[];window.ackOutcomes=[];window.sessionView={id:'a'.repeat(32),status:'active',game:'spin-wheel',version:1,spinCount:1,slots:[{tier:'tier1',name:'Prize',value:1250}],claims:[],pendingBonus:false,lastSpin:null};
const oldfetch=window.fetch;
window.fetch=async(url,opts={})=>{const action=new URL(String(url),'https://example.test/').searchParams.get('action');
if(action==='current')return new Response(JSON.stringify({ok:true,data:{session:{pools:{bonus:{prizes:[]}},odds:{bonusChance:0},claims:[]},view:window.sessionView,viewerToken:'c'.repeat(48)}}));
if(action==='ui_events'){const after=JSON.parse(opts.body).after;return new Response(JSON.stringify({ok:true,events:window.events.filter(x=>x.id>after)}));}
if(action==='ui_ack'){window.acks.push(JSON.parse(opts.body).commandId);window.ackOutcomes.push(JSON.parse(opts.body));return new Response(JSON.stringify({ok:true,acknowledged:true}));}
if(action==='tracks')return new Response(JSON.stringify({ok:true,tracks:[]}));
return oldfetch(url,opts);
};
</script>'''
TV='''<script>
window.events=[];window.acks=[];window.ackOutcomes=[];window.viewerGame={id:'a'.repeat(32),status:'active',game:'spin-wheel',version:1,spinCount:1,slots:[{tier:'tier1',name:'Prize',value:1250}],bonusSlices:[],claims:[],pendingBonus:false,lastSpin:null};
const oldfetch=window.fetch;
window.fetch=async(url,opts={})=>{const action=new URL(String(url),'https://example.test/').searchParams.get('action');
if(action==='watch')return new Response(JSON.stringify({ok:true,view:window.viewerGame}));
if(action==='ui_events'){const after=JSON.parse(opts.body).after;return new Response(JSON.stringify({ok:true,events:window.events.filter(x=>x.id>after)}));}
if(action==='ui_ack'){window.acks.push(JSON.parse(opts.body).commandId);window.ackOutcomes.push(JSON.parse(opts.body));return new Response(JSON.stringify({ok:true,acknowledged:true}));}
if(action==='tracks')return new Response(JSON.stringify({ok:true,tracks:[]}));
return oldfetch(url,opts);
};
</script>'''
PHONE='''<script>
window.mockPhone={id:'a'.repeat(32),status:'active',version:2,spinCount:4,claimedCount:2,remaining:6,pendingBonus:false,canStart:false,canSpin:true,lastPrize:'Prize B'};
window.commands=[];window.execution={};const oldfetch=window.fetch;
window.fetch=async(url,opts={})=>{const action=new URL(String(url),'https://example.test/').searchParams.get('action');
if(action==='claim')return new Response(JSON.stringify({ok:true,id:'a'.repeat(32),controllerToken:'d'.repeat(64),status:'pending'}));
if(action==='status'){const cmd=JSON.parse(opts.body).commandId;return new Response(JSON.stringify({ok:true,status:'paired',deviceName:'Mobile',game:mockPhone,delivery:cmd?execution[cmd]||'pending':null}));}
if(action==='command'){let {command,confirm}=JSON.parse(opts.body);commands.push({command,confirm});return new Response(JSON.stringify(command==='end'?{ok:true,accepted:true,applied:true,effect:'ended',game:{...mockPhone,status:'ended',canStart:true,canSpin:false}}:{ok:true,accepted:true,applied:false,commandId:commands.length,game:mockPhone}),{status:command==='end'?200:202});}
return oldfetch(url,opts);
};</script>'''

async def main():
 checks=[]
 def ck(msg,valid):checks.append((msg,bool(valid)))
 async with async_playwright() as pw:
  browser=await pw.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox'])
  for kind,extra in [('game',HOST),('display',TV)]:
   
   ctx=await browser.new_context(viewport={'width':1280,'height':800},reduced_motion='reduce')
   page=await ctx.new_page();page.set_default_timeout(3500);err=[];page.on('pageerror',lambda e:err.append(str(e)))
   await page.set_content(make_html(kind).replace('</head>',extra+'</head>',1),wait_until='domcontentloaded')
   await page.wait_for_timeout(900)
   await page.evaluate("events.push({id:1,command:'menu',deviceName:'Phone'})")
   await page.wait_for_timeout(1200)
   ck(f'{kind} menu action received',await page.locator('#console-menu').evaluate('(e)=>e.open') if kind=='game' else await page.locator('#display-remote-menu').is_visible())
   ck(f'{kind} menu acknowledged',await page.evaluate('acks.includes(1)'))
   await page.evaluate("events.push({id:2,command:'back',deviceName:'Phone'})")
   await page.wait_for_timeout(1200)
   ck(f'{kind} back closes menu',not(await page.locator('#console-menu').evaluate('(e)=>e.open') if kind=='game' else await page.locator('#display-remote-menu').is_visible()))
   ck(f'{kind} back acknowledged',await page.evaluate('acks.includes(2)'))
   await page.evaluate("events.push({id:3,command:'volume-up',deviceName:'Phone'})")
   await page.wait_for_timeout(1100)
   ck(f'{kind} volume controlled',await page.locator('#music-volume').input_value()=='65')
   ck(f'{kind} volume action acknowledged',await page.evaluate('acks.includes(3)'))
   await page.evaluate("events.push({id:4,command:'mute',deviceName:'Phone'})")
   await page.wait_for_timeout(1100)
   ck(f'{kind} mute action actually applied',await page.evaluate('Lucky.pref.muted'))
   ck(f'{kind} mute acknowledged',await page.evaluate('acks.includes(4)'))
   await page.evaluate("events.push({id:5,command:'music-next',deviceName:'Phone'})")
   await page.wait_for_timeout(1000)
   ck(f'{kind} missing-song failure is returned to controller',await page.evaluate('ackOutcomes.some(x=>x.commandId===5&&x.success===false)'))
   ck(f'{kind} JS no unhandled errors',not err)
   await ctx.close()
  ctx=await browser.new_context(viewport={'width':390,'height':800},reduced_motion='reduce')
  page=await ctx.new_page();page.set_default_timeout(3500);err=[];page.on('pageerror',lambda e:err.append(str(e)))
  html=make_html('controller').replace('<base href="https://example.test/">','<base href="https://example.test/controller.php?invite='+ID+'&key='+SECRET+'">').replace('</head>',PHONE+'</head>',1)
  await page.set_content(html,wait_until='domcontentloaded');await page.locator('#phone-name').fill('Mobile');await page.locator('#phone-join-form button').click();await page.wait_for_timeout(3200)
  ck('joining ongoing game reports 4 spins','4 spins' in await page.locator('#phone-game-state').inner_text())
  ck('joining ongoing game disables Start',await page.locator('[data-phone=start]').is_disabled())
  await page.locator('[data-phone=music-off]').click();await page.wait_for_timeout(150)
  ck('queue is not misreported as executed','Waiting for game screen' in await page.locator('#phone-status').inner_text())
  await page.evaluate('execution[1]="executed"');await page.wait_for_timeout(1100)
  ck('phone sees remote acknowledgment','completed on game screen' in await page.locator('#phone-status').inner_text())
  await page.evaluate('mockPhone.status="paused";mockPhone.canSpin=false');await page.wait_for_timeout(3200)
  ck('remote pause blocks Spin',await page.locator('[data-phone=spin]').is_disabled())
  ck('remote pause displays Resume', 'Resume' in await page.locator('[data-phone=pause]').inner_text())
  ck('phone browser clean',not err)
  await browser.close()
 for name,yes in checks:print(('PASS' if yes else 'FAIL')+' '+name)
 print('UI sync:',sum(1 for _,yes in checks if yes),'/',len(checks))
 if not all(yes for _,yes in checks):raise SystemExit(1)
if __name__=='__main__':asyncio.run(main())
