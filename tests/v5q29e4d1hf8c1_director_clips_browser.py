"""HF8C1: recorded proof video sources join the same Preview/Program Director safely."""
import asyncio
import re
from pathlib import Path
from playwright.async_api import async_playwright

ROOT = Path(__file__).resolve().parents[1]


async def main():
    proof = (ROOT / "proof.php").read_text()
    markup = re.search(r'<section id="camera-director"[\s\S]*?</section>', proof)
    assert markup
    mixer = (ROOT / "public/camera-director.js").read_text().replace("</script", "<\\/script")
    errors, checks = [], []

    def check(name, value):
        checks.append((name, bool(value)))

    async with async_playwright() as pw:
        browser = await pw.chromium.launch(executable_path="/usr/bin/chromium", headless=True, args=["--no-sandbox"])
        page = await browser.new_page(viewport={"width": 1280, "height": 900})
        page.on("pageerror", lambda e: errors.append(str(e)))
        html = f'''<base href="https://lucky.test/"><div id="camera-grid"><section class="camera-source" data-camera-id="desktop1"><strong>Desktop Camera</strong><video></video></section></div>
{markup.group(0)}<script>
window.mediaCalls=[];
window.fetch=async url=>{{
 mediaCalls.push(String(url));return {{ok:true,json:async()=>({{ok:true,clips:[
  {{id:'a'.repeat(32),claimId:'d'.repeat(32),kind:'video',title:'Winner reaction',src:'api/claim-media.php?action=stream&id='+'a'.repeat(32),trimStartMs:1000,trimEndMs:5000,durationMs:10000}},
  {{id:'b'.repeat(32),claimId:'d'.repeat(32),kind:'video',title:'Cutaway',src:'api/claim-media.php?action=stream&id='+'b'.repeat(32),trimStartMs:0,trimEndMs:3000,durationMs:3000}},
  {{id:'c'.repeat(32),claimId:'e'.repeat(32),kind:'video',title:'Other prize',src:'api/claim-media.php?action=stream&id='+'c'.repeat(32)}},
  {{id:'f'.repeat(32),claimId:'d'.repeat(32),kind:'video',title:'Bad remote',src:'https://other.test/video.mp4'}}
 ]}})}};
}};
</script><script>{mixer}</script>'''
        await page.set_content(html, wait_until="domcontentloaded")
        await page.evaluate("window.dispatchEvent(new CustomEvent('lucky:proof-open',{detail:{session:'a'.repeat(32),claim:'d'.repeat(32)}}))")
        await page.wait_for_function("document.getElementById('director-clip-count').textContent==='2'")
        check("Only eligible current-prize videos loaded", await page.locator("#director-preview-source option").count() == 4)
        check("Saved clips are listed alongside live desktop camera", await page.evaluate("LuckyCameraDirector.sources().some(s=>s.id==='camera:desktop1') && LuckyCameraDirector.sources().some(s=>s.id==='clip:'+'a'.repeat(32))"))
        await page.locator("#director-preview-source").select_option("camera:desktop1")
        await page.locator("#director-cut").click()
        await page.locator("#director-preview-source").select_option("clip:" + "a"*32)
        check("Previewing saved clip does not change Program", await page.evaluate("LuckyCameraDirector.state().program==='camera:desktop1'"))
        await page.locator("#director-wipe").click()
        check("Wipe takes saved clip to Program", await page.evaluate("LuckyCameraDirector.state().program==='clip:'+'a'.repeat(32) && LuckyCameraDirector.state().transition"))
        await page.wait_for_timeout(750)
        check("Wipe completes cleanly", not await page.evaluate("LuckyCameraDirector.state().transition"))
        await page.locator("#director-preview-source").select_option("clip:" + "b"*32)
        await page.locator("#director-dip").click()
        check("Dip transitions clip-to-clip", await page.evaluate("LuckyCameraDirector.state().program==='clip:'+'b'.repeat(32) && LuckyCameraDirector.state().transition"))
        await page.wait_for_timeout(750)
        check("Dip completes cleanly", not await page.evaluate("LuckyCameraDirector.state().transition"))
        await page.evaluate("window.dispatchEvent(new Event('lucky:proof-close'))")
        check("Proof closure clears all video sources", await page.evaluate("LuckyCameraDirector.sources().filter(s=>s.id.startsWith('clip:')).length===0"))
        check("No JavaScript exceptions", not errors)
        await browser.close()
    for name, ok in checks:
        print(("PASS " if ok else "FAIL ")+name, flush=True)
    if errors:
        print("Browser exceptions:", errors, flush=True)
    print(f"HF8C1 DIRECTOR CLIPS BROWSER: {sum(ok for _,ok in checks)}/{len(checks)}", flush=True)
    if not all(ok for _,ok in checks):
        raise SystemExit(1)


if __name__ == "__main__":
    asyncio.run(main())
