"""HF8C4: independent Admin Director selects recording sessions and owns publishing."""
import asyncio
import re
from pathlib import Path
from playwright.async_api import async_playwright

ROOT = Path(__file__).resolve().parents[1]


async def main():
    page_source = (ROOT / 'director.php').read_text()
    markup = re.search(r'<main class="director-studio-shell">[\s\S]*?</main>', page_source)
    assert markup, 'Standalone Camera Director page missing'
    scripts = [(ROOT / 'public' / name).read_text().replace('</script', '<\\/script')
               for name in ('camera-director.js', 'director-scenes.js', 'director-publish.js', 'director-studio.js')]
    prelude = r"""
    window.permissionCalls=0;window.sceneWrites=[];window.broadcastWrites=[];window.sceneData=[];window.cameraPresent=true;window.mockTake=null;
    const a='a'.repeat(32),b='b'.repeat(32),clip='d'.repeat(32);
    const rows=[{id:a,title:'Birthday live event',type:'live_event',status:'active'},
                {id:b,title:'Production shoot',type:'production',status:'draft'}];
    window.Lucky={getMe:async()=>({csrf:'signed-csrf-token'})};
    window.fetch=async (input,opts={})=>{
      const url=new URL(String(input),'https://lucky.test/');
      const action=url.searchParams.get('action');
      const body=opts.body?JSON.parse(opts.body):{};
      let result={ok:true};
      if(url.pathname.endsWith('/recording-sessions.php')){
        if(action==='list')result={ok:true,sessions:rows};
        else if(action==='detail')result={ok:true,session:rows.find(x=>x.id===url.searchParams.get('id')),
          takes:window.mockTake?[window.mockTake]:[],sources:[],assets:[]};
        else if(action==='take_start'){window.mockTake={id:'9'.repeat(32),status:'recording',take_index:1,started_at:'2026-10-27 18:00:00'};result={ok:true,take:window.mockTake,serverTimeMs:Date.now()};}
        else if(action==='take_stop'){window.mockTake=null;result={ok:true};}
        else if(action==='media_list')result={ok:true,media:url.searchParams.get('id')===a?
          [{id:clip,title:'Uploaded phone shot',kind:'video',mime:'video/webm',duration_ms:9000}]:[]};
      }else if(url.pathname.endsWith('/director-scenes.php')){
        if(action==='list')result={ok:true,scenes:window.sceneData.filter(x=>x.recordingSession===url.searchParams.get('recordingSession'))};
        if(action==='save'){window.sceneWrites.push(body);window.sceneData.push({...body,id:'e'.repeat(32)});result={ok:true,scene:body};}
      }else if(url.pathname.endsWith('/director-live.php')){
        if(action==='publish'){window.broadcastWrites.push(body);result={ok:true,id:'f'.repeat(32),key:'c'.repeat(48),visibility:body.visibility};}
        else if(action==='host_list')result={ok:true,offers:[]};
      }
      return {ok:true,status:200,json:async()=>result};
    };
    Object.defineProperty(navigator,'mediaDevices',{configurable:true,value:{
      enumerateDevices:async()=>window.cameraPresent?[{kind:'videoinput',deviceId:'mock-camera',label:'Test Webcam'}]:[],
      addEventListener:(name,fn)=>{if(name==='devicechange')window.devicechangeHandler=fn;},
      getUserMedia:async constraints=>{window.permissionCalls++;window.requestedConstraints=constraints;
        const canvas=document.createElement('canvas');canvas.width=64;canvas.height=36;return canvas.captureStream(15);}
    }});
    """
    checks, errors = [], []

    def check(name, value):
        checks.append((name, bool(value)))

    async with async_playwright() as pw:
        browser = await pw.chromium.launch(executable_path='/usr/bin/chromium', headless=True, args=['--no-sandbox'])
        page = await browser.new_page(viewport={'width': 1280, 'height': 960})
        page.on('pageerror', lambda error: errors.append(str(error)))
        html = '<base href="https://lucky.test/">' + markup.group(0) + '<script>' + prelude + '</script>' + ''.join('<script>'+src+'</script>' for src in scripts)
        await page.set_content(html, wait_until='domcontentloaded')
        await page.wait_for_function("document.getElementById('director-studio-session').options.length===3")
        check('Studio loads existing recording groups', await page.locator('#director-studio-session option').count() == 3)
        check('No camera or microphone prompt before action', await page.evaluate('permissionCalls===0'))
        await page.locator('#director-studio-session').select_option('a'*32)
        await page.locator('#director-studio-open').click()
        await page.wait_for_function("LuckyDirectorStudio.state().recordingSession==='a'.repeat(32)")
        await page.wait_for_function("LuckyCameraDirector.sources().some(s=>s.id==='clip:'+'d'.repeat(32))")
        check('Phone upload from recording bin appears as Director source', await page.evaluate("LuckyCameraDirector.sources().some(s=>s.id==='clip:'+'d'.repeat(32))"))
        await page.locator('#director-studio-camera').select_option('mock-camera')
        await page.locator('#director-studio-add-camera').click()
        await page.wait_for_function('LuckyDirectorStudio.state().cameras===1')
        check('Local camera video is permission-gated and mic stays off', await page.evaluate('permissionCalls===1 && requestedConstraints.audio===false'))
        check('Connected camera displays LIVE state', await page.locator('.camera-source[data-status="connected"] .director-camera-state').inner_text() == '● LIVE')
        await page.locator('.director-camera-name').fill('Stage left')
        await page.locator('.director-camera-name').press('Tab')
        check('Camera can be named without reacquiring permission', await page.evaluate("LuckyDirectorStudio.cameras()[0].label==='Stage left' && permissionCalls===1"))
        await page.locator('#director-studio-take-start').click()
        await page.wait_for_function("document.querySelector('.camera-source').dataset.status==='recording'")
        check('Group take activates red REC indicator', await page.locator('.camera-source .director-camera-state').inner_text() == '● REC')
        await page.locator('#director-studio-take-stop').click()
        await page.wait_for_function("document.querySelector('.camera-source').dataset.status==='connected'")
        check('Stopping take restores LIVE state', await page.locator('.camera-source .director-camera-state').inner_text() == '● LIVE')
        await page.locator('#director-studio-open').click()
        check('Reopening the same session keeps live cameras connected', await page.evaluate("LuckyDirectorStudio.state().cameras===1 && permissionCalls===1"))
        await page.locator('#director-studio-refresh').click()
        check('Refreshing session list preserves the opened session', await page.evaluate("LuckyDirectorStudio.state().recordingSession==='a'.repeat(32) && document.getElementById('director-studio-session').value==='a'.repeat(32)"))
        await page.wait_for_function("LuckyCameraDirector.sources().some(s=>s.id.startsWith('camera:'))")
        camera_id = await page.evaluate("LuckyCameraDirector.sources().find(s=>s.id.startsWith('camera:')).id")
        await page.locator('#director-preview-source').select_option(camera_id)
        await page.locator('#director-cut').click()
        check('Standalone Camera Director takes desktop camera to Program', await page.evaluate("id=>LuckyCameraDirector.state().program===id", camera_id))
        await page.locator('#director-scene-name').fill('Studio scene')
        await page.locator('#director-scene-save').click()
        await page.wait_for_function('sceneWrites.length===1')
        check('Saved scene is bound to recording session, not prize claim', await page.evaluate("sceneWrites[0].recordingSession==='a'.repeat(32) && !sceneWrites[0].claim"))
        await page.locator('#director-publish-consent').check()
        await page.locator('#director-publish').click()
        await page.wait_for_function('broadcastWrites.length===1')
        check('Live View publishing uses the selected recording session', await page.evaluate("broadcastWrites[0].recordingSession==='a'.repeat(32) && !broadcastWrites[0].claim"))
        await page.locator('#director-studio-session').select_option('b'*32)
        await page.locator('#director-studio-open').click()
        await page.wait_for_function("LuckyDirectorStudio.state().recordingSession==='b'.repeat(32)")
        check('Switching sessions ends prior broadcast and Program', await page.evaluate("!LuckyDirectorPublisher.state().onAir && !LuckyCameraDirector.state().program"))
        check('Switching recording sessions releases local camera permission', await page.evaluate('LuckyDirectorStudio.state().cameras===0'))
        await page.locator('#director-studio-camera').select_option('mock-camera')
        await page.locator('#director-studio-add-camera').click()
        await page.wait_for_function('LuckyDirectorStudio.state().cameras===1')
        await page.evaluate('cameraPresent=false;devicechangeHandler()')
        await page.wait_for_function('LuckyDirectorStudio.state().cameras===0')
        check('Unplugged enumerated camera is released without another permission prompt', await page.evaluate('permissionCalls===2 && LuckyDirectorStudio.state().cameras===0'))
        await page.evaluate("rows[1].status='completed'")
        await page.locator('#director-studio-refresh').click()
        check('Completed sessions immediately close capture controls', await page.evaluate("LuckyDirectorStudio.state().recordingSession===''"))
        check('No unhandled browser errors', not errors)
        await browser.close()
    for name, ok in checks:
        print(('PASS ' if ok else 'FAIL ') + name, flush=True)
    if errors:
        print('BROWSER ERRORS:', errors, flush=True)
    print(f'HF8C4 STANDALONE DIRECTOR BROWSER: {sum(ok for _,ok in checks)}/{len(checks)}', flush=True)
    if not all(ok for _, ok in checks):
        raise SystemExit(1)


if __name__ == '__main__':
    asyncio.run(main())
