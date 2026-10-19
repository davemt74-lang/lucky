"""Proof-page live camera auto-start and cross-page phone state sync regression.
A shared mocked server carries proof_state POSTs between separate browser pages.
"""
import asyncio, json, re
from pathlib import Path
from urllib.parse import urlparse, parse_qs
from playwright.async_api import async_playwright
from v5q6_proof_browser import html_page, MOCK, SID, CID, BASE

URL='https://example.test/'
CAM = '''
window.__getUserMediaCalls=0;
Object.defineProperty(navigator, 'mediaDevices', {configurable:true,value:{
 getUserMedia:async()=>{window.__getUserMediaCalls++;return new MediaStream();},
 enumerateDevices:async()=>[{kind:'videoinput',deviceId:'usb-1',label:'USB Prize Camera'}],
 addEventListener:()=>{} }});
HTMLMediaElement.prototype.play=function(){return Promise.resolve();};
'''
async def main():
  found=[]; errors=[]; state={'active':False,'claim':None,'count':0,'posts':[]}
  def check(label,condition): found.append((label,bool(condition)))
  async with async_playwright() as pw:
    browser=await pw.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox'])
    context=await browser.new_context()
    async def server(route):
      request=route.request
      action=parse_qs(urlparse(request.url).query).get('action',[''])[0]
      body=request.post_data_json or {}
      if action=='proof_state':
        state['active']=bool(body.get('open')); state['claim']=body.get('claim'); state['count']=body.get('reviewCount') or 0
        state['posts'].append(dict(body))
        result={'ok':True,'active':state['active']}
      elif action=='claim':
        result={'ok':True,'id':'a'*32,'controllerToken':'b'*64}
      elif action=='status':
        result={'ok':True,'status':'paired','proof':{'active':state['active'],'claimId':state['claim'],'reviewCount':state['count']},'game':{'status':'active','spinCount':1,'claimedCount':1,'remaining':2,'canSpin':True}}
      elif action=='ui_events': result={'ok':True,'events':[]}
      else: result={'ok':True}
      await route.fulfill(status=200,content_type='application/json',headers={'Access-Control-Allow-Origin':'*','Access-Control-Allow-Headers':'*','Access-Control-Allow-Methods':'POST, OPTIONS'},body=json.dumps(result))
    await context.route('**/*',lambda route:server(route) if 'controller.php?action=' in route.request.url else route.fulfill(status=200,body='',content_type='text/plain'))

    phone=await context.new_page(); phone.on('pageerror',lambda e: errors.append('phone: '+str(e)))
    html=(BASE/'controller.php').read_text().split('?>',1)[1]
    html=html.replace('<head>','<head><base href="'+URL+'controller.php?invite='+('a'*32)+'&key='+('b'*64)+'">',1)
    html=re.sub(r'<script src="public/voice-commands.js[^>]*></script>','',html)
    html=re.sub(r'<script src="public/phone-controller.js[^>]*></script>', lambda _: '<script>'+(BASE/'public/phone-controller.js').read_text()+'</script>',html)
    await phone.set_content(html,wait_until='domcontentloaded',timeout=6000);await phone.locator('#phone-name').fill('Testing');await phone.locator('#phone-join-form button').click()
    await phone.wait_for_function("document.querySelector('#phone-gameplay-shell')?.hidden===false && document.querySelector('#phone-controls')?.hidden===false",timeout=5000)
    check('paired phone starts with gameplay controls',await phone.locator('#phone-gameplay-shell').is_visible())
    check('proof buttons initially hidden',await phone.locator('#phone-proof-mode').is_hidden())

    page=await context.new_page(); page.on('pageerror',lambda e:errors.append('proof: '+str(e)))
    proof_js=(BASE/'public/proof.js').read_text().replace('new URL(location.href).searchParams',f"new URLSearchParams('session={SID}&claim={CID}&from=game')")
    mock=MOCK.replace('window.LuckyCapture={show:(...a)=>window.__captureArgs=a,release:()=>{}};','')
    mock=mock.replace("window.__errors=[];","window.__errors=[];")
    js=CAM+(BASE/'public/proof-sequence.js').read_text()+'\n'+(BASE/'public/claim-capture.js').read_text()+'\n'+proof_js
    doc=html_page('proof.php',js,mock).replace('<head>','<head><base href="'+URL+'">',1)
    # Local media list mock so missing camera footage cannot be conflated with media fetch.
    doc=doc.replace('</head>','<script>const origFetch=window.fetch;window.fetch=(url,opt)=>String(url).includes("claim-media.php?action=list")?Promise.resolve(new Response(JSON.stringify({ok:true,clips:[]}))):origFetch(url,opt);</script></head>',1)
    await page.set_content(doc,wait_until='domcontentloaded',timeout=6000)
    try: await page.wait_for_function('document.querySelector("#proof-prize-title")?.textContent==="Camera Prize"',timeout=11000)
    except Exception:
      print('DEBUG proof:',await page.locator('#proof-errors').inner_text(), await page.locator('#proof-state').inner_text(),'errors:',errors,flush=True)
      raise
    await page.wait_for_function('window.__getUserMediaCalls>0 && document.querySelector(".camera-source video")?.srcObject instanceof MediaStream',timeout=11000)
    check('proof verified exact claim',await page.locator('#proof-prize-title').inner_text()=='Camera Prize')
    check('camera permission requested without local Start Game flag',await page.evaluate('window.__getUserMediaCalls>0'))
    check('camera preview auto-started without button click',await page.locator('.camera-source video:not([hidden])').count()==1)
    check('video source is attached',await page.evaluate('document.querySelector(".camera-source video").srcObject instanceof MediaStream'))
    check('preview still works with recording consent off',not await page.locator('#capture-consent').is_checked())
    check('claim proof state POST sent to server',len(state['posts'])>0 and state['active'] and state['claim']==CID)
    await phone.wait_for_function('document.querySelector("#phone-proof-mode").hidden===false',timeout=5000)
    check('paired phone switches to proof control UI',await phone.locator('#phone-proof-mode').is_visible() and await phone.locator('#phone-gameplay-shell').is_hidden())
    check('phone proof accordion starts closed',not await phone.locator('#phone-proof-controls').evaluate('(e)=>e.open'))
    # A deliberately failing revision history must not suppress remote proof mode.
    check('proof state published before revision history fetch returns',state['posts'][0]['claim']==CID)
    await page.close()
    # Simulate explicit close state (pagehide is browser-background best-effort).
    state['active']=False
    await phone.wait_for_function('document.querySelector("#phone-gameplay-shell").hidden===false',timeout=5000)
    check('phone returns to game controls when proof closes',await phone.locator('#phone-gameplay-shell').is_visible())
    check('no uncaught exceptions',not errors)
    await context.close();await browser.close()
  for label,good in found:print(('PASS ' if good else 'FAIL ')+label,flush=True)
  if errors:print('JS errors:',errors)
  print(f'V5Q14B camera/phone integration: {sum(ok for _,ok in found)}/{len(found)}',flush=True)
  if not all(ok for _,ok in found):raise SystemExit(1)
if __name__=='__main__':asyncio.run(main())
