"""Permanent show URL across Director publish cycles, including private isolation and OFF AIR."""
import asyncio
import re
from pathlib import Path
from playwright.async_api import async_playwright

ROOT = Path(__file__).resolve().parents[1]
RECORDING = 'a' * 32
SHOW = 'b' * 32
FIRST = 'c' * 32
SECOND = 'd' * 32
THIRD = 'e' * 32
TOKEN = 'f' * 48
STABLE = f'https://lucky.test/live-view.php?show={SHOW}'


def asset(name):
    return (ROOT / name).read_text().replace('</script', '<\\/script')


async def main():
    markup = re.search(r'<main class="director-studio-shell">[\s\S]*?</main>', asset('director.php'))
    assert markup
    publisher = asset('public/director-publish.js')
    viewer = asset('public/director-view.js')
    errors = []
    checks = []

    def check(label, value):
        checks.append((label, bool(value)))

    host_mock = '''
    window.__published=[]; window.__closed=[];
    window.Lucky={getMe:async()=>({csrf:'owner-csrf'})};
    window.LuckyCameraDirector={programStream:()=>({
     getVideoTracks:()=>[{}],getTracks:()=>[{stop(){}}]
    })};
    window.RTCPeerConnection=class {constructor(){this.iceGatheringState='complete'}
     addTrack(){} close(){}};
    window.fetch=async(url,opts={})=>{
     const action=new URL(url,document.baseURI).searchParams.get('action');
     const payload=opts.body?JSON.parse(opts.body):{};
     let result={ok:true};
     if(action==='show_link')result.showId='b'.repeat(32);
     if(action==='publish'){
      const index=window.__published.length;
      result={id:(index===0?'c':'d').repeat(32),key:'f'.repeat(48),
       visibility:payload.visibility,showId:'b'.repeat(32)};
      window.__published.push(payload);
     }
     if(action==='host_status')result={live:true,visibility:window.__published.at(-1)?.visibility,
      viewerCount:2,maxViewers:8};
     if(action==='host_list')result.offers=[];
     if(action==='stop')window.__closed.push(payload.id);
     return {ok:true,status:200,json:async()=>({ok:true,...result})};
    };
    '''
    host_html = (f'<html><head><base href="https://lucky.test/"></head><body>'
                 + markup.group(0) + f'<script>{host_mock}</script><script>{publisher}</script>'
                 + '</body></html>')
    watch_mock = '''
     window.__live='';window.__requests=[];
     window.fetch=async(url,opts={})=>{
      const u=new URL(url,document.baseURI), action=u.searchParams.get('action');
      window.__requests.push({action,id:u.searchParams.get('id')});
      let result={ok:true};
      if(action==='resolve_show')result={ok:true,live:!!window.__live,
       broadcastId:window.__live||null,maxViewers:8};
      if(action==='poll')result={ok:true,answer:'v=0\\r\\n'};
      return {ok:true,status:200,json:async()=>result};
     };
     window.RTCPeerConnection=class{
      constructor(){this.iceGatheringState='complete';this.localDescription=null;}
      addTransceiver(){} close(){} createOffer(){return {type:'offer',sdp:'v=0\\r\\n'}}
      async setLocalDescription(offer){this.localDescription=offer;}
      async setRemoteDescription(){}
     };
    '''
    watcher_html = (f'<html><head><base href="https://lucky.test/"></head>'
                    f'<body data-broadcast-id="" data-broadcast-key="" data-show-id="{SHOW}">'
                    '<video id="director-view-video" muted></video><button id="director-view-sound"></button>'
                    '<p id="director-view-status">OFF AIR</p>'
                    f'<script>{watch_mock}</script><script>{viewer}</script></body></html>')

    async with async_playwright() as play:
        browser = await play.chromium.launch(executable_path='/usr/bin/chromium', headless=True,
                                              args=['--no-sandbox'])
        host = await browser.new_page(viewport={'width': 1440, 'height': 900})
        host.on('pageerror', lambda e: errors.append('Host: ' + str(e)))
        await host.set_content(host_html, wait_until='domcontentloaded')
        await host.evaluate('''id=>window.dispatchEvent(new CustomEvent('lucky:director-studio-open',
          {detail:{recordingSession:id}}))''', RECORDING)
        await host.wait_for_function('document.querySelector("#director-show-url").value.includes("?show=")')
        check('Authenticated recording creates a permanent public show URL',
              await host.locator('#director-show-url').input_value() == STABLE)
        check('Show address is openable before going live',
              await host.locator('#director-show-open').get_attribute('href') == STABLE)
        check('Copy remains enabled when the show is OFF AIR',
              await host.locator('#director-show-copy').is_enabled())
        await host.locator('#director-publish-consent').check()
        await host.locator('#director-publish').click()
        await host.wait_for_function('window.LuckyDirectorPublisher.state().onAir')
        private_url = await host.locator('#director-live-url').input_value()
        check('Private invitation has a secret and is distinct from public permanent link',
              f'id={FIRST}' in private_url and f'key={TOKEN}' in private_url
              and await host.locator('#director-show-url').input_value() == STABLE)
        await host.locator('#director-unpublish').click()
        await host.wait_for_function('!window.LuckyDirectorPublisher.state().onAir')
        check('Ending broadcast retains permanent public page',
              await host.locator('#director-show-url').input_value() == STABLE
              and await host.locator('#director-live-link').is_hidden())
        await host.locator('#director-broadcast-visibility').select_option('public')
        await host.locator('#director-publish-public-ack').check()
        await host.locator('#director-publish').click()
        await host.wait_for_function('window.LuckyDirectorPublisher.state().onAir')
        check('Explicitly public broadcast uses stable show URL, never token',
              await host.locator('#director-live-url').input_value() == STABLE
              and 'key=' not in await host.locator('#director-live-url').input_value())
        await host.wait_for_function('document.querySelector("#director-live-viewers").textContent.includes("2 / 8")')
        check('Director telemetry shows connected signaling viewer count',
              await host.locator('#director-live-health').inner_text() == 'Healthy')
        check('Two separate publications were consented and private by default',
              await host.evaluate('''__published.length===2&&__published[0].visibility==='private'&&
                __published[1].visibility==='public'&&__published[1].public_ack===true'''))

        watcher = await browser.new_page()
        watcher.on('pageerror', lambda e: errors.append('Viewer: ' + str(e)))
        await watcher.set_content(watcher_html, wait_until='domcontentloaded')
        check('Permanent viewer begins in OFF AIR mode',
              'OFF AIR' in await watcher.locator('#director-view-status').inner_text())
        await watcher.evaluate("window.__live='d'.repeat(32)")
        await watcher.wait_for_function('''window.__requests.some(x=>x.action==='watch'&&x.id==='d'.repeat(32))''',
                                        timeout=11000)
        check('Same viewer URL resolves the first public Program',
              await watcher.evaluate('window.__requests.some(x=>x.action===\'watch\'&&x.id===\'d\'.repeat(32))'))
        await watcher.evaluate("window.__live=''")
        await watcher.wait_for_function('document.querySelector("#director-view-status").textContent.includes("OFF AIR")',
                                        timeout=11000)
        check('Program revoked or private transitions viewer to OFF AIR',
              'OFF AIR' in await watcher.locator('#director-view-status').inner_text())
        await watcher.evaluate("window.__live='e'.repeat(32)")
        await watcher.wait_for_function('''window.__requests.some(x=>x.action==='watch'&&x.id==='e'.repeat(32))''',
                                        timeout=11000)
        check('Same bookmarked URL automatically reconnects to a new broadcast ID',
              await watcher.evaluate('''window.__requests.some(x=>x.action==='watch'&&x.id==='e'.repeat(32))'''))
        check('Permanent viewer never sends invitation tokens',
              await watcher.evaluate('''window.__requests.filter(x=>x.action==='watch').every(x=>x.id!=='c'.repeat(32))'''))
        check('Both host and public viewer have no script errors', not errors)
        await browser.close()

    for label, ok in checks:
        print(('PASS ' if ok else 'FAIL ') + label, flush=True)
    if errors:
        print('PAGE ERRORS', errors, flush=True)
    assert all(ok for _, ok in checks), 'V5Q32E1 browser test failed'
    print(f'V5Q32E1 PERMANENT SHOW BROWSER: {len(checks)}/{len(checks)}')


if __name__ == '__main__':
    asyncio.run(main())
