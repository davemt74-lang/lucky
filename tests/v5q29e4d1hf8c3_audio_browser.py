"""HF8C3: live Program audio follows Take and is exported/published, with no auto-mic."""
import asyncio
import re
from pathlib import Path
from playwright.async_api import async_playwright

ROOT = Path(__file__).resolve().parents[1]


async def main():
    proof = (ROOT / "proof.php").read_text()
    markup = re.search(r'<section id="camera-director"[\s\S]*?</section>', proof)
    assert markup and 'director-audio-volume' in markup.group(0)
    mixer = (ROOT / "public/camera-director.js").read_text().replace('</script', '<\\/script')
    publisher = (ROOT / "public/director-publish.js").read_text().replace('</script', '<\\/script')
    viewer = (ROOT / "public/director-view.js").read_text().replace('</script', '<\\/script')
    checks, errors = [], []

    def check(label, ok):
        checks.append((label, bool(ok)))

    prelude = r"""
    window.mediaRequests=0;window.answerTracks=[];window.recordedKinds=[];window.currentMic=null;
    window.testCtx=new AudioContext();
    window.testOsc=window.testCtx.createOscillator();
    window.testDest=window.testCtx.createMediaStreamDestination();
    window.testOsc.connect(window.testDest);window.testOsc.start();
    Object.defineProperty(navigator,'mediaDevices',{configurable:true,value:{
      getUserMedia:async()=>{window.mediaRequests++;window.currentMic=window.testDest.stream.clone();return window.currentMic;}
    }});
    window.Lucky={getMe:async()=>({csrf:'csrf-test'})};
    window.fetch=async (url,opts={})=>{
      const action=new URL(String(url),'https://lucky.test/').searchParams.get('action');
      const result=action==='publish'?{ok:true,id:'e'.repeat(32),key:'f'.repeat(48)}:
       action==='host_list'?{ok:true,offers:[{id:'a'.repeat(32),offer:'v=0\r\n'}]}:
       action==='list'?{ok:true,clips:[]}:{ok:true};
      return {ok:true,status:200,json:async()=>result};
    };
    class MockPeer {
      constructor(){this.connectionState='new';this.iceGatheringState='complete';}
      addTrack(track){window.answerTracks.push(track.kind);}
      setRemoteDescription(){return Promise.resolve();}
      setLocalDescription(desc){this.localDescription=desc;return Promise.resolve();}
      createAnswer(){return Promise.resolve({type:'answer',sdp:'v=0\r\n'});}
      close(){}
    }
    window.RTCPeerConnection=MockPeer;
    class FakeRecorder {
      static isTypeSupported(){return true;}
      constructor(stream,options={}){window.recordedKinds=stream.getTracks().map(t=>t.kind);this.mimeType=options.mimeType||'video/webm';this.state='inactive';}
      start(){this.state='recording';}
      stop(){this.state='inactive';this.ondataavailable?.({data:new Blob(['test'],{type:'video/webm'})});this.onstop?.();}
    }
    window.MediaRecorder=FakeRecorder;
    """

    async with async_playwright() as pw:
        browser = await pw.chromium.launch(executable_path='/usr/bin/chromium', headless=True, args=['--no-sandbox'])
        page = await browser.new_page(viewport={'width': 1280, 'height': 940})
        page.on('pageerror', lambda error: errors.append(str(error)))
        html = ('<base href="https://lucky.test/"><div id="camera-grid">'
                '<section class="camera-source" data-camera-id="desktop1"><strong>Desktop</strong><video></video></section>'
                '<section class="camera-source camera-source-phone" data-phone-pair="' + 'b' * 32 + '"><strong>Phone</strong><video></video></section>'
                '</div>' + markup.group(0) + '<script>' + prelude + '</script><script>' + mixer +
                '</script><script>' + publisher + '</script>')
        await page.set_content(html, wait_until='domcontentloaded')
        await page.evaluate("document.querySelector('[data-phone-pair] video').srcObject=window.testDest.stream")
        await page.evaluate("window.dispatchEvent(new CustomEvent('lucky:proof-open',{detail:{session:'c'.repeat(32),claim:'d'.repeat(32)}}))")
        check('No microphone permission before host action', await page.evaluate('mediaRequests===0'))
        await page.locator('#director-preview-source').select_option('phone:' + 'b' * 32)
        await page.locator('#director-cut').click()
        check('Taken phone routes its WebRTC audio', await page.evaluate("LuckyCameraDirector.audioState().channels.includes('phone:'+'b'.repeat(32))"))
        check('Program stream contains video and isolated audio track', await page.evaluate("(()=>{const s=LuckyCameraDirector.programStream();const ok=s.getVideoTracks().length===1&&s.getAudioTracks().length===1;s.getTracks().forEach(t=>t.stop());return ok;})()"))
        check('Stopping an export clone cannot kill Program audio', await page.evaluate("(()=>{const a=LuckyCameraDirector.programStream(),b=LuckyCameraDirector.programStream();a.getTracks().forEach(t=>t.stop());const ok=b.getAudioTracks()[0]?.readyState==='live';b.getTracks().forEach(t=>t.stop());return ok;})()"))
        await page.locator('#director-audio-volume').evaluate("el=>{el.value='25';el.dispatchEvent(new Event('input',{bubbles:true}));}")
        check('Output volume changes the mixer', await page.evaluate('LuckyCameraDirector.audioState().volume===25'))
        await page.locator('#director-audio-mute').check()
        check('Mute applies to Program', await page.evaluate('LuckyCameraDirector.audioState().muted===true'))
        await page.locator('#director-audio-mute').uncheck()
        await page.locator('#director-export-start').click()
        check('Program export records both audio and video', await page.evaluate("recordedKinds.includes('audio')&&recordedKinds.includes('video')"))
        await page.locator('#director-export-stop').click()
        await page.locator('#director-preview-source').select_option('camera:desktop1')
        await page.locator('#director-cut').click()
        check('Desktop shot does not silently capture mic', await page.evaluate("mediaRequests===0&&document.getElementById('director-audio-status').textContent.includes('Enable the host microphone')"))
        await page.locator('#director-host-mic').click()
        await page.wait_for_function('LuckyCameraDirector.audioState().microphone')
        check('Desktop mic requires explicit click', await page.evaluate("mediaRequests===1&&LuckyCameraDirector.audioState().channels.includes('host-mic')"))
        await page.locator('#director-publish-consent').check()
        await page.locator('#director-publish').click()
        await page.wait_for_function("answerTracks.includes('audio')&&answerTracks.includes('video')")
        check('Viewer offer gets mixed Program audio and video', await page.evaluate("answerTracks.includes('audio')&&answerTracks.includes('video')"))
        await page.locator('#director-unpublish').click()
        await page.wait_for_function('!LuckyDirectorPublisher.state().onAir')
        check('Unpublishing does not end the mixer', await page.evaluate("(()=>{const s=LuckyCameraDirector.programStream();const ok=s.getAudioTracks()[0]?.readyState==='live';s.getTracks().forEach(t=>t.stop());return ok;})()"))
        await page.evaluate("window.dispatchEvent(new Event('lucky:proof-close'))")
        check('Proof closure stops host microphone', await page.evaluate("!LuckyCameraDirector.audioState().microphone&&currentMic.getTracks().every(t=>t.readyState==='ended')"))
        check('Proof closure clears output state', await page.evaluate("!LuckyCameraDirector.audioState().hasAudioTrack&&LuckyCameraDirector.audioState().volume===100"))
        viewer_page = await browser.new_page()
        viewer_page.on('pageerror', lambda error: errors.append(str(error)))
        vh = ('<body data-broadcast-id="" data-broadcast-key=""><div id="director-public-list"></div>'
              '<p id="director-view-status"></p><button id="director-public-refresh"></button>'
              '<video id="director-view-video" muted></video><button id="director-view-sound" aria-pressed="false">Enable sound</button></body>'
              '<script>window.fetch=async()=>({ok:true,json:async()=>({ok:true,broadcasts:[]})});'
              'document.getElementById("director-view-video").play=async()=>{};</script><script>' + viewer + '</script>')
        await viewer_page.set_content(vh, wait_until='domcontentloaded')
        await viewer_page.locator('#director-view-sound').click()
        check('Viewer opts into sound with a user gesture', await viewer_page.evaluate("!document.getElementById('director-view-video').muted&&document.getElementById('director-view-sound').getAttribute('aria-pressed')==='true'"))
        await viewer_page.locator('#director-view-sound').click()
        check('Viewer can mute without controlling host', await viewer_page.evaluate("document.getElementById('director-view-video').muted&&document.getElementById('director-view-sound').getAttribute('aria-pressed')==='false'"))
        check('No unhandled browser errors', not errors)
        await browser.close()
    for label, ok in checks:
        print(('PASS ' if ok else 'FAIL ') + label, flush=True)
    if errors:
        print('BROWSER ERRORS:', errors, flush=True)
    print(f'HF8C3 PROGRAM AUDIO BROWSER: {sum(ok for _,ok in checks)}/{len(checks)}', flush=True)
    if not all(ok for _,ok in checks):
        raise SystemExit(1)


if __name__ == '__main__':
    asyncio.run(main())
