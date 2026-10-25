"""V5Q.28B: QR-paired phone media import, consent gate, claim binding and revocation."""
import asyncio,re
from pathlib import Path
from playwright.async_api import async_playwright

ROOT=Path(__file__).resolve().parents[1]
html=(ROOT/'controller.php').read_text().split('?>',1)[1]
for name in ('public/voice-commands.js','public/phone-controller.js','public/phone-capture.js'):
 js=(ROOT/name).read_text()
 html=re.sub(r'<script src="'+re.escape(name)+r'[^>]+></script>',lambda _: '<script>'+js.replace('</script','<\\/script')+'</script>',html)
html=html.replace('</head>','<base href="https://lucky.test/"><style>'+(ROOT/'public/platform.css').read_text()+'</style></head>',1)
stub='''<script>
window.__allow=false;window.__consent=true;window.__proofActive=true;window.__uploads=[];
window.fetch=async(url,opts={})=>{
 const u=String(url),action=new URL(u,'https://lucky.test/').searchParams.get('action');
 if(u.includes('phone-media.php')){__uploads.push({entries:[...opts.body.entries()].map(([k,v])=>[k,v instanceof File?v.name:v]),headers:opts.headers,credentials:opts.credentials});return new Response(JSON.stringify({ok:true,clip:{id:'d'.repeat(32)}}));}
 if(action==='status')return new Response(JSON.stringify({ok:true,status:'paired',claims:[],game:{id:'a'.repeat(32),status:'active',canSpin:true,spinCount:0,claimedCount:1,remaining:5},proof:__proofActive?{active:true,sessionId:'a'.repeat(32),claimId:'b'.repeat(32),phoneCameraAllowed:__allow,consent:__consent,phase:'live',savedCount:0}:{active:false}}));
 if(action==='command')return new Response(JSON.stringify({ok:true,applied:true,effect:'proof-consent'}));
 return new Response(JSON.stringify({ok:true}));
};</script>'''
html=html.replace('</head>',stub+'</head>',1)

async def main():
 checks=[];errors=[]
 def check(name,ok):checks.append((name,bool(ok)))
 async with async_playwright() as pw:
  browser=await pw.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox'])
  page=await browser.new_page(viewport={'width':375,'height':780})
  page.on('pageerror',lambda exc:errors.append(str(exc)))
  await page.evaluate("""() => {const row=JSON.stringify({id:'c'.repeat(32),token:'d'.repeat(64)});const map=new Map([['lucky-phone-controller-v5p',row]]);Object.defineProperty(window,'sessionStorage',{configurable:true,value:{getItem:k=>map.get(k)||null,setItem:(k,v)=>map.set(k,v),removeItem:k=>map.delete(k)}});} """)
  await page.set_content(html,wait_until='domcontentloaded')
  await page.locator('#phone-proof-controls').wait_for(state='visible',timeout=5500)
  check('Phone capture disabled without host permission',await page.locator('#phone-capture-open').is_disabled())
  await page.evaluate('__allow=true;__consent=false')
  await page.wait_for_timeout(900)
  check('Host permission alone insufficient without consent',await page.locator('#phone-capture-open').is_disabled())
  await page.evaluate('__consent=true')
  await page.locator('#phone-capture-open').wait_for(state='visible')
  await page.wait_for_function("!document.getElementById('phone-capture-open').disabled",timeout=2500)
  await page.locator('#phone-capture-open').click()
  check('Approved proof opens phone camera modal',await page.locator('#phone-capture-dialog').is_visible())
  for mime,name,mode in [('image/png','snapshot.png','photo'),('video/mp4','recording.mp4','video'),('audio/mpeg','commentary.mp3','audio')]:
   await page.locator('#phone-capture-file').set_input_files({'name':name,'mimeType':mime,'buffer':b'1234567890'})
   check(f'{mode} gallery file ready',await page.locator('#phone-capture-send').is_visible())
   await page.locator('#phone-capture-send').click()
   await page.wait_for_function(f"window.__uploads.length=={1+['photo','video','audio'].index(mode)}")
   await page.wait_for_function("document.getElementById('phone-capture-status').textContent.startsWith('✓') && document.getElementById('phone-capture-send').hidden",timeout=4000)
  captured=await page.evaluate('window.__uploads')
  for idx,(mime,name,mode) in enumerate([('image/png','snapshot.png','photo'),('video/mp4','recording.mp4','video'),('audio/mpeg','commentary.mp3','audio')]):
   values=dict(captured[idx]['entries'])
   check(f'{mode} bound to approved phone, session and claim',values.get('id')=='c'*32 and values.get('session')=='a'*32 and values.get('claim')=='b'*32 and values.get('kind')==mode and values.get('media')==name)
   check(f'{mode} uses header bearer, not admin cookie',captured[idx]['credentials']=='omit' and captured[idx]['headers'].get('X-Controller-Token')=='d'*64)
  await page.evaluate('__allow=false')
  await page.wait_for_function("!document.getElementById('phone-capture-dialog').open",timeout=2700)
  check('Disabling host permission closes phone capture immediately on poll',await page.locator('#phone-capture-open').is_disabled())
  await page.evaluate('__allow=true;__proofActive=false')
  await page.wait_for_function("document.getElementById('phone-capture-entry').hidden",timeout=2700)
  check('Closing host proof removes phone capture controls',await page.locator('#phone-capture-entry').is_hidden())
  for width in (320,375,430):
   await page.set_viewport_size({'width':width,'height':780})
   check(f'{width}px no horizontal overflow',await page.evaluate('document.documentElement.scrollWidth<=innerWidth+3'))
  check('No page errors',not errors)
  await browser.close()
 for name,passed in checks:print(('PASS ' if passed else 'FAIL ')+name,flush=True)
 if errors:print('Page errors:',errors)
 print(f'V5Q.28B phone capture: {sum(ok for _,ok in checks)}/{len(checks)}',flush=True)
 if not all(ok for _,ok in checks):raise SystemExit(1)

if __name__=='__main__':asyncio.run(main())
