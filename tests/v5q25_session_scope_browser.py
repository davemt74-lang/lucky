"""V5Q.25 game focus contract: no historical claims during package selection."""
import asyncio
from pathlib import Path
from playwright.async_api import async_playwright
from v5q25_package_live_browser import ROOT, BODY, CLIENT, LIVE

LIVE_ID='c'*32
ARCHIVE_ID='d'*32
PACKAGE_A='a'*32
PACKAGE_B='b'*32

HTML_START='''<html><head><base href="https://lucky.test/"></head><body>'''
HTML_END='''<script>''' + CLIENT.replace('</script','<\\/script') + '''</script><script>''' + LIVE.replace('</script','<\\/script') + '''</script></body></html>'''


def html(mode):
    seed=f'''<script>
window.__mode={mode!r};window.__actions=[];
window.LuckyWheel=class{{constructor(){{}}setSlices(){{}}spinTo(){{return Promise.resolve()}}}};
const ACTIVE={{
 view:{{id:'{LIVE_ID}',status:'active',version:2,spinCount:1,slots:[],claims:[{{name:'Current Prize',tier:'tier1',cents:3500}}]}},
 session:{{claims:[{{id:'{'e'*32}',prizeId:'{'f'*32}',name:'Current Prize',tier:'tier1',cents:3500}}],pools:{{tier1:{{prizes:[]}},tier2:{{prizes:[]}},bonus:{{prizes:[]}}}},odds:{{bonusChance:0}},prizePackage:{{name:'Package A'}}}}
}};
const ARCHIVE={{
 view:{{id:'{ARCHIVE_ID}',status:'ended',version:11,spinCount:5,slots:[],claims:[{{name:'OLD WIN',tier:'tier1',cents:9999}}]}},
 session:{{claims:[{{id:'{'1'*32}',prizeId:'{'2'*32}',name:'OLD WIN',tier:'tier1',cents:9999}}],pools:{{tier1:{{prizes:[]}},tier2:{{prizes:[]}},bonus:{{prizes:[]}}}},odds:{{bonusChance:0}}}}
}};
window.Lucky={{
 $:id=>document.getElementById(id),pref:{{musicOn:false}},tracks:[],
 api:async(action,p={{}})=>{{__actions.push(action);
  if(action==='last')return {{data:ARCHIVE}};
  if(action==='current')return {{data:window.__mode==='live'?ACTIVE:window.__mode==='historical'?ARCHIVE:null}};
  if(action==='session')return {{...ARCHIVE}};
  if(action==='prize_packages')return {{selected:'{PACKAGE_A}',packages:[
    {{id:'{PACKAGE_A}',name:'Original Prizes',enabled:true,playerSelectable:true,publicationStatus:'published'}},
    {{id:'{PACKAGE_B}',name:'Second Package',enabled:true,playerSelectable:true,publicationStatus:'published'}}
  ]}};
  if(action==='prize_offer')return {{offer:null}};
  return {{ok:true}};
 }},loadTracks:async()=>[],bindMusic:()=>{{}},note:(id,s)=>{{const e=document.getElementById(id);if(e)e.textContent=s}},
 money:c=>'$'+Number(c/100).toFixed(2),speak:()=>{{}},beep:()=>{{}},idempotency:()=> 'test_nonce'
}};
window.fetch=async()=>new Response(JSON.stringify({{ok:true,states:{{}},clips:[]}}));
</script>'''
    return HTML_START+BODY+seed+HTML_END


async def main():
    checks=[]; errors=[]
    def ck(label,val):checks.append((label,bool(val)))
    async with async_playwright() as p:
        browser=await p.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox'])
        for mode in ['idle','live']:
            page=await browser.new_page(viewport={'width':1400,'height':900})
            page.on('pageerror',lambda err:errors.append(str(err)))
            await page.set_content(html(mode),wait_until='domcontentloaded')
            await page.locator('#host-package-choice-cards button').first.wait_for(timeout=6000)
            await page.wait_for_function("window.__actions.includes('current')")
            if mode=='idle':
                ck('Idle game has no claimed-prize panel',await page.locator('.host-claims-column').is_hidden())
                ck('Idle game chooser occupies full-width host',await page.evaluate("document.body.classList.contains('game-no-live-session')"))
                ck('No stale claim cards rendered',await page.locator('#claim-list .claim-proof-row').count()==0)
                ck('Old historic data not requested in current view',await page.evaluate("!__actions.includes('last')"))
                ck('Package chooser remains visible with no live game',await page.locator('#host-prize-package-chooser').is_visible())
                ck('No history navigation link on idle page',await page.locator('#claims-page-link').is_hidden())
            else:
                await page.wait_for_function("document.querySelector('.host-claims-column').hidden===false")
                ck('Active game shows current claims panel',await page.locator('.host-claims-column').is_visible())
                ck('Active game hides pregame chooser',await page.locator('#host-prize-package-chooser').is_hidden())
                ck('Active game displays only its own prize',await page.locator('#claim-list .claim-proof-row').count()==1 and 'Current Prize' in await page.locator('#claim-list').inner_text())
                ck('Old historic prize absent in active panel','OLD WIN' not in await page.locator('#claim-list').inner_text())
                ck('Active claim link pinned to active session',LIVE_ID in (await page.locator('#claims-page-link').get_attribute('href')))
            await page.close()
        page=await browser.new_page()
        page.on('pageerror',lambda err:errors.append(str(err)))
        await page.set_content(html('historical'),wait_until='domcontentloaded')
        await page.wait_for_function("window.__actions.includes('current')")
        ck('A defensive ended-session response never leaks claims into pregame sidebar',await page.locator('.host-claims-column').is_hidden())
        ck('An ended state is never mistaken for a running game',await page.locator('#host-prize-package-chooser').is_visible())
        await page.close()
        ck('No uncaught browser errors',not errors)
        await browser.close()
    for label,ok in checks: print(('PASS ' if ok else 'FAIL ')+label,flush=True)
    print('V5Q.25 session scope:',sum(ok for _,ok in checks),'/',len(checks))
    if errors:print('Errors:',errors)
    if not all(ok for _,ok in checks):raise SystemExit(1)

if __name__=='__main__':asyncio.run(main())
