"""HF8C7 Director grouped media: camera + Program + offline reload upload queue."""
import asyncio
from pathlib import Path
from playwright.async_api import async_playwright

ROOT = Path(__file__).resolve().parents[1]
SESSION = "a" * 32
TAKE = "b" * 32


async def main():
    source = (ROOT / "public/director-recording-sync.js").read_text().replace("</script", "<\\/script")
    mock = """
      window.offline = true;
      Object.defineProperty(navigator,'onLine',{configurable:true,get(){return !window.offline;}});
      window.uploads = [];
      window.Lucky = {getMe:async()=>({csrf:'csrf-test'})};
      window.fetch = async(url,opts)=>{
        if (!String(url).includes('api/recording-host.php')) throw Error('Wrong API');
        let form=opts.body,record={};
        for(let [name,value] of form.entries())record[name]=value;
        window.uploads.push({record,token:opts.headers['X-CSRF-Token'],path:String(url)});
        return {ok:true,status:201,json:async()=>({ok:true,asset:{id:'d'.repeat(32)}})};
      };
      const stream={getVideoTracks:()=>[{readyState:'live'}]};
      window.LuckyDirectorStudio={cameras:()=>[{id:'cam_test',label:'Desktop Cam',stream}]};
      class TestRecorder{
        static isTypeSupported(){return true;}
        constructor(s,o){this.stream=s;this.mimeType='video/webm';this.state='inactive';}
        start(){this.state='recording';}
        stop(){
          if(this.state!=='recording')return;
          this.state='inactive';
          this.ondataavailable?.({data:new Blob([new Uint8Array(400)],{type:'video/webm'})});
          this.onstop?.();
        }
      }
      window.MediaRecorder=TestRecorder;
      window.LuckyCameraDirector={
        recording:false,
        state(){return {recording:this.recording};},
        startExport(meta){this.recording=true;this.meta=meta;this.start=Date.now();return true;},
        stopExport(){if(!this.recording)return;this.recording=false;
          window.dispatchEvent(new CustomEvent('lucky:director-program-captured',{detail:{
            ...this.meta,startMs:this.start,endMs:Date.now(),
            blob:new Blob([new Uint8Array(300)],{type:'video/webm'})
          }}));
        },
        refreshClips(){}
      };
      window.LuckyDirectorWorkspace={refreshTimeline(){}};
    """
    html = f"""<!doctype html><html><body>
      <button id="director-studio-take-start">Start</button>
      <p id="director-studio-take-status"></p>
      <div class="camera-source" data-camera-id="cam_test"></div>
      <script>{mock}</script><script>{source}</script>
    </body></html>"""
    errors = []
    async with async_playwright() as pw:
        browser = await pw.chromium.launch(headless=True)
        context = await browser.new_context()
        await context.route("https://lucky.test/director.php", lambda route: route.fulfill(
            status=200, content_type="text/html", body=html
        ))
        page = await context.new_page()
        page.on("pageerror", lambda error: errors.append(str(error)))
        await page.goto("https://lucky.test/director.php", wait_until="load")
        await page.evaluate("""([s,t])=>window.dispatchEvent(new CustomEvent('lucky:director-take-start',{
            detail:{recordingSession:s,takeId:t,clockOffsetMs:250}}))""", [SESSION, TAKE])
        assert await page.locator(".director-source-rec").count() == 1, "Camera must show recording state"
        await page.evaluate("""([s,t])=>window.dispatchEvent(new CustomEvent('lucky:director-take-stop',{
            detail:{recordingSession:s,takeId:t}}))""", [SESSION, TAKE])
        await page.wait_for_timeout(500)
        assert await page.locator(".director-source-rec").count() == 0, "Recording status must clear"
        assert await page.evaluate("window.uploads.length") == 0, "Offline capture must not upload"
        await page.reload(wait_until="load")
        await page.evaluate("window.offline=false;window.dispatchEvent(new Event('online'))")
        await page.wait_for_function("window.uploads.length === 2", timeout=12000)
        uploaded = await page.evaluate("""window.uploads.map(x=>({
            source:x.record.sourceKey,session:x.record.session,take:x.record.takeId,
            type:x.record.media.type,token:x.token,offset:x.record.clockOffsetMs
        }))""")
        assert {r["source"] for r in uploaded} == {"cam_test", "program"}, uploaded
        assert all(r["session"] == SESSION and r["take"] == TAKE for r in uploaded)
        assert all(r["type"] == "video/webm" and r["token"] == "csrf-test" for r in uploaded)
        assert all(r["offset"] == "250" for r in uploaded), uploaded
        await page.reload(wait_until="load")
        await page.evaluate("window.offline=false;window.dispatchEvent(new Event('online'))")
        await page.wait_for_timeout(350)
        assert await page.evaluate("window.uploads.length") == 0, "Successful upload must be removed from persistent queue"
        assert not errors, errors
        print("HF8C7 DIRECTOR SYNC BROWSER: 9/9")
        await browser.close()


if __name__ == "__main__":
    asyncio.run(main())
