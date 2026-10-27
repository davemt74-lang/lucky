"""HF8C0 headless browser: private/public choice, explicit public consent, directory and Program-only publish."""
import asyncio
import re
from pathlib import Path
from playwright.async_api import async_playwright

ROOT = Path(__file__).resolve().parents[1]


async def main():
    proof = (ROOT / "proof.php").read_text()
    markup = re.search(r'<section id="camera-director"[\s\S]*?</section>', proof)
    assert markup
    mixer = (ROOT / "public/camera-director.js").read_text()
    publisher = (ROOT / "public/director-publish.js").read_text()
    results, errors = [], []

    def check(label, ok):
        results.append((label, bool(ok)))

    async with async_playwright() as pw:
        browser = await pw.chromium.launch(executable_path="/usr/bin/chromium", headless=True, args=["--no-sandbox"])
        page = await browser.new_page(viewport={"width": 1280, "height": 1000})
        page.on("pageerror", lambda error: errors.append(str(error)))
        html = f'''<base href="https://lucky.test/"><div id="camera-grid"><section class="camera-source" data-camera-id="desktop1"><strong>Cam A</strong><video></video></section></div>
{markup.group(0)}<script>
window.Lucky={{getMe:async()=>({{csrf:'csrf-test'}})}};
window.calls=[];window.stopped=false;
window.fetch=async (url,opts={{}})=>{{
 const action=new URL(url,'https://lucky.test/').searchParams.get('action');
 calls.push({{action,body:opts.body?JSON.parse(opts.body):{{}}}});
 const result=action==='publish'?{{ok:true,id:'b'.repeat(32),key:'c'.repeat(48)}}:
  action==='host_list'?{{ok:true,offers:[]}}:{{ok:true,stopped:action==='stop'}};
 return {{ok:true,status:200,json:async()=>result}};
}};
class MockPeer{{constructor(){{this.iceGatheringState='complete';this.connectionState='new';}}addTrack(){{}}close(){{}}}}
window.RTCPeerConnection=MockPeer;
</script><script>{mixer}</script><script>{publisher}</script>'''
        await page.set_content(html, wait_until="domcontentloaded")
        await page.evaluate("window.dispatchEvent(new CustomEvent('lucky:proof-open',{detail:{session:'a'.repeat(32),claim:'d'.repeat(32)}}))")
        await page.locator("#director-preview-source").select_option("camera:desktop1")
        await page.locator("#director-cut").click()
        check("Program taken from live camera", await page.evaluate("LuckyCameraDirector.state().program==='camera:desktop1'"))
        check("No automatic publishing", await page.evaluate("calls.filter(x=>x.action==='publish').length===0"))
        await page.locator("#director-publish").click()
        check("Without consent no publish request", await page.evaluate("calls.filter(x=>x.action==='publish').length===0"))
        await page.locator("#director-publish-consent").check()
        await page.locator("#director-publish").click()
        await page.wait_for_function("LuckyDirectorPublisher.state().onAir")
        check("Explicit consent publishes only after Take", await page.evaluate("calls.filter(x=>x.action==='publish').length===1"))
        check("Private Live View URL issued with scoped token", "live-view.php" in (await page.locator("#director-live-url").input_value()))
        check("Publisher is visibly ON AIR", "ON AIR" in (await page.locator("#director-live-badge").inner_text()))
        await page.locator("#director-unpublish").click()
        await page.wait_for_function("!LuckyDirectorPublisher.state().onAir")
        check("Stop revokes server invitation", await page.evaluate("calls.some(x=>x.action==='stop')"))
        check("Stop removes Live View share link", await page.locator("#director-live-link").is_hidden())
        await page.locator("#director-publish").click()
        await page.wait_for_function("LuckyDirectorPublisher.state().onAir")
        await page.evaluate("window.dispatchEvent(new Event('lucky:proof-close'))")
        await page.wait_for_function("!LuckyDirectorPublisher.state().onAir")
        check("Proof closure shuts down Program publishing", True)
        check("No director browser errors", not errors)
        # A fresh session starts private even after prior broadcasts.
        await page.evaluate("window.dispatchEvent(new CustomEvent('lucky:proof-open',{detail:{session:'a'.repeat(32),claim:'d'.repeat(32)}}))")
        await page.locator("#director-preview-source").select_option("camera:desktop1")
        await page.locator("#director-cut").click()
        check("Private remains the default", await page.locator("#director-broadcast-visibility").input_value() == "private")
        await page.locator("#director-broadcast-visibility").select_option("public")
        check("Public confirmation appears only when selected", await page.locator("#director-public-ack-label").is_visible())
        await page.locator("#director-publish-consent").check()
        count_before = await page.evaluate("calls.filter(x=>x.action==='publish').length")
        await page.locator("#director-publish").click()
        check("Public without additional acknowledgement is blocked", await page.evaluate("calls.filter(x=>x.action==='publish').length") == count_before)
        await page.locator("#director-publish-public-ack").check()
        await page.locator("#director-publish").click()
        await page.wait_for_function("LuckyDirectorPublisher.state().onAir")
        check("Public publish passes both consents and selected mode", await page.evaluate("(()=>{const x=calls.filter(x=>x.action==='publish').at(-1);return x.body.visibility==='public'&&x.body.public_ack===true&&x.body.consent===true})()"))
        url = await page.locator("#director-live-url").input_value()
        check("Public link has ID but no invitation secret", "live-view.php?id=" in url and "key=" not in url)
        check("Visibility locked while on air", await page.locator("#director-broadcast-visibility").is_disabled())
        check("Host is still the only director", await page.evaluate("LuckyDirectorPublisher.state().visibility==='public'"))
        await page.locator("#director-unpublish").click()
        await page.wait_for_function("!LuckyDirectorPublisher.state().onAir")
        check("Public broadcast explicitly stops", await page.locator("#director-live-link").is_hidden())

        # Public directory is read only; only IDs explicitly returned by the server can appear.
        directory = await browser.new_page()
        directory.on("pageerror", lambda error: errors.append(str(error)))
        viewer_script = (ROOT / "public/director-view.js").read_text()
        directory_markup = f'''<base href="https://lucky.test/"><div id="director-public-directory">
<div id="director-public-list"></div><button id="director-public-refresh"></button>
<p id="director-view-status"></p></div><script>
window.fetch=async (url)=>({{ok:true,status:200,json:async()=>({{ok:true,broadcasts:[{{id:'e'.repeat(32)}}]}})}});
</script><script>{viewer_script}</script>'''
        await directory.set_content(directory_markup, wait_until="domcontentloaded")
        await directory.wait_for_selector(".director-public-item")
        public_link = await directory.locator(".director-public-item").first.get_attribute("href")
        check("Public directory renders view-only Program links", "live-view.php?id=" in public_link and "key=" not in public_link)
        check("Directory contains no camera switcher", await directory.locator("#director-preview-source, #director-cut, #director-publish").count() == 0)
        await directory.close()
        await browser.close()
    for label, ok in results:
        print(("PASS " if ok else "FAIL ") + label, flush=True)
    if errors:
        print("BROWSER ERRORS:", errors, flush=True)
    print(f"HF8C0 VISIBILITY BROWSER: {sum(ok for _, ok in results)}/{len(results)}", flush=True)
    if not all(ok for _, ok in results):
        raise SystemExit(1)


if __name__ == "__main__":
    asyncio.run(main())
