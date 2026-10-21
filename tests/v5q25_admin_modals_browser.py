"""V5Q25 admin: package configuration and full prize editing live in their own dialogs."""
import asyncio
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[1]
BODY=(ROOT/'admin.php').read_text().split('?>',1)[1].split('<script src="public/client.js"',1)[0]
SCRIPT=(ROOT/'public/admin.js').read_text()
BOOT="""<script>
Object.defineProperty(window,'localStorage',{configurable:true,value:{getItem:()=>null,setItem:()=>{}}});
window.__calls=[];window.__package={id:'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',name:'Original Prizes',version:1,enabled:true,playerSelectable:true,publicationStatus:'published',image:'',budgetCents:null,config:{
 pools:{tier1:{budgetCents:30000,prizes:[{id:'prize-one',name:'Prize one',cents:2500,url:'',videoUrl:'',image:'',description:'Congratulations for {prize_name} today.',proofAngles:[]}]},tier2:{budgetCents:0,prizes:[]},bonus:{budgetCents:0,prizes:[]}},
 odds:{bonusChance:12,tier1Weight:1,tier2Weight:1,bonusWeight:1}}};
window.Lucky={ $:id=>document.getElementById(id), note:(id,message)=>{document.getElementById(id).textContent=message;}, money:c=>'$'+(c/100).toFixed(2),
 loadTracks:async()=>[],fillTracks:()=>{},idempotency:()=> 'nonce',
 api:async(action,opts={})=>{__calls.push(action);
  if(action==='settings')return {settings:{config:structuredClone(__package.config),intro:{title:'',subtitle:'',trackId:'',volume:70},theme:{accent:'#f6cb74',background:'#0d1423'}}};
  if(action==='prize_packages')return {packages:[structuredClone(__package)],selected:__package.id};
  if(action==='package_save'){Object.assign(__package,opts.body);__package.version++;return {package:structuredClone(__package)};}
  if(action==='history')return {history:[]};if(action==='sessions')return {sessions:[]};if(action==='manifest')return {games:[]};
  if(action==='last')return {data:null};return {ok:true};
 }};
</script>"""
HTML='<html><head><base href="https://lucky.test/"></head><body>'+BODY+BOOT+'<script>'+SCRIPT.replace('</script','<\\/script')+'</script></body></html>'
async def main():
 checks=[];errors=[]
 def ck(name,condition):checks.append((name,bool(condition)))
 async with async_playwright() as p:
  b=await p.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox'])
  page=await b.new_page(viewport={'width':1366,'height':840})
  page.on('pageerror',lambda e:errors.append(str(e)))
  await page.set_content(HTML,wait_until='domcontentloaded')
  await page.locator('.prize-catalog-card').first.wait_for(timeout=6000)
  ck('Individual prize displayed as a card',await page.locator('.prize-catalog-card').count()==1)
  ck('Prize card has readable description excerpt', 'Congratulations' in await page.locator('.prize-catalog-excerpt').inner_text())
  ck('Package settings do not render inline',await page.locator('#package-name').is_hidden())
  ck('Prize details do not render inline',await page.locator('#prize-edit-name').is_hidden())
  ck('Compact video URL input absent from the listing',await page.locator('#prize-items input[type=url]').count()==0)
  await page.locator('#open-package-settings').click()
  ck('Package settings open in full modal',await page.locator('#package-settings-modal').is_visible())
  ck('Package image and budget are in modal',await page.locator('#package-budget').is_visible() and await page.locator('#package-image-upload').count()==1)
  await page.locator('#package-settings-close').click()
  ck('Package settings close without losing prizes',await page.locator('#package-settings-modal').is_hidden() and await page.locator('.prize-catalog-card').count()==1)
  await page.locator('.prize-catalog-edit').click()
  ck('Prize editor opens in full modal',await page.locator('#prize-editor-modal').is_visible())
  ck('Photo and video uploads are independent',await page.locator('#prize-edit-photo-upload').count()==1 and await page.locator('#prize-edit-video-upload').count()==1)
  ck('Legacy video URL input absent from editor',await page.locator('#prize-editor-modal input[placeholder*="video URL"]').count()==0)
  ck('Smart tags are exposed inside prize modal',await page.locator('#prize-edit-tags button').count()==3)
  await page.locator('#prize-edit-description').fill('Congratulations {prize_name}!')
  await page.locator('#prize-edit-save').click()
  await page.wait_for_function("document.querySelector('#prize-editor-modal').open===false")
  ck('Edited description reflected on prize card', 'Congratulations {prize_name}' in await page.locator('.prize-catalog-excerpt').inner_text())
  ck('Prize edit persisted via package_save',await page.evaluate("__calls.includes('package_save')"))
  ck('No JavaScript runtime exceptions',not errors)
  await b.close()
 for name,result in checks:print(('PASS ' if result else 'FAIL ')+name,flush=True)
 print('V5Q.25 admin modal layout:',sum(v for _,v in checks),'/',len(checks))
 if errors:print('Errors:',errors)
 if not all(v for _,v in checks):raise SystemExit(1)
if __name__=='__main__':asyncio.run(main())
