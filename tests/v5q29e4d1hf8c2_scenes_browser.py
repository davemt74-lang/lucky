"""HF8C2: persisted scene cue is private until explicit Take into Program."""
import asyncio
import re
from pathlib import Path
from playwright.async_api import async_playwright

ROOT = Path(__file__).resolve().parents[1]

async def main():
    proof = (ROOT / "proof.php").read_text()
    markup = re.search(r'<section id="camera-director"[\s\S]*?</section>', proof)
    assert markup and 'director-scene-list' in markup.group(0)
    mixer = (ROOT / "public/camera-director.js").read_text()
    scenes = (ROOT / "public/director-scenes.js").read_text()
    checks, errors = [], []
    def check(name, ok):
        checks.append((name, bool(ok)))
    async with async_playwright() as pw:
        browser = await pw.chromium.launch(executable_path="/usr/bin/chromium", headless=True, args=["--no-sandbox"])
        page = await browser.new_page(viewport={"width": 1280, "height": 1000})
        page.on("pageerror", lambda e: errors.append(str(e)))
        html = f'''<base href="https://lucky.test/"><div id="camera-grid">
<section class="camera-source" data-camera-id="desktop1"><strong>Desktop 1</strong><video></video></section>
<section class="camera-source" data-camera-id="desktop2"><strong>Desktop 2</strong><video></video></section></div>
{markup.group(0)}
<script>
window.sceneItems=[];window.sceneCalls=[];
window.Lucky={{getMe:async()=>({{csrf:'test-token'}})}};
window.fetch=async (url,opts={{}})=>{{
 const path=String(url);const action=new URL(path,'https://lucky.test/').searchParams.get('action');
 if(path.includes('claim-media.php'))return {{ok:true,status:200,json:async()=>({{ok:true,clips:[]}})}};
 const body=opts.body?JSON.parse(opts.body):{{}};sceneCalls.push({{action,body,headers:opts.headers||{{}}}});
 if(action==='save'){{
  const item={{...body,id:'f'.repeat(32)}};
  const existing=sceneItems.findIndex(s=>s.name===item.name);
  if(existing>=0)sceneItems[existing]=item;else sceneItems.push(item);
  return {{ok:true,status:200,json:async()=>({{ok:true,scene:item}})}};
 }}
 if(action==='remove'){{window.sceneItems=sceneItems.filter(s=>s.id!==body.id);return {{ok:true,status:200,json:async()=>({{ok:true,removed:true}})}};}}
 return {{ok:true,status:200,json:async()=>({{ok:true,scenes:sceneItems}})}};
}};
</script><script>{mixer}</script><script>{scenes}</script>'''
        await page.set_content(html, wait_until="domcontentloaded")
        await page.evaluate("window.dispatchEvent(new CustomEvent('lucky:proof-open',{detail:{session:'a'.repeat(32),claim:'d'.repeat(32)}}))")
        await page.locator("#director-preview-source").select_option("camera:desktop1")
        await page.locator("#director-overlay-text").fill("Original")
        await page.locator("#director-cut").click()
        check("Original text taken into Program", await page.evaluate("LuckyCameraDirector.state().programGraphics.text==='Original'"))
        await page.locator("#director-preview-source").select_option("camera:desktop2")
        await page.locator("#director-overlay-text").fill("Winner <b>LIVE</b>")
        await page.locator("#director-overlay-sticker").select_option("🏆")
        check("Editing Preview graphics leaves Program unchanged", await page.evaluate("LuckyCameraDirector.state().programGraphics.text==='Original'"))
        await page.locator("#director-scene-name").fill("Winner close-up")
        await page.locator("#director-scene-transition").select_option("wipe")
        await page.locator("#director-scene-save").click()
        await page.wait_for_function("LuckyDirectorScenes.state().count===1")
        check("Scene saved through scoped server API", await page.evaluate("sceneCalls.some(x=>x.action==='save'&&x.body.session==='a'.repeat(32)&&x.body.claim==='d'.repeat(32)&&x.body.transition==='wipe')"))
        check("Scene write includes CSRF token", await page.evaluate("sceneCalls.some(x=>x.action==='save'&&x.headers['X-CSRF-Token']==='test-token')"))
        check("Save does not take Preview live", await page.evaluate("LuckyCameraDirector.state().program==='camera:desktop1'&&LuckyCameraDirector.state().programGraphics.text==='Original'"))
        await page.locator("#director-scene-list button").filter(has_text="CUE").click()
        check("Cue only sets Preview", await page.evaluate("LuckyCameraDirector.state().preview==='camera:desktop2' && LuckyCameraDirector.state().program==='camera:desktop1'"))
        await page.locator("#director-scene-list button").filter(has_text="TAKE").click()
        check("Take commits camera and graphic together", await page.evaluate("LuckyCameraDirector.state().program==='camera:desktop2' && LuckyCameraDirector.state().programGraphics.text==='Winner <b>LIVE</b>' && LuckyCameraDirector.state().programGraphics.sticker==='🏆'"))
        check("Saved wipe transition applied on Take", await page.evaluate("LuckyCameraDirector.state().transition"))
        check("Scene name is safely displayed as text", await page.locator("#director-scene-list .director-scene-detail strong").inner_text() == "Winner close-up")
        await page.evaluate("window.dispatchEvent(new Event('lucky:proof-close'))")
        await page.evaluate("window.dispatchEvent(new CustomEvent('lucky:proof-open',{detail:{session:'a'.repeat(32),claim:'d'.repeat(32)}}))")
        await page.wait_for_function("LuckyDirectorScenes.state().count===1")
        check("Scene recovers after proof reopening", await page.locator("#director-scenes-count").inner_text() == "1/16 scenes")
        await page.evaluate("document.querySelector('[data-camera-id=desktop2]').remove();window.dispatchEvent(new Event('lucky:cameras-refreshed'))")
        await page.wait_for_function("document.querySelector('#director-scene-list button').disabled")
        check("Offline source cannot be taken live", await page.locator("#director-scene-list button").filter(has_text="TAKE").is_disabled())
        await page.evaluate("window.dispatchEvent(new Event('lucky:proof-close'))")
        check("Proof close clears active scene context", await page.evaluate("!LuckyDirectorScenes.state().active && LuckyDirectorScenes.state().count===0"))
        check("No browser exceptions", not errors)
        await browser.close()
    for label, ok in checks:
        print(("PASS " if ok else "FAIL ")+label, flush=True)
    if errors:
        print("BROWSER ERRORS:", errors, flush=True)
    print(f"HF8C2 SCENES BROWSER: {sum(ok for _,ok in checks)}/{len(checks)}", flush=True)
    if not all(ok for _,ok in checks):
        raise SystemExit(1)

if __name__ == "__main__":
    asyncio.run(main())
