"""V5Q32B: authenticated Director camera cuts and safe show cards across browsers."""
import asyncio
import json
import re
from copy import deepcopy
from pathlib import Path
from urllib.parse import parse_qs, urlparse

from playwright.async_api import async_playwright

ROOT = Path(__file__).resolve().parents[1]
GAME, RECORDING, CLAIM = ('a' * 32, 'b' * 32, 'd' * 32)


def asset(name):
    return (ROOT / name).read_text().replace('</script', '<\\/script')


async def main():
    markup = re.search(r'<main class="director-studio-shell">[\s\S]*?</main>', asset('director.php'))
    assert markup, 'Actual Director markup is required'
    css = '\n'.join(asset(path) for path in (
        'public/style.css', 'public/platform.css', 'public/director-workspace.css', 'public/director-game.css'))
    scripts = asset('public/director-workspace.js') + '\n' + asset('public/director-game.js')
    director_mock = f"""
      window.__takes=[];window.__previews=[];window.__published=0;
      let preview='',program='';
      window.Lucky={{
        api:async(action,opts={{}})=>{{
          const post=opts.body!==undefined;
          const res=await fetch('/api/director?action='+action,{{method:post?'POST':'GET',
            headers:post?{{'Content-Type':'application/json'}}:{{}},
            body:post?JSON.stringify(opts.body):undefined}});
          const data=await res.json();if(!res.ok||!data.ok)throw Error(data.error||'Failed');return data;
        }},
        idempotency:()=>Math.random().toString(36).slice(2)+Date.now(),
        $:id=>document.getElementById(id)
      }};
      window.LuckyCameraDirector={{
        sources:()=>[{{id:'camera:one',label:'Desktop Camera'}},{{id:'phone:two',label:'Ada phone'}}],
        select:id=>{{if(!['camera:one','phone:two'].includes(id))return false;
          preview=id;window.__previews.push(id);return true;}},
        take:kind=>{{if(!preview||!['cut','fade','wipe','dip'].includes(kind))return false;
          program=preview;window.__takes.push([program,kind]);return true;}},
        state:()=>({{preview,program}})
      }};
      window.LuckyDirectorStudio={{state:()=>({{recordingSession:'{RECORDING}'}}),open:()=>Promise.resolve()}};
      document.getElementById('director-studio-session').add(new Option('Game take','{RECORDING}'));
      document.getElementById('director-studio-take-start').disabled=false;
    """
    director_html = ('<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1">'
                     f'<style>{css}</style></head><body class="director-studio-page">'
                     '<div class="shell"><header class="header">Lucky Games</header>' + markup.group(0) + '</div>'
                     f'<script>{director_mock}</script><script>{scripts}</script></body></html>')
    audience_html = ('<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1">'
                     f'<style>{asset("public/style.css")}</style></head><body data-page="display">'
                     '<div id="display-state">Audience view</div>'
                     f'<script>{asset("public/client.js")}</script>'
                     '<script>async function tick(){const r=await fetch("/api/stage");'
                     'window.Lucky.showPresentation((await r.json()).view)}'
                     'tick();setInterval(tick,350);</script></body></html>')
    state = {'game': {'id': GAME, 'version': 1, 'status': 'active',
             'players': [{'id': 'player-1', 'name': 'Ada'}, {'id': 'player-2', 'name': 'Ben'}],
             'hosts': [{'id': 'host-1', 'name': 'MC'}],
             'currentPlayer': {'id': 'player-1', 'name': 'Ada', 'costume': {'name': 'Space',
                 'items': [{'id': 'helmet', 'name': 'Space Helmet'}]}, 'prizeCostumeItems': []},
             'party': 'Party Night', 'theme': 'Space',
             'claims': [{'id': CLAIM, 'name': 'Golden Ticket', 'playerName': 'Ada'}],
             'latestPrize': {'id': CLAIM, 'name': 'Golden Ticket', 'playerName': 'Ada'}},
             'operators': [], 'assignments': {}, 'moments': [],
             'recording': {'id': RECORDING, 'title': 'Game Take'}, 'takes': [],
             'presentation': None, 'serverTimeMs': 0}
    requests = []

    async def serve(route):
        parsed = urlparse(route.request.url)
        if parsed.path == '/api/stage':
            await route.fulfill(json={'view': {'status': state['game']['status'],
                                               'showPresentation': deepcopy(state['presentation'])}})
            return
        action = parse_qs(parsed.query).get('action', [''])[0]
        if action == 'director_context':
            await route.fulfill(json={'ok': True, 'context': deepcopy(state)})
            return
        if action == 'director_action':
            data = json.loads(route.request.post_data or '{}')
            requests.append(data)
            if data['expectedVersion'] != state['game']['version']:
                await route.fulfill(status=409, json={'ok': False, 'error': 'Version mismatch'})
                return
            state['game']['version'] += 1
            state['moments'].append({'id': str(len(requests)), 'type': data['action'],
                                     'at': '2026-11-02T12:00:00Z',
                                     'label': data['action'], 'offsetMs': 1000})
            if data['action'] == 'cue':
                state['presentation'] = {'id': str(len(requests)), 'cue': data['cue'],
                                         'headline': 'Costume spotlight' if data['cue'] == 'costume_focus' else 'Celebration',
                                         'detail': '<img src=x onerror=alert(1)>',
                                         'expiresAtMs': 9999999999999}
            elif data['action'] == 'clear_presentation':
                state['presentation'] = None
            await route.fulfill(json={'ok': True, 'changed': True, 'context': deepcopy(state)})
            return
        await route.fulfill(status=404, json={'ok': False})

    checks = []
    def check(name, result):
        checks.append((name, bool(result)))

    async with async_playwright() as playwright:
        browser = await playwright.chromium.launch(executable_path='/usr/bin/chromium',
                                                    headless=True, args=['--no-sandbox'])
        control = await browser.new_context(viewport={'width': 1380, 'height': 900})
        audience = await browser.new_context(viewport={'width': 390, 'height': 844})
        errors = []
        for context in (control, audience):
            await context.route('**/director.php?*', lambda route: route.fulfill(
                status=200, content_type='text/html', body=director_html))
            await context.route('**/display.php?*', lambda route: route.fulfill(
                status=200, content_type='text/html', body=audience_html))
            await context.route('**/api/director*', serve)
            await context.route('**/api/stage', serve)
        director, tv = await control.new_page(), await audience.new_page()
        director.on('pageerror', lambda error: errors.append('Director: ' + str(error)))
        tv.on('pageerror', lambda error: errors.append('TV: ' + str(error)))
        await director.goto('https://lucky.test/director.php?game=' + GAME)
        await tv.goto('https://lucky.test/display.php?session=' + GAME)
        await director.wait_for_function("document.querySelector('#director-game-status').textContent.includes('ACTIVE')")
        check('Two separate browser contexts load Director and audience screens',
              await director.locator('#director-game-panel').count() == 1 and
              await tv.locator('#display-state').count() == 1)
        check('No recording or broadcast begins on load',
              await director.evaluate('window.__takes.length===0&&window.__published===0'))
        await director.locator('#director-game-camera-source').select_option('phone:two')
        await director.locator('#director-game-preview').click()
        check('Preview cues the camera privately without a Program switch',
              await director.evaluate('window.__previews.at(-1)==="phone:two"&&window.__takes.length===0'))
        await director.locator('#director-game-transition').select_option('fade')
        await director.locator('#director-game-take').click()
        await director.wait_for_function('window.__takes.length===1')
        check('Take changes the real local Program compositor with selected transition',
              await director.evaluate('JSON.stringify(window.__takes[0])===JSON.stringify(["phone:two","fade"])'))
        await director.wait_for_function("document.querySelector('#director-game-feedback').textContent.includes('synchronized')")
        check('Server logs the camera cut for cross-browser timeline editing',
              any(r['action'] == 'program_cut' and r['transition'] == 'fade' and
                  r['sourceLabel'] == 'Ada phone' for r in requests))
        await director.locator('#director-game-costume-item').select_option('helmet')
        await director.get_by_role('button', name='Costume close-up').click()
        await tv.wait_for_selector('#lucky-show-card:not([hidden])', timeout=10000)
        check('Audience display receives Director costume cue without loading video credentials',
              await tv.locator('.lucky-show-headline').inner_text() == 'Costume spotlight' and
              await tv.locator('video').count() == 0 and
              await tv.locator('[data-broadcast-token]').count() == 0)
        check('Audience untrusted cue detail is rendered as text, not executable HTML',
              await tv.locator('.lucky-show-detail').inner_text() == '<img src=x onerror=alert(1)>'
              and await tv.locator('.lucky-show-detail img').count() == 0)
        check('Costume cue references the specific selected costume layer',
              any(r['action'] == 'cue' and r['cue'] == 'costume_focus' and r['itemId'] == 'helmet'
                  for r in requests))
        await director.locator('#director-game-clear-presentation').click()
        await tv.wait_for_function("document.querySelector('#lucky-show-card')?.hidden===true")
        check('Clear on Director removes the card on the independent audience screen',
              await tv.locator('#lucky-show-card').is_hidden())
        check('Mobile audience display has no horizontal overflow',
              await tv.evaluate('document.documentElement.scrollWidth<=innerWidth+1'))
        await tv.evaluate('window.Lucky.showPresentation({status:"active",showPresentation:{headline:"Old cue",detail:"Expired",expiresAtMs:Date.now()-1}})')
        check('Expired cues cannot reappear when reconnecting or polling',
              await tv.locator('#lucky-show-card').is_hidden())
        check('Both browser screens remain free of JavaScript exceptions', not errors)
        await browser.close()

    for name, ok in checks:
        print(('PASS' if ok else 'FAIL'), name, flush=True)
    if errors:
        print('JavaScript errors:', errors)
    print('V5Q32B two-screen Program stage:', sum(ok for _, ok in checks), '/', len(checks))
    if not all(ok for _, ok in checks):
        raise SystemExit(1)


if __name__ == '__main__':
    asyncio.run(main())
