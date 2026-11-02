"""V5Q32A: two independent browsers share authoritative live-game state."""
import asyncio
import json
import re
from copy import deepcopy
from pathlib import Path
from urllib.parse import parse_qs, urlparse

from playwright.async_api import async_playwright

ROOT = Path(__file__).resolve().parents[1]
GAME, RECORDING, PAIR, CLAIM = ('a' * 32, 'b' * 32, 'c' * 32, 'd' * 32)


def asset(name):
    return (ROOT / name).read_text().replace('</script', '<\\/script')


async def main():
    markup = re.search(r'<main class="director-studio-shell">[\s\S]*?</main>', asset('director.php'))
    assert markup, 'Director production markup is required'
    css = '\n'.join(asset(file) for file in (
        'public/style.css', 'public/platform.css', 'public/director-workspace.css', 'public/director-game.css'))
    scripts = asset('public/director-workspace.js') + '\n' + asset('public/director-game.js')
    mock = f"""
    window.__recordStarts=0;
    window.Lucky={{
      api:async(action,opts={{}})=>{{
        const post=opts.body!==undefined;
        const url='/api/director?action='+encodeURIComponent(action)+(opts.query?.session?'&session='+opts.query.session:'');
        const response=await fetch(url,{{method:post?'POST':'GET',headers:post?{{'Content-Type':'application/json'}}:{{}},
          body:post?JSON.stringify(opts.body):undefined}});
        const result=await response.json();if(!response.ok||!result.ok)throw Error(result.error||'HTTP error');
        return result;
      }},
      idempotency:()=>Math.random().toString(36).slice(2)+Date.now(),
      $:id=>document.getElementById(id)
    }};
    window.LuckyCameraDirector={{state:()=>({{preview:'',program:''}})}};
    window.LuckyDirectorStudio={{state:()=>({{recordingSession:'{RECORDING}'}}),open:()=>Promise.resolve()}};
    document.getElementById('director-studio-session').add(new Option('Linked game take','{RECORDING}'));
    document.getElementById('director-studio-take-start').disabled=false;
    document.getElementById('director-studio-take-start').addEventListener('click',()=>window.__recordStarts++);
    """
    document = ('<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1">'
                f'<style>{css}</style></head><body class="director-studio-page">'
                '<div class="shell"><header class="header"><a class="brand">Lucky Games</a></header>'
                + markup.group(0) + '</div>' + f'<script>{mock}</script><script>{scripts}</script></body></html>')
    state = {'game': {'id': GAME, 'version': 1, 'status': 'active', 'players': [
        {'id': 'player-1', 'name': 'Ada'}, {'id': 'player-2', 'name': 'Ben'}],
        'hosts': [{'id': 'host-1', 'name': 'MC'}], 'currentPlayer': {'id': 'player-1', 'name': 'Ada'},
        'party': 'Party Night', 'theme': 'Space',
        'claims': [{'id': CLAIM, 'name': 'Helmet', 'playerName': 'Ada'}],
        'latestPrize': {'id': CLAIM, 'name': 'Helmet', 'playerName': 'Ada'}},
        'operators': [{'playerId': 'player-1', 'name': 'Ada', 'pairId': PAIR, 'approved': True}],
        'assignments': {}, 'moments': [], 'recording': {'id': RECORDING, 'title': 'Game Take'},
        'takes': [], 'serverTimeMs': 0}
    requests = []

    async def serve_api(route):
        parsed = urlparse(route.request.url)
        action = parse_qs(parsed.query).get('action', [''])[0]
        if action == 'director_context':
            await route.fulfill(status=200, json={'ok': True, 'context': deepcopy(state)})
            return
        if action == 'director_action':
            payload = json.loads(route.request.post_data or '{}')
            requests.append(payload)
            if payload.get('expectedVersion') != state['game']['version']:
                await route.fulfill(status=409, json={'ok': False, 'error': 'Another screen changed the game.'})
                return
            if payload['action'] == 'assign_camera':
                state['assignments'][payload['claimId']] = payload['pairId']
            elif payload['action'] == 'next_player':
                state['game']['currentPlayer'] = {'id': 'player-2', 'name': 'Ben'}
            state['game']['version'] += 1
            state['moments'].append({'type': payload['action'], 'at': '2026-11-02T12:00:00Z',
                                     'label': 'Director ' + payload['action'], 'offsetMs': 2000})
            await route.fulfill(status=200, json={'ok': True, 'changed': True, 'context': deepcopy(state)})
            return
        await route.fulfill(status=404, json={'ok': False, 'error': 'Unexpected API action'})

    checks = []
    def check(label, passed):
        checks.append((label, bool(passed)))

    async with async_playwright() as play:
        browser = await play.chromium.launch(executable_path='/usr/bin/chromium', headless=True, args=['--no-sandbox'])
        a = await browser.new_context(viewport={'width': 1440, 'height': 970})
        b = await browser.new_context(viewport={'width': 390, 'height': 844})
        for context in (a, b):
            await context.route('**/director.php?*', lambda route: route.fulfill(status=200, content_type='text/html', body=document))
            await context.route('**/api/director*', serve_api)
        desktop, mobile = await a.new_page(), await b.new_page()
        errors = []
        desktop.on('pageerror', lambda e: errors.append(str(e)))
        mobile.on('pageerror', lambda e: errors.append(str(e)))
        for page in (desktop, mobile):
            await page.goto('https://lucky.test/director.php?game=' + GAME)
            await page.wait_for_function("document.querySelector('#director-game-status').textContent.includes('ACTIVE')")
        check('Two independent browsers load the same server game',
              'Ada' in await desktop.locator('#director-game-current-player').inner_text()
              and 'Ada' in await mobile.locator('#director-game-current-player').inner_text())
        check('Desktop opens Live Game Director drawer',
              await desktop.locator('#director-workspace-drawer').is_visible())
        check('Mobile opens the same Director tools without horizontal overflow',
              await mobile.locator('#director-workspace-drawer').is_visible()
              and await mobile.evaluate('document.documentElement.scrollWidth<=innerWidth+1'))
        check('Linked recording does not start recording automatically',
              await desktop.evaluate('window.__recordStarts===0'))
        await desktop.locator('#director-game-claim').select_option(CLAIM)
        await desktop.locator('#director-game-operator').select_option(PAIR)
        await desktop.locator('#director-game-assign').click()
        check('Director assigns approved player phone to exact prize',
              bool(requests) and requests[-1]['action'] == 'assign_camera'
              and requests[-1]['claimId'] == CLAIM and requests[-1]['pairId'] == PAIR)
        await mobile.wait_for_function("document.querySelector('#director-game-operator').value==='" + PAIR + "'", timeout=10000)
        check('Second browser independently receives cameraman assignment',
              await mobile.locator('#director-game-operator').input_value() == PAIR)
        await desktop.get_by_role('button', name='Celebration').click()
        await mobile.wait_for_function("document.querySelector('#director-game-moment-count').textContent.includes('2 moments')", timeout=10000)
        check('New cue appears in timeline on second computer',
              'Director cue' in await mobile.locator('#director-game-moments').inner_text())
        await desktop.locator('#director-game-next').click()
        await mobile.wait_for_function("document.querySelector('#director-game-current-player').textContent==='Ben'", timeout=10000)
        check('Next-player command propagates to second screen',
              await mobile.locator('#director-game-current-player').inner_text() == 'Ben')
        await desktop.locator('#director-game-record-start').click()
        check('Manual Record proxies existing Director take button only after click',
              await desktop.evaluate('window.__recordStarts===1'))
        check('Game and multicamera-editor links target the same session',
              await mobile.locator('#director-game-open-wheel').get_attribute('href') == 'game.php?session=' + GAME
              and await mobile.locator('#director-game-open-editor').get_attribute('href') ==
              'recording-editor.php?session=' + RECORDING)
        check('No JavaScript exceptions across independent browsers', not errors)
        await browser.close()
    for label, passed in checks:
        print(('PASS' if passed else 'FAIL'), label, flush=True)
    if errors:
        print('JS errors:', errors)
    print('V5Q32A two-browser:', sum(ok for _, ok in checks), '/', len(checks))
    if not all(ok for _, ok in checks):
        raise SystemExit(1)


if __name__ == '__main__':
    asyncio.run(main())
