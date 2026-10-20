"""Verify a phone-awarded spin keeps the winning sector and hides claim until wheel stops."""
import asyncio
from playwright.async_api import async_playwright
from v5q6_proof_browser import html_page, MOCK, SID, CID, BASE

EXTRA = r"""
window.__initialSlices=[
 {name:'Blow Job',cents:3000,tier:'tier2',kind:'prize'},
 {name:'15 Second French Kiss',cents:500,tier:'tier1',kind:'prize'}
];
window.__view={id:'SESSION',status:'active',version:1,spinCount:0,
 slots:[{tier:'tier2',name:'Blow Job',value:3000},{tier:'tier1',name:'15 Second French Kiss',value:500}],
 claims:[],pendingBonus:false,lastSpin:null,spinPending:false};
window.__session={id:'SESSION',claims:[],odds:{bonusChance:0},pools:{
 tier1:{prizes:[{id:'p1',name:'15 Second French Kiss',cents:500}]},
 tier2:{prizes:[{id:'p2',name:'Blow Job',cents:3000}]},bonus:{prizes:[]}}};
window.__acks=[];
Lucky.api=async(action,options={})=>{
 if(action==='last')return {data:{view:__view,session:__session}};
 if(action==='session')return {view:__view,session:__session};
 if(action==='spin_revealed'){
  __acks.push(options.body);
  __view.lastSpin.revealedAt=new Date().toISOString();
  __view.spinPending=false;__view.version++;
  return {revealed:true};
 }
 if(action==='prize_offer')return {offer:null};
 return {};
};
window.LuckyWheel=class{
 constructor(){this.current=[];this.rotation=0;window.__wheel=this;}
 setSlices(slices){this.current=slices.slice();}
 spinTo(index,slices){
  this.setSlices(slices);window.__landing=index;
  return new Promise(resolve=>{window.__finishSpin=resolve});
 }
};
""".replace('SESSION',SID)
async def main():
 checks=[];errors=[]
 def ok(label,value):checks.append((label,bool(value)))
 async with async_playwright() as pw:
  browser=await pw.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox'])
  page=await browser.new_page(viewport={'width':1250,'height':850})
  page.set_default_timeout(8000)
  page.on('pageerror',lambda e:errors.append(str(e)))
  js=(BASE/'public/console.js').read_text()+'\n'+(BASE/'public/game.js').read_text()
  await page.set_content(html_page('game.php',js,MOCK+EXTRA),wait_until='domcontentloaded')
  await page.wait_for_function("window.__wheel&&document.querySelector('#spin-game').disabled===false")
  await page.evaluate("""() => {
   __view.version=2;__view.spinCount=1;
   __view.lastSpin={type:'award',spinCount:1,index:1,slices:__initialSlices,
    at:new Date().toISOString()};
   __view.spinPending=true;
   __view.slots[1]={tier:'tier2',name:'Go Second Base',value:1500};
   __view.claims=[{id:'CLAIM',name:'15 Second French Kiss',tier:'tier1',cents:500}];
   __session.claims=[{id:'CLAIM',prizeId:'p1',name:'15 Second French Kiss',
    tier:'tier1',cents:500}];
  }""".replace('CLAIM',CID))
  await page.wait_for_function('typeof window.__finishSpin==="function"',timeout=6000)
  ok('phone spin animates the persisted winning index',await page.evaluate('__landing===1'))
  ok('winning sector is the original unmodified prize',await page.evaluate('__wheel.current[__landing].name==="15 Second French Kiss"'))
  ok('claim hidden while wheel is moving',await page.locator('#claim-list .platform-claim').count()==0)
  ok('no early award total', '0 claimed' in await page.locator('#session-totals').inner_text())
  ok('no early acknowledgement',await page.evaluate('__acks.length===0'))
  await page.evaluate('__finishSpin()')
  await page.wait_for_function('__acks.length===1')
  await page.wait_for_function("document.querySelector('#claim-list').textContent.includes('15 Second French Kiss')")
  ok('completion acknowledges the matching spin',await page.evaluate('__acks[0].spinCount===1'))
  ok('claim exactly matches the stopped wheel',await page.evaluate('__wheel.current[__landing].name===document.querySelector("#claim-list strong").textContent'))
  ok('winner sector is not overwritten by next prize',await page.evaluate('__wheel.current[1].name!=="Go Second Base"'))
  await page.evaluate("LuckyHostRemote('back')")
  ok('stale phone Back cannot open game menu',not await page.locator('#console-menu').evaluate('(e)=>e.open'))
  await page.evaluate("LuckyHostRemote('done')")
  ok('stale phone Done leaves gameplay unobstructed',not await page.locator('#console-menu').evaluate('(e)=>e.open'))
  ok('no browser exceptions',not errors)
  await browser.close()
 for label,val in checks:print(('PASS' if val else 'FAIL'),label,flush=True)
 if errors:print('Browser exceptions:',errors,flush=True)
 print('V5Q.15E wheel reveal:',sum(v for _,v in checks),'/',len(checks),flush=True)
 if not all(val for _,val in checks):raise SystemExit(1)
if __name__=='__main__':asyncio.run(main())
