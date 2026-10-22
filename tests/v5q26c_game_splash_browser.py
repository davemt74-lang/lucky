"""V5Q.26C: game-owned splash editor, picture upload, and voice-button removal."""
import asyncio
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[1]
ADMIN=(ROOT/'admin.php').read_text()
SECTION=ADMIN.split('<section id="tab-games"',1)[1].split('<section id="tab-prizes"',1)[0]
HTML='<section id="tab-games"'+SECTION.replace('aria-label="Game catalog" hidden>','aria-label="Game catalog">',1)
CSS=(ROOT/'public/platform.css').read_text()
JS=(ROOT/'public/game-catalog.js').read_text()
BOOT="""<script>
window.__calls=[];
window.__games=[
{id:'spin-wheel',title:'Spin the Wheel',tagline:'',description:'Wheel',publisherName:'',publisherWebsite:'',category:'Games',version:'bundled',ageRating:'',playersMin:1,playersMax:8,controllerMode:'custom',enabled:true,builtIn:true,status:'ready',images:{}},
{id:'next-game',title:'Next Game',tagline:'',description:'Future',publisherName:'',publisherWebsite:'',category:'Games',version:'0.1',ageRating:'',playersMin:1,playersMax:4,controllerMode:'custom',enabled:false,builtIn:false,status:'draft',images:{},splash:{intro:{title:'Future Night',subtitle:'Soon',trackId:'',volume:42,announcement:false},theme:{accent:'#f6cb74',background:'#111b30'}}}];
window.__settings={intro:{title:'Existing Wheel Welcome',subtitle:'Original subtitle',trackId:'',volume:45,announcement:false},theme:{accent:'#aabbcc',background:'#101d30'}};
window.Lucky={
 fillTracks:(id,v)=>{const n=document.getElementById(id);n.replaceChildren(new Option('No song selected',''));n.value='';},
 loadTracks:async()=>[],
 api:async(action,opts={})=>{
  __calls.push({action,body:opts.body});
  if(action==='game_catalog')return {games:structuredClone(__games)};
  if(action==='settings')return {settings:structuredClone(__settings)};
  if(action==='settings_save'){__settings={intro:structuredClone(opts.body.intro),theme:structuredClone(opts.body.theme)};return {settings:__settings};}
  if(action==='game_catalog_save'){let g=__games.find(x=>x.id===opts.body.id);Object.assign(g,opts.body);return {game:structuredClone(g)};}
  if(action==='game_art_upload'){let g=__games.find(x=>x.id===opts.body.get('id'));g.images.splash='f'.repeat(32)+'.png';return {game:structuredClone(g)};}
  if(action==='game_packages')return {packages:[],issues:[]};
  if(action==='game_publisher_channels')return {channels:[]};
  if(action==='game_update_connection')return {connection:{connected:false}};
  return {ok:true};
 }};</script>"""
PAGE='<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><base href="https://lucky.test/"><style>'+CSS+'</style></head><body class="admin-studio-page">'+HTML+BOOT+'<script>'+JS.replace('</script','<\\/script')+'</script></body></html>'
async def main():
 checks=[];errors=[]
 def ck(name,val):checks.append((name,bool(val)))
 async with async_playwright() as pw:
  b=await pw.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox'])
  page=await b.new_page(viewport={'width':1280,'height':900})
  page.on('pageerror',lambda e:errors.append(str(e)))
  await page.set_content(PAGE,wait_until='domcontentloaded')
  await page.wait_for_function("document.getElementById('intro-title').value==='Existing Wheel Welcome'")
  ck('Previously saved intro loaded',await page.locator('#intro-title').input_value()=='Existing Wheel Welcome')
  ck('Old sidebar removed','data-tab="intro"' not in ADMIN and 'id="tab-intro"' not in ADMIN)
  ck('Background upload belongs to selected game',await page.locator('[data-game-art=splash]').count()==1)
  ck('Preview links to selected game',await page.locator('#catalog-preview-splash').get_attribute('href')=='intro.php?game=spin-wheel')
  await page.locator('#intro-title').fill('New Wheel Party')
  await page.locator('#catalog-editor button[type=submit]').click()
  await page.wait_for_function("__games[0].splash?.intro?.title==='New Wheel Party'")
  ck('Ready game uses authoritative settings API',await page.evaluate("__calls.some(x=>x.action==='settings_save'&&x.body.game==='spin-wheel')"))
  await page.locator('#catalog-game-list .game-catalog-card').nth(1).click()
  await page.wait_for_function("document.getElementById('intro-title').value==='Future Night'")
  ck('Draft has separate splash settings',await page.locator('#intro-title').input_value()=='Future Night')
  ck('Draft preview hidden until installed',await page.locator('#catalog-preview-splash').is_hidden())
  await page.locator('#intro-title').fill('Future Night Two')
  await page.locator('#catalog-editor button[type=submit]').click()
  await page.wait_for_function("__games[1].splash.intro.title==='Future Night Two'")
  ck('Draft persisted without creating live settings',await page.evaluate("__calls.filter(x=>x.action==='settings_save').length===1"))
  png=bytes.fromhex('89504e470d0a1a0a0000000d49484452000000010000000108060000001f15c4890000000d4944415478081d63f8cfc0f01f000160029ffa5b42c80000000049454e44ae426082')
  await page.locator('[data-game-art=splash]').set_input_files({'name':'splash.png','mimeType':'image/png','buffer':png})
  await page.wait_for_function("(__games[1].images.splash||'').endsWith('.png')")
  ck('Upload targets exact draft and splash slot',await page.evaluate("__calls.some(x=>x.action==='game_art_upload'&&x.body.get('id')==='next-game'&&x.body.get('slot')==='splash')"))
  ck('Private artwork route supplies image', 'action=game_art' in (await page.locator('#catalog-splash-preview').get_attribute('src') or ''))
  ck('Other game artwork stays unchanged',await page.evaluate("!__games[0].images.splash"))
  await page.set_viewport_size({'width':375,'height':840})
  ck('Mobile layout without body overflow',await page.evaluate('document.documentElement.scrollWidth<=innerWidth+3'))
  intro=(ROOT/'intro.php').read_text();script=(ROOT/'public/intro.js').read_text()
  ck('Voice button and listening handler removed','id="intro-voice"' not in intro and 'intro-voice' not in script)
  ck('Splash JS loads game ID from page', "document.body.dataset.game||'spin-wheel'" in script)
  ck('No JS exceptions',not errors)
  await b.close()
 for name,ok in checks:print(('PASS ' if ok else 'FAIL ')+name,flush=True)
 print('V5Q26C:',sum(ok for _,ok in checks),'/',len(checks),flush=True)
 if errors:print('ERRORS',errors)
 if not all(ok for _,ok in checks):raise SystemExit(1)
if __name__=='__main__':asyncio.run(main())
