"""Approved phone players choose enabled prize packages only between games."""
import asyncio,re
from pathlib import Path
from playwright.async_api import async_playwright

ROOT=Path(__file__).resolve().parents[1]
PAIR='a'*32;SECRET='b'*64
MOCK="""<script>
window.__packages=[
 {id:'c'.repeat(32),name:'Original Prizes',enabled:true,playerSelectable:true},
 {id:'d'.repeat(32),name:'Game Night',enabled:true,playerSelectable:true},
 {id:'e'.repeat(32),name:'Admin Private',enabled:true,playerSelectable:false}
];
window.__selected='c'.repeat(32);
window.__requests=[];
window.__game={id:null,game:'spin-wheel',status:'ready',version:0,spinCount:0,
 claimedCount:0,claimedCents:0,remaining:8,canStart:true,canSpin:false,
 packages:__packages.filter(p=>p.playerSelectable),selectedPackage:__selected};
window.fetch=async(url,opts={})=>{
 const action=new URL(String(url),'https://lucky.test/').searchParams.get('action');
 if(action==='claim')return new Response(JSON.stringify({ok:true,id:'PAIR',controllerToken:'TOKEN'}));
 if(action==='status')return new Response(JSON.stringify({ok:true,status:'paired',proof:{active:false},game:{
  ...__game,selectedPackage:__selected
 }}));
 if(action==='select_package'){
  const body=JSON.parse(opts.body);__requests.push({action,body});
  if(__game.status==='active')return new Response(JSON.stringify({ok:false,error:'Game running'}),{status:409});
  if(!__packages.some(p=>p.id===body.package&&p.enabled&&p.playerSelectable))
   return new Response(JSON.stringify({ok:false,error:'Package not allowed'}),{status:403});
  __selected=body.package;
  return new Response(JSON.stringify({ok:true,selected:__selected}));
 }
 return new Response(JSON.stringify({ok:true}));
};
</script>""".replace('PAIR',PAIR).replace('TOKEN',SECRET)

async def main():
 checks=[];errors=[]
 def ck(name,condition):checks.append((name,bool(condition)))
 raw=(ROOT/'controller.php').read_text().split('?>',1)[1]
 raw=raw.replace('</head>','<base href="https://lucky.test/controller.php?invite='+PAIR+'&key='+SECRET+'">'+MOCK+'</head>',1)
 raw=re.sub(r'<script src="public/voice-commands.js[^"]*" defer></script>','',raw)
 raw=re.sub(r'<script src="public/phone-controller.js[^"]*" defer></script>',lambda _:'<script>'+(ROOT/'public/phone-controller.js').read_text()+'</script>',raw)
 async with async_playwright() as pw:
  browser=await pw.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox'])
  page=await browser.new_page(viewport={'width':390,'height':844})
  page.on('pageerror',lambda e:errors.append(str(e)))
  await page.set_content(raw,wait_until='domcontentloaded')
  await page.locator('#phone-name').fill('Phone Player')
  await page.locator('#phone-join-form button').click()
  await page.wait_for_function("document.querySelector('#phone-controls').hidden===false")
  await page.wait_for_function("document.querySelector('#phone-package-picker').hidden===false")
  ck('package chooser visible before game',await page.locator('#phone-package-picker').is_visible())
  ck('phone lists only public-enabled prize packages',await page.locator('#phone-package-select option').count()==2)
  ck('host preference synchronized to phone',await page.locator('#phone-package-select').input_value()=='c'*32)
  await page.locator('#phone-package-select').select_option('d'*32)
  await page.wait_for_function("__selected==='d'.repeat(32)")
  ck('paired phone selects next-game package with scoped API',await page.evaluate("__requests.some(x=>x.action==='select_package'&&x.body.id==='a'.repeat(32)&&x.body.package==='d'.repeat(32))"))
  await page.evaluate("()=>{__game={...__game,id:'f'.repeat(32),status:'active',canStart:false,canSpin:true}}")
  await page.wait_for_function("document.querySelector('#phone-package-picker').hidden===true")
  ck('running game hides package selector',await page.locator('#phone-package-picker').is_hidden())
  ck('phone has no browser JS errors',not errors)
  await browser.close()
 for name,passed in checks:print(('PASS' if passed else 'FAIL')+' '+name,flush=True)
 if errors:print('BROWSER ERRORS',errors,flush=True)
 print('V5Q20 PHONE',sum(v for _,v in checks),'/',len(checks),flush=True)
 if not all(v for _,v in checks):raise SystemExit(1)
if __name__=='__main__':asyncio.run(main())
