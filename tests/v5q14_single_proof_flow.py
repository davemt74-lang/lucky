"""Browser regression: game awards route to the ONE desktop proof editor; phones switch control surfaces."""
import asyncio
from pathlib import Path
from playwright.async_api import async_playwright
from v5q6_proof_browser import html_page, MOCK, SID, CID, BASE

async def main():
    checks=[]; errors=[]
    def ck(name,ok):checks.append((name,bool(ok)))
    async with async_playwright() as pw:
        browser=await pw.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox'])
        page=await browser.new_page(viewport={'width':1440,'height':860})
        page.set_default_timeout(6000)
        page.on('pageerror',lambda e:errors.append(str(e)))
        await page.route('**/proof.php?*', lambda route:route.fulfill(status=200,content_type='text/html',body='<html><body id="proof-route-confirmed">Proof page</body></html>'))
        game_js=(BASE/'public/game.js').read_text().replace("location.assign('proof.php?session='","window.__captureNavigation('proof.php?session='")
        source=html_page('game.php',game_js,MOCK+'\nwindow.__captureNavigation=path=>{window.__proofNav=path;};')
        await page.set_content(source,wait_until='domcontentloaded')
        await page.wait_for_function("document.getElementById('spin-game').disabled===false")
        ck('game page does not embed a duplicate proof dialog',await page.locator('#win-dialog').count()==0)
        ck('existing Edit Proof link points to unified claim page',f'claim={CID}' in (await page.locator('#claim-list a[href*=proof]').first.get_attribute('href')))
        await page.evaluate("""() => {
          const old=window.Lucky.api;
          window.Lucky.api=(a,o)=>a==='spin'?
            Promise.resolve({result:{event:{type:'award',claim:{id:'CID',name:'Camera Prize',cents:1250,tier:'tier1'},animation:{index:0,slices:[]}}}}):old(a,o);
        }""".replace('CID',CID))
        await page.locator('#spin-game').click()
        await page.wait_for_function('window.__proofNav')
        dest=await page.evaluate('window.__proofNav')
        parts=dest.split('?',1)[1]
        from urllib.parse import parse_qs
        args=parse_qs(parts)
        ck('winner routes to same claim proof.php endpoint',dest.startswith('proof.php?'))
        ck('winner carries verified claim ID',args.get('claim')==[CID])
        ck('winner carries exact session ID',args.get('session')==[SID])
        ck('winner mode distinguishes fresh award from later editing',args.get('mode')==['win'])
        ck('return points to initiating game',args.get('from')==['game'])
        ck('browser ran without JavaScript exceptions',not errors)
        await browser.close()
    for name,passed in checks:print(('PASS ' if passed else 'FAIL ')+name,flush=True)
    if errors:print('Browser errors:',errors)
    print(f'Single proof station: {sum(v for _,v in checks)}/{len(checks)}',flush=True)
    if not all(x for _,x in checks):raise SystemExit(1)

if __name__=='__main__':asyncio.run(main())
