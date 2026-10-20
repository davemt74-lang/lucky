"""Browser acceptance and phone decision smoke checks."""
import asyncio,re,json
from urllib.parse import urlparse,parse_qs
from playwright.async_api import async_playwright
from v5q6_proof_browser import BASE,html_page,MOCK,CID,SID
async def main():
 tests=[];errors=[]
 async with async_playwright() as pw:
  b=await pw.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox'])
  pg=await b.new_page();pg.on('pageerror',lambda e:errors.append(str(e)))
  js=(BASE/'public/game.js').read_text().replace("location.assign('proof.php?session=","window.__captureNavigation('proof.php?session=")
  mock=MOCK+"\nwindow.__captureNavigation=path=>{window.__proofNav=path;};window.__offerStatus='pending';"
  await pg.set_content(html_page('game.php',js,mock),wait_until='domcontentloaded')
  await pg.wait_for_function('document.querySelector("#spin-game").disabled===false')
  await pg.evaluate("""() => { const orig=Lucky.api;Lucky.api=async(a,o)=>{
   if(a==='spin')return {result:{event:{type:'award',claim:{id:'dddddddddddddddddddddddddddddddd',name:'Camera Prize',cents:1250,tier:'tier1'},animation:{index:0,slices:[]}}}};
   if(a==='prize_offer')return {offer:{claimId:'dddddddddddddddddddddddddddddddd',session:'cccccccccccccccccccccccccccccccc',name:'Camera Prize',cents:1250,status:__offerStatus,decidedAt:__offerStatus==='accepted'?new Date().toISOString().replace('T',' ').replace('Z',''):null}};
   if(a==='prize_decide'){__offerStatus=o.body.choice;return {offer:{status:__offerStatus}};}
   const result=await orig(a,o);
   if(a==='session'&&__offerStatus!=='accepted'&&result.session)result.session.claims=[]; // stale original game snapshot
   return result;
  }}""")
  await pg.evaluate("document.querySelector('#console-menu').showModal()")
  await pg.locator('#spin-game').click()
  await pg.evaluate("document.querySelector('#console-menu').close()")
  await pg.wait_for_function("document.querySelector('#game-message').textContent.includes('Camera Prize')",timeout=9000)
  tests.append(('desktop has no prize acceptance controls',await pg.locator('#prize-offer-stage,#prize-offer-accept,#prize-offer-decline').count()==0))
  tests.append(('desktop shows winner without the phone decision prompt','Camera Prize' in await pg.locator('#game-message').inner_text() and 'Accept or decline' not in await pg.locator('#game-message').inner_text()))
  tests.append(('no proof before phone accepts',await pg.evaluate('!window.__proofNav')))
  await pg.evaluate("window.__offerStatus='accepted'")
  await pg.wait_for_function('window.__proofNav',timeout=5000)
  tests.append(('accept opens correct claim',await pg.evaluate('window.__proofNav.includes("claim=dddddddddddddddddddddddddddddddd")')))
  await pg.close()
  sent=[];phone_proof={'active':False,'phase':'opening','claimId':CID,'consent':False,'reviewCount':0,'savedCount':0}
  async def api(route):
   a=parse_qs(urlparse(route.request.url).query).get('action',[''])[0];q=route.request.post_data_json or {};sent.append((a,q))
   if a=='claim':data={'ok':True,'id':'a'*32,'controllerToken':'b'*64}
   elif a=='status':data={'ok':True,'status':'paired','proof':dict(phone_proof),'game':{'status':'active','canSpin':False,'offer':{'status':'accepted' if phone_proof['active'] else 'pending','claimId':CID,'name':'Camera Prize','cents':1250}}}
   elif a=='command':phone_proof['active']=True;data={'ok':True,'applied':True,'effect':'prize_accepted','game':{'status':'active','canSpin':False,'offer':{'status':'accepted','claimId':CID,'name':'Camera Prize'}}}
   else:data={'ok':True}
   await route.fulfill(content_type='application/json',body=json.dumps(data))
  phone=await b.new_page();phone.on('pageerror',lambda e:errors.append(str(e)))
  await phone.route('**/api/controller.php?action=*',api)
  html=(BASE/'controller.php').read_text().split('?>',1)[1]
  html=html.replace('<head>','<head><base href="https://example.test/controller.php?invite='+('a'*32)+'&key='+('b'*64)+'">',1)
  html=re.sub(r'<script src="public/voice-commands.js[^>]*></script>','',html)
  html=re.sub(r'<script src="public/phone-controller.js[^>]*></script>',lambda _: '<script>'+(BASE/'public/phone-controller.js').read_text()+'</script>',html)
  await phone.set_content(html,wait_until='domcontentloaded');await phone.locator('#phone-name').fill('Prize phone');await phone.locator('#phone-join-form button').click()
  await phone.wait_for_function('document.querySelector("#phone-prize-offer").hidden===false')
  tests.append(('phone presents accept screen',await phone.locator('#phone-prize-offer').is_visible()))
  tests.append(('phone hides gameplay controls',await phone.locator('#phone-gameplay-shell').is_hidden()))
  await phone.locator('[data-phone="prize-accept"]').click()
  await phone.wait_for_function('document.querySelector("#phone-status").textContent.includes("Game")',timeout=4000)
  tests.append(('paired phone sends acceptance',any(a=='command' and q.get('command')=='prize-accept' for a,q in sent)))
  await phone.wait_for_function("document.querySelector('#phone-proof-mode').hidden===false && document.querySelector('#phone-proof-phase').textContent.includes('Opening')")
  tests.append(('phone switches immediately to opening editor after acceptance',await phone.locator('#phone-proof-mode').is_visible() and await phone.locator('#phone-gameplay-shell').is_hidden()))
  tests.append(('recording consent blocked until desktop editor opens',await phone.locator('#phone-proof-consent').is_disabled()))
  phone_proof['phase']='live'
  await phone.wait_for_function("document.querySelector('#phone-proof-phase').textContent.includes('Live camera ready') && document.querySelector('#phone-proof-consent').disabled===false",timeout=5000)
  tests.append(('phone starts proof controls after desktop editor opens',await phone.locator('#phone-proof-consent').is_enabled()))
  await phone.close();await b.close()
 tests.append(('browser has no errors',not errors))
 for name,ok in tests:print(('PASS' if ok else 'FAIL'),name,flush=True)
 print('V5Q15B',sum(x for _,x in tests),'/',len(tests))
 if not all(x for _,x in tests):raise SystemExit(1)
if __name__=='__main__':asyncio.run(main())
