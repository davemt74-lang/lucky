"""Current-page Chromium UI regressions with an isolated PHP renderer and mocked APIs.

These exercise the current markup, scripts and styles, not the historical V5M
HTML snapshots. They do not certify a real database, HTTP security headers,
camera/microphone access, media playback, or cross-device connectivity.
"""
import asyncio
import mimetypes
from contextlib import asynccontextmanager
from functools import lru_cache
from pathlib import Path
from urllib.parse import urlsplit

from playwright.async_api import async_playwright, expect
from v5p_pairing import php_page

BASE = Path(__file__).resolve().parents[1]
PUBLIC = (BASE / 'public').resolve()
ORIGIN = 'https://example.test'
SESSION = 'a' * 32
CLAIM = 'd' * 32
VIEWER = 'c' * 48

MOCK = r'''<script>
(() => {
 'use strict';
 const clone=value=>JSON.parse(JSON.stringify(value));
 const id='a'.repeat(32),viewerToken='c'.repeat(48);
 const api=window.__api={spin:0,posts:[],calls:[],unhandled:[],tracks:[],data:null,
  settings:{config:{pools:{
   tier1:{budgetCents:30000,prizes:[{id:'t1-1',name:'Gift Card',cents:3750,url:'',image:''}]},
   tier2:{budgetCents:3000,prizes:[{id:'t2-1',name:'Bonus Card',cents:3000,url:'',image:''}]},
   bonus:{budgetCents:2500,prizes:[{id:'bon-1',name:'Mega Prize',cents:2500,url:'',image:''}]}},
   odds:{bonusChance:12,tier1Weight:1,tier2Weight:1,bonusWeight:1}},
   intro:{title:'Spin the Wheel',subtitle:'Come and win!',volume:70,trackId:'',announcement:true},
   theme:{accent:'#f6cb74',background:'#0d1423'}}};
 api.packages=[{id:'f'.repeat(32),name:'Test Prizes',version:1,
  config:clone(api.settings.config),enabled:true,playerSelectable:true,
  publicationStatus:'published',phoneCameraEnabled:true,budgetCents:null,image:''}];
 api.create=()=>{
  const selected=api.packages[0];
  api.data={viewerToken,view:{id,game:'spin-wheel',status:'active',spinCount:0,version:1,
   slots:[{name:'Gift Card',value:3750,tier:'tier1',pending:null}],claims:[],
   lastSpin:null,pendingBonus:false,spinPending:false},
   session:{id,game:'spin-wheel',pools:clone(selected.config.pools),
    odds:clone(selected.config.odds),claims:[],prizePackage:clone(selected)}};
  return api.data;
 };
 // Keep Start Game's optional permission request deterministic and private.
 // These tests deliberately do not create real or fake camera streams.
 if(navigator.mediaDevices)Object.defineProperty(navigator.mediaDevices,'getUserMedia',{
  configurable:true,value:()=>Promise.reject(new DOMException('No camera in UI fixture','NotAllowedError'))});
 window.fetch=async (url,options={})=>{
  const target=new URL(String(url),document.baseURI),action=target.searchParams.get('action');
  const path=target.pathname,input=typeof options.body==='string'?JSON.parse(options.body):{};
  api.calls.push({path,action});
  if(options.method==='POST')api.posts.push({path,action,input});
  let out=null;
  if(path==='/api/controller.php'){
   if(action==='list')out={pairs:[]};
   else if(action==='ui_events')out={events:[]};
   else if(action==='ui_ack')out={};
  }else if(path==='/api/claim-proof.php'&&action==='summary')out={states:{}};
  else if(path==='/api/claim-media.php'&&action==='list')out={clips:[]};
  else if(path==='/api/video-ai.php'&&action==='status')out={claude:{configured:false},skills:[]};
  else if(path==='/api/index.php'){
   switch(action){
    case 'me':out={admin:true,csrf:'test-csrf'};break;
    case 'current':case 'last':out={data:api.data};break;
    case 'tracks':out={tracks:api.tracks};break;
    case 'settings':out={settings:api.settings};break;
    case 'prize_packages':out={packages:api.packages,selected:api.packages[0].id};break;
    case 'package_save':{
     const item=api.packages.find(p=>p.id===input.id);
     if(!item||input.version!==item.version)throw Error('Fixture package version mismatch');
     Object.assign(item,clone(input),{version:item.version+1});out={package:item};break;
    }
    case 'history':out={history:[{id,game:'spin-wheel',claims:1,spins:1,awardedCents:3750,
     endedAt:'2026-10-17 20:00',createdAt:'2026-10-17 19:00'}]};break;
    case 'manifest':out={games:[{id:'spin-wheel',name:'Spin the Wheel',status:'ready',path:'game.php'}]};break;
    case 'sessions':out={sessions:[]};break;
    case 'game_catalog':out={games:[]};break;
    case 'game_packages':out={packages:[],issues:[],active:{}};break;
    case 'game_publisher_channels':out={channels:[]};break;
    case 'game_update_connection':out={connection:{connected:false}};break;
    case 'phone_dashboard_launch':out={launch:null};break;
    case 'party_studio':out={parties:[],themes:[],costumes:[],actions:[
     {id:'equip',name:'Equip',kind:'equip'},{id:'remove',name:'Remove',kind:'remove'}]};break;
    case 'paired_phones':out={phones:[]};break;
    case 'start':out={data:api.create()};break;
    case 'spin':{
     const claim={id:'d'.repeat(32),prizeId:'t1-1',name:'Gift Card',tier:'tier1',cents:3750,
      url:'',image:'',at:'2026-10-17T12:00:00Z',slot:1};
     api.spin++;
     const animation={index:0,slices:[{name:claim.name,cents:claim.cents,tier:claim.tier,kind:'prize'}],
      spinCount:api.spin,type:'award'};
     // Public claims intentionally lack the private proof ID. Host links must
     // be built from the authoritative session claims, as in the real API.
     const publicClaim={name:claim.name,tier:claim.tier,cents:claim.cents};
     Object.assign(api.data.view,{version:api.data.view.version+1,spinCount:api.spin,
      lastSpin:animation,claims:[publicClaim],spinPending:true,
      slots:[{name:'Bonus Card',value:3000,tier:'tier2',pending:null}]});
     api.data.session.claims=[claim];
     out={result:{event:{type:'award',claim,animation},view:api.data.view}};break;
    }
    case 'spin_revealed':api.data.view.spinPending=false;api.data.view.version++;out={};break;
    case 'pause':case 'resume':
     api.data.view.status=action==='pause'?'paused':'active';api.data.view.version++;
     out={result:{event:{type:action==='pause'?'paused':'resumed'}}};break;
    case 'session':out=api.data;break;
    case 'watch':out={view:api.data?.view||api.create().view};break;
    // This scenario has a committed claim and no active acceptance offer.
    // Prize acceptance/handoff is covered by its dedicated browser suites.
    case 'prize_offer':out={offer:null};break;
    case 'track_add':api.tracks.push({id:'t1',title:input.title,kind:'url',src:input.url});out={};break;
   }
  }
  if(out===null){
   api.unhandled.push(path+'?action='+action);
   return new Response(JSON.stringify({ok:false,error:'Unmocked API request'}),{
    status:501,headers:{'Content-Type':'application/json'}});
  }
  return new Response(JSON.stringify({ok:true,...out}),{
   status:200,headers:{'Content-Type':'application/json'}});
 };
})();
</script>'''


@lru_cache(maxsize=5)
def prepare(kind):
    """php_page copies code to a TemporaryDirectory and blocks database access."""
    name = 'index' if kind == 'hub' else kind
    if name not in {'index', 'admin', 'game', 'intro', 'display'}:
        raise ValueError('Unknown browser regression page')
    html = php_page(name)
    if '</head>' not in html:
        raise AssertionError(f'{name}.php did not render a complete page')
    return html.replace('</head>', MOCK + '</head>', 1)


async def main():
    results = []

    def check(name, passed, detail=''):
        results.append((name, bool(passed)))
        print(('PASS' if passed else 'FAIL') + ' ' + name, flush=True)
        assert passed, f'{name}: {detail}'

    async with async_playwright() as playwright:
        browser = await playwright.chromium.launch(
            headless=True, executable_path='/usr/bin/chromium', args=['--no-sandbox'])
        try:
            @asynccontextmanager
            async def page_for(kind, width=1280, height=900):
                html = prepare(kind)
                name = 'index' if kind == 'hub' else kind
                context = await browser.new_context(
                    viewport={'width': width, 'height': height},
                    reduced_motion='reduce', permissions=[], service_workers='block')
                errors, unexpected = [], []
                try:
                    page = await context.new_page()
                    page.set_default_timeout(8000)
                    page.on('pageerror', lambda error: errors.append(str(error)))

                    async def serve(route):
                        request = route.request
                        url = urlsplit(request.url)
                        if url.scheme == 'https' and url.netloc == 'example.test':
                            if url.path == f'/{name}.php' and request.resource_type == 'document':
                                await route.fulfill(status=200, content_type='text/html', body=html)
                                return
                            if url.path.startswith('/public/'):
                                asset = (BASE / url.path.lstrip('/')).resolve()
                                if asset.is_relative_to(PUBLIC) and asset.is_file():
                                    await route.fulfill(path=str(asset), content_type=
                                        mimetypes.guess_type(asset.name)[0] or 'application/octet-stream')
                                    return
                            if url.path == '/favicon.ico':
                                await route.fulfill(status=204)
                                return
                        unexpected.append(request.url)
                        await route.abort('blockedbyclient')

                    # All resources, including versioned CSS and dynamically
                    # loaded scripts, come from this checkout. No network fallback.
                    await context.route('**/*', serve)
                    await page.goto(f'{ORIGIN}/{name}.php', wait_until='load')
                    if kind == 'hub':
                        await page.wait_for_function("__api.calls.some(x=>x.action==='phone_dashboard_launch')")
                    elif kind == 'admin':
                        await expect(page.locator('#admin-status')).to_have_text('Admin settings loaded.')
                    elif kind == 'game':
                        await expect(page.locator('#game-state')).to_have_text('READY FOR NEW GAME')
                        await page.wait_for_function("__api.calls.some(x=>x.action==='ui_events')")
                    elif kind == 'intro':
                        await expect(page.locator('#intro-subtitle')).to_have_text('Come and win!')
                    else:
                        await expect(page.locator('#display-state')).to_have_text('LIVE · 0 SPINS')
                        await page.wait_for_function("__api.calls.some(x=>x.action==='ui_events')")
                    yield page, errors, unexpected
                finally:
                    await context.close()

            async def clean(page, errors, unexpected, label):
                unhandled = await page.evaluate('__api.unhandled')
                check(f'{label} has no uncaught JavaScript errors', not errors, errors)
                check(f'{label} has no unmocked API requests', not unhandled, unhandled)
                check(f'{label} loads only local fixture resources', not unexpected, unexpected)

            async with page_for('hub') as (page, errors, unexpected):
                check('hub game card visible', await page.get_by_role('heading', name='Spin the Wheel').is_visible())
                check('hub Game 2 unavailable', await page.locator('.upcoming .disabled-action').inner_text() == 'Coming next')
                check('hub game enters current splash', await page.locator('.game-console-tile').get_attribute('href') == 'intro.php?game=spin-wheel')
                await clean(page, errors, unexpected, 'hub')

            async with page_for('admin') as (page, errors, unexpected):
                check('admin loaded selected package', await page.locator('#prize-items .prize-catalog-card').count() == 1)
                await page.locator('#add-prize-button').click()
                await expect(page.locator('#prize-editor-modal')).to_be_visible()
                await expect(page.locator('#prize-edit-costume-item')).to_be_enabled()
                await page.locator('#prize-edit-name').fill('Gift Basket')
                await page.locator('#prize-edit-value').fill('25')
                await page.locator('#prize-edit-save').click()
                await expect(page.locator('#prize-editor-modal')).to_be_hidden()
                await expect(page.locator('#prize-items .prize-catalog-card')).to_have_count(2)
                check('admin added named prize', await page.locator('#prize-items').get_by_role('heading', name='Gift Basket').is_visible())
                check('admin saves prize to selected versioned package', await page.evaluate("""__api.posts.some(x=>
                    x.action==='package_save'&&x.input.id===__api.packages[0].id&&x.input.version===1&&
                    x.input.config.pools.tier1.prizes.some(p=>p.name==='Gift Basket'&&p.cents===2500))"""))
                await page.locator('#save-prizes').click()
                await page.wait_for_function("__api.posts.filter(x=>x.action==='package_save').length===2")
                check('explicit package save uses updated version', await page.evaluate(
                    "__api.posts.filter(x=>x.action==='package_save')[1].input.version===2"))
                await page.locator('[data-tab="music"]').click()
                check('admin music tab opens', await page.locator('#tab-music').is_visible())
                await page.locator('#music-add-url input[name="title"]').fill('Theme Song')
                await page.locator('#music-add-url input[name="url"]').fill('https://example.com/theme.mp3')
                await page.locator('#music-add-url button[type="submit"]').click()
                await expect(page.locator('#music-items .platform-claim')).to_have_count(1)
                check('hosted song persisted and listed', await page.locator('#music-items strong').inner_text() == 'Theme Song'
                      and await page.evaluate("__api.tracks.length===1&&__api.tracks[0].src==='https://example.com/theme.mp3'"))
                await clean(page, errors, unexpected, 'admin')

            async with page_for('game') as (page, errors, unexpected):
                await page.locator('#host-tab-display').click()
                await page.locator('#live-open-game-menu').click()
                await expect(page.locator('#console-menu')).to_be_visible()
                check('game start initially enabled', await page.locator('#start-game').is_enabled())
                check('game spin initially disabled', await page.locator('#spin-game').is_disabled())
                await page.locator('#start-game').click()
                await expect(page.locator('#multiplayer-setup')).to_be_visible()
                check('game start awaits participant confirmation', await page.evaluate(
                    "!__api.posts.some(x=>x.action==='start')"))
                await page.locator('#setup-confirm').click()
                await expect(page.locator('#spin-game')).to_be_enabled()
                check('game submits player and host setup', await page.evaluate("""__api.posts.some(x=>
                    x.action==='start'&&x.input.multiplayer.players.length===1&&x.input.multiplayer.hosts.length===1)"""))
                await page.locator('#spin-game').click()
                await expect(page.locator('#game-message')).to_have_text('🎉 Gift Card · $37.50!')
                await expect(page.locator('#claim-list .platform-claim')).to_have_count(1)
                await expect(page.locator('#spin-game')).to_be_enabled()
                check('game reveals spin exactly once', await page.evaluate(
                    "__api.posts.filter(x=>x.action==='spin').length===1&&__api.posts.filter(x=>x.action==='spin_revealed').length===1"))
                await page.locator('[data-console-resume]').click()
                await page.locator('#host-tab-game').click()
                check('game shows current winner announcement', await page.locator('#game-message').is_visible()
                      and await page.locator('#game-message').get_attribute('data-highlight') == 'true')
                check('game records visible claimed prize', await page.locator('#claim-list .platform-claim').is_visible())
                check('game proof link uses private claim ID', await page.locator('#claim-list a').get_attribute('href')
                      == f'proof.php?session={SESSION}&claim={CLAIM}&from=game')
                await page.locator('#host-tab-display').click()
                check('game provides visible viewer link', await page.locator('#share-display').is_visible()
                      and await page.locator('#viewer-link').input_value()
                      == f'{ORIGIN}/display.php?session={SESSION}&token={VIEWER}')
                await page.locator('#live-open-game-menu').click()
                await expect(page.locator('#pause-game')).to_have_text('▶ Resume')
                await expect(page.locator('#pause-game')).to_be_enabled()
                check('opening game menu pauses and blocks spin', await page.locator('#spin-game').is_disabled()
                      and await page.evaluate("__api.posts.some(x=>x.action==='pause')"))
                await page.locator('#pause-game').click()
                await expect(page.locator('#spin-game')).to_be_enabled()
                check('game resume enables spin', await page.evaluate(
                    "__api.data.view.status==='active'&&__api.posts.some(x=>x.action==='resume')"))
                check('game has no reported runtime error', await page.locator('#game-error').inner_text() == '')
                await clean(page, errors, unexpected, 'game')

            async with page_for('intro') as (page, errors, unexpected):
                check('game splash loaded', await page.locator('#intro-title').inner_text() == 'Spin the Wheel')
                check('splash entry points to host', await page.locator('#intro-enter').get_attribute('href') == 'game.php?game=spin-wheel')
                await clean(page, errors, unexpected, 'intro')

            async with page_for('display') as (page, errors, unexpected):
                check('display shows shared status', await page.locator('#display-state').inner_text() == 'LIVE · 0 SPINS')
                check('display has no spin controls', await page.locator('#spin-game').count() == 0)
                await clean(page, errors, unexpected, 'display')

            for kind in ['hub', 'admin', 'game', 'display', 'intro']:
                async with page_for(kind, 375, 812) as (page, errors, unexpected):
                    await page.evaluate('new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)))')
                    width = await page.evaluate('Math.max(document.documentElement.scrollWidth,document.body.scrollWidth)')
                    check(f'{kind} mobile width fits', width <= 390, f'width={width}, viewport=375')
                    await clean(page, errors, unexpected, f'{kind} mobile')
        finally:
            await browser.close()
    print(f'Browser: {sum(passed for _, passed in results)}/{len(results)} checks')


if __name__ == '__main__':
    asyncio.run(asyncio.wait_for(main(), timeout=180))
