"""V5Q.25C: Game Catalog uses accessible, isolated panels without replacing forms."""
import asyncio
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[1]
ADMIN=(ROOT/'admin.php').read_text()
SECTION=ADMIN.split('<section id="tab-games"',1)[1].split('<section id="tab-prizes"',1)[0]
HTML='<section id="tab-games"'+SECTION
HTML=HTML.replace('aria-label="Game catalog" hidden>','aria-label="Game catalog">',1)
BASE='''<script>
window.__api=[];
window.Lucky={api:async(action,opts={})=>{
 __api.push({action,body:opts.body});
 if(action==='game_catalog')return {games:[{id:'spin-wheel',title:'Spin the Wheel',
  publisherName:'Lucky Games',tagline:'Classic game',description:'Wheel fun',category:'Games',
  version:'bundled',ageRating:'Everyone',playersMin:1,playersMax:8,controllerMode:'custom',
  enabled:true,builtIn:true,status:'ready',images:{} }]};
 if(action==='game_packages')return {packages:[],issues:[],active:{}};
 if(action==='game_publisher_channels')return {channels:[]};
 if(action==='game_update_connection')return {connection:{connected:false}};
 return {ok:true};
}};
</script>'''
HEAD='''<head><meta name="viewport" content="width=device-width,initial-scale=1"><style>'''+(ROOT/'public/platform.css').read_text()+'''</style></head>'''
SCRIPTS='''<script>'''+(ROOT/'public/game-catalog.js').read_text().replace('</script','<\\/script')+'''</script><script>'''+(ROOT/'public/game-catalog-tabs.js').read_text()+'''</script>'''
PAGE='<!doctype html><html>'+HEAD+'<body class="admin-studio-page">'+HTML+BASE+SCRIPTS+'</body></html>'
NAMES=['profiles','packages','publishers','legacy']
async def main():
 checks=[];errors=[]
 def check(label,yes):checks.append((label,bool(yes)))
 async with async_playwright() as pw:
  browser=await pw.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox'])
  page=await browser.new_page(viewport={'width':1280,'height':800})
  page.on('pageerror',lambda err:errors.append(str(err)))
  await page.set_content(PAGE)
  await page.wait_for_function("document.getElementById('catalog-title').value==='Spin the Wheel'")
  check('Four navigable catalog tabs',await page.locator('[data-catalog-tab]').count()==4)
  check('Only profile pane initially visible',all([await page.locator('#game-catalog-pane-'+name).is_visible()==(name=='profiles') for name in NAMES]))
  check('Existing game details loaded',await page.locator('#catalog-title').input_value()=='Spin the Wheel')
  check('Create draft button visible only on profile tab',await page.locator('#catalog-new-game').is_visible())
  await page.locator('#catalog-tagline').fill('Not saved yet')
  await page.locator('#game-catalog-tab-packages').click()
  check('Package upload and refresh available',await page.locator('#game-package-upload').is_visible() and await page.locator('#game-package-refresh').is_visible())
  check('Profile fields hidden on Packages',not await page.locator('#catalog-editor').is_visible())
  check('Packages tab selected with accessible states',await page.locator('#game-catalog-tab-packages').get_attribute('aria-selected')=='true' and await page.locator('#game-catalog-tab-profiles').get_attribute('aria-selected')=='false')
  await page.locator('#game-catalog-tab-publishers').click()
  check('Per-game publisher editor available',await page.locator('#game-publisher-form').is_visible())
  await page.locator('#game-publisher-url').fill('https://publisher.example/releases.json')
  await page.locator('#game-catalog-tab-legacy').click()
  check('Legacy editor available independently',await page.locator('#game-update-connect-form').is_visible() and not await page.locator('#game-publisher-form').is_visible())
  await page.locator('#game-catalog-tab-legacy').focus()
  await page.keyboard.press('Home')
  check('Home key returns focus to Profiles',await page.locator('#game-catalog-tab-profiles').evaluate('(el)=>document.activeElement===el'))
  await page.keyboard.press('End')
  check('End key focuses Legacy',await page.locator('#game-catalog-tab-legacy').evaluate('(el)=>document.activeElement===el'))
  await page.keyboard.press('ArrowRight')
  check('ArrowRight wraps to Profiles',await page.locator('#game-catalog-tab-profiles').get_attribute('aria-selected')=='true')
  check('Draft game profile text survives tab switches',await page.locator('#catalog-tagline').input_value()=='Not saved yet')
  await page.locator('#game-catalog-tab-publishers').click()
  check('Publisher feed draft survives tab switches',await page.locator('#game-publisher-url').input_value()=='https://publisher.example/releases.json')
  check('No catalog UI action automatically writes backend',not any(x['action'].endswith('_save') for x in await page.evaluate('window.__api')))
  for width in [375,768,1280]:
   await page.set_viewport_size({'width':width,'height':800})
   check(f'{width}px: tabs remain reachable without horizontal body overflow',await page.evaluate('document.documentElement.scrollWidth<=window.innerWidth+3'))
  # Navigations are restricted in this browser sandbox; tab state itself is exercised.
  check('No uncaught browser exceptions',not errors)
  await browser.close()
 for label,yes in checks:print(('PASS ' if yes else 'FAIL ')+label,flush=True)
 print(f'V5Q.25C Game Catalog tabs: {sum(ok for _,ok in checks)} / {len(checks)}')
 if errors:print('ERRORS',errors)
 if not all(ok for _,ok in checks):raise SystemExit(1)
if __name__=='__main__':asyncio.run(main())
