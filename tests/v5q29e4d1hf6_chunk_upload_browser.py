"""HF6: Large proof videos upload in resumable pieces; never lose local recording on failure."""
import asyncio
import re
from pathlib import Path
from playwright.async_api import async_playwright

ROOT = Path(__file__).resolve().parents[1]
html = (ROOT / "controller.php").read_text().split("?>", 1)[1]
for filename in ("public/voice-commands.js", "public/phone-controller.js", "public/phone-capture.js"):
    script = (ROOT / filename).read_text().replace("</script", "<\\/script")
    html = re.sub(r'<script src="' + re.escape(filename) + r'[^>]+></script>', lambda _: "<script>" + script + "</script>", html)
html = html.replace("</head>", '<base href="https://lucky.test/"><style>' + (ROOT / "public/platform.css").read_text() + "</style></head>", 1)
html = html.replace("</head>", '''<script>
window.__uploads=[];window.__commands=[];window.__failIndex=1;window.__failed=false;
window.fetch=async(url,opts={})=>{
  const u=String(url),action=new URL(u,'https://lucky.test/').searchParams.get('action');
  if(u.includes('phone-media.php')){
    const form=opts.body,entries=[...form.entries()],fields=Object.fromEntries(entries.filter(([key])=>key!=='chunk'));
    const item=form.get('chunk');
    __uploads.push({action:fields.uploadAction,index:fields.index??null,total:fields.total??null,bytes:item?.size||0,
      fileName:item?.name||'',token:opts.headers?.['X-Controller-Token'],credentials:opts.credentials,
      claim:fields.claim,session:fields.session,nonce:fields.nonce});
    if(fields.uploadAction==='chunk'&&Number(fields.index)===__failIndex&&!__failed){__failed=true;throw Error('Simulated Wi-Fi drop');}
    return new Response(JSON.stringify({ok:true,upload:{next:Number(fields.index)+1,total:Number(fields.total)},
      clip:{id:'f'.repeat(32)}}));
  }
  if(action==='status')return new Response(JSON.stringify({ok:true,status:'paired',claims:[],
    game:{id:'a'.repeat(32),status:'active',canSpin:true,spinCount:0,claimedCount:1,remaining:5},
    proof:{active:true,sessionId:'a'.repeat(32),claimId:'b'.repeat(32),phoneCameraAllowed:true,consent:true,phase:'live',savedCount:0}}));
  if(action==='command'){__commands.push(JSON.parse(opts.body||'{}').command);return new Response(JSON.stringify({ok:true}));}
  return new Response(JSON.stringify({ok:true}));
};
</script></head>''', 1)

async def main():
    checks = []
    def check(name, condition):
        checks.append((name, bool(condition)))
    errors = []
    async with async_playwright() as pw:
        browser = await pw.chromium.launch(executable_path="/usr/bin/chromium", headless=True, args=["--no-sandbox"])
        page = await browser.new_page(viewport={"width": 375, "height": 780})
        page.on("pageerror", lambda exc: errors.append(str(exc)))
        await page.evaluate("""() => {
          const row=JSON.stringify({id:'c'.repeat(32),token:'d'.repeat(64)});
          const map=new Map([['lucky-phone-controller-v5p',row]]);
          Object.defineProperty(window,'sessionStorage',{configurable:true,value:{
            getItem:k=>map.get(k)||null,setItem:(k,v)=>map.set(k,v),removeItem:k=>map.delete(k)
          }});
        }""")
        await page.set_content(html, wait_until="domcontentloaded")
        await page.wait_for_function("!document.getElementById('phone-capture-open').disabled", timeout=5000)
        await page.locator("#phone-capture-open").click()
        payload = b"video proof bytes" * 70000  # > 1 MB, must transfer in multiple requests
        await page.locator("#phone-capture-file").set_input_files({"name": "my-proof.mp4", "mimeType": "video/mp4", "buffer": payload})
        check("Video selected, Send enabled", await page.locator("#phone-capture-send").is_enabled())
        await page.locator("#phone-capture-send").click()
        await page.wait_for_function("document.getElementById('phone-capture-status').textContent.includes('tap Send to retry')", timeout=5000)
        check("Failed chunk keeps original phone recording", await page.locator("#phone-capture-send").is_visible())
        check("Network interruption displays retriable upload status", await page.locator("#phone-capture-status").is_visible())
        await page.locator("#phone-capture-send").click()
        await page.wait_for_function("document.getElementById('phone-capture-status').textContent.startsWith('✓')", timeout=8000)
        check("Video reaches Video Studio after retry", await page.locator("#phone-capture-send").is_hidden())
        uploaded = await page.evaluate("window.__uploads")
        pieces = [row for row in uploaded if row["action"] == "chunk"]
        commits = [row for row in uploaded if row["action"] == "commit"]
        total = (len(payload) + 524287) // 524288
        check("Recorded video split into exact 512 KB pieces", all(row["bytes"] <= 524288 for row in pieces) and len(pieces) == total + 2)
        check("Missing piece retried without changing upload receipt", len({row["nonce"] for row in uploaded}) == 1)
        check("Finalization only occurs after all pieces acknowledged", len(commits) == 1 and uploaded[-1]["action"] == "commit")
        check("All chunks bound to same paired phone and prize", all(row["token"] == "d"*64 and row["credentials"] == "omit"
              and row["claim"] == "b"*32 and row["session"] == "a"*32 for row in uploaded))
        check("Chunk indexes restart at 0 and progress after retry", [int(row["index"]) for row in pieces] == [0, 1, 0, 1, 2])
        check("No browser exceptions", not errors)
        await browser.close()
    for name, success in checks:
        print(("PASS " if success else "FAIL ") + name, flush=True)
    if errors:
        print("Browser exceptions:", errors, flush=True)
    print(f"HF6 CHUNKED PHONE PROOF BROWSER: {sum(ok for _, ok in checks)}/{len(checks)}", flush=True)
    if not all(ok for _, ok in checks):
        raise SystemExit(1)

if __name__ == "__main__":
    asyncio.run(main())
