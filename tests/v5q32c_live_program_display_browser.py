"""Opt-in WebRTC Program reception on both TV and host game browsers."""
import asyncio
import json
from pathlib import Path
from urllib.parse import parse_qs, urlparse

from playwright.async_api import async_playwright

ROOT = Path(__file__).resolve().parents[1]
BROADCAST, KEY = ('b' * 32, 'c' * 48)


def asset(path):
    return (ROOT / path).read_text().replace('</script', '<\\/script')


async def main():
    css = asset('public/style.css') + '\n' + asset('public/display-program.css')
    client = asset('public/client.js')
    viewer = asset('public/display-program.js')
    mock = '''
     window.__peers=[];
     window.RTCPeerConnection=class {
       constructor(){this.iceGatheringState='complete';this.connectionState='new';this.localDescription=null;
         window.__peers.push(this)}
       addTransceiver(){}
       createOffer(){return Promise.resolve({type:'offer',sdp:'v=0\\r\\nlocal-offer'})}
       setLocalDescription(d){this.localDescription=d;return Promise.resolve()}
       setRemoteDescription(d){this.remoteDescription=d;this.connectionState='connected';
         this.onconnectionstatechange?.();return Promise.resolve()}
       close(){this.connectionState='closed'}
     };
    '''
    def html(host=False):
        body = '<button id="start-game" type="button">Start game</button>' if host else '<div id="display-state">TV display</div>'
        dataset = '' if host else 'data-page="display"'
        return ('<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1">'
                f'<style>{css}</style></head><body {dataset}>{body}'
                f'<script>{mock}</script><script>{client}</script></body></html>')
    requests = []
    allowed = True

    async def route_assets(route):
        path = urlparse(route.request.url).path
        if path.endswith('display-program.js'):
            await route.fulfill(status=200, content_type='text/javascript', body=viewer)
        elif path.endswith('display-program.css'):
            await route.fulfill(status=200, content_type='text/css', body=css)

    async def route_api(route):
        nonlocal allowed
        url = urlparse(route.request.url)
        action = parse_qs(url.query).get('action', [''])[0]
        data = json.loads(route.request.post_data or '{}') if route.request.method == 'POST' else {}
        requests.append({'action': action, 'method': route.request.method, 'url': route.request.url, 'body': data})
        if not allowed and action == 'watch':
            await route.fulfill(status=403, json={'ok': False, 'error': 'Broadcast ended'})
        elif action == 'watch':
            await route.fulfill(json={'ok': True, 'live': True, 'visibility': 'private'})
        elif action == 'offer':
            await route.fulfill(json={'ok': True, 'queued': True})
        elif action == 'poll':
            await route.fulfill(json={'ok': True, 'answer': 'v=0\r\nremote-answer'})
        else:
            await route.fulfill(status=404, json={'ok': False})

    checks = []
    def check(label, passed):
        checks.append((label, bool(passed)))

    async with async_playwright() as playwright:
        browser = await playwright.chromium.launch(executable_path='/usr/bin/chromium',
                                                    headless=True, args=['--no-sandbox'])
        tv_context = await browser.new_context(viewport={'width': 390, 'height': 844})
        host_context = await browser.new_context(viewport={'width': 1360, 'height': 870})
        errors = []
        for context in (tv_context, host_context):
            await context.route('**/public/display-program.js*', route_assets)
            await context.route('**/public/display-program.css*', route_assets)
            await context.route('**/api/director-live.php*', route_api)
            await context.route('**/display.php', lambda route: route.fulfill(
                status=200, content_type='text/html', body=html(False)))
            await context.route('**/game.php', lambda route: route.fulfill(
                status=200, content_type='text/html', body=html(True)))
        tv, host = await tv_context.new_page(), await host_context.new_page()
        tv.on('pageerror', lambda error: errors.append('TV: ' + str(error)))
        host.on('pageerror', lambda error: errors.append('Host: ' + str(error)))
        await tv.goto('https://lucky.test/display.php')
        await host.goto('https://lucky.test/game.php')
        await tv.wait_for_selector('#lucky-program-connect')
        await host.wait_for_selector('#lucky-program-connect')
        check('Viewer entry is available on both host game and TV display',
              await tv.locator('#lucky-program-connect').count() == 1 and
              await host.locator('#lucky-program-connect').count() == 1)
        check('No camera, microphone, WebRTC or broadcast request starts automatically',
              not requests and await tv.evaluate('window.__peers.length===0') and
              await host.evaluate('window.__peers.length===0'))
        await tv.locator('#lucky-program-connect').click()
        await tv.locator('#lucky-program-invitation').fill(
            'https://evil.invalid/live-view.php?id=' + BROADCAST + '&key=' + KEY)
        await tv.locator('#lucky-program-join').click()
        check('Cross-origin invitations cannot activate a feed',
              'this Lucky site' in await tv.locator('#lucky-program-form-status').inner_text() and not requests)
        await tv.locator('#lucky-program-invitation').fill(
            'https://lucky.test/live-view.php?id=' + BROADCAST + '&key=' + KEY)
        await tv.locator('#lucky-program-join').click()
        await tv.wait_for_function('window.__peers.length===1')
        await tv.wait_for_function("document.querySelector('#lucky-program-connect').textContent.includes('connected')")
        check('Authorized private invite connects the receiver without a new camera permission',
              await tv.evaluate('window.__peers.length===1&&window.__peers[0].remoteDescription.type==="answer"'))
        check('Private bearer remains out of request URLs and is sent in a POST body',
              len(requests) >= 3 and all(x['method'] == 'POST' and KEY not in x['url'] for x in requests)
              and all(x['body'].get('key') == KEY for x in requests))
        check('The pasted invitation is cleared, not stored in the DOM or URL',
              await tv.locator('#lucky-program-invitation').input_value() == ''
              and KEY not in await tv.locator('body').inner_html()
              and KEY not in tv.url)
        check('Read-only Program video is displayed without audio autoplay',
              await tv.locator('#lucky-program-surface').is_visible()
              and await tv.locator('#lucky-program-video').evaluate('(video)=>video.muted'))
        await tv.locator('#lucky-program-size').click()
        check('Fullscreen presentation is available while wheel stays loaded',
              await tv.locator('#lucky-program-surface').evaluate('(el)=>el.classList.contains("is-maximized")')
              and await tv.locator('#display-state').count() == 1)
        await tv.locator('#lucky-program-stop').click()
        check('Disconnect closes the peer and blanks the video immediately',
              await tv.locator('#lucky-program-surface').is_hidden()
              and await tv.evaluate('window.__peers[0].connectionState==="closed"'))
        check('Invitation is not retained in browser storage',
              await tv.evaluate('!Object.values(localStorage).some(x=>x.includes("' + KEY + '")) && '
                                '!Object.values(sessionStorage).some(x=>x.includes("' + KEY + '"))'))
        check('Both layouts fit their respective viewports',
              await tv.evaluate('document.documentElement.scrollWidth<=innerWidth+1') and
              await host.evaluate('document.documentElement.scrollWidth<=innerWidth+1'))
        check('No browser JavaScript exceptions', not errors)
        await browser.close()

    for label, passed in checks:
        print(('PASS' if passed else 'FAIL'), label, flush=True)
    if errors:
        print('Errors:', errors)
    print('V5Q32C authorized Program viewer:', sum(ok for _, ok in checks), '/', len(checks))
    if not all(ok for _, ok in checks):
        raise SystemExit(1)


if __name__ == '__main__':
    asyncio.run(main())
