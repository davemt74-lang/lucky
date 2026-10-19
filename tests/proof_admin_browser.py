"""Per-prize proof-angle admin form browser contract, with simulated camera discovery and API."""
import asyncio,sys
from pathlib import Path
from playwright.async_api import async_playwright
sys.path.insert(0,str(Path(__file__).parent))
from v5p_pairing import make_html
MOCK='''<script>
Object.defineProperty(navigator,'mediaDevices',{configurable:true,value:{
 getUserMedia:async()=>new MediaStream(),
 enumerateDevices:async()=>[{kind:'videoinput',deviceId:'front',label:'USB Front Cam'},{kind:'videoinput',deviceId:'back',label:'USB Rear Cam'}]
}});
window.__saved=null;
const sourceFetch=window.fetch;
window.fetch=async(url,opts={})=>{
 const action=new URL(String(url),'https://example.test/').searchParams.get('action');
 if(action==='settings')return new Response(JSON.stringify({ok:true,settings:{config:{pools:{tier1:{budgetCents:1000,prizes:[{id:'demo',name:'Prize Test',cents:1000,url:'',image:'',proofAngles:[]}]},tier2:{budgetCents:0,prizes:[]},bonus:{budgetCents:0,prizes:[]}},odds:{tier1Weight:1,tier2Weight:1,bonusWeight:1,bonusChance:12}},intro:{title:'Spin',subtitle:'',trackId:'',volume:70},theme:{accent:'#f6cb74',background:'#0d1423'}}}));
 if(action==='settings_save'){__saved=JSON.parse(opts.body);return new Response(JSON.stringify({ok:true,settings:__saved}));}
 if(action==='sessions')return new Response(JSON.stringify({ok:true,sessions:[]}));
 return sourceFetch(url,opts);
};
</script>'''
async def main():
 out=[];err=[]
 def ck(n,v):out.append((n,bool(v)))
 async with async_playwright() as pw:
  b=await pw.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox'])
  page=await b.new_page(viewport={'width':1440,'height':900});page.on('pageerror',lambda e:err.append(str(e)))
  await page.set_content(make_html('admin').replace('</head>',MOCK+'</head>',1),wait_until='domcontentloaded')
  await page.wait_for_timeout(250)
  ck('prize proof angles section shown',await page.locator('.proof-angle-settings').count()==1)
  await page.locator('.proof-angle-settings summary').click()
  await page.get_by_role('button',name='Match cameras on this device').click()
  ck('camera count auto-populates 2 angles',await page.locator('.proof-angle-row').count()==2)
  ck('detected USB names populate labels', 'USB Front Cam' in await page.locator('.proof-angle-row input').first.input_value())
  await page.locator('.proof-angle-row input').first.fill('Prize Hero Closeup')
  await page.locator('.proof-angle-row input[type=number]').first.fill('11')
  await page.locator('.proof-angle-row input[type=number]').first.press('Tab')
  await page.locator('#save-prizes').click();await page.wait_for_timeout(150)
  ck('prize angle labels saved to settings',await page.evaluate("__saved?.config?.pools?.tier1?.prizes?.[0]?.proofAngles?.[0]?.label==='Prize Hero Closeup'"))
  ck('prize per-angle timing saved',await page.evaluate("__saved?.config?.pools?.tier1?.prizes?.[0]?.proofAngles?.[0]?.seconds===11"))
  ck('no JavaScript exceptions',not err)
  await page.screenshot(path='/mnt/data/Lucky-Game-Hub-V5Q2-admin-proof.png',full_page=True)
  await b.close()
 for n,ok in out:print(('PASS ' if ok else 'FAIL ')+n)
 print('Proof admin:',sum(ok for _,ok in out),'/',len(out))
 if err:print(err)
 if not all(ok for _,ok in out):raise SystemExit(1)
if __name__=='__main__':asyncio.run(main())
