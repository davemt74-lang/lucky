"""Isolated browser and validation tests; not real DB, checkout or physical gamepads."""
from pathlib import Path
import subprocess,re, asyncio, tempfile, shutil
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[1]
CSS='\n'.join((ROOT/f'public/{file}').read_text() for file in ['style.css','platform.css'])
MOCK='''<script>
window.fakeCatalog=[{id:'a'.repeat(32),title:'Arcade Pad',summary:'Controller for the couch',category:'hardware',status:'available',priceCents:5999,imageUrl:'',purchaseUrl:'https://example.org/arcade',sortOrder:0}];
window.fakePosts=[];window.fetch=async(url,options={})=>{
const params=new URL(url,'https://example.test/').searchParams;const a=params.get('action');let data={ok:true};
if(a==='me')data={ok:true,csrf:'csrf-token',admin:true};
else if(a==='catalog'||a==='catalog_admin')data={ok:true,products:window.fakeCatalog.filter(p=>a==='catalog_admin'||p.status!=='draft')};
else if(a==='catalog_save'){const p=JSON.parse(options.body);window.fakePosts.push(p);p.id=p.id||'b'.repeat(32);window.fakeCatalog=[...window.fakeCatalog.filter(x=>x.id!==p.id),p];}
else if(a==='catalog_delete'){const p=JSON.parse(options.body);window.fakeCatalog=window.fakeCatalog.filter(x=>x.id!==p.id);}
return new Response(JSON.stringify(data),{status:200,headers:{'Content-Type':'application/json'}});};
</script>'''
def render(name):
 config=ROOT/'storage/config.php';assert not config.exists(),'test requires no installed config'
 try:
  config.write_text('<?php return [];')
  proc=subprocess.run(['php','-r',f'define("LUCKY_TESTING", true); $_SESSION=["admin_id"=>1]; include "{ROOT/name}.php";'],capture_output=True,text=True,timeout=10)
  assert proc.returncode==0,(proc.stderr,proc.stdout[:500]);return proc.stdout
 finally: config.unlink(missing_ok=True)
def fixture(name):
 html=render(name)
 html=re.sub(r'<link rel="stylesheet" href="public/style.css(?:\?[^"]*)?"><link rel="stylesheet" href="public/platform.css(?:\?[^"]*)?">','<style>'+CSS+'</style>',html)
 html=html.replace('</head>','<base href="https://example.test/">'+MOCK+'</head>',1)
 def inline(m):return '<script>'+ (ROOT/m.group(1).split('?',1)[0]).read_text().replace('</script','<\\/script')+'</script>'
 return re.sub(r'<script src="([^"]+)" defer></script>',inline,html)
async def main():
 checks=[]
 def check(n,c):checks.append((n,bool(c)))
 async with async_playwright() as pw:
  browser=await pw.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox'])
  async def openpage(name,width=1280):
   c=await browser.new_context(viewport={'width':width,'height':850});p=await c.new_page();errors=[];p.on('pageerror',lambda e: errors.append(str(e)));await p.set_content(fixture(name),wait_until='domcontentloaded');await p.wait_for_timeout(170);return p,c,errors
  p,c,errors=await openpage('shop')
  check('store reads public products',await p.locator('.catalog-tile').count()==1)
  check('external buy link HTTPS',await p.get_by_role('link',name='View product').get_attribute('href')=='https://example.org/arcade')
  await p.locator('#catalog-filter').select_option('game');check('store category filters',await p.locator('.catalog-tile').count()==0)
  check('store no JS error',not errors);await c.close()
  p,c,errors=await openpage('catalog-admin')
  check('admin edits product',await p.locator('#catalog-manage-list .catalog-manage-item').count()==1)
  await p.locator('.catalog-actions button').first.click()
  check('edit form population',await p.locator('input[name=title]').input_value()=='Arcade Pad')
  await p.locator('input[name=title]').fill('Arcade Pad Pro')
  await p.locator('#catalog-form button[type=submit]').click();await p.wait_for_timeout(160)
  check('catalog save calls API',await p.evaluate('fakePosts.some(x=>x.title==="Arcade Pad Pro")'))
  check('admin no JS errors',not errors);await c.close()
  p,c,errors=await openpage('devices')
  check('devices empty fallback', 'No controller detected' in await p.locator('#controller-status').inner_text())
  await p.evaluate("navigator.getGamepads=()=>[{index:0,id:'Demo Pad',buttons:Array(16).fill({pressed:false}),axes:[0,0],connected:true}]")
  await p.locator('#controller-refresh').click()
  check('devices shows connected gamepad', 'Demo Pad' in await p.locator('#controller-list').inner_text())
  check('devices no JS errors',not errors);await c.close()
  p,c,errors=await openpage('index')
  check('home has game link',await p.locator('.game-console-tile').count()==1)
  await p.keyboard.press('ArrowRight');check('arrow moves to game link',await p.evaluate('document.activeElement?.classList.contains("game-console-tile")'))
  check('home no JS errors',not errors);await c.close()
  p,c,errors=await openpage('game')
  check('game menu initially closed',not await p.locator('#console-menu').evaluate('(e)=>e.open'))
  await p.keyboard.press('Escape');await p.wait_for_timeout(70)
  check('Escape opens in-game menu',await p.locator('#console-menu').evaluate('(e)=>e.open'))
  await p.locator('[data-console-resume]').click();check('menu resumes/closes',not await p.locator('#console-menu').evaluate('(e)=>e.open'))
  check('game no JS errors',not errors);await c.close()
  for typ in ['shop','catalog-admin','devices','index','game']:
   p,c,err=await openpage(typ,375)
   width=await p.evaluate('Math.max(document.body.scrollWidth, document.documentElement.scrollWidth)')
   check(f'{typ} fits 375px screen',width<=391)
   await c.close()
  await browser.close()
 for name,ok in checks: print(('PASS' if ok else 'FAIL'),name)
 print('V5N browser',sum(ok for _,ok in checks),'/',len(checks))
 if not all(ok for _,ok in checks):raise SystemExit(1)
if __name__=='__main__':asyncio.run(main())
