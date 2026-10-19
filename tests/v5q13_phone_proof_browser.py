"""Phone proof buttons activate immediately for live prize capture."""
import asyncio, re
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[1]
ID='a'*32;SECRET='b'*64;CID='c'*32
MOCK='''<script>
window.__commands=[];window.__proofOpen=false;window.__reviewReady=false;
window.fetch=async(url,opts={})=>{const action=new URL(String(url),'https://example.test/').searchParams.get('action');
 if(action==='claim')return new Response(JSON.stringify({ok:true,id:'PAIR_ID',controllerToken:'TOKEN'}));
 if(action==='status')return new Response(JSON.stringify({ok:true,status:'paired',proof:{active:__proofOpen,claimId:'CLAIM_ID',reviewCount:__reviewReady?1:0},game:{status:'active',spinCount:1,claimedCount:1,remaining:4,canSpin:true}}));
 if(action==='command'){const body=JSON.parse(opts.body);__commands.push(body.command);return new Response(JSON.stringify({ok:true,applied:true,effect:'updated'}));}
 return new Response(JSON.stringify({ok:true}));};
</script>'''.replace('PAIR_ID',ID).replace('CLAIM_ID',CID).replace('TOKEN',SECRET)
async def run():
 results=[];errors=[]
 def ok(name,good):results.append((name,bool(good)))
 raw=(ROOT/'controller.php').read_text().split('?>',1)[1]
 raw=re.sub(r'<link rel="stylesheet" href="public/style.css"><link rel="stylesheet" href="public/platform.css\?v=[^"]+">', lambda _: '<style>'+(ROOT/'public/style.css').read_text()+'\n'+(ROOT/'public/platform.css').read_text()+'</style>',raw)
 raw=raw.replace('</head>','<base href="https://example.test/controller.php?invite='+ID+'&key='+SECRET+'">'+MOCK+'</head>',1)
 raw=raw.replace('<script src="public/voice-commands.js?v=5p2" defer></script>','')
 raw=re.sub(r'<script src="public/phone-controller.js\?v=[^"]+" defer></script>',lambda _: '<script>'+(ROOT/'public/phone-controller.js').read_text()+'</script>',raw)
 async with async_playwright() as pw:
  browser=await pw.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox'])
  page=await browser.new_page(viewport={'width':390,'height':844});page.on('pageerror',lambda e:errors.append(str(e)))
  await page.set_content(raw,wait_until='domcontentloaded')
  await page.locator('#phone-name').fill('Phone');await page.locator('#phone-join-form button').click()
  await page.wait_for_timeout(950)
  ok('controls available after pairing',await page.locator('#phone-controls').is_visible())
  ok('proof accordion hidden without claim',await page.locator('#phone-proof-controls').is_hidden())
  await page.evaluate('__proofOpen=true');await page.wait_for_timeout(1250)
  ok('claim proof accordion appears',await page.locator('#phone-proof-controls').is_visible())
  ok('gameplay controls hidden while proof open',await page.locator('#phone-gameplay-shell').is_hidden())
  ok('proof screen owns the phone interface',await page.locator('#phone-proof-mode').is_visible())
  ok('music buttons hidden while proof open',await page.locator('#phone-game-audio').is_hidden())
  ok('proof controls open immediately',await page.locator('#phone-proof-controls').evaluate('(e)=>e.open'))
  ok('record controls require no additional tap',await page.locator('#phone-proof-recording').is_visible())
  ok('record buttons available before review',await page.locator('#phone-proof-recording').is_visible())
  ok('approval controls hidden before review',await page.locator('#phone-proof-review').is_hidden())
  await page.evaluate('__reviewReady=true');await page.wait_for_timeout(900)
  ok('review buttons replace record buttons',await page.locator('#phone-proof-review').is_visible() and await page.locator('#phone-proof-recording').is_hidden())
  await page.locator('[data-phone="proof-approve"]').click()
  await page.wait_for_timeout(100)
  ok('Approve sent as phone command',await page.evaluate("__commands.includes('proof-approve')"))
  await page.evaluate('__proofOpen=false');await page.wait_for_timeout(1000)
  ok('accordion hidden when proof closed',await page.locator('#phone-proof-controls').is_hidden())
  ok('accordion closes again',not await page.locator('#phone-proof-controls').evaluate('(e)=>e.open'))
  ok('gameplay controls return after proof',await page.locator('#phone-gameplay-shell').is_visible())
  ok('music controls return after proof',await page.locator('#phone-game-audio').is_visible())
  ok('no JS exceptions',not errors)
  await browser.close()
 for name,v in results:print(('PASS' if v else 'FAIL'),name,flush=True)
 if errors:print('JS errors:',errors)
 print('Phone proof:',sum(v for _,v in results),'/',len(results))
 if not all(v for _,v in results):raise SystemExit(1)
if __name__=='__main__':asyncio.run(run())