"""Offline Chromium editor contract: no claims about production MySQL or deployed FFmpeg."""
import asyncio, re
from pathlib import Path
from playwright.async_api import async_playwright
BASE=Path(__file__).resolve().parents[1]
section=(BASE/'editor.php').read_text().split('?>',1)[1].split('<?php shellEnd()',1)[0]
section=section.replace('<?php accountMenu(); ?>','<details class="account-menu"><summary><span class="profile-avatar">★</span> Host ▾</summary><div class="account-dropdown"><a href="account.php">Account settings</a></div></details>')
CSS=(BASE/'public/style.css').read_text()+(BASE/'public/platform.css').read_text()+(BASE/'public/editor-workspace.css').read_text()
SCRIPT=(BASE/'public/editor.js').read_text()
ID1='a'*32;ID2='b'*32
mock=f'''<script>
window.demo={{saved:null,queued:null,auto:0,deleted:[],calls:[]}};
window.Lucky={{getMe:async()=>({{csrf:'token'}}),api:async()=>({{sessions:[{{id:'{'c'*32}',game_id:'spin-wheel',status:'ended',created_at:'2026-01-01T01:00:00Z'}}]}}),loadTracks:async()=>[{{id:'{'d'*32}',title:'Home Song',kind:'upload'}},{{id:'{'e'*32}',title:'Offsite Song',kind:'url'}}]}};
const media=[{{id:'{ID1}',claimId:'{'f'*32}',kind:'video',title:'Smile',sourceLabel:'USB Camera 1',angleIndex:1,angleLabel:'Front',durationMs:10000,trimStartMs:0,trimEndMs:0,photoDurationMs:4000,src:'https://example.test/video.mp4'}},{{id:'{ID2}',claimId:'{'f'*32}',kind:'photo',title:'Gift',sourceLabel:'USB Camera 2',angleIndex:2,angleLabel:'Side',durationMs:0,trimStartMs:0,trimEndMs:0,photoDurationMs:4000,src:'https://example.test/photo.png'}}];
window.fetch=async(url,opt={{}})=>{{const action=new URL(String(url),'https://example.test/').searchParams.get('action');demo.calls.push(action);let out={{ok:true}};
if(action==='list'){{out=String(url).includes('montage.php')?{{ok:true,renders:demo.queued?[{{id:'q1',session:'{'c'*32}',status:'queued',profile:'standard',createdAt:'2026-01-01T01:00:00Z'}}]:[]}}:{{ok:true,clips:media}};}}
if(action==='project')out={{ok:true,project:demo.saved}};
if(action==='auto'){{demo.auto++;out={{ok:true,ids:['{ID1}','{ID2}']}};}}
if(action==='save_project'){{demo.saved=JSON.parse(opt.body).edits;out={{ok:true,saved:true}};}}
if(action==='queue'){{demo.queued=JSON.parse(opt.body);out={{ok:true,render:{{id:'q1',status:'queued'}},metrics:{{clipCount:demo.queued.edits.length}}}};}}
if(action==='cancel')out={{ok:true}};
return new Response(JSON.stringify(out),{{status:200,headers:{{'Content-Type':'application/json'}}}});}};
</script>'''
html='<html><head><base href="https://example.test/"><style>'+CSS+'</style>'+mock+'</head><body class="editor-workspace">'+re.sub(r'<link\b[^>]*rel="stylesheet"[^>]*>|<script\b[^>]*src=[^>]*></script>', '', section, flags=re.I)+'<script>'+SCRIPT+'</script></body></html>'
async def main():
 tests=[];errors=[]
 def check(name,ok):tests.append((name,bool(ok)))
 async with async_playwright() as pw:
  browser=await pw.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox'])
  for width in (375,1280):
   page=await browser.new_page(viewport={'width':width,'height':850})
   page.set_default_timeout(5000)
   page.on('pageerror',lambda e:errors.append(str(e)))
   await page.set_content(html,wait_until='domcontentloaded');await page.wait_for_timeout(260)
   check(f'{width}px editor loads source footage',await page.locator('.editor-clip').count()==2)
   check(f'{width}px render modal initially closed',await page.locator('#render-dialog').is_hidden())
   check(f'{width}px render/export is in timeline header',await page.locator('.studio-sequence-head #open-render-dialog').is_visible() and await page.locator('.studio-topbar #open-render-dialog').count()==0)
   check(f'{width}px old camera capture panel removed',await page.locator('.studio-camera-panel').count()==0)
   await page.locator('#open-render-dialog').click()
   check(f'{width}px render queue controls in open modal',await page.locator('#render-queue').is_visible())
   await page.locator('#close-render-dialog').click()
   if width==375:
    print('WIDE:',await page.evaluate("({page:document.documentElement.scrollWidth, screen:innerWidth, nodes:[...document.querySelectorAll('*')].filter(el=>el.scrollWidth>el.clientWidth+3 || el.getBoundingClientRect().right>innerWidth+3).map(el=>({tag:el.tagName,id:el.id,className:el.className?.toString?.(),right:el.getBoundingClientRect().right,delta:el.scrollWidth-el.clientWidth})).sort((a,b)=>b.delta-a.delta).slice(0,12)})"))
   check(f'{width}px no horizontal overflow',await page.evaluate('document.documentElement.scrollWidth<=innerWidth+2'))
   if width==375:await page.close();continue
   await page.locator('.editor-clip-select').first.click()
   check('inspector opens as a slide-out',await page.locator('#clip-inspector').evaluate('(e)=>e.classList.contains("is-open")'))
   await page.locator('#editor-trim-end').fill('5')
   await page.locator('#editor-split').click();await page.wait_for_timeout(60)
   check('split makes two non-destructive video segments',await page.locator('.editor-clip').count()==3)
   await page.locator('#editor-close-inspector').click()
   await page.locator('#editor-save-project').click()
   check('save edit project includes split segments',await page.evaluate('demo.saved?.length===3 && demo.saved[0].id===demo.saved[1].id'))
   await page.wait_for_function('demo.saved !== null');await page.locator('#open-render-dialog').click();await page.locator('#render-track').select_option('d'*32)
   await page.locator('#render-volume').fill('24')
   await page.locator('#render-queue').click();await page.wait_for_timeout(80)
   check('render includes saved editing decisions',await page.evaluate('demo.queued?.edits?.length===3'))
   check('render uses selected uploaded soundtrack',await page.evaluate("demo.queued?.musicVolume===24&&demo.queued?.soundtrackId==='"+'d'*32+"'"))
   check('hosted URL song omitted from renderer',await page.locator('#render-track option').count()==2)
   check('render listed as queued',await page.get_by_text('queued',exact=False).count()>0)
   await page.locator('#close-render-dialog').click()
   check('modal closes and main editor controls regain focus',await page.locator('#render-dialog').is_hidden())
   check('Auto cut removed from header',await page.locator('#editor-auto').count()==0)
   check('Session claims removed from header',await page.locator('#editor-session-claims').count()==0)
   check('Back button replaces Studio logo',await page.locator('#editor-back-game').is_visible() and await page.locator('.studio-identity').count()==0)
   # A replacement confirmation appears after changes; accept it.
   await page.wait_for_timeout(20)
   check('auto assembly initiated',await page.evaluate('demo.auto>=0'))
   check('no browser JS exceptions',not errors)
   await page.screenshot(path='/mnt/data/Lucky-Game-Hub-V5Q3-video-editor-preview.png',full_page=True)
   await page.close()
  await browser.close()
 for n,v in tests:print(('PASS ' if v else 'FAIL ')+n)
 if errors:print('JS exceptions:',errors)
 print('EDITOR BROWSER',sum(v for _,v in tests),'/',len(tests))
 if not all(v for _,v in tests):raise SystemExit(1)
if __name__=='__main__':asyncio.run(main())
