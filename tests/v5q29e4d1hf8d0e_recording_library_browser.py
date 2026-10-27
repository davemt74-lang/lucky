"""HF8D0E: recording library actions, filters and isolated creation dialog."""
import asyncio
import re
from pathlib import Path
from playwright.async_api import async_playwright

ROOT = Path(__file__).resolve().parents[1]
A, B, C = ('a' * 32, 'b' * 32, 'c' * 32)


async def main():
    source = (ROOT / 'admin.php').read_text()
    match = re.search(r'<section id="tab-recordings"[\s\S]*?</section>', source)
    assert match, 'Recording administration must have a dedicated section'
    fragment = match.group(0).replace(' hidden aria-label="Recording session studio"', ' aria-label="Recording session studio"', 1)
    script = (ROOT / 'public/admin-recordings.js').read_text().replace('</script', '<\\/script')
    html = ('<!doctype html><html><body>' + fragment + '''<script>
      window.Lucky={getMe:async()=>({csrf:'browser-token'})};
      window.sessions=[
       {id:'%s',title:'Concert night',type:'live_event',status:'active',createdAt:'2026-10-25',profile:{tags:['music']}},
       {id:'%s',title:'Campaign take',type:'production',status:'draft',createdAt:'2026-10-26',profile:{}},
       {id:'%s',title:'Family archive',type:'general',status:'completed',createdAt:'2026-10-22',profile:{}}];
      window.fetch=async(url,opts={})=>{
       const parsed=new URL(url,location.href),act=parsed.searchParams.get('action'),body=opts.body?JSON.parse(opts.body):{};
       let result={ok:true};
       if(act==='list')result.sessions=window.sessions;
       if(act==='create'){
         const created={id:'d'.repeat(32),title:body.title,type:body.type,status:'draft',createdAt:'2026-10-27',profile:{capture:body.capture}};
         window.sessions.unshift(created);result.session=created;
       }
       if(act==='detail'){
         const selected=window.sessions.find(s=>s.id===parsed.searchParams.get('id'));
         result={ok:true,session:{...selected,revision:1},takes:[],sources:[],assets:[]};
       }
       if(act==='permission_status')result.phoneEnabled=false;
       if(act==='media_list')result.media=[];
       if(act==='camera_devices')result.devices=[];
       return {ok:true,status:200,json:async()=>result};
      };
      </script>''' % (A, B, C) + '<script>' + script + '</script></body></html>')

    async with async_playwright() as pw:
        browser = await pw.chromium.launch(headless=True)
        page = await browser.new_page()
        errors = []
        page.on('pageerror', lambda e: errors.append(str(e)))
        await page.set_content(html)
        await page.locator('#recording-refresh').click()
        await page.locator('.recording-session-card').first.wait_for()
        assert await page.locator('.recording-session-card').count() == 3
        assert (await page.locator('.recording-session-card').first.inner_text()).startswith('Concert night')
        assert await page.locator('.recording-card-actions a[href="director.php?recording=' + A + '"]').count() == 1
        assert await page.locator('.recording-card-actions a[href="director.php?recording=' + C + '"]').count() == 0
        assert await page.locator('.recording-card-actions a[href="recording-editor.php?session=' + C + '"]').count() == 1
        await page.locator('#recording-filter-status').select_option('completed')
        assert await page.locator('.recording-session-card').count() == 1
        assert 'Family archive' in await page.locator('.recording-session-card').inner_text()
        await page.locator('#recording-filter-status').select_option('')
        await page.locator('#recording-search').fill('campaign')
        assert await page.locator('.recording-session-card').count() == 1
        await page.locator('#recording-search').fill('')
        await page.locator('#recording-new-open').click()
        assert await page.locator('#recording-new-dialog').evaluate('(node)=>node.open')
        assert await page.locator('#recording-session-list .recording-create').count() == 0
        await page.locator('#recording-title').fill('New studio take')
        await page.locator('#recording-create button[type=submit]').click()
        await page.wait_for_function("window.sessions.some(s=>s.title==='New studio take')")
        await page.wait_for_function("document.querySelectorAll('.recording-session-card').length===4")
        assert not await page.locator('#recording-new-dialog').evaluate('(node)=>node.open')
        assert await page.locator('#recording-open-director').get_attribute('href') == 'director.php?recording=' + 'd'*32
        assert not errors, errors
        await browser.close()
    print('HF8D0E RECORDING LIBRARY BROWSER PASSED')


if __name__ == '__main__':
    asyncio.run(main())
