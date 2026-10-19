"""Phone remains paired during host migration, reports HTTP failures, recovers after migration."""
import asyncio, re
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[1]
ID='a'*32
SECRET='b'*64
MOCK="""<script>
window.__mode='needs-upgrade';window.__statusRequests=0;
window.fetch=async(url,opts={})=>{
 const action=new URL(String(url),'https://example.test/').searchParams.get('action');
 if(action==='claim')return new Response(JSON.stringify({ok:true,id:'PAIR_ID',controllerToken:'TOKEN'}));
 if(action==='status'){
  __statusRequests++;
  if(__mode==='failed')return new Response(JSON.stringify({ok:false,error:'Host service temporarily unavailable'}),{status:503});
  const upgradeRequired=__mode==='needs-upgrade';
  return new Response(JSON.stringify({ok:true,status:'paired',proof:{active:false},
   game:{status:'ready',canStart:!upgradeRequired,canSpin:false,upgradeRequired}}));
 }
 return new Response(JSON.stringify({ok:true}));
};
</script>""".replace('PAIR_ID',ID).replace('TOKEN',SECRET)

async def main():
 results=[]; errors=[]
 def test(name,good):results.append((name,bool(good)))
 raw=(ROOT/'controller.php').read_text().split('?>',1)[1]
 raw=re.sub(r'<link rel="stylesheet" href="public/style.css"><link rel="stylesheet" href="public/platform.css\\?v=[^"]+">',
    lambda _: '<style>'+(ROOT/'public/style.css').read_text()+'\\n'+(ROOT/'public/platform.css').read_text()+'</style>',raw)
 raw=raw.replace('</head>','<base href="https://example.test/controller.php?invite='+ID+'&key='+SECRET+'">'+MOCK+'</head>',1)
 raw=re.sub(r'<script src="public/voice-commands.js[^"]*" defer></script>','',raw)
 raw=re.sub(r'<script src="public/phone-controller.js[^"]*" defer></script>',
   lambda _: '<script>'+(ROOT/'public/phone-controller.js').read_text()+'</script>',raw)
 async with async_playwright() as pw:
  browser=await pw.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox'])
  phone=await browser.new_page(viewport={'width':390,'height':844})
  phone.on('pageerror',lambda e:errors.append(str(e)))
  await phone.set_content(raw,wait_until='domcontentloaded')
  await phone.locator('#phone-name').fill('Living Room')
  await phone.locator('#phone-join-form button').click()
  await phone.wait_for_function("document.querySelector('#phone-game-state')?.textContent.includes('HOST DATABASE UPDATE REQUIRED')",timeout=6000)
  test('paired phone shows host migration instructions',await phone.locator('#phone-game-state').is_visible())
  test('unsafe game start disabled',await phone.locator('[data-phone="start"]').is_disabled())
  test('unsafe spin disabled',await phone.locator('[data-phone="spin"]').is_disabled())
  await phone.evaluate("window.__mode='failed'")
  await phone.wait_for_function("document.querySelector('#phone-status').textContent.includes('HTTP 503')",timeout=6000)
  test('HTTP 503 is reported instead of anonymous retry', 'Host service temporarily unavailable' in await phone.locator('#phone-status').inner_text())
  await phone.evaluate("window.__mode='ready'")
  await phone.wait_for_function("document.querySelector('[data-phone=start]').disabled===false",timeout=6000)
  test('controller recovers after migration without new pairing',await phone.locator('#phone-controls').is_visible())
  test('migration banner disappears', 'HOST DATABASE UPDATE REQUIRED' not in await phone.locator('#phone-game-state').inner_text())
  test('no JavaScript exceptions',not errors)
  await browser.close()
 for label,good in results:print(('PASS' if good else 'FAIL'),label,flush=True)
 if errors:print('Browser exceptions:',errors,flush=True)
 print('V5Q15C phone recovery:',sum(x for _,x in results),'/',len(results),flush=True)
 if not all(x for _,x in results):raise SystemExit(1)
if __name__=='__main__':asyncio.run(main())
