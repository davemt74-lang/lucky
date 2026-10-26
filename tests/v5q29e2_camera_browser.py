"""Browser acceptance for QR-paired, consent-gated full-screen Camera Studio."""
from pathlib import Path
import json
from playwright.sync_api import sync_playwright, expect

root = Path(__file__).resolve().parents[1]
html = '<!doctype html>' + (root / 'camera.php').read_text().split('<!doctype html>', 1)[1]
script = (root / 'public/recording-camera.js').read_text()
css = (root / 'public/platform.css').read_text()
basecss = (root / 'public/style.css').read_text()
phone_id = 'a' * 32
phone_key = 'b' * 64
session_id = 'c' * 32
mock_state = {'available': True, 'uploads': 0, 'fail_once': False}


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
    elif '/api/recording-phone.php?action=list' in url:
        if mock_state['fail_once']:
            mock_state['fail_once'] = False
            route.abort('failed')
            return
        assert route.request.headers.get('x-controller-token') == phone_key
        assert phone_id in route.request.post_data
        rows = [{'id': session_id, 'title': 'Live concert', 'type': 'live_event', 'status': 'active', 'clips': mock_state['uploads'], 'takeId': 'd'*32}] if mock_state['available'] else []
        route.fulfill(status=200, content_type='application/json', body=json.dumps({'ok': True, 'sessions': rows}))
    elif '/api/recording-phone.php?action=upload' in url:
        assert route.request.headers.get('x-controller-token') == phone_key
        assert b'Live concert' not in route.request.post_data_buffer
        assert b'name="consent"\r\n\r\nyes' in route.request.post_data_buffer
        assert session_id.encode() in route.request.post_data_buffer
        mock_state['uploads'] += 1
        route.fulfill(status=201, content_type='application/json', body=json.dumps({'ok': True, 'asset': {'kind': 'video', 'id': 'e'*32, 'existing': False}}))
    else:
        route.fulfill(status=404, body='Not found')


with sync_playwright() as playwright:
    browser = playwright.chromium.launch(headless=True)
    context = browser.new_context(viewport={'width': 390, 'height': 844}, is_mobile=True, has_touch=True)
    context.add_init_script(f"sessionStorage.setItem('lucky-phone-controller-v5p',JSON.stringify({json.dumps({'id':phone_id,'token':phone_key})}));")
    context.route('**/*', respond)
    page = context.new_page()
    page.goto('https://lucky.test/camera.php')
    expect(page.locator('#session-camera-session')).to_have_value(session_id)
    expect(page.locator('#session-camera-open')).to_be_disabled()
    assert page.locator('.recording-phone-app').evaluate('(e)=>e.getBoundingClientRect().height') >= 844
    page.locator('#session-camera-consent').check()
    expect(page.locator('#session-camera-open')).to_be_enabled()
    page.locator('#session-camera-file').set_input_files({'name': 'concert.mp4', 'mimeType': 'video/mp4', 'buffer': b'FAKE_VIDEO_FIXTURE'})
    expect(page.locator('#session-camera-send')).to_be_visible()
    page.locator('#session-camera-send').click()
    expect(page.locator('#session-camera-message')).to_contain_text('Saved video')
    assert mock_state['uploads'] == 1, 'One upload after explicit consent'
    page.locator('[data-session-mode="audio"]').click()
    expect(page.locator('[data-session-mode="audio"]')).to_have_attribute('aria-pressed','true')
    mock_state['fail_once'] = True
    page.locator('#session-camera-refresh').click()
    expect(page.locator('#session-camera-session')).to_have_value(session_id)
    expect(page.locator('#session-camera-badge')).to_contain_text('Reconnecting')
    expect(page.locator('#session-camera-open')).to_be_enabled()
    page.locator('#session-camera-refresh').click()
    expect(page.locator('#session-camera-badge')).to_contain_text('available session')
    expect(page.locator('#session-camera-message')).to_contain_text('reconnected')
    mock_state['available'] = False
    page.locator('#session-camera-refresh').click()
    expect(page.locator('#session-camera-badge')).to_have_text('0 available sessions')
    expect(page.locator('#session-camera-open')).to_be_hidden()
    browser.close()

print('V5Q.29E2 FULL-SCREEN PHONE CAMERA BROWSER CERTIFIED')
