"""V5Q.26B: phone connection, claim decision and proof countdown contract."""
import asyncio,re
from pathlib import Path
from playwright.async_api import async_playwright
BASE=Path(__file__).resolve().parents[1]
HTML=(BASE/'controller.php').read_text().split('?>',1)[1]
JS=(BASE/'public/phone-controller.js').read_text()
HTML=re.sub(r'<script src="public/voice-commands.js[^>]+></script>','',HTML)
HTML=re.sub(r'<script src="public/phone-controller.js[^>]+></script>',lambda _:'<script>'+JS.replace('</script','<\\/script')+'</script>',HTML)
MOCK=r"""<script>
window.__calls=[];window.__paired=false;window.__phase='live';window.__acceptDelay=false;window.__accepted=false;window.__proof=false;
window.__offer={status:'pending',claimId:'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',name:'Family Pizza Night',tier:'tier2',cents:2950,description:'A pizza dinner for everyone.',image:'',videoUrl:''};
window.fetch=async(url,opts={})=>{
 const action=new URL(String(url),'https://lucky.test/').searchParams.get('action');
 if(action==='status')return new Response(JSON.stringify({ok:true,status:__paired?'paired':'pending',proof:__proof?{active:true,claimId:__offer.claimId,phase:__phase,consent:true,reviewCount:0}:{active:false},game:{id:'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',status:'active',canStart:false,canSpin:false,spinCount:1,remaining:4,claimedCount:1,offer:{...__offer,status:__accepted?'accepted':'pending'}}}));
 if(action==='command'){const command=JSON.parse(opts.body).command;__calls.push(command);
  if(command==='prize-accept'&&__acceptDelay)await new Promise(resolve=>setTimeout(resolve,850));
  if(command==='prize-accept'){__accepted=true;__proof=true;}
  return new Response(JSON.stringify({ok:true,accepted:true,applied:true,effect:command==='prize-accept'?'prize_accepted':'updated',game:{id:'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',status:'active',offer:{...__offer,status:__accepted?'accepted':'pending'}}}));
 }
 if(action==='dashboard_games')return new Response(JSON.stringify({ok:true,games:[]}));
 return new Response(JSON.stringify({ok:true}));
};
</script>"""
HTML=HTML.replace('</head>','<base href="https://lucky.test/">'+MOCK+'</head>',1)
async def main():
 checks=[];errors=[]
 def ck(name,val):checks.append((name,bool(val)))
 async with async_playwright() as pw:
  browser=await pw.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox'])
  page=await browser.new_page(viewport={'width':390,'height':844},reduced_motion='reduce')
  page.on('pageerror',lambda e:errors.append(str(e)))
  await page.evaluate("""() => {const bag=new Map([['lucky-phone-controller-v5p',JSON.stringify({id:'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',token:'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc'})]]);Object.defineProperty(window,'sessionStorage',{configurable:true,value:{setItem:(k,v)=>bag.set(k,v),getItem:k=>bag.get(k)||null,removeItem:k=>bag.delete(k)}})}""")
  await page.set_content(HTML,wait_until='domcontentloaded')
  await page.locator('#phone-wait').wait_for(state='visible',timeout=5000)
  ck('Awaiting approval excludes game controls',await page.locator('#phone-controls').is_hidden())
  ck('Waiting connection badge',await page.locator('#phone-connection-state').get_attribute('data-state')=='waiting')
  await page.evaluate('__paired=true')
  await page.locator('#phone-prize-offer').wait_for(state='visible',timeout=5000)
  ck('Paired connection badge',await page.locator('#phone-connection-state').get_attribute('data-state')=='connected')
  ck('Separate prize amount and tier',await page.locator('#phone-offer-value').inner_text()=='$29.50' and 'TIER 2' in await page.locator('#phone-offer-tier').inner_text())
  ck('Prize name not conflated with value',await page.locator('#phone-offer-name').inner_text()=='Family Pizza Night')
  await page.locator('[data-phone=prize-decline]').click()
  ck('Custom confirmation opens',await page.locator('#phone-decline-dialog').is_visible())
  await page.locator('#phone-decline-cancel').click()
  ck('Cancel makes no command',await page.evaluate('__calls.length===0'))
  await page.locator('[data-phone=prize-decline]').click()
  await page.locator('#phone-decline-confirm').click()
  await page.wait_for_function('__calls.length===1')
  ck('Decline requires affirmative action',await page.evaluate("__calls[0]==='prize-decline'"))
  await page.evaluate('__acceptDelay=true')
  await page.locator('[data-phone=prize-accept]').click()
  ck('Accept disabled while pending',await page.locator('[data-phone=prize-accept]').is_disabled())
  await page.evaluate("document.querySelector('[data-phone=prize-accept]').click()")
  await page.wait_for_function('__accepted===true')
  ck('Duplicate Accept prevented',await page.evaluate("__calls.filter(c=>c==='prize-accept').length===1"))
  await page.locator('#phone-proof-prize').wait_for(state='visible',timeout=5000)
  ck('Prize value retained in proof',await page.locator('#phone-proof-prize-value').inner_text()=='$29.50')
  await page.evaluate("__phase='countdown'")
  await page.locator('#phone-proof-countdown').wait_for(state='visible',timeout=5000)
  ck('Countdown separate from recording', 'starts after' in await page.locator('#phone-proof-phase').inner_text())
  ck('Countdown cancel visible and record disabled',await page.locator('[data-phone=proof-stop]').is_visible() and await page.locator('[data-phone=proof-record]').is_disabled())
  await page.evaluate("__phase='recording'")
  await page.wait_for_function("document.getElementById('phone-proof-countdown').hidden")
  ck('Recording has Stop action', 'Stop recording' in await page.locator('[data-phone=proof-stop]').inner_text())
  ck('No script errors',not errors)
  await browser.close()
 for name,v in checks:print(('PASS ' if v else 'FAIL ')+name,flush=True)
 print('V5Q26B PHONE:',sum(v for _,v in checks),'/',len(checks),flush=True)
 if errors:print('JS errors:',errors)
 if not all(v for _,v in checks):raise SystemExit(1)
if __name__=='__main__':asyncio.run(main())
