"""Isolated Chromium UI tests with a mocked API. No live database or microphone testing."""
import asyncio, re, json
from pathlib import Path
from playwright.async_api import async_playwright
BASE=Path(__file__).resolve().parents[1]
VIEWS=BASE/'tests/fixtures'
CSS=(BASE/'public/style.css').read_text()+'\n'+(BASE/'public/platform.css').read_text()
MOCK=r'''<script>
window.__api={spin:0,posts:[],active:false,settings:{config:{pools:{tier1:{budgetCents:30000,prizes:[{id:'t1-1',name:'Gift Card',cents:3750,url:'',image:''}]},tier2:{budgetCents:3000,prizes:[{id:'t2-1',name:'Bonus Card',cents:3000,url:'',image:''}]},bonus:{budgetCents:2500,prizes:[{id:'bon-1',name:'Mega Prize',cents:2500,url:'',image:''}]}},odds:{bonusChance:12,tier1Weight:1,tier2Weight:1,bonusWeight:1}},intro:{title:'Spin the Wheel',subtitle:'Come and win!',volume:70,trackId:'',announcement:true},theme:{accent:'#f6cb74',background:'#0d1423'}},tracks:[],data:null};
const demoId='a'.repeat(32),viewerToken='b'.repeat(48);
window.__api.create=()=>{const api=window.__api;api.active=true;
 const view={id:demoId,status:'active',spinCount:0,version:1,slots:[{name:'Gift Card',value:3750,tier:'tier1',pending:null}],claims:[],lastSpin:null,pendingBonus:false};
 api.data={viewerToken,view,session:{id:demoId,pools:JSON.parse(JSON.stringify(api.settings.config.pools)),odds:api.settings.config.odds,claims:[]}};
 return api.data;};
window.fetch=async function(url,opt={}){
 const api=window.__api,act=(new URL(String(url),'https://example.test/')).searchParams.get('action');let out={ok:true};
 const input=typeof opt.body==='string'?JSON.parse(opt.body):{};
 if(opt.method==='POST')api.posts.push({action:act,input});
 if(act==='me')out={ok:true,admin:true,csrf:'test-csrf'};
 else if(act==='last')out={ok:true,data:api.data};
 else if(act==='tracks')out={ok:true,tracks:api.tracks};
 else if(act==='settings')out={ok:true,settings:api.settings};
 else if(act==='history')out={ok:true,history:[{id:demoId,claims:1,spins:1,awardedCents:3750,endedAt:'2026-10-17 20:00',createdAt:'2026-10-17 19:00'}]};
 else if(act==='settings_save'){api.settings={...api.settings,...input};out={ok:true,settings:api.settings};}
 else if(act==='start')out={ok:true,data:api.create()};
 else if(act==='spin'){
  const c={id:'c'+(api.spin+1),prizeId:'t1-1',name:'Gift Card',tier:'tier1',cents:3750,url:'',image:'',at:'2026-10-17T12:00:00Z',slot:1};api.spin++;
  const anim={index:0,slices:[{name:'Gift Card',cents:3750,tier:'tier1',kind:'prize'}],spinCount:api.spin,type:'award'};
  api.data.view={...api.data.view,version:2,spinCount:api.spin,lastSpin:anim,claims:[c],slots:[{name:'Bonus Card',value:3000,tier:'tier2',pending:null}]};
  api.data.session.claims=[c];out={ok:true,result:{event:{type:'award',claim:c,animation:anim},view:api.data.view}};
 }
 else if(act==='pause'){api.data.view.status='paused';api.data.view.version++;out={ok:true,result:{event:{type:'paused'}}};}
 else if(act==='resume'){api.data.view.status='active';api.data.view.version++;out={ok:true,result:{event:{type:'resumed'}}};}
 else if(act==='end'){api.data.view.status='ended';api.data.view.version++;out={ok:true,result:{event:{type:'ended'}}};}
 else if(act==='session')out={ok:true,...api.data};
 else if(act==='watch')out={ok:true,view:api.data?.view||api.create().view};
 else if(act==='track_add'){api.tracks.push({id:'t1',title:input.title,kind:'url',src:input.url});out={ok:true};}
 else if(act==='track_remove'){api.tracks=[];out={ok:true};}
 else if(act==='logout')out={ok:true};
 else out={ok:true};
 return new Response(JSON.stringify(out),{status:200,headers:{'Content-Type':'application/json'}});
};
</script>'''

def prepare(kind):
 text=(VIEWS/f'{kind}.html').read_text()
 text=re.sub(r'<link rel="stylesheet" href="public/style.css"><link rel="stylesheet" href="public/platform.css">', lambda m:'<style>'+CSS+'</style>',text)
 # Insert mock before JS and CSS.
 text=text.replace('</head>','<base href="https://example.test/">'+MOCK+'</head>',1)
 def script_repl(m):
  src=m.group(1)
  code=(BASE/src).read_text()
  return '<script>'+code.replace('</script','<\\/script')+'</script>'
 text=re.sub(r'<script src="([^"]+)" defer></script>',script_repl,text)
 return text

async def main():
 results=[]
 async with async_playwright() as playwright:
  browser=await playwright.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-web-security'])
  async def page_for(kind,w=1280,h=900):
   context=await browser.new_context(viewport={'width':w,'height':h},reduced_motion='reduce',permissions=[])
   page=await context.new_page();errors=[];page.on('pageerror',lambda ex:errors.append(str(ex)))
   await page.set_content(prepare(kind),wait_until='domcontentloaded');return page,context,errors
  p,c,e=await page_for('hub');results.append(('hub game card visible',await p.get_by_text('Spin the Wheel').count()>0));results.append(('hub Game 2 unavailable',await p.get_by_text('Coming next').count()>0));await c.close()
  p,c,e=await page_for('admin');await p.wait_for_timeout(450)
  results.append(('admin loaded config',await p.locator('#prize-items input').count()==5))
  await p.locator('#add-prize input[name="name"]').fill('Gift Basket')
  await p.locator('#add-prize input[name="value"]').fill('25')
  await p.locator('#add-prize button').click()
  results.append(('admin add prize',await p.get_by_text('Gift Basket').count()>0 or await p.locator('#prize-items input').count()==10))
  await p.locator('#save-prizes').click();await p.wait_for_timeout(150)
  results.append(('admin saves settings',await p.evaluate("__api.posts.some(x=>x.action==='settings_save')")))
  await p.locator('[data-tab="music"]').click()
  results.append(('admin music tab opens',await p.locator('#tab-music').is_visible()))
  await p.locator('#music-add-url input[name="title"]').fill('Theme Song')
  await p.locator('#music-add-url input[name="url"]').fill('https://example.com/theme.mp3')
  await p.locator('#music-add-url button').click();await p.wait_for_timeout(120)
  results.append(('hosted song accepted',await p.evaluate("__api.tracks.length===1")))
  results.append(('admin no uncaught JS errors',not e))
  await p.screenshot(path='/mnt/data/Lucky-Game-Hub-V5M-admin-preview.png',full_page=True);await c.close()
  p,c,e=await page_for('game',1280,900);await p.wait_for_timeout(300)
  results.append(('game start initially enabled',await p.locator('#start-game').is_enabled()))
  results.append(('game spin initially disabled',not await p.locator('#spin-game').is_enabled()))
  await p.locator('#start-game').click();await p.wait_for_timeout(120)
  results.append(('game start activates spin',await p.locator('#spin-game').is_enabled()))
  results.append(('game provides viewer link',not await p.locator('#share-display').is_hidden()))
  await p.locator('#spin-game').click();await p.wait_for_timeout(950)
  results.append(('game creates winner popup',await p.locator('#win-dialog').evaluate('(n)=>n.open')))
  results.append(('game records claimed prize',await p.locator('#claim-list .platform-claim').count()==1))
  await p.locator('#close-win').click()
  await p.locator('#pause-game').click();await p.wait_for_timeout(130)
  results.append(('game pause blocks spin',not await p.locator('#spin-game').is_enabled()))
  await p.locator('#pause-game').click();await p.wait_for_timeout(130)
  results.append(('game resume enables spin',await p.locator('#spin-game').is_enabled()))
  results.append(('game no uncaught JS errors',not e))
  await p.screenshot(path='/mnt/data/Lucky-Game-Hub-V5M-game-preview.png',full_page=True);await c.close()
  p,c,e=await page_for('intro');await p.wait_for_timeout(150)
  results.append(('game splash loaded',await p.locator('#intro-title').inner_text()=='Spin the Wheel'))
  results.append(('splash entry points to host',await p.locator('#intro-enter').get_attribute('href')=='game.php'))
  results.append(('intro no uncaught JS errors',not e));await c.close()
  p,c,e=await page_for('display');await p.wait_for_timeout(200)
  results.append(('display shows shared status',await p.locator('#display-state').inner_text()=='LIVE · 0 SPINS'))
  results.append(('display has no spin controls',await p.locator('#spin-game').count()==0))
  results.append(('display no JS errors',not e));await c.close()
  for kind in ['hub','admin','game','display','intro']:
   p,c,e=await page_for(kind,375,812);await p.wait_for_timeout(250)
   width=await p.evaluate('Math.max(document.documentElement.scrollWidth,document.body.scrollWidth)')
   results.append((f'{kind} mobile width fits',width<=390))
   await c.close()
  await browser.close()
 for name,okay in results:print(('PASS' if okay else 'FAIL')+' '+name)
 print(f'Browser: {sum(ok for _,ok in results)}/{len(results)} checks')
 if not all(ok for _,ok in results):raise SystemExit(1)

if __name__=='__main__':asyncio.run(main())
