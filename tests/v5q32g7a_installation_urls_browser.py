"""G7A: actual sharing/media consumers with the production installation URL helper.

Uses current PHP-rendered or extracted page markup, native DOM/clipboard events
and canvas tracks. Only server transport/fixture records are mocked; no real LAN,
camera, certificate trust, MariaDB or third-party WebRTC claim is made here.
"""
import asyncio
import base64
import json
import re
from contextlib import asynccontextmanager
from pathlib import Path
from urllib.parse import urlparse

from playwright.async_api import async_playwright
from v5p_pairing import MOCK as PAIR_BOOT, php_page
from v5q25_package_live_browser import BODY as GAME_BODY, BOOT as GAME_BOOT
from v5q25_admin_modals_browser import BODY as ADMIN_BODY, BOOT as ADMIN_BOOT

ROOT = Path(__file__).resolve().parents[1]
LAN = 'https://192.168.1.42:8843'
LOCAL = 'http://localhost:8833'
ID, SHOW, KEY = 'a' * 32, 'd' * 32, 'b' * 48
PNG = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j4N8AAAAASUVORK5CYII=')


def asset(name):
    return (ROOT / name).read_text().replace('</script', '<\\/script')


def body_of(page):
    raw = php_page(page)
    body = re.search(r'<body\b[^>]*>([\s\S]*)</body>', raw)
    assert body, page
    return re.sub(r'<script\b[^>]*>[\s\S]*?</script>', '', body.group(1))


def script(code):
    return '<script>' + code + '</script>'


def html(body, boot, scripts, base, *, mode='homeserver', helper=True, dataset='', lan=LAN):
    network = json.dumps({'ok': True, 'deployment': mode, 'lanOrigin': lan, 'leaseSeconds': 30 if lan else 0})
    common = r"""
    window.__network=NETWORK;window.__requests=[];window.__copied=[];window.__timeOffset=0;
    const originalNow=performance.now.bind(performance);
    Object.defineProperty(performance,'now',{configurable:true,value:()=>originalNow()+__timeOffset});
    Object.defineProperty(navigator,'clipboard',{configurable:true,value:{writeText:async value=>{__copied.push(value);}}});
    Object.defineProperty(window,'isSecureContext',{configurable:true,value:true});
    const previousFetch=window.fetch;
    window.fetch=async(url,options={})=>{
     const parsed=new URL(String(url),document.baseURI);
     __requests.push({url:parsed.href,action:parsed.searchParams.get('action'),
      method:options.method||'GET',credentials:options.credentials||'',headers:options.headers||{},body:options.body||null});
     if(parsed.pathname.endsWith('/api/installation-urls.php')){
      if(window.__holdLease)return new Promise(()=>{});
      return {ok:__network.ok!==false,json:async()=>structuredClone(__network)};
     }
     return previousFetch(url,options);
    };
    """.replace('NETWORK', network)
    tags = (f'<meta name="lucky-deployment" content="{mode}">'
            f'<meta name="lucky-lan-origin" content="{lan}">'
            f'<meta name="lucky-lan-lease-seconds" content="{30 if lan else 0}">')
    styles = asset('public/style.css') + '\n' + asset('public/platform.css')
    return ('<!doctype html><html><head><meta charset="utf-8"><base href="' + base + '">'
            + tags + '<style>' + styles + '</style></head><body ' + dataset + '>'
            + body + boot + script(common)
            + (script(asset('public/installation-urls.js')) if helper else '')
            + ''.join(script(asset(name)) for name in scripts) + '</body></html>')


GAME_EXTRA = script(r"""
window.__liveSession={viewerToken:'b'.repeat(48),view:{id:'a'.repeat(32),status:'active',version:1,
 spinCount:0,slots:[],claims:[]},session:{pools:{tier1:{prizes:[]},tier2:{prizes:[]},bonus:{prizes:[]}},
 odds:{bonusChance:0},prizePackage:{name:'Test prize package'}}};
const oldGameApi=Lucky.api;
Lucky.getMe=async()=>({csrf:'owner-csrf'});
Lucky.api=async(action,options={})=>{
 if(action==='current'||action==='last')return {data:__liveSession};
 if(action==='session')return __liveSession;
 if(action==='watch')return {view:__liveSession.view};
 return oldGameApi(action,options);
};
""")

PUBLISH_BOOT = script(r"""
window.__outputs=[];window.__publishes=[];
window.Lucky={getMe:async()=>({csrf:'owner-csrf'}),api:async()=>({context:null})};
window.LuckyCameraDirector={programStream:()=>{
 const canvas=document.getElementById('director-program');canvas.width=320;canvas.height=180;
 canvas.getContext('2d').fillRect(0,0,320,180);const output=canvas.captureStream(10);
 __outputs.push(output);return output;
}};
window.RTCPeerConnection=class {constructor(){this.iceGatheringState='complete'}addTrack(){}close(){}};
window.fetch=async(url,options={})=>{
 const action=new URL(String(url),document.baseURI).searchParams.get('action');let result={ok:true};
 if(action==='show_link')result.showId='d'.repeat(32);
 if(action==='publish'){__publishes.push(JSON.parse(options.body));result={ok:true,id:'a'.repeat(32),key:'b'.repeat(48),showId:'d'.repeat(32)};}
 if(action==='host_list')result.offers=[];
 if(action==='host_status')result={ok:true,live:true,visibility:__publishes.at(-1)?.visibility,viewerCount:0};
 return {ok:true,status:200,json:async()=>result};
};
""")

VIEW_BOOT = script(r"""
window.__peers=[];
window.RTCPeerConnection=class {
 constructor(){this.iceGatheringState='complete';this.connectionState='new';__peers.push(this);}
 addTransceiver(){}createOffer(){return Promise.resolve({type:'offer',sdp:'v=0\r\nlocal'});}
 async setLocalDescription(value){this.localDescription=value;}
 async setRemoteDescription(value){this.remoteDescription=value;this.connectionState='connected';this.onconnectionstatechange?.();}
 close(){this.connectionState='closed';}
};
window.fetch=async(url)=>{
 const action=new URL(String(url),document.baseURI).searchParams.get('action');
 return {ok:true,status:200,json:async()=>({ok:true,live:true,...(action==='poll'?{answer:'v=0\r\nremote'}:{})})};
};
""")

ADMIN_EXTRA = script(r"""
__package.config.pools.tier1.prizes[0].image='http://localhost:8833/api/index.php?action=prize_image&id='+'a'.repeat(32);
const oldAdminApi=Lucky.api;
Lucky.api=async(action,options={})=>{
 if(action==='image_upload')return {path:'api/index.php?action=prize_image&id='+'a'.repeat(32)};
 if(action==='prize_video_upload')return {path:'api/index.php?action=prize_video&id='+'a'.repeat(32)};
 if(action==='party_studio')return {costumes:[]};
 return oldAdminApi(action,options);
};
window.fetch=async()=>({ok:true,json:async()=>({ok:true})});
""")

PHONE_BOOT = script(r"""
const bag=new Map([['lucky-phone-controller-v5p',JSON.stringify({id:'a'.repeat(32),token:'c'.repeat(64)})]]);
Object.defineProperty(window,'sessionStorage',{configurable:true,value:{getItem:key=>bag.get(key)||null,setItem:(key,value)=>bag.set(key,value),removeItem:key=>bag.delete(key)}});
window.fetch=async(url,options={})=>{
 const action=new URL(String(url),document.baseURI).searchParams.get('action');let result={ok:true};
 if(action==='status')result={ok:true,status:'paired',proof:{active:false},game:{id:'a'.repeat(32),game:'spin-wheel',status:'active',version:1,
  remaining:3,canStart:false,canSpin:false,spinCount:1,claimedCount:1,offer:{status:'pending',claimId:'d'.repeat(32),name:'Test prize',cents:1000,
   image:'http://localhost:8833/api/index.php?action=prize_image&id='+'a'.repeat(32),
   videoUrl:'http://localhost:8833/api/index.php?action=prize_video&id='+'a'.repeat(32)}}};
 if(action==='command')result={ok:true,accepted:true,commandId:1,applied:false};
 if(action==='dashboard_games')result.games=[];
 return {ok:true,status:200,json:async()=>result};
};
""")


async def main():
    checks = []

    def check(label, value):
        checks.append((label, bool(value)))
        print(('PASS ' if value else 'FAIL ') + label, flush=True)
        assert value, label

    async with async_playwright() as playwright:
        browser = await playwright.chromium.launch(executable_path='/usr/bin/chromium', headless=True,
                                                    args=['--no-sandbox'], timeout=15000)

        @asynccontextmanager
        async def page_fixture(markup, address):
            context = await browser.new_context(viewport={'width': 1360, 'height': 960}, reduced_motion='reduce')
            page = await context.new_page()
            page.set_default_timeout(6000)
            errors = []
            page.on('pageerror', lambda error: errors.append(str(error)))

            async def route(request):
                if request.request.url == address:
                    await request.fulfill(status=200, content_type='text/html', body=markup)
                elif '/api/index.php?action=prize_image&' in request.request.url:
                    await request.fulfill(status=200, content_type='image/png', body=PNG)
                else:
                    await request.abort()

            await page.route('**/*', route)
            try:
                await page.goto(address, wait_until='domcontentloaded', timeout=10000)
                yield page
                check('Consumer fixture has no uncaught browser errors: ' + urlparse(address).path, not errors)
            finally:
                await context.close()

        try:
            devices = body_of('devices')
            address = LOCAL + '/devices.php'
            markup = html(devices, PAIR_BOOT, ['public/qr.js', 'public/pairing-host.js'], address)
            async with page_fixture(markup, address) as page:
                await page.locator('#pair-new').click()
                await page.wait_for_function('document.getElementById("pair-link").value.length>0')
                expected = LAN + '/controller.php?invite=' + ID + '&key=' + 'b' * 64
                check('Host pairing field contains trusted LAN origin and unchanged invitation capability',
                      await page.locator('#pair-link').input_value() == expected)
                check('Pairing QR is rendered by the actual QR encoder', await page.locator('#pair-qr svg rect').count() > 100)
                await page.locator('#pair-copy').click()
                check('Pairing copy uses the same LAN invitation', await page.evaluate('__copied.at(-1)') == expected)
                check('Host invitation API remains local and authenticated', await page.evaluate('''__requests.some(r=>r.action==='invite'&&
                  r.url.startsWith('http://localhost:8833/api/')&&r.credentials==='same-origin'&&r.headers['X-CSRF-Token']==='csrf-value')'''))
                await page.evaluate('__timeOffset+=31000')
                await page.locator('#pair-copy').click()
                check('Copy revalidates age even before delayed expiry callbacks; no expired capability is copied',
                      await page.evaluate('__copied.length===1') and await page.locator('#pair-link').input_value() == '')
                check('Expired host invite hides QR and explains recovery', await page.locator('#pair-invite').is_hidden()
                      and 'sharing is unavailable' in await page.locator('#pair-host-status').inner_text())
                before = await page.evaluate('testCalls.filter(r=>r.act==="invite").length')
                await page.locator('#pair-new').click()
                check('An open stale page cannot mint another invite',
                      await page.evaluate('testCalls.filter(r=>r.act==="invite").length') == before)
                await page.evaluate('''async()=>{__network.lanOrigin='https://10.2.3.4:8843';await LuckyInstallationUrls.refresh();}''')
                await page.locator('#pair-new').click()
                await page.wait_for_function('document.getElementById("pair-link").value.startsWith("https://10.2.3.4:8843/")')
                check('A newly validated network rebuilds the invitation without changing its scope',
                      await page.locator('#pair-link').input_value() == expected.replace(LAN, 'https://10.2.3.4:8843'))

            for helper in (True, False):
                markup = html(devices, PAIR_BOOT, ['public/qr.js', 'public/pairing-host.js'], address, helper=helper, lan='')
                async with page_fixture(markup, address) as page:
                    await page.locator('#pair-new').click()
                    check('Declared HomeServer without ' + ('a fresh LAN lease' if helper else 'the helper') + ' never creates/copies localhost QR',
                          await page.evaluate('!testCalls.some(r=>r.act==="invite")')
                          and await page.locator('#pair-link').input_value() == '' and await page.locator('#pair-invite').is_hidden())
                    check('Unavailable pairing has an actionable status', 'HomeServer sharing is unavailable' in await page.locator('#pair-host-status').inner_text())

            address = LOCAL + '/game.php'
            markup = html(GAME_BODY, GAME_BOOT + GAME_EXTRA, ['public/game.js'], address)
            async with page_fixture(markup, address) as page:
                await page.wait_for_function('document.getElementById("viewer-link").value.length>0')
                check('Game TV invitation uses LAN but Open Display retains local host origin',
                      await page.locator('#viewer-link').input_value() == LAN + '/display.php?session=' + ID + '&token=' + KEY
                      and (await page.locator('#open-viewer').get_attribute('href')).startswith(LOCAL + '/display.php?'))
                await page.locator('#host-tab-display').click()
                await page.locator('#copy-viewer').click()
                check('Game copy emits the LAN viewer capability', await page.evaluate('__copied.at(-1)') == LAN + '/display.php?session=' + ID + '&token=' + KEY)
                await page.evaluate('''async()=>{__network.ok=false;await LuckyInstallationUrls.refresh();}''')
                check('Failed renewal clears game sharing while the live game stays active',
                      await page.locator('#viewer-link').input_value() == '' and await page.locator('#copy-viewer').is_disabled()
                      and 'ACTIVE GAME' in await page.locator('#game-state').inner_text()
                      and (await page.locator('#open-viewer').get_attribute('href')).startswith(LOCAL))

            display_body = body_of('display')
            address = LOCAL + '/display.php?session=' + ID + '&token=' + KEY
            markup = html(display_body, GAME_BOOT + GAME_EXTRA, ['public/qr.js', 'public/display.js'], address,
                          dataset='data-page="display" data-session="' + ID + '" data-token="' + KEY + '"')
            async with page_fixture(markup, address) as page:
                check('TV creates its phone-controller QR using a fresh installation lease', await page.locator('#display-pair-qr svg rect').count() > 100)
                await page.evaluate('''async()=>{__network.leaseSeconds=1;await LuckyInstallationUrls.refresh();}''')
                await page.wait_for_function('document.getElementById("display-pair-qr").textContent.includes("sharing is unavailable")', timeout=2500)
                check('Automatic lease expiry removes the TV QR without stopping game state polling',
                      await page.locator('#display-pair-qr svg').count() == 0 and 'LIVE' in await page.locator('#display-state').inner_text())

            package_body = re.search(r'<section class="surface"[\s\S]*?<script src=', (ROOT / 'package-game.php').read_text()).group(0).rsplit('<script src=', 1)[0]
            package_body = re.sub(r'<\?php[\s\S]*?\?>|<\?=[\s\S]*?\?>', '', package_body)
            address = LOCAL + '/package-game.php?game=sample-game'
            markup = html(package_body, GAME_BOOT + GAME_EXTRA + script('__liveSession.session.packageVersion="1.0.0";'),
                          ['public/qr.js', 'public/package-game.js'], address)
            async with page_fixture(markup, address) as page:
                await page.wait_for_function('document.getElementById("pkg-phone-link").value.length>0')
                check('Custom-game phone invitation uses LAN while sandboxed host iframe stays relative',
                      (await page.locator('#pkg-phone-link').input_value()).startswith(LAN + '/package-controller.php?')
                      and (await page.locator('#package-frame').get_attribute('src')).startswith('package-view.php?'))
                await page.evaluate('''async()=>{__network.ok=false;await LuckyInstallationUrls.refresh();}''')
                check('Custom-game sharing fails closed without unloading the local game',
                      await page.locator('#pkg-phone-invite').is_hidden() and await page.locator('#package-frame').is_visible())

            director = re.search(r'<main class="director-studio-shell">[\s\S]*?</main>', (ROOT / 'director.php').read_text()).group(0)
            address = LOCAL + '/director.php'
            markup = html(director, PUBLISH_BOOT, ['public/director-publish.js', 'public/director-game.js'], address)
            async with page_fixture(markup, address) as page:
                await page.evaluate('''window.dispatchEvent(new CustomEvent('lucky:director-studio-open',{detail:{recordingSession:'a'.repeat(32)}}))''')
                await page.wait_for_function('document.getElementById("director-show-url").value.length>0')
                check('Permanent show copy URL is LAN and Open remains local',
                      await page.locator('#director-show-url').input_value() == LAN + '/live-view.php?show=' + SHOW
                      and await page.locator('#director-show-open').get_attribute('href') == LOCAL + '/live-view.php?show=' + SHOW)
                await page.wait_for_function('document.getElementById("director-game-broadcast-url").value.length>0')
                check('Game Director exposes the standby permalink but keeps its Open action local',
                      await page.locator('#director-game-broadcast-url').input_value() == LAN + '/live-view.php?show=' + SHOW
                      and await page.locator('#director-game-broadcast-open').get_attribute('href') == LOCAL + '/live-view.php?show=' + SHOW)
                await page.locator('#director-publish-consent').check()
                await page.locator('#director-publish').click()
                await page.wait_for_function('LuckyDirectorPublisher.state().onAir')
                check('Consented private Program uses a LAN invitation without changing local signaling',
                      await page.locator('#director-live-url').input_value() == LAN + '/live-view.php?id=' + ID + '&key=' + KEY
                      and await page.evaluate('__requests.filter(r=>r.action==="publish").every(r=>r.url.startsWith("http://localhost:8833/api/"))'))
                await page.evaluate('''async()=>{__network.ok=false;await LuckyInstallationUrls.refresh();}''')
                check('Network lease loss clears private and permanent share controls, not the owned broadcast/media',
                      await page.locator('#director-live-url').input_value() == '' and await page.locator('#director-show-url').input_value() == ''
                      and await page.evaluate('LuckyDirectorPublisher.state().onAir&&__outputs[0].getVideoTracks()[0].readyState==="live"'))
                check('Game Director still reports the actual ON AIR state when a sharing lease expires',
                      'ON AIR' in await page.locator('#director-game-on-air').inner_text()
                      and await page.locator('#director-game-broadcast-url').input_value() == ''
                      and await page.locator('#director-game-broadcast-copy').is_disabled()
                      and 'Program remains ON AIR' in await page.locator('#director-game-broadcast-help').inner_text())
                await page.evaluate('''async()=>{__network.ok=true;__network.lanOrigin='https://10.2.3.4:8843';await LuckyInstallationUrls.refresh();}''')
                check('Validated network replacement rebuilds the same private publication link',
                      await page.locator('#director-live-url').input_value() == 'https://10.2.3.4:8843/live-view.php?id=' + ID + '&key=' + KEY)
                await page.locator('#director-unpublish').click()
                await page.wait_for_function('!LuckyDirectorPublisher.state().onAir')
                await page.locator('#director-broadcast-visibility').select_option('public')
                await page.locator('#director-publish-public-ack').check()
                await page.locator('#director-publish').click()
                await page.wait_for_function('LuckyDirectorPublisher.state().onAir')
                check('Explicit public publication exposes only its stable show address, never a private key',
                      await page.locator('#director-live-url').input_value() == 'https://10.2.3.4:8843/live-view.php?show=' + SHOW)

            address = LOCAL + '/game.php'
            markup = html('<button id="start-game">Game stays loaded</button>', VIEW_BOOT, ['public/display-program.js'], address)
            async with page_fixture(markup, address) as page:
                await page.locator('#lucky-program-connect').click()
                await page.locator('#lucky-program-invitation').fill('https://evil.test/live-view.php?id=' + ID + '&key=' + KEY)
                await page.locator('#lucky-program-join').click()
                check('Pasted foreign invitation never sends a bearer or creates a peer', await page.evaluate('__peers.length===0&&!__requests.some(r=>r.action==="watch")'))
                await page.locator('#lucky-program-invitation').fill(LAN + '/live-view.php?id=' + ID + '&key=' + KEY)
                await page.locator('#lucky-program-join').click()
                await page.wait_for_function('__peers.length===1&&__peers[0].remoteDescription')
                check('Pasted trusted LAN invitation resolves locally; private bearer remains POST-only', await page.evaluate('''__requests.filter(r=>r.action).every(r=>
                  r.url.startsWith('http://localhost:8833/api/')&&r.method==='POST'&&!r.url.includes('b'.repeat(48))&&JSON.parse(r.body).key==='b'.repeat(48))'''))
                check('Connecting Program does not navigate the host or retain the pasted secret', page.url == address
                      and await page.locator('#lucky-program-invitation').input_value() == '')

            address = LAN + '/admin.php'
            markup = html(ADMIN_BODY, ADMIN_BOOT + ADMIN_EXTRA, ['public/admin.js'], address)
            async with page_fixture(markup, address) as page:
                await page.locator('.prize-catalog-card').first.wait_for()
                check('Existing localhost prize artwork renders from this installation LAN origin',
                      await page.locator('.prize-catalog-art img').get_attribute('src') == LAN + '/api/index.php?action=prize_image&id=' + ID)
                await page.locator('.prize-catalog-edit').click()
                await page.locator('#prize-edit-photo-upload').set_input_files({'name': 'test.png', 'mimeType': 'image/png', 'buffer': PNG})
                await page.wait_for_function('document.getElementById("prize-edit-status").textContent.includes("Photo uploaded")')
                await page.locator('#prize-edit-video-upload').set_input_files({'name': 'test.webm', 'mimeType': 'video/webm', 'buffer': b'fixture-only'})
                await page.wait_for_function('document.getElementById("prize-edit-status").textContent.includes("Video uploaded")')
                await page.locator('#prize-edit-save').click()
                await page.wait_for_function('!document.getElementById("prize-editor-modal").open')
                check('New HomeServer photo/video persistence contains no localhost or current network IP', await page.evaluate('''__package.config.pools.tier1.prizes[0].image===
                  '/api/index.php?action=prize_image&id='+'a'.repeat(32)&&__package.config.pools.tier1.prizes[0].videoUrl===
                  '/api/index.php?action=prize_video&id='+'a'.repeat(32)'''))
                await page.locator('#open-package-settings').click()
                await page.locator('#package-image-upload').set_input_files({'name': 'cover.png', 'mimeType': 'image/png', 'buffer': PNG})
                await page.wait_for_function('document.getElementById("package-image").value.startsWith("/api/")')
                check('Package cover uploads use the same portable HomeServer path contract',
                      await page.locator('#package-image').input_value() == '/api/index.php?action=prize_image&id=' + ID)

            address = LAN + '/controller.php'
            markup = html(body_of('controller'), PHONE_BOOT, ['public/phone-controller.js'], address)
            async with page_fixture(markup, address) as page:
                await page.locator('#phone-prize-offer').wait_for(state='visible')
                check('Paired phone resolves historical localhost prize artwork to its current LAN server',
                      await page.locator('#phone-offer-photo').get_attribute('src') == LAN + '/api/index.php?action=prize_image&id=' + ID)
                check('Historical localhost uploaded video remains an available desktop-play action', await page.locator('#phone-offer-play-video').is_visible())
                await page.locator('#phone-offer-play-video').click()
                await page.wait_for_function('__requests.some(r=>r.action==="command")')
                check('Phone action bearer stays on current LAN API with existing credentials-omit policy', await page.evaluate('''__requests.some(r=>r.action==='command'&&
                  r.url.startsWith('https://192.168.1.42:8843/api/')&&r.credentials==='omit'&&r.headers['X-Controller-Token']==='c'.repeat(64))'''))

            address = 'https://education.example.test/~class/lucky/game.php'
            markup = html(GAME_BODY, GAME_BOOT + GAME_EXTRA, ['public/game.js'], address, mode='cloud')
            async with page_fixture(markup, address) as page:
                await page.wait_for_function('document.getElementById("viewer-link").value.length>0')
                check('Hosted education subdomain/subdirectory retains exact share origin and path',
                      await page.locator('#viewer-link').input_value() == 'https://education.example.test/~class/lucky/display.php?session=' + ID + '&token=' + KEY)
                check('Hosted pages do not request the HomeServer network lease', await page.evaluate('!__requests.some(r=>r.url.includes("installation-urls.php"))'))
        finally:
            await browser.close()
    print(f'V5Q32G7A INSTALLATION URL BROWSER: {len(checks)}/{len(checks)}', flush=True)


if __name__ == '__main__':
    asyncio.run(main())
