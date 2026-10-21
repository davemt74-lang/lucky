"""V5Q25 pre-game package chooser and Live camera/sections smoke in isolated browser."""
import asyncio
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[1]
BODY=(ROOT/'game.php').read_text().split('?>',1)[1].split('<script src="public/voice-commands.js"',1)[0]
CLIENT=(ROOT/'public/game.js').read_text();LIVE=(ROOT/'public/live-setup.js').read_text();PROFILES=(ROOT/'public/camera-profiles.js').read_text()
BOOT="""<script>
window.__actions=[];window.LuckyWheel=class{constructor(){}setSlices(){}spinTo(){return Promise.resolve()}};
window.Lucky={
 $:id=>document.getElementById(id),pref:{musicOn:false},tracks:[],
 api:async(action,p={})=>{__actions.push({action,body:p.body});
  if(action==='last')return {data:null};
  if(action==='prize_packages')return {selected:'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',packages:[
   {id:'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',name:'Original Prizes',image:'https://images.example/one.png',enabled:true,playerSelectable:true,publicationStatus:'published'},
   {id:'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',name:'Pizza Night',image:'https://images.example/two.png',enabled:true,playerSelectable:true,publicationStatus:'published'}]};
  if(action==='package_select')return {selected:p.body.id};
  if(action==='start')return {data:{view:{id:'cccccccccccccccccccccccccccccccc',status:'active',version:1,spinCount:0,slots:[],claims:[]},session:{pools:{tier1:{prizes:[]},tier2:{prizes:[]},bonus:{prizes:[]}},odds:{bonusChance:0},prizePackage:{name:'Pizza Night'}}}};
  return {ok:true};},
 loadTracks:async()=>[],bindMusic:()=>{},note:(id,s)=>{const el=document.getElementById(id);if(el)el.textContent=s},
 money:c=>'$'+Number(c/100).toFixed(2),speak:()=>{},beep:()=>{},idempotency:()=> 'test_nonce',
};
window.fetch=async()=>new Response(JSON.stringify({ok:true,states:{}}));
</script>"""
HTML='<html><head><base href="http://127.0.0.1/"></head><body>'+BODY+BOOT+'<script>'+CLIENT.replace('</script','<\\/script')+'</script><script>'+PROFILES.replace('</script','<\\/script')+'</script><script>'+LIVE.replace('</script','<\\/script')+'</script></body></html>'
async def main():
 checks=[];errors=[]
 def ck(x,v):checks.append((x,bool(v)))
 async with async_playwright() as p:
  b=await p.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox'])
  page=await b.new_page(viewport={'width':1440,'height':860})
  page.on('pageerror',lambda e:errors.append(str(e)))
  await page.set_content(HTML,wait_until='domcontentloaded')
  await page.locator('#host-package-choice-cards button').first.wait_for(timeout=6000)
  ck('Two enabled packages generate an illustrated chooser',await page.locator('#host-package-choice-cards button').count()==2)
  ck('Pre-game chooser replaces the wheel',await page.locator('.platform-wheel-card').is_hidden())
  ck('Original package begins selected',await page.locator('#host-package-choice-cards button.selected').get_attribute('data-package-id')=='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')
  await page.evaluate("window.LuckyHostRemote('right')")
  ck('Phone Right arrow advances highlighted package',await page.locator('#host-package-choice-cards button.selected').get_attribute('data-package-id')=='bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb')
  await page.evaluate("window.LuckyHostRemote('select')")
  await page.wait_for_function("window.__actions.some(x=>x.action==='start')")
  ck('Phone Select saves package before game start',await page.evaluate("() => {const acts=__actions.map(x=>x.action);return acts.indexOf('package_select')>=0&&acts.indexOf('package_select')<acts.lastIndexOf('start')&&__actions.find(x=>x.action==='package_select').body.id==='bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'}"))
  ck('New session returns to wheel when running',await page.locator('#host-prize-package-chooser').is_hidden())
  await page.locator('#host-tab-display').click()
  ck('Live tab shows camera setup and existing session information',await page.locator('.host-live-controls').is_visible() and await page.locator('#live-session-section').is_visible())
  ck('No Cameras / Session / Prize video button row',await page.locator('.live-setup-sections button').count()==0)
  ck('Only Detect Cameras is in live setup header',await page.locator('.live-camera-header button').count()==1 and 'Detect Cameras' in await page.locator('.live-camera-header').inner_text())
  await page.evaluate('''() => {Object.defineProperty(window,'localStorage',{configurable:true,value:{items:{},getItem(k){return this.items[k]??null;},setItem(k,v){this.items[k]=v;}}});Object.defineProperty(navigator,'mediaDevices',{configurable:true,value:{
    getUserMedia:async()=>new MediaStream(),
    enumerateDevices:async()=>[
      {kind:'videoinput',deviceId:'front',label:'Front camera'},
      {kind:'videoinput',deviceId:'side',label:'Side camera'}]
  }});}''')
  await page.locator('#live-camera-detect').click()
  await page.wait_for_function("document.querySelectorAll('.live-camera-item').length===2")
  ck('Each detected camera gets its own preview card',await page.locator('.live-camera-item').count()==2)
  ck('Both camera names shown', 'Front camera' in await page.locator('#live-camera-list').inner_text() and 'Side camera' in await page.locator('#live-camera-list').inner_text())
  ck('Each card has selection and preview control',await page.locator('.live-camera-item input[type=checkbox]').count()==2 and await page.locator('.live-camera-item button').count()==2)
  await page.wait_for_function("document.querySelectorAll('.live-camera-preview-action[aria-pressed=true]').length===2")
  ck('Detect button automatically starts private previews',await page.locator('.live-camera-preview-action[aria-pressed=true]').count()==2)
  await page.locator('.live-camera-item').last.locator('input').check()
  ck('Camera selection persists for proof',await page.evaluate("LuckyCameraProfiles.selected('spin-wheel').includes('side')"))
  await page.locator('.live-camera-preview-action').first.click()
  ck('Individual preview may be stopped without removing other camera boxes',await page.locator('.live-camera-item').count()==2 and await page.locator('.live-camera-preview-action[aria-pressed=true]').count()==1)
  ck('No browser JS runtime errors',not errors)
  await b.close()
 for s,v in checks:print(('PASS ' if v else 'FAIL ')+s,flush=True)
 print('V5Q25 chooser/live:',sum(v for _,v in checks),'/',len(checks),flush=True)
 if not all(v for _,v in checks):raise SystemExit(1)
if __name__=='__main__':asyncio.run(main())
