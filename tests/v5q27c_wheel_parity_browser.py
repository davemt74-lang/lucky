"""V5Q.27C: TV wheel keeps the winner briefly, then shows the current session prizes and cents."""
import asyncio
import re
from pathlib import Path
from playwright.async_api import async_playwright

ROOT=Path(__file__).resolve().parents[1]
DISPLAY=(ROOT/'tests/fixtures/display.html').read_text()
SCRIPT=(ROOT/'public/display.js').read_text()
MOCK=r"""
window.__view={
 id:'a'.repeat(32),status:'active',version:1,spinCount:0,spinPending:false,
 slots:[{name:'Original Product',value:1200,tier:'tier1'}],
 claims:[],pendingBonus:false,bonusEnabled:false,bonusSlices:[],lastSpin:null
};
window.Lucky={
 $:id=>document.getElementById(id),
 api:async action=>{if(action==='watch')return {view:structuredClone(__view)};return {};},
 money:n=>'$'+(n/100).toFixed(2),
 loadTracks:async()=>[],bindMusic:()=>{},beep:()=>{},pref:{musicOn:false,musicVolume:55},
 toggleMusic:async()=>{},stopMusic:()=>{},volume:()=>55
};
window.LuckyWheel=class {
 constructor(){this.current=[];window.__displayWheel=this;}
 setSlices(items){this.current=items.map(p=>({...p}));}
 spinTo(index,slices){this.setSlices(slices);window.__landed=index;return new Promise(resolve=>{window.__finishDisplaySpin=resolve;});}
};
"""

async def main():
 checks=[];errors=[]
 def check(label,okay):checks.append((label,bool(okay)))
 async with async_playwright() as pw:
  browser=await pw.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox'])
  page=await browser.new_page(viewport={'width':1280,'height':800})
  page.set_default_timeout(10000)
  page.on('pageerror',lambda e:errors.append(str(e)))
  html=re.sub(r'<script\b[^>]*>.*?</script>','',DISPLAY,flags=re.S|re.I)
  html=html.replace('</head>','<script>'+MOCK+'</script></head>',1)
  html=html.replace('</body>','<script>'+SCRIPT+'</script></body>',1)
  await page.set_content(html,wait_until='domcontentloaded')
  await page.wait_for_function("window.__displayWheel?.current[0]?.name==='Original Product'")
  check('initial TV wheel shows correct product and cents',
        await page.evaluate("__displayWheel.current[0].cents===1200"))
  await page.evaluate("""() => {
   __view.version=2;__view.spinCount=1;__view.spinPending=true;
   __view.lastSpin={
    type:'award',spinCount:1,index:0,
    slices:[{name:'Original Product',cents:1200,tier:'tier1',kind:'prize'}],
    at:new Date().toISOString()
   };
   __view.slots=[{name:'Replacement Product',value:2450,tier:'tier2'}];
   __view.claims=[{name:'Original Product',tier:'tier1',cents:1200}];
  }""")
  await page.wait_for_function("typeof window.__finishDisplaySpin==='function'")
  check('TV retains the actual winner during animation',
        await page.evaluate("__displayWheel.current[__landed].name==='Original Product'"))
  await page.evaluate("window.__finishDisplaySpin()")
  await page.evaluate("""() => {
   __view.spinPending=false;__view.version=3;
   __view.lastSpin.revealedAt=new Date().toISOString();
  }""")
  await page.wait_for_function("__displayWheel.current[0]?.name==='Replacement Product'",timeout=8000)
  check('settled TV wheel displays latest session product and price',
        await page.evaluate("__displayWheel.current[0].name==='Replacement Product' && __displayWheel.current[0].cents===2450"))
  check('TV does not keep an awarded product on the wheel',
        await page.evaluate("!__displayWheel.current.some(p=>p.name==='Original Product')"))
  check('no browser errors',not errors)
  await browser.close()
 for label,value in checks:print(('PASS ' if value else 'FAIL ')+label,flush=True)
 if errors:print('BROWSER ERRORS:',errors,flush=True)
 print('V5Q27C TV WHEEL:',sum(v for _,v in checks),'/',len(checks),flush=True)
 if not all(value for _,value in checks):raise SystemExit(1)

if __name__=='__main__':asyncio.run(main())
