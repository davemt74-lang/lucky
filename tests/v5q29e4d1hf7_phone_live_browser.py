"""HF7: paired phone live feed occupies the existing desktop proof video grid."""
import asyncio
import re
from pathlib import Path
from playwright.async_api import async_playwright

ROOT = Path(__file__).resolve().parents[1]
NONCE = "c" * 32
SESSION = "a" * 32
CLAIM = "b" * 32


async def main():
    results = []
    errors = []

    def check(label, passed):
        results.append((label, bool(passed)))

    async with async_playwright() as p:
        browser = await p.chromium.launch(executable_path="/usr/bin/chromium", headless=True, args=["--no-sandbox"])
        host = await browser.new_page()
        host.on("pageerror", lambda error: errors.append("desktop: " + str(error)))
        host_js = (ROOT / "public/phone-live-host.js").read_text()
        await host.set_content(f"""<div id="camera-grid" class="camera-grid"><section class="camera-source" id="desktop-cam">USB camera</section></div>
<script>
window.__liveOffers=[{{id:'{NONCE}',nonce:'d'.repeat(32),name:'Paired Pixel',offer:'v=0\\r\\no=fake\\r\\n'}}];
window.__answers=[];
window.Lucky={{getMe:async()=>({{csrf:'host-csrf'}})}};
window.fetch=async(url,options={{}})=>{{
 const action=new URL(String(url),'https://lucky.test/').searchParams.get('action');
 if(action==='answer')__answers.push({{body:JSON.parse(options.body),csrf:options.headers['X-CSRF-Token']}});
 return new Response(JSON.stringify({{ok:true,phones:__liveOffers}}));
}};
class FakeRTC {{
 iceGatheringState='complete';connectionState='new';localDescription=null;
 async setRemoteDescription(value){{this.remoteDescription=value;}}
 async createAnswer(){{return {{type:'answer',sdp:'v=0\\r\\no=answer\\r\\n'}};}}
 async setLocalDescription(value){{this.localDescription=value;
  this.ontrack?.({{streams:[new MediaStream()],track:null}});
 }}
 close(){{this.connectionState='closed';}}
}}
window.RTCPeerConnection=FakeRTC;
</script><script>{host_js.replace('</script','<\\/script')}</script>""")
        await host.evaluate(f"window.dispatchEvent(new CustomEvent('lucky:proof-open',{{detail:{{session:'{SESSION}',claim:'{CLAIM}'}}}}))")
        await host.wait_for_function("window.__answers.length === 1")
        check("Phone video appears in original desktop camera grid, beside USB camera",
              await host.locator("#camera-grid > .camera-source").count() == 2)
        check("Phone tile has an actual live video element",
              await host.locator("#camera-grid .camera-source-phone video").count() == 1)
        check("Host answer bound to exact approved prize and CSRF token",
              await host.evaluate(f"__answers[0].body.session==='{SESSION}' && __answers[0].body.claim==='{CLAIM}' && __answers[0].csrf==='host-csrf'"))
        await host.evaluate("document.getElementById('camera-grid').replaceChildren();window.dispatchEvent(new Event('lucky:cameras-refreshed'))")
        check("Refreshing desktop webcam preview preserves active phone tile",
              await host.locator("#camera-grid .camera-source-phone").count() == 1)
        await host.evaluate("window.__liveOffers=[]")
        await host.wait_for_function("document.querySelectorAll('.camera-source-phone').length===0", timeout=5000)
        check("Stopping phone live camera removes its desktop video tile",
              await host.locator("#camera-grid .camera-source-phone").count() == 0)
        await host.evaluate("window.dispatchEvent(new Event('lucky:proof-close'))")

        phone = await browser.new_page(viewport={"width": 375, "height": 780})
        phone.on("pageerror", lambda error: errors.append("phone: " + str(error)))
        html = (ROOT / "controller.php").read_text().split("?>", 1)[1]
        html = re.sub(r'<script src="[^"]+" defer></script>', "", html)
        mocks = f"""<script>
const keyMap=new Map([['lucky-phone-controller-v5p',JSON.stringify({{id:'{NONCE}',token:'f'.repeat(64)}})]]);
Object.defineProperty(window,'sessionStorage',{{configurable:true,value:{{getItem:k=>keyMap.get(k)||null,setItem:(k,v)=>keyMap.set(k,v)}}}});
window.__liveEvents=[];
window.fetch=async(url,opts={{}})=>{{
 const action=new URL(String(url),'https://lucky.test/').searchParams.get('action');
 if(action==='offer'||action==='end')window.__liveEvents.push({{action,payload:JSON.parse(opts.body),token:opts.headers['X-Controller-Token']}});
 return new Response(JSON.stringify({{ok:true,answer:action==='poll'?'v=0\\r\\no=answer\\r\\n':null}}));
}};
const sample=document.createElement('canvas');sample.width=20;sample.height=20;
Object.defineProperty(navigator,'mediaDevices',{{configurable:true,value:{{getUserMedia:async()=>sample.captureStream(15)}}}});
HTMLMediaElement.prototype.play=async function(){{}};
class FakeRTC {{
 iceGatheringState='complete';connectionState='new';localDescription=null;
 addTrack(){{}}
 async createOffer(){{return {{type:'offer',sdp:'v=0\\r\\no=phone\\r\\n'}};}}
 async setLocalDescription(value){{this.localDescription=value;}}
 async setRemoteDescription(value){{this.currentRemoteDescription=value;}}
 close(){{this.connectionState='closed';}}
}}
window.RTCPeerConnection=FakeRTC;
</script>"""
        inline = ''.join("<script>" + (ROOT / file).read_text().replace("</script", "<\\/script") + "</script>"
                         for file in ("public/phone-capture.js", "public/phone-live-phone.js"))
        html = html.replace("</body>", mocks + inline + "</body>")
        await phone.set_content(html, wait_until="domcontentloaded")
        await phone.evaluate(f"window.dispatchEvent(new CustomEvent('lucky:phone-proof-status',{{detail:{{active:true,phoneCameraAllowed:true,consent:true,phase:'live',sessionId:'{SESSION}',claimId:'{CLAIM}'}}}}))")
        await phone.evaluate("window.LuckyPhoneCapture.open()")
        await phone.locator('[data-phone-capture-mode="video"]').click()
        check("Phone offers dedicated live desktop preview without recording",
              not await phone.locator("#phone-capture-live-start").evaluate("el=>el.hidden"))
        await phone.locator("#phone-capture-live-start").click()
        await phone.wait_for_function("window.__liveEvents.some(e=>e.action==='offer')", timeout=5000)
        check("Phone sends live offer with paired token and bound proof",
              await phone.evaluate(f"__liveEvents.some(e=>e.action==='offer' && e.token==='{'f'*64}' && e.payload.session==='{SESSION}' && e.payload.claim==='{CLAIM}')"))
        check("Phone can now choose recording while live streaming",
              not await phone.locator("#phone-capture-live-record").evaluate("el=>el.hidden"))
        await phone.locator("#phone-capture-live-stop").click()
        await phone.wait_for_function("window.__liveEvents.some(e=>e.action==='end')", timeout=5000)
        check("Stop live preview tells desktop to release peer feed",
              await phone.evaluate("__liveEvents.some(e=>e.action==='end')"))
        await phone.locator("#phone-capture-live-start").click()
        await phone.wait_for_function("__liveEvents.filter(e=>e.action==='offer').length===2", timeout=5000)
        await phone.evaluate(f"window.dispatchEvent(new CustomEvent('lucky:phone-proof-status',{{detail:{{active:true,phoneCameraAllowed:true,consent:false,phase:'live',sessionId:'{SESSION}',claimId:'{CLAIM}'}}}}))")
        await phone.wait_for_function("__liveEvents.filter(e=>e.action==='end').length===2", timeout=5000)
        check("Revoking consent immediately ends live phone streaming", True)
        await browser.close()

    for label, passed in results:
        print(("PASS " if passed else "FAIL ") + label, flush=True)
    if errors:
        print("Browser errors:", errors, flush=True)
    print(f"HF7 PHONE LIVE BROWSER: {sum(v for _,v in results)}/{len(results)}", flush=True)
    if errors or not all(value for _, value in results):
        raise SystemExit(1)


if __name__ == "__main__":
    asyncio.run(main())
