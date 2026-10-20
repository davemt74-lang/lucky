"""Phone proof consent and recording/review/save phases remain synchronized."""
import asyncio,re
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[1]
ID='a'*32;TOKEN='b'*64
MOCK=r"""<script>
window.__commands=[];window.__proof={active:true,consent:false,phase:'live',reviewCount:0,savedCount:0};
window.fetch=async(url,opts={})=>{
 const action=new URL(String(url),'https://example.test/').searchParams.get('action');
 if(action==='claim')return new Response(JSON.stringify({ok:true,id:'PAIR_ID',controllerToken:'PAIR_TOKEN'}));
 if(action==='status')return new Response(JSON.stringify({ok:true,status:'paired',proof:__proof,game:{status:'active',canSpin:true}}));
 if(action==='command'){
  const c=JSON.parse(opts.body).command;__commands.push(c);
  if(c==='proof-consent-on')__proof.consent=true;
  if(c==='proof-consent-off')__proof.consent=false;
  if(c==='proof-save'){__proof.phase='saved';__proof.reviewCount=0;__proof.savedCount=1;}
  return new Response(JSON.stringify({ok:true,applied:true,effect:'updated'}));
 }
 return new Response(JSON.stringify({ok:true}));
};
</script>""".replace('PAIR_ID',ID).replace('PAIR_TOKEN',TOKEN)
async def main():
 checks=[];errors=[]
 def check(label,passed):checks.append((label,bool(passed)))
 markup=(ROOT/'controller.php').read_text().split('?>',1)[1]
 markup=markup.replace('</head>','<base href="https://example.test/controller.php?invite='+ID+'&key='+TOKEN+'">'+MOCK+'</head>',1)
 markup=re.sub(r'<script src="public/voice-commands.js[^"]*" defer></script>','',markup)
 markup=re.sub(r'<script src="public/phone-controller.js[^"]*" defer></script>',lambda _:'<script>'+(ROOT/'public/phone-controller.js').read_text()+'</script>',markup)
 async with async_playwright() as pw:
  b=await pw.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox'])
  p=await b.new_page(viewport={'width':390,'height':844});p.on('pageerror',lambda e:errors.append(str(e)))
  await p.set_content(markup,wait_until='domcontentloaded')
  await p.locator('#phone-name').fill('Camera remote');await p.locator('#phone-join-form button').click()
  await p.wait_for_function("document.querySelector('#phone-proof-consent')?.disabled===false")
  check('phone consent starts unchecked',not await p.locator('#phone-proof-consent').is_checked())
  check('phone cannot record without consent',await p.locator('[data-phone="proof-record"]').is_disabled())
  await p.locator('#phone-proof-consent').check()
  await p.wait_for_function('__proof.consent===true')
  await p.wait_for_function("document.querySelector('[data-phone=proof-record]').disabled===false")
  check('phone consent command accepted',await p.evaluate("__commands.includes('proof-consent-on')"))
  await p.evaluate("__proof.phase='recording'")
  await p.wait_for_function("document.querySelector('[data-phone=proof-record]').textContent.includes('Recording')")
  check('record label changes while recording',await p.locator('[data-phone="proof-record"]').inner_text()=='● Recording…')
  check('stop available while recording',await p.locator('[data-phone="proof-stop"]').is_visible())
  await p.evaluate("__proof.phase='processing'")
  await p.wait_for_function("document.querySelector('#phone-proof-phase').textContent.includes('Processing')")
  check('processing disables capture actions',await p.locator('#phone-proof-recording').is_hidden())
  await p.evaluate("__proof.phase='review';__proof.reviewCount=1")
  await p.wait_for_function("document.querySelector('[data-phone=proof-save]').offsetParent!==null")
  check('Save visible only after processed video',await p.locator('[data-phone="proof-save"]').is_visible())
  await p.locator('[data-phone="proof-save"]').click()
  await p.wait_for_function("__proof.phase==='saved'")
  await p.wait_for_function("document.querySelector('#phone-proof-phase').textContent.includes('saved')")
  check('Save forwarded to host',await p.evaluate("__commands.includes('proof-save')"))
  check('Saved phase waits for next choice',await p.locator('#phone-proof-review').is_hidden())
  check('Saved recording offers re-record',await p.locator('#phone-proof-rerecord-saved').is_visible())
  check('no browser exceptions',not errors)
  await b.close()
 for label,val in checks:print(('PASS' if val else 'FAIL'),label,flush=True)
 if errors:print(errors,flush=True)
 print('V5Q15F phone recording:',sum(x for _,x in checks),'/',len(checks),flush=True)
 if not all(x for _,x in checks):raise SystemExit(1)
if __name__=='__main__':asyncio.run(main())
