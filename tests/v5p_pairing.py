"""Mocked-browser acceptance: UI and controller transport. No real MySQL/HTTPS connectivity."""
from pathlib import Path
import subprocess,re,asyncio
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[1]
CSS='\n'.join((ROOT/'public'/n).read_text() for n in ('style.css','platform.css'))
ID='a'*32;SECRET='b'*64;VIEW='c'*48
MOCK='''<script>
Object.defineProperty(window,"isSecureContext",{configurable:true,value:true});window.testCalls=[];window.testPairs=[];window.testApproved=false;window.testCmds=[];window.testSpin=0;
window.fetch=async (url,opts={})=>{const act=new URL(String(url),'https://example.test/').searchParams.get('action');
let data={ok:true},status=200,inp=opts.body?JSON.parse(opts.body):{};window.testCalls.push({act,inp});
if(act==='me')data={ok:true,admin:true,csrf:'csrf-value'};
else if(act==='last')data={ok:true,data:null};
else if(act==='tracks')data={ok:true,tracks:[]};
else if(act==='list')data={ok:true,pairs:window.testPairs};
else if(act==='invite')data={ok:true,id:'a'.repeat(32),url:'controller.php?invite='+('a'.repeat(32))+'&key='+('b'.repeat(64)),expiresInSeconds:300};
else if(act==='approve'){const p=window.testPairs.find(p=>p.id===inp.id);p.status='paired';data={ok:true,status:'paired'};}
else if(act==='revoke'){window.testPairs=window.testPairs.filter(p=>p.id!==inp.id);data={ok:true,status:'revoked'};}
else if(act==='poll'){data={ok:true,commands:window.testCmds.splice(0)};}
else if(act==='claim'||act==='claim_display'){window.testPairs=[{id:'a'.repeat(32),device_name:inp.name,status:'pending',invite_expires_at:'2026-10-17 22:59:59'}];data={ok:true,id:'a'.repeat(32),controllerToken:'d'.repeat(64),status:'pending'};}
else if(act==='status')data={ok:true,status:window.testApproved?'paired':'pending',deviceName:'Home Phone'};
else if(act==='command'){window.testCmds.push({command:inp.command,deviceName:'Home Phone'});data={ok:true,accepted:true};}
else if(act==='disconnect'){data={ok:true,status:'revoked'};}
else if(act==='start'){window.testSpin++;data={ok:true,data:{view:{id:'a'.repeat(32),status:'active',spinCount:0,version:1,slots:[],claims:[]},session:{pools:{bonus:{prizes:[]}},odds:{bonusChance:0}},viewerToken:'c'.repeat(48)}};}
else if(act==='settings')data={ok:true,settings:{}};
else if(act==='watch')data={ok:true,view:{status:'active',spinCount:0,version:1,claims:[],slots:[]}};
return new Response(JSON.stringify(data),{status,headers:{'Content-Type':'application/json'}});
};
</script>'''

def php_page(name,session=False):
 cfg=ROOT/'storage/config.php'
 assert not cfg.exists()
 try:
  cfg.write_text('<?php return [];')
  prefix='$_GET=["session"=>"'+ID+'","token"=>"'+VIEW+'"]; ' if name=='display' else ''
  command=f'define("LUCKY_TESTING",true); $_SESSION=["admin_id"=>1]; {prefix} include "{ROOT/name}.php";'
  p=subprocess.run(['php','-r',command],capture_output=True,text=True,timeout=15)
  assert p.returncode==0,(p.stderr,p.stdout[:150])
  return p.stdout
 finally:cfg.unlink(missing_ok=True)

def make_html(name):
 raw=php_page(name)
 raw=re.sub(r'<link rel="stylesheet" href="public/style.css(?:\?[^"]*)?"><link rel="stylesheet" href="public/platform.css(?:\?[^"]*)?">','<style>'+CSS+'</style>',raw)
 raw=raw.replace('</head>','<base href="https://example.test/">'+MOCK+'</head>',1)
 return re.sub(r'<script src="([^"]+)" defer></script>',lambda m:'<script>'+(ROOT/m.group(1).split('?',1)[0]).read_text().replace('</script','<\\/script')+'</script>',raw)

async def main():
 checks=[]
 def check(msg,got):checks.append((msg,bool(got)))
 async with async_playwright() as pw:
  browser=await pw.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox'])
  async def open_page(name,width=1200,query=''):
   ctx=await browser.new_context(viewport={'width':width,'height':820},reduced_motion='reduce')
   page=await ctx.new_page();err=[];page.on('pageerror',lambda e:err.append(str(e)))
   html=make_html(name)
   html=html.replace('<base href="https://example.test/">','<base href="https://example.test/'+name+'.php'+query+'">')
   await page.set_content(html,wait_until='domcontentloaded')
   return page,ctx,err
  page,ctx,err=await open_page('game')
  check('host shows connect-phone entry',await page.locator('#pair-new').count()==1)
  await page.locator('#pair-new').click()
  await page.wait_for_timeout(150)
  check('host invite displays QR',await page.locator('#pair-qr svg rect').count()>100)
  check('host invite contains local pairing URL', 'controller.php?invite=' in await page.locator('#pair-link').input_value())
  check('host invitation creation calls authenticated API',await page.evaluate("testCalls.some(x=>x.act==='invite')"))
  await page.screenshot(path=str(ROOT.parent/'Lucky-Game-Hub-V5P-host-pairing.png'),full_page=True)
  await page.evaluate("testPairs=[{id:'a'.repeat(32),device_name:'Home Phone',status:'pending'}]")
  await page.wait_for_timeout(2200)
  check('host receives pending phone',await page.get_by_text('Home Phone').count()>0)
  await page.get_by_role('button',name='Approve').click()
  await page.wait_for_timeout(90)
  check('host approval calls API',await page.evaluate("testCalls.some(x=>x.act==='approve')"))
  await page.evaluate("testCmds.push({command:'start',deviceName:'Home Phone'})")
  await page.wait_for_timeout(950)
  check('legacy queued start does not double-execute a server-authoritative game start',await page.evaluate('testSpin===0'))
  check('host pairing JS has no uncaught errors',not err)
  await ctx.close()
  page,ctx,err=await open_page('controller',375,'?invite='+ID+'&key='+SECRET)
  check('phone join prompts for name',await page.locator('#phone-join').is_visible())
  check('phone controls hidden before host approval',await page.locator('#phone-controls').is_hidden())
  await page.locator('#phone-name').fill('Home Phone')
  await page.locator('#phone-join-form button').click();await page.wait_for_timeout(110)
  check('phone sends pairing request',await page.evaluate("testCalls.some(x=>x.act==='claim')"))
  check('phone waiting approval',await page.locator('#phone-wait').is_visible())
  check('phone gets pending state without exposing token in page',await page.locator('#phone-wait').is_visible())
  await page.evaluate('testApproved=true')
  await page.wait_for_timeout(2100)
  check('phone controls unlock on host approval',await page.locator('#phone-controls').is_visible())
  await page.locator('[data-phone=spin]').click();await page.wait_for_timeout(90)
  check('phone posts spin command',await page.evaluate("testCalls.some(x=>x.act==='command'&&x.inp.command==='spin')"))
  check('phone command includes nonce',await page.evaluate("testCalls.some(x=>x.act==='command'&&x.inp.nonce.length>=12)"))
  await page.locator('[data-phone=music-next]').click();await page.wait_for_timeout(80)
  check('phone sends song selection',await page.evaluate("testCalls.some(x=>x.act==='command'&&x.inp.command==='music-next')"))
  check('phone fits mobile width',await page.evaluate('document.documentElement.scrollWidth <= innerWidth + 1'))
  check('phone JS no uncaught exceptions',not err)
  await page.screenshot(path=str(ROOT.parent/'Lucky-Game-Hub-V5P-phone-controller.png'),full_page=True)
  await ctx.close()
  page,ctx,err=await open_page('controller',375,'?session='+ID+'&viewer='+VIEW)
  await page.locator('#phone-name').fill('TV Phone');await page.locator('#phone-join-form button').click();await page.wait_for_timeout(100)
  check('TV pairing calls display token verification',await page.evaluate("testCalls.some(x=>x.act==='claim_display')"))
  await ctx.close()
  page,ctx,err=await open_page('display')
  await page.wait_for_timeout(230)
  check('TV view has scannable pairing QR',await page.locator('#display-pair-qr svg rect').count()>100)
  check('TV view QR rendered without JS errors',not err)
  await ctx.close()
  page,ctx,err=await open_page('devices',375)
  check('Devices page has host pairing section',await page.locator('#phone-pairing').count()==1)
  check('Devices page fits mobile width',await page.evaluate('document.documentElement.scrollWidth <= innerWidth + 1'))
  check('Devices JS no uncaught errors',not err)
  await ctx.close()
  await browser.close()
 for title,passed in checks:print(('PASS' if passed else 'FAIL'),title)
 print('V5P browser:',sum(p for _,p in checks),'/',len(checks))
 if not all(p for _,p in checks):raise SystemExit(1)
if __name__=='__main__':asyncio.run(main())
