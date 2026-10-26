"""V5Q.29E4D1 full-screen media bin, inspector, export dialog and mobile layout."""
from pathlib import Path
import json
from playwright.sync_api import sync_playwright, expect

root=Path(__file__).resolve().parents[1]
fragment=(root/'recording-editor.php').read_text().split('?>',1)[1].split('<?php shellEnd(); ?>',1)[0]
html='<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body class="mc-editor-page"><div class="shell"><header class="header">Global site navigation</header><main>'+fragment+'</main><footer class="footer">Global footer</footer></div></body></html>'
session_id='a'*32
media=[
 {'id':'b'*32,'source_id':'1'*32,'source_name':'Main camera','kind':'video','title':'Concert opening','duration_ms':9000,'initial_global_offset_ms':0,'sync_confidence':1,'take_title':'Show','take_index':1},
 {'id':'c'*32,'source_id':'2'*32,'source_name':'Roaming camera','kind':'photo','title':'Crowd photo','duration_ms':4000,'initial_global_offset_ms':1500,'sync_confidence':1,'take_title':'Show','take_index':1},
 {'id':'d'*32,'source_id':'3'*32,'source_name':'Sound desk','kind':'audio','title':'Ambient audio','duration_ms':9000,'initial_global_offset_ms':0,'sync_confidence':1,'take_title':'Show','take_index':1},
]
errors=[]
state={'revision':0,'timeline':{'offsets':[],'switches':[]},'saves':0}

def respond(route):
 url=route.request.url
 if 'recording-editor.php' in url:route.fulfill(status=200,content_type='text/html',body=html)
 elif '/public/recording-editor.js' in url:route.fulfill(status=200,content_type='application/javascript',body=(root/'public/recording-editor.js').read_text())
 elif '/public/recording-editor.css' in url:route.fulfill(status=200,content_type='text/css',body=(root/'public/recording-editor.css').read_text())
 elif '/public/client.js' in url:route.fulfill(status=200,content_type='application/javascript',body='window.Lucky={getMe:async()=>({csrf:"studio-test"})};')
 elif '/api/recording-timeline.php' in url:
  if 'action=get' in url:
   result={'ok':True,'project':{'session':{'id':session_id,'title':'Full concert','type':'live_event','status':'completed'},'revision':state['revision'],'timeline':state['timeline']},'media':media}
  else:
   assert route.request.headers.get('x-csrf-token')=='studio-test'
   payload=json.loads(route.request.post_data)
   assert payload['revision']==state['revision']
   state['revision']+=1;state['timeline']=payload['timeline'];state['saves']+=1
   result={'ok':True,'edit':{'revision':state['revision'],'timeline':state['timeline']}}
  route.fulfill(status=200,content_type='application/json',body=json.dumps(result))
 elif '/api/recording-export.php' in url:route.fulfill(status=200,content_type='application/json',body=json.dumps({'ok':True,'exports':[]}))
 elif '/api/recording-media.php' in url:route.fulfill(status=404,body='Mocked private clip')
 else:route.fulfill(status=404,body='Mock unavailable')

with sync_playwright() as playwright:
 browser=playwright.chromium.launch(headless=True)
 for width,height in [(1366,900),(390,844)]:
  state.update({'revision':0,'timeline':{'offsets':[],'switches':[]},'saves':0})
  context=browser.new_context(viewport={'width':width,'height':height},is_mobile=width<500,has_touch=width<500)
  context.route('**/*',respond)
  page=context.new_page();page.on('pageerror',lambda e:errors.append(str(e)))
  page.goto('https://lucky.test/recording-editor.php?session='+session_id)
  expect(page.locator('#mc-session-name')).to_have_text('Full concert')
  expect(page.locator('.mc-media-item')).to_have_count(3)
  expect(page.locator('.mc-lane')).to_have_count(3)
  expect(page.locator('.header')).to_be_hidden()
  assert page.evaluate('document.documentElement.scrollWidth <= innerWidth + 1'),'Document overflowed horizontally'
  if width>500:
   assert page.evaluate('document.documentElement.scrollHeight <= innerHeight + 1'),'Desktop studio not full-screen'
  page.locator('[data-media-filter="video"]').click()
  expect(page.locator('.mc-media-item')).to_have_count(1)
  page.locator('[data-media-filter="all"]').click()
  page.locator('#mc-media-search').fill('sound')
  expect(page.locator('.mc-media-item')).to_have_count(1)
  page.locator('#mc-media-search').fill('')
  page.locator('.mc-media-item[data-media-id="'+'b'*32+'"]').click()
  expect(page.locator('#mc-inspector')).to_be_visible()
  expect(page.locator('#mc-monitor-mark')).to_have_text('Main camera')
  expect(page.locator('#mc-inspector-toggle')).to_have_attribute('aria-expanded','true')
  page.locator('[data-nudge="100"]').click()
  expect(page.locator('#mc-offset')).to_have_value('0.10')
  page.locator('#mc-inspector-close').click()
  expect(page.locator('#mc-inspector')).to_be_hidden()
  page.locator('#mc-inspector-toggle').click()
  expect(page.locator('#mc-inspector')).to_be_visible()
  page.keyboard.press('Escape')
  expect(page.locator('#mc-inspector')).to_be_hidden()
  page.locator('#mc-save').click()
  expect(page.locator('#mc-save')).to_have_text('✓ Saved')
  page.locator('#mc-export-open').click()
  expect(page.locator('#mc-export-dialog')).to_be_visible()
  page.locator('#mc-export-close').click()
  expect(page.locator('#mc-export-dialog')).to_be_hidden()
  context.close()
  assert state['saves']==1 and state['timeline']['offsets']==[{'assetId':'b'*32,'offsetMs':100}],state
 assert not errors,errors
 browser.close()
print('V5Q.29E4D1 FULL-SCREEN STUDIO BROWSER CERTIFIED')
