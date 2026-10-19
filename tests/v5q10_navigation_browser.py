"""V5Q.10 regression contract: no cross-session claims, phones live on Devices, focus navigation works."""
import asyncio
from pathlib import Path
from playwright.async_api import async_playwright
import v5q6_proof_browser as fixture

ROOT=Path(__file__).resolve().parents[1]
checks=[]
def check(label,passed):
    checks.append((label,bool(passed)))

async def main():
    layout=(ROOT/'lib/Layout.php').read_text()
    claims=(ROOT/'claims.php').read_text()
    devices=(ROOT/'devices.php').read_text()
    game=(ROOT/'game.php').read_text()
    editor=(ROOT/'public/editor.js').read_text()
    claim_js=(ROOT/'public/claims.js').read_text()
    check('global nav excludes Host Game', '<a href="game.php"' not in layout)
    check('global nav excludes all-claims entry', '<a href="claims.php"' not in layout)
    check('global nav includes Devices', '<a href="devices.php"' in layout)
    check('claims page requires exact game session', "preg_match('/^[a-f0-9]{32}$/D',$sessionId)" in claims)
    check('claims page invalid session redirects to game', "header('Location: game.php',true,302)" in claims)
    check('claims script requests one explicit session', "query:{id:requested}" in claim_js and 'data.session.id!==requested' in claim_js)
    check('claim proof editing preserves originating session', 'sessionId)}&claim=${encodeURIComponent(c.id)}&from=claims' in claim_js)
    check('devices page owns phone pairing widget', 'pairingWidget()' in devices and 'device-phone-primary' in devices)
    check('devices page loads QR and phone approval script', 'public/pairing-host.js' in devices and 'public/qr.js' in devices)
    check('game page restores phone pairing as dedicated tab', 'pairingWidget()' in game and 'host-panel-phones' in game and 'host-tab-phones' in game)
    check('game page retains exact-session claims link', 'id="claims-page-link"' in game)
    check('editor session claims link is scoped to selected session', "'claims.php?session='+encodeURIComponent(session)" in editor)
    errors=[]
    async with async_playwright() as pw:
        browser=await pw.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox'])
        for width in (375,1280):
            page=await browser.new_page(viewport={'width':width,'height':850},reduced_motion='reduce')
            page.on('pageerror',lambda e:errors.append(str(e)))
            html=fixture.html_page('game.php',(ROOT/'public/game.js').read_text()+'\n'+(ROOT/'public/console.js').read_text(),fixture.MOCK)
            await page.set_content(html,wait_until='domcontentloaded')
            await page.wait_for_timeout(240)
            check(f'{width} default game tab selected',await page.locator('#host-tab-game').get_attribute('aria-selected')=='true')
            check(f'{width} claim action links to its own game',('proof.php?session='+fixture.SID) in await page.locator('#claim-list a').first.get_attribute('href'))
            check(f'{width} phone arrow moves between tabs',await page.evaluate("async () => {document.querySelector('#host-tab-game').focus();await LuckyHostRemote('right');return document.querySelector('#host-tab-music').getAttribute('aria-selected')==='true';}"))
            check(f'{width} tab change leaves claim ledger visible',await page.locator('.host-claims-column').is_visible())
            check(f'{width} phone panel is present but hidden unless selected',await page.locator('#host-panel-phones').count()==1 and await page.locator('#host-panel-phones').is_hidden())
            check(f'{width} no horizontal overflow',await page.evaluate('document.documentElement.scrollWidth<=innerWidth+2'))
            await page.close()
        await browser.close()
    check('no JavaScript errors',not errors)
    for label,passed in checks:
        print(('PASS' if passed else 'FAIL'),label,flush=True)
    if errors:print('Browser exceptions:',errors)
    print(f'V5Q.10 navigation: {sum(x for _,x in checks)}/{len(checks)}',flush=True)
    if not all(x for _,x in checks):raise SystemExit(1)

if __name__=='__main__': asyncio.run(main())
