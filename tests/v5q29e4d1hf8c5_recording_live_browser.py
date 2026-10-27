"""HF8C5: arm-gated production phone streams, Director sources, and group take controls."""
import asyncio
import re
from pathlib import Path

from playwright.async_api import async_playwright

ROOT = Path(__file__).resolve().parents[1]
A = 'a' * 32
B = 'b' * 32
P = 'c' * 32
N = 'd' * 32

PEER = r"""
window.peerInstances=[];
window.RTCPeerConnection=class {
  constructor(){this.iceGatheringState='complete';this.connectionState='new';this.localDescription=null;this.remoteDescription=null;window.peerInstances.push(this);}
  addTrack(track,stream){this.recordedTrack=track;}
  async createOffer(){return {type:'offer',sdp:'v=0\r\no=- 1 1 IN IP4 127.0.0.1\r\ns=offer\r\n'};}
  async createAnswer(){return {type:'answer',sdp:'v=0\r\no=- 2 2 IN IP4 127.0.0.1\r\ns=answer\r\n'};}
  async setLocalDescription(d){this.localDescription=d;}
  async setRemoteDescription(d){
    this.remoteDescription=d;this.connectionState='connected';
    if(d.type==='offer'&&this.ontrack){
      const canvas=document.createElement('canvas');canvas.width=64;canvas.height=36;
      const stream=canvas.captureStream(1);this.ontrack({streams:[stream],track:stream.getVideoTracks()[0]});
    }
    this.onconnectionstatechange?.();
  }
  addEventListener(){} removeEventListener(){}
  close(){this.connectionState='closed';}
};
"""


def read(name):
    return (ROOT / name).read_text().replace('</script', '<\\/script')


async def check(label, result, checks):
    checks.append((label, bool(result)))


async def run_director(browser, checks):
    markup = re.search(r'<main class="director-studio-shell">[\s\S]*?</main>', read('director.php'))
    assert markup, 'Camera Director must have standalone markup'
    prelude = PEER + f"""
    window.directorOffers=[];window.takeWrites=[];window.adminEvents=[];window.revoked=false;
    const A='{A}',B='{B}',P='{P}',N='{N}';
    const rows=[{{id:A,title:'Live production',type:'production',status:'active'}},
                {{id:B,title:'Other production',type:'production',status:'active'}}];
    window.Lucky={{getMe:async()=>({{csrf:'signed-test'}})}};
    window.fetch=async(url,opt={{}})=>{{
      const path=new URL(String(url),'https://lucky.test/'),act=path.searchParams.get('action');
      const body=opt.body?JSON.parse(opt.body):{{}};
      let value={{ok:true}};
      if(path.pathname.endsWith('recording-sessions.php')){{
        const id=path.searchParams.get('id')||body.id;
        if(act==='list')value={{ok:true,sessions:rows}};
        if(act==='detail')value={{ok:true,session:rows.find(r=>r.id===id),takes:window.takeWrites.length&&!window.revoked?[{{id:N,status:'recording',take_index:1}}]:[],assets:[],sources:[]}};
        if(act==='camera_devices')value={{ok:true,devices:[{{armed:true,online:true}}]}};
        if(act==='media_list')value={{ok:true,media:[]}};
        if(act==='take_start'){{window.takeWrites.push(body);value={{ok:true,take:{{id:N,status:'recording',take_index:1}}}};}}
        if(act==='take_stop'){{window.revoked=true;window.takeWrites.push(body);value={{ok:true}};}}
      }}else if(path.pathname.endsWith('recording-live.php')){{
        if(act==='list')value={{ok:true,phones:path.searchParams.get('session')===A?[{{id:P,nonce:N,name:'Paired iPhone',offer:'v=0\\r\\no=- 1 1 IN IP4 127.0.0.1\\r\\ns=offer\\r\\n'}}]:[]}};
        if(act==='answer'){{window.directorOffers.push({{body,csrf:opt.headers['X-CSRF-Token']}});value={{ok:true}};}}
      }}
      return {{ok:true,status:200,json:async()=>value}};
    }};
    """
    scripts = ['camera-director.js', 'director-studio.js', 'recording-live-host.js']
    html = ('<base href="https://lucky.test/">'
            + markup.group(0)
            + '<script>' + prelude + '</script>'
            + ''.join('<script>' + read('public/' + script) + '</script>' for script in scripts))
    page = await browser.new_page(viewport={'width': 1280, 'height': 900})
    errors = []
    page.on('pageerror', lambda error: errors.append(str(error)))
    async def serve_director(route):
        await route.fulfill(status=200, content_type='text/html', body=html)

    await page.route('https://lucky.test/hf8c5-director', serve_director)
    await page.goto('https://lucky.test/hf8c5-director', wait_until='domcontentloaded')
    await page.wait_for_function("document.querySelector('#director-studio-session').options.length===3")
    await check('No camera permission requested just by opening Director', await page.evaluate('peerInstances.length===0'), checks)
    await page.locator('#director-studio-session').select_option(A)
    await page.locator('#director-studio-open').click()
    await page.wait_for_function("LuckyRecordingLiveHost.state().cameras===1")
    await page.wait_for_function('directorOffers.length===1')
    await check('Host answers only recording-session phone offer with CSRF', await page.evaluate(
        "directorOffers[0].body.session==='a'.repeat(32)&&directorOffers[0].body.id==='c'.repeat(32)&&directorOffers[0].csrf==='signed-test'"), checks)
    await check('Paired phone becomes selectable Director camera source', await page.evaluate(
        "LuckyCameraDirector.sources().some(s=>s.id==='phone:'+'c'.repeat(32))"), checks)
    await page.locator('#director-preview-source').select_option('phone:' + P)
    await page.locator('#director-cut').click()
    await check('Phone can be cut into Program by host', await page.evaluate(
        "LuckyCameraDirector.state().program==='phone:'+'c'.repeat(32)"), checks)
    await page.locator('#director-studio-take-title').fill('Concert angle 1')
    await page.locator('#director-studio-take-start').click()
    await page.wait_for_function('takeWrites.length===1')
    await check('Take start uses selected group and stable nonce', await page.evaluate(
        "takeWrites[0].id==='a'.repeat(32)&&takeWrites[0].title==='Concert angle 1'&&/^[a-f0-9]{32}$/.test(takeWrites[0].nonce)"), checks)
    await check('Active grouped take displays recording clock', await page.evaluate(
        "!document.getElementById('director-studio-record-clock').hidden && /^● REC 00:00:\\d\\d$/.test(document.getElementById('director-studio-record-clock').textContent)"), checks)
    await page.locator('#director-studio-take-stop').click()
    await page.wait_for_function('takeWrites.length===2')
    await check('Take stop uses same group and active take ID', await page.evaluate(
        "takeWrites[1].id==='a'.repeat(32)&&takeWrites[1].takeId==='d'.repeat(32)"), checks)
    await page.wait_for_function("document.getElementById('director-studio-record-clock').hidden")
    await check('Stop clears recording clock', await page.evaluate(
        "document.getElementById('director-studio-record-clock').hidden"), checks)
    await page.locator('#director-studio-session').select_option(B)
    await page.locator('#director-studio-open').click()
    await page.wait_for_function("LuckyRecordingLiveHost.state().recording==='b'.repeat(32)")
    await check('Switching recording removes previous phone feed and Program', await page.evaluate(
        "LuckyRecordingLiveHost.state().cameras===0 && !LuckyCameraDirector.state().program"), checks)
    await check('No Director JavaScript errors', not errors, checks)
    if errors:
        print('Director browser errors:', errors)
    await page.close()


async def run_phone(browser, checks):
    prelude = PEER + f"""
    const ID='{P}',SESSION='{A}';
    sessionStorage.setItem('lucky-phone-controller-v5p',JSON.stringify({{id:ID,token:'f'.repeat(64)}}));
    window.offerWrites=[];window.endWrites=[];window.permissionCalls=0;
    Object.defineProperty(navigator,'mediaDevices',{{configurable:true,value:{{
      getUserMedia:async()=>{{window.permissionCalls++;throw Error('Unexpected permission prompt');}}
    }}}});
    window.fetch=async(url,opts={{}})=>{{
      const act=new URL(String(url),'https://lucky.test/').searchParams.get('action');
      const body=opts.body?JSON.parse(opts.body):{{}};
      if(act==='offer')window.offerWrites.push(body);
      if(act==='end')window.endWrites.push(body);
      return {{ok:true,status:200,json:async()=>act==='poll'?
        {{ok:true,answer:'v=0\\r\\no=- 2 2 IN IP4 127.0.0.1\\r\\ns=answer\\r\\n'}}:{{ok:true,accepted:true}}}};
    }};
    window.testStream=()=>{{const c=document.createElement('canvas');c.width=32;c.height=32;return c.captureStream(1);}};
    """
    html = ('<base href="https://lucky.test/"><div id="session-camera-arm-status"></div>'
            + '<script>' + prelude + '</script>'
            + '<script>' + read('public/recording-live-phone.js') + '</script>')
    page = await browser.new_page()
    errors = []
    page.on('pageerror', lambda error: errors.append(str(error)))
    # sessionStorage is blocked in about:blank/opaque origins. Serve this
    # isolated fixture from a routed HTTPS origin, like the real paired phone.
    async def serve_phone(route):
        await route.fulfill(status=200, content_type='text/html', body=html)

    await page.route('https://lucky.test/hf8c5-phone', serve_phone)
    await page.goto('https://lucky.test/hf8c5-phone', wait_until='domcontentloaded')
    await check('Phone preview is off until armed', await page.evaluate(
        "offerWrites.length===0 && LuckyRecordingPhonePreview.state().sharing===false"), checks)
    await page.evaluate("""() => {
      window.cam=testStream();
      dispatchEvent(new CustomEvent('lucky:recording-camera-live',{detail:{session:'a'.repeat(32),stream:cam,consent:false,armed:true}}));
    }""")
    await page.wait_for_timeout(120)
    await check('Consent missing never generates offer', await page.evaluate('offerWrites.length===0'), checks)
    await page.evaluate("""() => {
      dispatchEvent(new CustomEvent('lucky:recording-camera-live',{detail:{session:'a'.repeat(32),stream:cam,consent:true,armed:true}}));
    }""")
    await page.wait_for_function('offerWrites.length===1')
    await check('Explicit arm offers current camera stream to host', await page.evaluate(
        "offerWrites[0].session==='a'.repeat(32)&&/^[a-f0-9]{32}$/.test(offerWrites[0].nonce)"), checks)
    await page.wait_for_function('LuckyRecordingPhonePreview.state().connected')
    await check('Camera preview does not request new browser media permission', await page.evaluate('permissionCalls===0'), checks)
    await page.evaluate("""() => dispatchEvent(new CustomEvent('lucky:recording-camera-live',{detail:{
       session:'a'.repeat(32),stream:cam,consent:false,armed:false}}))""")
    await page.wait_for_function('endWrites.length===1')
    await check('Disarm sends teardown and releases WebRTC peer', await page.evaluate(
        "LuckyRecordingPhonePreview.state().sharing===false&&peerInstances[0].connectionState==='closed'"), checks)
    await check('Disarm does not stop original local MediaRecorder video track', await page.evaluate(
        "cam.getVideoTracks()[0].readyState==='live'"), checks)
    await check('No phone preview JavaScript errors', not errors, checks)
    if errors:
        print('Phone browser errors:', errors)
    await page.close()


async def main():
    checks = []
    async with async_playwright() as pw:
        browser = await pw.chromium.launch(executable_path='/usr/bin/chromium', headless=True, args=['--no-sandbox'])
        await run_director(browser, checks)
        await run_phone(browser, checks)
        await browser.close()
    for label, ok in checks:
        print(('PASS ' if ok else 'FAIL ') + label, flush=True)
    print(f'HF8C5 LIVE RECORDING BROWSER: {sum(ok for _,ok in checks)}/{len(checks)}', flush=True)
    if not all(ok for _, ok in checks):
        raise SystemExit(1)


if __name__ == '__main__':
    asyncio.run(main())
