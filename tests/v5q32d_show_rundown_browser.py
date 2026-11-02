"""Two Director browsers share a server-owned show rundown and a local-only Program invitation."""
import asyncio
import json
import re
from copy import deepcopy
from pathlib import Path
from urllib.parse import parse_qs, urlparse

from playwright.async_api import async_playwright

ROOT = Path(__file__).resolve().parents[1]
GAME = 'a' * 32
PRIVATE = 'https://lucky.test/live-view.php?id=' + 'b' * 32 + '&key=' + 'c' * 48


def asset(name):
    return (ROOT / name).read_text().replace('</script', '<\\/script')


async def main():
    markup = re.search(r'<main class="director-studio-shell">[\s\S]*?</main>', asset('director.php'))
    assert markup
    css = '\n'.join(asset(p) for p in (
        'public/style.css', 'public/platform.css', 'public/director-workspace.css', 'public/director-game.css'))
    script = asset('public/director-game.js')
    mock = '''
      window.__mockOnAir=false;
      window.Lucky={api:async(action,opts={})=>{
       const params=new URLSearchParams({action});if(opts.query?.session)params.set('session',opts.query.session);
       const response=await fetch('/api/director?'+params,{method:opts.body?'POST':'GET',
        headers:opts.body?{'Content-Type':'application/json'}:{},body:opts.body?JSON.stringify(opts.body):undefined});
       const data=await response.json();if(!response.ok||!data.ok)throw Error(data.error||'API error');return data;
      },idempotency:()=>Math.random().toString(36).slice(2)+Date.now()};
      window.LuckyCameraDirector={state:()=>({preview:'',program:''}),sources:()=>[]};
      window.LuckyDirectorPublisher={state:()=>({onAir:window.__mockOnAir})};
      window.LuckyDirectorStudio={state:()=>({recordingSession:''})};
    '''
    html = ('<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1">'
            f'<style>{css}</style></head><body class="director-studio-page">'
            + markup.group(0) + f'<script>{mock}</script><script>{script}</script></body></html>')
    steps = [
        {'id': 'intro', 'trigger': 'next_player', 'cue': 'turn_intro', 'durationSec': 8, 'enabled': True, 'detail': ''},
        {'id': 'spin', 'trigger': 'spin_start', 'cue': 'scene_cut', 'durationSec': 6, 'enabled': True, 'detail': 'Wheel spinning'},
        {'id': 'prize', 'trigger': 'prize_reveal', 'cue': 'prize_reveal', 'durationSec': 12, 'enabled': True, 'detail': ''},
        {'id': 'costume', 'trigger': 'costume_action', 'cue': 'costume_focus', 'durationSec': 9, 'enabled': True, 'detail': ''},
        {'id': 'reaction', 'trigger': 'manual', 'cue': 'reaction', 'durationSec': 8, 'enabled': True, 'detail': ''},
        {'id': 'finale', 'trigger': 'manual', 'cue': 'celebration', 'durationSec': 10, 'enabled': True, 'detail': ''}]
    state = {'game': {'id': GAME, 'version': 1, 'status': 'active', 'players': [
        {'id': 'player-1', 'name': 'Ada'}, {'id': 'player-2', 'name': 'Ben'}],
        'hosts': [], 'currentPlayer': {'id': 'player-1', 'name': 'Ada'}, 'claims': []},
        'operators': [], 'assignments': {}, 'moments': [], 'recording': None, 'takes': [],
        'presentation': None, 'rundown': {'steps': steps, 'enabled': False, 'paused': False,
                                        'cursor': 0, 'lastStepId': None}}
    requests = []

    async def director_api(route):
        action = parse_qs(urlparse(route.request.url).query).get('action', [''])[0]
        if action == 'director_context':
            await route.fulfill(json={'ok': True, 'context': deepcopy(state)})
            return
        if action != 'director_action':
            await route.fulfill(status=404, json={'ok': False, 'error': 'Unexpected action'})
            return
        payload = json.loads(route.request.post_data or '{}')
        requests.append(payload)
        if payload.get('expectedVersion') != state['game']['version']:
            await route.fulfill(status=409, json={'ok': False, 'error': 'Game changed'})
            return
        r = state['rundown']
        if payload['action'] == 'rundown_save':
            r.update(steps=deepcopy(payload['steps']), cursor=0, lastStepId=None)
        elif payload['action'] == 'rundown_control':
            cmd = payload['command']
            if cmd == 'enable': r.update(enabled=True, paused=False)
            if cmd == 'disable': r.update(enabled=False)
            if cmd == 'pause': r.update(paused=True)
            if cmd == 'resume': r.update(paused=False)
            if cmd in ('next', 'replay'):
                step = r['steps'][r['cursor'] % len(r['steps'])]
                r['lastStepId'] = step['id']
                if cmd == 'next': r['cursor'] = (r['cursor'] + 1) % len(r['steps'])
        state['game']['version'] += 1
        await route.fulfill(json={'ok': True, 'changed': True, 'context': deepcopy(state)})

    checks = []
    def check(name, passed):
        checks.append((name, bool(passed)))

    async with async_playwright() as play:
        browser = await play.chromium.launch(executable_path='/usr/bin/chromium',
                                             headless=True, args=['--no-sandbox'])
        desktop_context = await browser.new_context(viewport={'width': 1440, 'height': 940})
        mobile_context = await browser.new_context(viewport={'width': 390, 'height': 844})
        errors = []
        for browser_context in (desktop_context, mobile_context):
            await browser_context.route('**/director.php?*', lambda route: route.fulfill(
                content_type='text/html', body=html))
            await browser_context.route('**/api/director*', director_api)
        desktop, mobile = await desktop_context.new_page(), await mobile_context.new_page()
        for page in (desktop, mobile):
            page.on('pageerror', lambda error: errors.append(str(error)))
            await page.goto('https://lucky.test/director.php?game=' + GAME)
            await page.wait_for_function("document.querySelector('#director-game-status').textContent.includes('ACTIVE')")
        check('Desktop and phone Director show the same six editable steps',
              await desktop.locator('.director-rundown-step').count() == 6 and
              await mobile.locator('.director-rundown-step').count() == 6)
        check('Auto broadcast and show automation start disabled',
              await desktop.locator('#director-rundown-status').inner_text() == 'AUTO OFF' and
              await desktop.locator('#director-game-broadcast-copy').is_disabled())
        await desktop.locator('.director-rundown-step').first.locator('.director-rundown-row-actions button').nth(1).click()
        check('Step controls reorder without immediate server mutation',
              await desktop.locator('.director-rundown-step').first.locator('strong').inner_text() == '1. scene cut' and
              len(requests) == 0)
        await desktop.locator('#director-rundown-save').click()
        await desktop.wait_for_function("document.querySelector('#director-rundown-save').disabled")
        check('Saving posts ordered step data with an expected game version',
              bool(requests) and requests[-1]['action'] == 'rundown_save' and
              requests[-1]['steps'][0]['id'] == 'spin' and requests[-1]['expectedVersion'] == 1)
        await mobile.locator('#director-game-refresh').click()
        await mobile.wait_for_function("document.querySelector('.director-rundown-step strong').textContent.includes('scene cut')")
        check('Second browser immediately sees the saved server rundown',
              await mobile.locator('.director-rundown-step').first.locator('strong').inner_text() == '1. scene cut')
        await desktop.locator('#director-rundown-enable').click()
        await desktop.wait_for_function("document.querySelector('#director-rundown-status').textContent.includes('AUTO ON')")
        check('Automation is armed explicitly by authenticated Director action',
              requests[-1]['action'] == 'rundown_control' and requests[-1]['command'] == 'enable')
        await desktop.locator('#director-rundown-next').click()
        await desktop.wait_for_function("document.querySelector('#director-rundown-replay').disabled===false")
        check('Next step and Replay controls are functional',
              requests[-1]['command'] == 'next' and
              await desktop.locator('#director-rundown-replay').is_enabled())
        await desktop.locator('#director-rundown-pause').click()
        await desktop.wait_for_function("document.querySelector('#director-rundown-status').textContent.includes('PAUSED')")
        check('The Director can pause automation independently of gameplay',
              requests[-1]['command'] == 'pause' and state['game']['status'] == 'active')
        await desktop.evaluate('''link=>{document.querySelector('#director-live-url').value=link;
           window.__mockOnAir=true}''', PRIVATE)
        await desktop.wait_for_function("document.querySelector('#director-game-broadcast-copy').disabled===false")
        check('Live Game Director shows actual final Program invitation and ON AIR badge',
              await desktop.locator('#director-game-broadcast-url').input_value() == PRIVATE and
              'ON AIR' in await desktop.locator('#director-game-on-air').inner_text())
        check('Private broadcast invitation stays local to the publishing Director',
              await mobile.locator('#director-game-broadcast-url').input_value() == '' and
              PRIVATE not in json.dumps(state))
        check('Copy and Open Broadcast controls use the same final Program URL',
              await desktop.locator('#director-game-broadcast-open').get_attribute('href') == PRIVATE and
              await desktop.locator('#director-game-broadcast-copy').is_enabled())
        check('No browser script errors in desktop or mobile Director', not errors)
        await browser.close()
    for label, passed in checks:
        print(('PASS' if passed else 'FAIL') + ' ' + label)
    assert all(passed for _, passed in checks), 'V5Q32D browser regression failed'
    print(f'V5Q32D DIRECTOR BROWSER: {len(checks)}/{len(checks)}')


if __name__ == '__main__':
    asyncio.run(main())
