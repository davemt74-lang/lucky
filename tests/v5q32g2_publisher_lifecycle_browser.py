"""V5Q32G2: real publisher controls and canvas tracks survive cancelled async work.

The fixture reads current proof markup and the production publisher. Authorization
and signaling are deliberately gated; canvas capture uses native Chromium media
tracks. This is not a physical camera, full mixer, or real-network WebRTC test.
"""
import asyncio
import re
from contextlib import asynccontextmanager
from pathlib import Path

from playwright.async_api import async_playwright

ROOT = Path(__file__).resolve().parents[1]
PRELUDE = r"""
window.PublisherTest = (() => {
  const deferred = () => {
    let resolve;
    const promise = new Promise(done => { resolve = done; });
    return {promise, resolve};
  };
  const authGate = deferred(), createGate = deferred(), stopGate = deferred();
  const state = {calls: [], outputs: [], authCalls: 0, creates: 0, serverActive: null,
    holdAuth: false, holdCreate: false, holdStop: false};
  const id = n => String(n).repeat(32);
  // A zero-delay task flushes continuations of the fixture gates deterministically;
  // assertions never rely on a guessed camera/network delay.
  const flush = () => new Promise(done => setTimeout(done, 0));
  state.releaseAuth = async () => { state.holdAuth = false; authGate.resolve(); await flush(); };
  state.releaseCreate = async () => { state.holdCreate = false; createGate.resolve(); await flush(); };
  state.releaseStop = async () => { state.holdStop = false; stopGate.resolve(); await flush(); };
  state.releaseAll = () => { state.holdAuth = state.holdCreate = state.holdStop = false;
    authGate.resolve(); createGate.resolve(); stopGate.resolve(); };
  state.count = action => state.calls.filter(call => call.action === action).length;
  state.open = () => window.dispatchEvent(new CustomEvent('lucky:proof-open', {
    detail: {session: 'a'.repeat(32), claim: 'b'.repeat(32)}
  }));
  window.Lucky = {getMe: async () => {
    const sequence = ++state.authCalls;
    if (state.holdAuth) await authGate.promise;
    return {csrf: 'csrf-' + sequence};
  }};
  window.LuckyCameraDirector = {programStream: () => {
    const canvas = document.getElementById('director-program');
    canvas.width = 320; canvas.height = 180;
    const context = canvas.getContext('2d'); context.fillStyle = '#173b69';
    context.fillRect(0, 0, 320, 180);
    const output = canvas.captureStream(10);
    state.outputs.push(output); return output;
  }};
  window.fetch = async (url, options = {}) => {
    const parsed = new URL(String(url), document.baseURI);
    const action = parsed.searchParams.get('action');
    const data = options.body ? JSON.parse(options.body) : Object.fromEntries(parsed.searchParams);
    state.calls.push({action, data, csrf: options.headers?.['X-CSRF-Token']});
    let result;
    if (action === 'publish') {
      const sequence = ++state.creates;
      if (sequence === 1 && state.holdCreate) await createGate.promise;
      state.serverActive = id(sequence);
      result = {ok: true, id: id(sequence), key: 'c'.repeat(48)};
    } else if (action === 'stop') {
      if (data.id === id(1) && state.holdStop) await stopGate.promise;
      if (state.serverActive === data.id) state.serverActive = null;
      result = {ok: true};
    } else if (action === 'host_list') {
      result = {ok: true, offers: []};
    } else {
      throw new Error('Unexpected publisher fixture action: ' + action);
    }
    return {ok: true, status: 200, json: async () => result};
  };
  return state;
})();
"""


async def main():
    proof = (ROOT / 'proof.php').read_text()
    section = re.search(r'<section id="camera-director"[\s\S]*?</section>', proof)
    assert section and all(name in section.group(0) for name in (
        'director-publish-consent', 'director-publish-public-ack', 'director-unpublish'))
    publisher = (ROOT / 'public/director-publish.js').read_text().replace('</script', '<\\/script')
    html = ('<!doctype html><html><head><base href="https://lucky.test/">'
            '<meta charset="utf-8"></head><body>' + section.group(0) +
            '<script>' + PRELUDE + '</script><script>' + publisher + '</script></body></html>')
    checks = []

    def check(label, ok):
        checks.append((label, bool(ok)))
        print(('PASS ' if ok else 'FAIL ') + label, flush=True)
        assert ok, label

    async with async_playwright() as playwright:
        browser = await playwright.chromium.launch(
            executable_path='/usr/bin/chromium', headless=True,
            args=['--no-sandbox'], timeout=15000)

        @asynccontextmanager
        async def fixture(**gates):
            context = await browser.new_context(viewport={'width': 1280, 'height': 960})
            page = await context.new_page()
            page.set_default_timeout(5000)
            errors = []
            page.on('pageerror', lambda error: errors.append(str(error)))
            await page.route('**/*', lambda route: route.abort())
            try:
                await page.set_content(html, wait_until='domcontentloaded')
                await page.evaluate('(gates) => { Object.assign(PublisherTest, gates); PublisherTest.open(); }', gates)
                yield page, errors
                check('No unhandled browser errors in controlled lifecycle case', not errors)
            finally:
                try:
                    await page.evaluate('async () => { PublisherTest.releaseAll(); await LuckyDirectorPublisher.stop(true); }')
                finally:
                    await context.close()

        async def begin(page):
            await page.locator('#director-publish-consent').check()
            await page.locator('#director-publish').click()

        async def off_air(page, label):
            check(label, await page.evaluate("""() => !LuckyDirectorPublisher.state().onAir &&
                !LuckyDirectorPublisher.state().publishing &&
                document.getElementById('director-live-badge').textContent === 'OFF AIR' &&
                document.getElementById('director-live-link').hidden &&
                document.getElementById('director-live-url').value === ''"""))

        try:
            async with fixture(holdAuth=True) as (page, errors):
                check('Opening the real publisher UI does not acquire media', await page.evaluate('PublisherTest.outputs.length === 0'))
                await begin(page)
                await page.wait_for_function('PublisherTest.authCalls === 1')
                check('Pending publication owns a live native canvas video track', await page.evaluate("""() =>
                    PublisherTest.outputs[0] instanceof MediaStream &&
                    PublisherTest.outputs[0].getVideoTracks().length === 1 &&
                    PublisherTest.outputs[0].getVideoTracks()[0].readyState === 'live'"""))
                check('Cancel is enabled while authorization is pending',
                      await page.locator('#director-unpublish').is_enabled() and
                      'Cancel' in await page.locator('#director-unpublish').inner_text())
                await page.locator('#director-unpublish').click()
                check('Cancel immediately stops the owned native capture track', await page.evaluate(
                    "PublisherTest.outputs[0].getTracks().every(track => track.readyState === 'ended')"))
                await off_air(page, 'Cancel keeps the real badge and share controls OFF AIR')
                await page.evaluate('PublisherTest.releaseAuth()')
                check('Late authorization sends no publish request', await page.evaluate("PublisherTest.count('publish') === 0"))
                await off_air(page, 'Late authorization does not resurrect a cancelled publication')
                await page.locator('#director-publish').click()
                await page.wait_for_function('LuckyDirectorPublisher.state().onAir')
                check('An explicit retry publishes a fresh native output', await page.evaluate("""() =>
                    PublisherTest.outputs.length === 2 &&
                    PublisherTest.outputs[1].getVideoTracks()[0].readyState === 'live' &&
                    !document.getElementById('director-live-link').hidden &&
                    document.getElementById('director-live-url').value.includes('key=' + 'c'.repeat(48))"""))
                await page.locator('#director-unpublish').click()
                await page.wait_for_function("PublisherTest.count('stop') === 1")
                check('End Live View revokes the exact retry and stops all owned outputs', await page.evaluate("""() =>
                    PublisherTest.calls.find(call => call.action === 'stop').data.id === '1'.repeat(32) &&
                    PublisherTest.outputs.every(output => output.getTracks().every(track => track.readyState === 'ended'))"""))

            for checkbox in ('director-publish-consent', 'director-publish-public-ack'):
                async with fixture(holdAuth=True) as (page, errors):
                    if checkbox.endswith('public-ack'):
                        await page.locator('#director-broadcast-visibility').select_option('public')
                        await page.locator('#director-publish-public-ack').check()
                    await begin(page)
                    await page.wait_for_function('PublisherTest.authCalls === 1')
                    await page.locator('#' + checkbox).uncheck()
                    check(checkbox + ' revocation immediately stops capture', await page.evaluate(
                        "PublisherTest.outputs[0].getTracks().every(track => track.readyState === 'ended')"))
                    await page.locator('#' + checkbox).check()
                    await page.evaluate('PublisherTest.releaseAuth()')
                    check(checkbox + ' rechecking does not authorize the old async intent', await page.evaluate("PublisherTest.count('publish') === 0"))
                    await off_air(page, checkbox + ' revoked intent remains OFF AIR')

            async with fixture(holdCreate=True, holdStop=True) as (page, errors):
                await begin(page)
                await page.wait_for_function("PublisherTest.count('publish') === 1")
                await page.locator('#director-unpublish').click()
                await off_air(page, 'Cancel during server creation immediately returns OFF AIR')
                await page.locator('#director-publish').click()
                await page.wait_for_function('PublisherTest.authCalls === 2')
                check('New intent queues without sending a replacement create early', await page.evaluate("""() =>
                    LuckyDirectorPublisher.state().publishing && PublisherTest.count('publish') === 1"""))
                await page.evaluate('PublisherTest.releaseCreate()')
                await page.wait_for_function("PublisherTest.count('stop') === 1")
                check('Stale response is revoked by its own ID before the new create', await page.evaluate("""() =>
                    PublisherTest.count('publish') === 1 &&
                    PublisherTest.calls.find(call => call.action === 'stop').data.id === '1'.repeat(32) &&
                    !LuckyDirectorPublisher.state().onAir"""))
                await page.evaluate('PublisherTest.releaseStop()')
                await page.wait_for_function('LuckyDirectorPublisher.state().onAir')
                check('Second publication survives stale cleanup and retains its own output', await page.evaluate("""() =>
                    PublisherTest.serverActive === '2'.repeat(32) &&
                    PublisherTest.outputs[0].getTracks().every(track => track.readyState === 'ended') &&
                    PublisherTest.outputs[1].getVideoTracks()[0].readyState === 'live' &&
                    document.getElementById('director-live-url').value.includes('id=' + '2'.repeat(32))"""))
                check('Browser signaling order is create, stale-ID cleanup, replacement create', await page.evaluate("""() =>
                    JSON.stringify(PublisherTest.calls.filter(call => ['publish','stop'].includes(call.action))
                      .map(call => call.action + ':' + (call.data.id || ''))) ===
                    JSON.stringify(['publish:', 'stop:' + '1'.repeat(32), 'publish:'])"""))
                await page.locator('#director-unpublish').click()
                await page.wait_for_function("PublisherTest.count('stop') === 2")

            async with fixture(holdCreate=True) as (page, errors):
                await begin(page)
                await page.wait_for_function("PublisherTest.count('publish') === 1")
                # MediaStreamTrack.stop() ends the native track without the app's
                # separate Program-ended custom event: adoption must revalidate.
                await page.evaluate('PublisherTest.outputs[0].getVideoTracks()[0].stop()')
                await page.evaluate('PublisherTest.releaseCreate()')
                await page.wait_for_function("PublisherTest.count('stop') === 1 && !LuckyDirectorPublisher.state().publishing")
                await off_air(page, 'A native track ending during creation cannot become ON AIR')
                check('Ended native output revokes returned ID without host polling', await page.evaluate("""() =>
                    PublisherTest.count('host_list') === 0 && PublisherTest.serverActive === null &&
                    PublisherTest.calls.find(call => call.action === 'stop').data.id === '1'.repeat(32)"""))
        finally:
            await browser.close()

    print(f'V5Q32G2 PUBLISHER LIFECYCLE BROWSER: {sum(ok for _, ok in checks)}/{len(checks)}', flush=True)


if __name__ == '__main__':
    asyncio.run(asyncio.wait_for(main(), timeout=90))
