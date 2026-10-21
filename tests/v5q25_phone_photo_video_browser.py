"""V5Q.25 phone: image is phone-only, video launches desktop, accepted prize remains visible."""
import asyncio,re
from pathlib import Path
from http.server import HTTPServer,BaseHTTPRequestHandler
from threading import Thread
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[1]
HTML=(ROOT/'controller.php').read_text().split('?>',1)[1]
CLIENT=(ROOT/'public/phone-controller.js').read_text()
HTML=re.sub(r'<script src="public/voice-commands.js[^>]+></script>','',HTML)
HTML=re.sub(r'<script src="public/phone-controller.js[^>]+></script>',lambda _:'<script>'+CLIENT.replace('</script','<\\/script')+'</script>',HTML)
MOCK="""<script>
window.__commands=[];window.__proof=false;window.__offer={status:'pending',claimId:'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',name:'Pizza Prize',cents:2900,description:'You won Pizza Prize worth $29.00!',image:'http://127.0.0.1:8967/photo.jpg',videoUrl:'api/index.php?action=prize_video&id=eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee'};
window.fetch=async(url,opts={})=>{
 const action=new URL(String(url),'http://127.0.0.1:8967/').searchParams.get('action');
 if(action==='status')return new Response(JSON.stringify({ok:true,status:'paired',game:{id:'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',game:'spin-wheel',status:'active',version:1,remaining:3,canStart:false,canSpin:false,spinCount:1,claimedCount:1,offer:window.__offer},proof:__proof?{active:true,claimId:__offer.claimId,consent:true,phase:'live'}:{active:false}}));
 if(action==='command'){const body=JSON.parse(opts.body);__commands.push(body.command);return new Response(JSON.stringify({ok:true,accepted:true,commandId:1,applied:false}));}
 if(action==='dashboard_games')return new Response(JSON.stringify({ok:true,games:[]}));
 return new Response(JSON.stringify({ok:true}));
};
</script>"""
HTML=HTML.replace('</head>','<base href="http://127.0.0.1:8967/">'+MOCK+'</head>',1)
async def main():
  checks=[];errors=[]
  def ck(name,v): checks.append((name,bool(v)))
  async with async_playwright() as p:
    browser=await p.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox'])
    page=await browser.new_page(viewport={'width':390,'height':800})
    page.on('pageerror',lambda e:errors.append(str(e)))
    await page.add_init_script("sessionStorage.setItem('lucky-phone-controller-v5p',JSON.stringify({id:'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',token:'"+'c'*64+"'}))")
    await page.evaluate("""() => {const bag=new Map([['lucky-phone-controller-v5p',JSON.stringify({id:'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',token:'"""+'c'*64+"""'})]]);Object.defineProperty(window,'sessionStorage',{configurable:true,value:{setItem:(k,v)=>bag.set(k,v),getItem:k=>bag.get(k)||null,removeItem:k=>bag.delete(k)}})}""")
    await page.set_content(HTML,wait_until='domcontentloaded')
    await page.locator('#phone-prize-offer').wait_for(state='visible',timeout=5000)
    ck('Award photo is shown in the phone prize card','photo.jpg' in (await page.locator('#phone-offer-photo').get_attribute('src') or ''))
    ck('Desktop play button appears for an uploaded video',await page.locator('#phone-offer-play-video').is_visible())
    ck('Custom description displayed on phone', 'Pizza Prize worth $29.00' in await page.locator('#phone-offer-description').inner_text())
    ck('Phone does not embed video',await page.locator('#phone-prize-offer video,#phone-prize-offer iframe').count()==0)
    await page.locator('#phone-offer-play-video').click()
    await page.wait_for_function("__commands.includes('prize-video-play')")
    ck('Play Video sends authenticated desktop command',await page.evaluate("__commands.includes('prize-video-play')"))
    await page.evaluate("__proof=true;__offer={...__offer,status:'accepted'}")
    await page.locator('#phone-proof-prize').wait_for(state='visible',timeout=5500)
    ck('Accepted prize photo persists into proof controls','photo.jpg' in (await page.locator('#phone-proof-prize-photo').get_attribute('src') or ''))
    ck('Accepted prize also retains Play Video button',await page.locator('#phone-proof-play-video').is_visible())
    await page.evaluate("__proof=false;__offer={...__offer,status:'pending',claimId:'dddddddddddddddddddddddddddddddd',image:'',videoUrl:''}")
    await page.locator('#phone-prize-offer').wait_for(state='visible',timeout=5500)
    ck('Image-free prize hides photo',await page.locator('#phone-offer-photo').is_hidden())
    ck('Video-free prize hides Play Video',await page.locator('#phone-offer-play-video').is_hidden())
    ck('No unhandled JS exceptions',not errors)
    await browser.close()
  for name,v in checks:print(('PASS ' if v else 'FAIL ')+name,flush=True)
  print('V5Q25 phone:',sum(v for _,v in checks),'/',len(checks),flush=True)
  if not all(v for _,v in checks):raise SystemExit(1)
if __name__=='__main__':asyncio.run(main())
