"""HF8C6: production workspace is video-first, usable and wired to real media."""
import asyncio
import re
from pathlib import Path
from playwright.async_api import async_playwright

ROOT = Path(__file__).resolve().parents[1]
A, SOURCE, TAKE = ('a' * 32, 'b' * 32, 'c' * 32)


def read(path):
    return (ROOT / path).read_text().replace('</script', '<\\/script')


async def main():
    source = read('director.php')
    markup = re.search(r'<main class="director-studio-shell">[\s\S]*?</main>', source)
    assert markup, 'Standalone Director markup is required'
    script = read('public/director-workspace.js')
    css = '\n'.join(read(name) for name in ['public/style.css', 'public/platform.css', 'public/director-workspace.css'])
    mock = f"""
     window.currentSession='';
     window.LuckyDirectorStudio={{state:()=>({{recordingSession:window.currentSession}})}};
     window.mockMedia=[];
     window.fetch=async(url)=>{{
      const p=new URL(String(url),'https://lucky.test/'),act=p.searchParams.get('action');
      let data={{ok:true}};
      if(act==='detail')data={{ok:true,takes:[{{id:'{TAKE}',title:'Opening angle',take_index:1}}],sources:[{{id:'{SOURCE}',display_name:'Paired iPhone'}}],assets:[]}};
      if(act==='media_list')data={{ok:true,media:window.mockMedia}};
      return {{ok:true,status:200,json:async()=>data}};
     }};
    """
    html = ('<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1">'
            '<style>' + css + '</style></head><body class="director-studio-page">'
            '<div class="shell"><main>' + markup.group(0) + '</main></div>'
            '<script>' + mock + '</script><script>' + script + '</script></body></html>')
    checks = []

    async with async_playwright() as pw:
        browser = await pw.chromium.launch(headless=True)
        page = await browser.new_page(viewport={'width': 1600, 'height': 1050}, device_scale_factor=1)
        errors = []
        page.on('pageerror', lambda e: errors.append(str(e)))
        await page.set_content(html, wait_until='domcontentloaded')
        await page.wait_for_function('!!window.LuckyDirectorWorkspace')

        async def test(label, condition):
            assert condition, label
            checks.append(label)

        await test('Redesign retains original Program canvas and Preview canvas',
                   await page.locator('#director-workspace-monitors #director-preview').count() == 1 and
                   await page.locator('#director-workspace-monitors #director-program').count() == 1)
        await test('Live compositor root remains connected to the document',
                   await page.evaluate("document.querySelector('#camera-director').isConnected"))
        box = await page.locator('#director-workspace-monitors').bounding_box()
        grid = await page.locator('#director-workspace-grid').bounding_box()
        timeline = await page.locator('.director-workspace-timeline').bounding_box()
        await test('Monitors visibly lead the layout, followed by live cameras and timeline',
                   box['y'] < grid['y'] < timeline['y'] and box['width'] > 1200)
        await test('Default tool drawer is closed and camera permission is not requested',
                   await page.locator('#director-workspace-drawer').is_hidden() and
                   await page.locator('.camera-source').count() == 0)
        await page.get_by_role('button', name='☷ Sessions').click()
        await test('Session controls open in drawer and retain their original IDs',
                   await page.locator('#director-workspace-drawer').is_visible() and
                   await page.locator('#director-workspace-drawer #director-studio-open').count() == 1)
        await page.keyboard.press('Escape')
        await test('Escape closes settings drawer',await page.locator('#director-workspace-drawer').is_hidden())
        await page.get_by_role('button', name='◉ Go Live').click()
        await test('Live settings expose private/public visibility and participant consent',
                   await page.locator('#director-broadcast-visibility').count() == 1 and
                   await page.locator('#director-publish-consent').count() == 1 and
                   await page.locator('#director-broadcast-visibility option').count() == 2)
        await page.locator('#director-workspace-close').click()

        await page.evaluate(f"""() => {{
          const select=document.getElementById('director-studio-session');
          select.add(new Option('Live event - production', '{A}'));select.value='{A}';
          window.currentSession='{A}';
          window.mockMedia=[{{id:'d'.repeat(32),source_id:'{SOURCE}',take_id:'{TAKE}',kind:'video',title:'Real camera clip',duration_ms:22000,start_offset_ms:2500}},
                            {{id:'e'.repeat(32),source_id:null,take_id:'{TAKE}',kind:'audio',title:'Program mix',duration_ms:16000,start_offset_ms:0}}];
          window.dispatchEvent(new CustomEvent('lucky:director-studio-open',{{detail:{{recordingSession:'{A}'}}}}));
        }}""")
        await page.wait_for_function("document.querySelectorAll('.director-workspace-timeline-clip').length===2")
        await test('Timeline contains only real media grouped into source lanes',
                   await page.locator('.director-workspace-lane').count() == 2 and
                   await page.locator('.director-workspace-timeline-clip').count() == 2)
        await test('Editor link opens the same selected recording session',
                   await page.locator('#director-workspace-editor-link').get_attribute('href') ==
                   f'recording-editor.php?session={A}')
        await test('Current session label updates and drawer is closed on selection',
                   'Live event' in await page.locator('#director-workspace-session-title').inner_text() and
                   await page.locator('#director-workspace-drawer').is_hidden())
        await page.evaluate("""() => {
          const card=document.createElement('section');card.className='camera-source';
          const title=document.createElement('strong');title.textContent='USB Camera';
          const vid=document.createElement('video');card.append(title,vid);
          document.getElementById('camera-grid').append(card);
        }""")
        await page.wait_for_function("document.querySelector('#director-workspace-camera-count').textContent==='1 camera'")
        await test('Connected devices appear in the main multiview grid',
                   await page.locator('#director-workspace-grid .camera-source').count() == 1)
        await page.set_viewport_size({'width': 380, 'height': 760})
        await test('Mobile monitors stack vertically without horizontal page overflow',
                   await page.evaluate("""() => {
                     const p=document.querySelector('#director-preview').getBoundingClientRect();
                     const g=document.querySelector('#director-program').getBoundingClientRect();
                     return g.y>p.y && document.documentElement.scrollWidth<=window.innerWidth+2;
                   }"""))
        await page.evaluate("""() => {window.currentSession='';window.dispatchEvent(new Event('lucky:director-studio-close'));}""")
        await test('Switching away clears session editor access and timeline context',
                   await page.locator('#director-workspace-editor-link').get_attribute('aria-disabled') == 'true' and
                   await page.locator('.director-workspace-timeline-clip').count() == 0)
        await test('No unhandled browser exceptions', not errors)
        await browser.close()

    for label in checks:
        print('PASS', label)
    print(f'HF8C6 DIRECTOR WORKSPACE BROWSER PASSED ({len(checks)} checks)')


if __name__ == '__main__':
    asyncio.run(main())
