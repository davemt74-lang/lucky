"""Browser acceptance: opt-in camera arm, host take, auto upload, revocation."""
from pathlib import Path
import json
from playwright.sync_api import sync_playwright, expect

root = Path(__file__).resolve().parents[1]
html = '<!doctype html>' + (root / 'camera.php').read_text().split('<!doctype html>', 1)[1]
script = (root / 'public/recording-camera.js').read_text()
css = (root / 'public/platform.css').read_text()
basecss = (root / 'public/style.css').read_text()
phone_id, phone_key, session_id, take_id = 'a'*32, 'b'*64, 'c'*32, 'd'*32
state = {'armed': False, 'take': None, 'uploads': 0, 'revoked': False}

def respond(route):
    url = route.request.url
    if url.endswith('camera.php'):
        route.fulfill(status=200, content_type='text/html', body=html)
    elif '/public/recording-camera.js' in url:
        route.fulfill(status=200, content_type='application/javascript', body=script)
    elif '/public/platform.css' in url:
        route.fulfill(status=200, content_type='text/css', body=css)
    elif '/public/style.css' in url:
        route.fulfill(status=200, content_type='text/css', body=basecss)
    elif '/api/recording-phone.php' in url:
        assert route.request.headers.get('x-controller-token') == phone_key
        action = url.split('action=')[1]
        if action == 'list':
            rows = [{'id': session_id, 'title': 'Live concert', 'type': 'live_event', 'status': 'active', 'clips': state['uploads'], 'takeId': state['take']['id'] if state['take'] else None}]
            reply = {'ok': True, 'sessions': rows}
        elif action == 'arm':
            posted = json.loads(route.request.post_data)
            assert posted['session'] == session_id and posted['kind'] == 'video'
            if posted['enabled']: assert posted['consent'] == 'yes'
            state['armed'] = posted['enabled']
            reply = {'ok': True, 'camera': {'armed': state['armed'], 'kind': 'video'}}
        elif action == 'poll':
            if state['revoked']:
                route.fulfill(status=403, content_type='application/json', body=json.dumps({'ok': False, 'error': 'Host revoked camera access'}))
                return
            reply = {'ok': True, 'coordination': {'armed': state['armed'], 'serverTimeMs': int(__import__('time').time()*1000), 'kind': 'video', 'activeTake': state['take']}}
        elif action == 'upload':
            body = route.request.post_data_buffer
            assert b'name="consent"\r\n\r\nyes' in body
            assert b'name="recordedStartMs"' in body and b'name="clockOffsetMs"' in body
            assert take_id.encode() in body
            state['uploads'] += 1
            reply = {'ok': True, 'asset': {'kind': 'video', 'id': 'e'*32, 'existing': False}}
        else:
            raise AssertionError('Unknown camera action: ' + action)
        route.fulfill(status=200, content_type='application/json', body=json.dumps(reply))
    else:
        route.fulfill(status=404, body='Not found')

with sync_playwright() as playwright:
    browser = playwright.chromium.launch(headless=True, args=['--use-fake-device-for-media-stream', '--use-fake-ui-for-media-stream'])
    context = browser.new_context(viewport={'width': 390, 'height': 844}, is_mobile=True, has_touch=True)
    context.add_init_script(f"sessionStorage.setItem('lucky-phone-controller-v5p',JSON.stringify({json.dumps({'id':phone_id,'token':phone_key})}));")
    context.route('**/*', respond)
    page = context.new_page()
    page.goto('https://lucky.test/camera.php')
    expect(page.locator('#session-camera-session')).to_have_value(session_id)
    expect(page.locator('#session-camera-arm')).to_be_disabled()
    page.locator('#session-camera-consent').check()
    page.locator('#session-camera-open').click()
    expect(page.locator('#session-camera-arm')).to_be_enabled(timeout=15000)
    page.locator('#session-camera-arm').click()
    expect(page.locator('#session-camera-arm')).to_contain_text('Disarm')
    state['take'] = {'id': take_id, 'index': 1, 'title': 'Live set', 'startedAtMs': int(__import__('time').time()*1000)}
    expect(page.locator('#session-camera-live-dot')).to_be_visible(timeout=20000)
    state['take'] = None
    expect(page.locator('#session-camera-session option')).to_contain_text('1 clips', timeout=20000)
    assert state['uploads'] == 1, 'One synchronized take yields exactly one upload'
    expect(page.locator('#session-camera-live-dot')).to_be_hidden()
    state['revoked'] = True
    expect(page.locator('#session-camera-arm')).to_contain_text('Arm for host', timeout=15000)
    assert not state['armed'] or state['revoked'], 'Host revocation forces local recording stop'
    browser.close()

print('V5Q.29E3 COORDINATED MULTICAMERA BROWSER CERTIFIED')
