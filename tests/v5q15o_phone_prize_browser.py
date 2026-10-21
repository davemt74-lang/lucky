"""V5Q.15O phone-only prize speech and optional safe video playback."""
import asyncio,re,json
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[1]
ID='a'*32;TOKEN='b'*64;CLAIM='c'*32;SESSION='d'*32
BOOT="""<script>
window.__spoken=[];window.__canceled=0;
window.SpeechSynthesisUtterance=class{constructor(text){this.text=text}};
Object.defineProperty(window,'speechSynthesis',{configurable:true,value:{
 cancel:()=>window.__canceled++,speak:(u)=>window.__spoken.push(u.text)}});
window.__award={id:'SESSION',status:'active',canSpin:false,spinning:true,offer:null};
window.__calls=[];
window.fetch=async(url,opt={})=>{
 const a=new URL(String(url),'https://lucky.test/').searchParams.get('action');
 if(a==='claim')return new Response(JSON.stringify({ok:true,id:'PAIR',controllerToken:'TOKEN'}));
 if(a==='status')return new Response(JSON.stringify({ok:true,status:'paired',
  proof:{active:false},game:__award}));
 if(a==='command'){
  const cmd=JSON.parse(opt.body).command;__calls.push(cmd);
  if(cmd==='prize-accept')__award={...__award,offer:{...__award.offer,status:'accepted'}};
  return new Response(JSON.stringify({ok:true,applied:true,effect:cmd==='prize-accept'?'prize_accepted':'updated',game:__award}));
 }
 return new Response(JSON.stringify({ok:true}));
};
</script>""".replace('SESSION',SESSION).replace('PAIR',ID).replace('TOKEN',TOKEN)
async def main():
 checks=[];errors=[]
 def check(label,ok):checks.append((label,bool(ok)))
 raw=(ROOT/'controller.php').read_text().split('?>',1)[1]
 raw=raw.replace('</head>','<base href="https://lucky.test/controller.php?invite='+ID+'&key='+TOKEN+'">'+BOOT+'</head>',1)
 raw=re.sub(r'<script src="public/voice-commands.js[^"]*" defer></script>','',raw)
 raw=re.sub(r'<script src="public/phone-controller.js[^"]*" defer></script>',lambda _:'<script>'+(ROOT/'public/phone-controller.js').read_text()+'</script>',raw)
 async with async_playwright() as pw:
  browser=await pw.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox'])
  p=await browser.new_page(viewport={'width':390,'height':844})
  p.on('pageerror',lambda e:errors.append(str(e)))
  await p.set_content(raw,wait_until='domcontentloaded')
  await p.locator('#phone-name').fill('Controller')
  await p.locator('#phone-join-form button').click()
  await p.wait_for_function("document.querySelector('#phone-controls').hidden===false")
  check('phone hides offer while wheel is spinning',await p.locator('#phone-prize-offer').is_hidden())
  check('no early prize speech',await p.evaluate('__spoken.length===0'))
  await p.evaluate("""() => {__award={...__award,spinning:false,offer:{
   status:'pending',claimId:'CLAIM',name:'Grand Prize',cents:3750,
   videoUrl:'api/index.php?action=prize_video&id='+('e'.repeat(32))}}}""".replace('CLAIM',CLAIM))
  await p.wait_for_function("document.querySelector('#phone-offer-name').textContent.includes('Grand Prize')")
  await p.wait_for_function('__spoken.length===1')
  check('prize announced only on phone after reveal',await p.evaluate("__spoken[0]==='Congratulations! You won Grand Prize!'"))
  check('phone shows Accept/Decline',await p.locator('[data-phone="prize-accept"]').is_visible() and await p.locator('[data-phone="prize-decline"]').is_visible())
  check('uploaded video shows Play Video action',await p.locator('#phone-offer-play-video').is_visible())
  await p.locator('#phone-offer-play-video').click()
  await p.wait_for_function("__calls.includes('prize-video-play')")
  check('phone Play Video commands desktop, not phone player',await p.locator('#phone-offer-media video').count()==0)
  await p.wait_for_timeout(1400)
  check('status polling does not repeat speech',await p.evaluate('__spoken.length===1'))
  await p.locator('#phone-offer-speak').click()
  check('Hear prize again is an explicit speech replay',await p.evaluate('__spoken.length===2'))
  await p.evaluate("""() => {__award.offer={...__award.offer,status:'pending',claimId:'e'.repeat(32),name:'Another Prize',videoUrl:'https://www.youtube.com/watch?v=dQw4w9WgXcQ'}}""")
  await p.wait_for_function("document.querySelector('#phone-offer-name').textContent.includes('Another Prize')")
  check('new prize gets one announcement',await p.evaluate('__spoken.length===3'))
  check('external YouTube links never get prize playback action',await p.locator('#phone-offer-play-video').is_hidden())
  await p.evaluate("""() => {__award.offer={...__award.offer,status:'pending',claimId:'f'.repeat(32),name:'No Video',videoUrl:'https://example.com/store'}}""")
  await p.wait_for_function("document.querySelector('#phone-offer-name').textContent.includes('No Video')")
  check('ordinary shopping URL never offers video playback',await p.locator('#phone-offer-play-video').is_hidden())
  await p.locator('[data-phone="prize-accept"]').click()
  await p.wait_for_function("document.querySelector('#phone-proof-mode').hidden===false")
  check('phone acceptance hands off to proof',await p.evaluate("__calls.includes('prize-accept')"))
  check('Play Video action hidden once prize accepted',await p.locator('#phone-offer-play-video').is_hidden())
  check('no JS exceptions',not errors)
  await browser.close()
 for title,passed in checks:print(('PASS' if passed else 'FAIL')+': '+title,flush=True)
 if errors:print('Errors:',errors,flush=True)
 print('V5Q15O phone prize:',sum(v for _,v in checks),'/',len(checks),flush=True)
 if not all(v for _,v in checks):raise SystemExit(1)
if __name__=='__main__':asyncio.run(main())
