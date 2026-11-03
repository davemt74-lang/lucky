"""V5Q32F1: authorized TURN and manual viewer recovery without new capture permission."""
import asyncio
from pathlib import Path
from playwright.async_api import async_playwright

ROOT=Path(__file__).resolve().parents[1]

async def main():
    viewer=(ROOT/'public/director-view.js').read_text().replace('</script','<\\/script')
    broadcast='a'*32
    token='b'*48
    html=f'''<!doctype html><html><head><base href="https://lucky.test/"></head>
    <body data-broadcast-id="{broadcast}" data-broadcast-key="{token}" data-show-id="">
     <video id="director-view-video" muted></video>
     <button id="director-view-sound">Enable sound</button>
     <p id="director-view-status">Connecting…</p>
     <div id="director-view-ice"></div>
     <button id="director-view-retry">Retry connection</button>
     <script>
      window.requests=[];window.instances=[];
      window.fetch=async (url,opts={{}})=>{{
       const action=new URL(url,document.baseURI).searchParams.get('action');
       window.requests.push(action);
       let result={{ok:true}};
       if(action==='watch')result.ice={{relayEnabled:true,iceServers:[
        {{urls:'stun:stun.l.google.com:19302'}},
        {{urls:['turn:relay.example.test:3478'],username:'1234567890:test',credential:'temp-hmac'}}
       ]}};
       if(action==='poll')result.answer='v=0\\r\\n';
       return {{ok:true,status:200,json:async()=>result}};
      }};
      window.RTCPeerConnection=class {{
       constructor(config){{this.config=config;this.connectionState='new';this.iceConnectionState='checking';
        this.iceGatheringState='complete';window.instances.push(this);}}
       addTransceiver(){{}}
       close(){{this.connectionState='closed';}}
       createOffer(){{return {{type:'offer',sdp:'v=0\\r\\n'}};}}
       async setLocalDescription(desc){{this.localDescription=desc;}}
       async setRemoteDescription(){{}}
      }};
     </script><script>{viewer}</script></body></html>'''
    errors=[]
    async with async_playwright() as play:
        browser=await play.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox'])
        page=await browser.new_page()
        page.on('pageerror',lambda err:errors.append(str(err)))
        await page.set_content(html,wait_until='domcontentloaded')
        await page.wait_for_function('window.instances.length===1')
        assert await page.evaluate("window.instances[0].config.iceServers.length") == 2
        print('PASS viewer receives authorized STUN and TURN ICE configuration',flush=True)
        assert await page.locator('#director-view-ice').inner_text() == 'Relay available · negotiating'
        print('PASS viewer distinguishes relay-available diagnostic',flush=True)
        await page.evaluate("""() => {
         const p=window.instances[0];p.connectionState='failed';p.onconnectionstatechange();
        }""")
        await page.wait_for_function("document.querySelector('#director-view-status').textContent.includes('Retrying')")
        print('PASS failed WebRTC connection schedules retry',flush=True)
        await page.locator('#director-view-retry').click()
        await page.wait_for_function('window.instances.length===2')
        assert await page.evaluate("window.requests.filter(v=>v==='watch').length") >= 2
        print('PASS Retry button creates fresh authorized connection',flush=True)
        await page.evaluate("""() => {
         const p=window.instances[1];p.connectionState='connected';p.iceConnectionState='connected';
         p.onconnectionstatechange();p.oniceconnectionstatechange();
        }""")
        assert 'LIVE' in await page.locator('#director-view-status').inner_text()
        assert 'ICE connected' in await page.locator('#director-view-ice').inner_text()
        print('PASS recovery updates Program and ICE health',flush=True)
        assert not errors,errors
        print('V5Q32F1 BROWSER: 5/5',flush=True)
        await browser.close()

if __name__=='__main__':
    asyncio.run(main())
