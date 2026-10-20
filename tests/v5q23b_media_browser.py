"""V5Q.23B browser proof: offline image, audio, video and font URIs in sandbox."""
import asyncio
import os
import subprocess
from pathlib import Path
from playwright.async_api import async_playwright

ROOT=Path(__file__).resolve().parents[1]
def render():
    env={**os.environ,'LUCKY_SDK_BROWSER':'1'}
    result=subprocess.run(['php',str(ROOT/'tests/v5q23b_offline_media.php')],
                          cwd=ROOT,env=env,check=True,capture_output=True,text=True)
    return result.stdout

async def main():
    html=render()
    assert '<html>' in html and 'data:image/png;base64,' in html
    assert 'data:audio/wav;base64,' in html and 'data:video/webm;base64,' in html
    assert 'data:font/woff2;base64,' in html
    checks=[];errors=[];requests=[]
    def ck(name,result):checks.append((name,bool(result)))
    async with async_playwright() as pw:
        browser=await pw.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox'])
        page=await browser.new_page()
        page.on('pageerror',lambda err:errors.append(str(err)))
        page.on('request',lambda req:requests.append(req.url))
        await page.route('https://offline.test/',lambda r:r.fulfill(status=200,content_type='text/html',
          body='<iframe id="game" sandbox="allow-scripts" src="/view" style="width:600px;height:400px"></iframe>'
            '<script>window.events=[];window.addEventListener("message",e=>events.push(e.data));</script>'))
        await page.route('https://offline.test/view',lambda r:r.fulfill(status=200,content_type='text/html',
          headers={'Content-Security-Policy':"sandbox allow-scripts; default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; img-src data:; media-src data:; font-src data:; connect-src 'none'; form-action 'none'"},
          body=html))
        await page.goto('https://offline.test/')
        frame=page.frame_locator('#game')
        await frame.locator('#cover').wait_for()
        await page.wait_for_function("document.querySelector('#game').contentWindow!==null")
        await frame.locator('#cover').evaluate('img => img.decode()')
        ck('Image decodes completely offline',await frame.locator('#cover').evaluate('img => img.naturalWidth===1'))
        ck('Audio is embedded rather than fetched',(await frame.locator('#sound').get_attribute('src')).startswith('data:audio/wav;base64,'))
        ck('Video and poster are offline',(await frame.locator('#movie').get_attribute('src')).startswith('data:video/webm;base64,') and
           (await frame.locator('#movie').get_attribute('poster')).startswith('data:image/png;base64,'))
        ck('Font CSS is embedded in the isolated document',await frame.locator('style').first.evaluate("s => s.textContent.includes('data:font/woff2;base64,')"))
        ck('Iframe has an opaque origin',await frame.locator('body').evaluate('() => window.origin==="null"'))
        ck('SDK initialized before game script',await frame.locator('body').evaluate('() => typeof LuckyGameSDK?.onState==="function"'))
        await page.locator('#game').evaluate("frame => frame.contentWindow.postMessage({type:'lucky:state',state:{status:'active',version:1}},'*')")
        await frame.locator('body').evaluate("() => LuckyGameSDK.sendInput('buzz','one')")
        await page.wait_for_function("window.events.some(e=>e.type==='lucky:input')")
        ck('SDK can send bounded input to trusted host',await page.evaluate("() => events.some(e=>e.name==='buzz'&&e.value==='one')"))
        ck('No internet asset requests',requests==['https://offline.test/','https://offline.test/view'])
        ck('No browser script failures',not errors)
        await browser.close()
    for name,ok in checks:print(('PASS ' if ok else 'FAIL ')+name,flush=True)
    print('V5Q.23B browser:',sum(ok for _,ok in checks),'/',len(checks))
    if not all(ok for _,ok in checks):raise SystemExit(1)
if __name__=='__main__':asyncio.run(main())
