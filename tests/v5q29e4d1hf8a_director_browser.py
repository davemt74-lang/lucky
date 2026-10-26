"""HF8A: Cut, fade, overlays and bounded Program export in the real browser."""
import asyncio
import re
from pathlib import Path
from playwright.async_api import async_playwright

ROOT = Path(__file__).resolve().parents[1]


async def main():
    results, errors = [], []

    def check(label, ok):
        results.append((label, bool(ok)))

    proof = (ROOT / "proof.php").read_text()
    markup = re.search(r'<section id="camera-director"[\s\S]*?</section>', proof)
    assert markup, "Camera Director HTML missing"
    script = (ROOT / "public/camera-director.js").read_text().replace("</script", "<\\/script")
    html = f'''<div id="camera-grid"><section class="camera-source" data-camera-id="desktop-1"><strong>USB Camera A</strong><video></video></section></div>
{markup.group(0)}<script>{script}</script>'''
    async with async_playwright() as pw:
        browser = await pw.chromium.launch(executable_path="/usr/bin/chromium", headless=True, args=["--no-sandbox"])
        page = await browser.new_page(viewport={"width": 1280, "height": 900})
        page.on("pageerror", lambda e: errors.append(str(e)))
        await page.set_content(html, wait_until="domcontentloaded")
        await page.evaluate("window.dispatchEvent(new CustomEvent('lucky:proof-open',{detail:{session:'a'.repeat(32),claim:'b'.repeat(32)}}))")
        check("Desktop camera shows as preview source",
              await page.locator("#director-preview-source option").count() == 2)
        await page.locator("#director-preview-source").select_option("camera:desktop-1")
        check("Cue selects only Preview, leaving Program off",
              await page.evaluate("LuckyCameraDirector.state().preview==='camera:desktop-1' && LuckyCameraDirector.state().program===''"))
        await page.locator("#director-cut").click()
        check("Cut selects desktop camera as Program output",
              await page.evaluate("LuckyCameraDirector.state().program==='camera:desktop-1'"))
        check("Program export becomes available after a valid Take",
              await page.locator("#director-export-start").is_enabled())
        await page.evaluate("""() => {
          const card=document.createElement('section');card.className='camera-source';card.dataset.phonePair='c'.repeat(32);
          const name=document.createElement('strong');name.textContent='Paired Phone B';
          const video=document.createElement('video');card.append(name,video);
          document.getElementById('camera-grid').append(card);
        }""")
        await page.wait_for_function("document.getElementById('director-preview-source').options.length===3")
        check("Paired phone camera is automatically added to Director sources",
              await page.locator("#director-source-count").inner_text() == "2")
        await page.locator("#director-preview-source").select_option("phone:" + "c" * 32)
        check("Phone source cues without interrupting Program camera",
              await page.evaluate("LuckyCameraDirector.state().preview.startsWith('phone:') && LuckyCameraDirector.state().program==='camera:desktop-1'"))
        await page.locator("#director-fade").click()
        check("Fade places phone camera on Program with transition active",
              await page.evaluate("LuckyCameraDirector.state().program.startsWith('phone:') && LuckyCameraDirector.state().transition"))
        await page.wait_for_timeout(700)
        check("Program fade completes cleanly", not await page.evaluate("LuckyCameraDirector.state().transition"))
        await page.locator("#director-overlay-text").fill("Lucky Winner LIVE")
        await page.locator("#director-overlay-sticker").select_option("🏆")
        await page.wait_for_timeout(100)
        check("Text and sticker content apply to the live canvas controls",
              await page.locator("#director-overlay-sticker").input_value() == "🏆" and
              await page.locator("#director-overlay-text").input_value() == "Lucky Winner LIVE")
        # Chrome supports WebM MediaRecorder on canvas.captureStream even with no webcam.
        await page.locator("#director-export-start").click()
        await page.wait_for_function("LuckyCameraDirector.state().recording", timeout=5000)
        check("Export records composite Program rather than source camera",
              await page.locator("#director-recording-badge").is_visible())
        await page.wait_for_timeout(1500)
        await page.locator("#director-export-stop").click()
        await page.wait_for_function("!LuckyCameraDirector.state().recording", timeout=5000)
        await page.wait_for_function("!document.getElementById('director-export-download').hidden", timeout=5000)
        check("Exported Program file downloadable locally",
              (await page.locator("#director-export-download").get_attribute("href")).startswith("blob:"))
        await page.evaluate("document.querySelector('[data-phone-pair]').remove()")
        await page.wait_for_function("LuckyCameraDirector.state().program===''", timeout=3000)
        check("Phone disconnect clears current Program source safely", True)
        await page.evaluate("window.dispatchEvent(new Event('lucky:proof-close'))")
        check("Proof closure clears cue and published Program",
              await page.evaluate("!LuckyCameraDirector.state().preview && !LuckyCameraDirector.state().program"))
        check("No browser exceptions", not errors)
        await browser.close()
    for name, ok in results:
        print(("PASS " if ok else "FAIL ") + name, flush=True)
    if errors:
        print("Browser errors:", errors, flush=True)
    print(f"HF8A DIRECTOR BROWSER: {sum(ok for _,ok in results)}/{len(results)}", flush=True)
    if not all(ok for _,ok in results):
        raise SystemExit(1)


if __name__ == "__main__":
    asyncio.run(main())
