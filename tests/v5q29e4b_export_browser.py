"""Browser acceptance: save edits, queue master-audio multicamera MP4, retrieve private export."""
from pathlib import Path
import json
from playwright.sync_api import sync_playwright, expect

root=Path(__file__).resolve().parents[1]
fragment=(root/'recording-editor.php').read_text().split('?>',1)[1].split('<?php shellEnd(); ?>',1)[0]
html='<!doctype html><html><head><meta charset="utf-8"></head><body>'+fragment+'</body></html>'
session_id='a'*32
video_id='b'*32
audio_id='c'*32
export_id='d'*32
state={'revision':1,'offsets':[],'saves':0,'queued':False,'queue':None}
media=[
 {'id':video_id,'take_id':'e'*32,'source_id':'f'*32,'source_name':'Main stage','kind':'video','title':'Stage',
  'duration_ms':5000,'initial_global_offset_ms':0,'sync_confidence':1,'take_title':'Take 1','take_index':1},
 {'id':audio_id,'take_id':'e'*32,'source_id':'1'*32,'source_name':'Sound desk','kind':'audio','title':'Mix',
  'duration_ms':5000,'initial_global_offset_ms':0,'sync_confidence':1,'take_title':'Take 1','take_index':1},
]
errors=[]
def handler(route):
 url=route.request.url
 if 'recording-editor.php' in url: route.fulfill(status=200,content_type='text/html; charset=utf-8',body=html)
 elif '/public/client.js' in url:route.fulfill(status=200,content_type='application/javascript',body='window.Lucky={getMe:async()=>({csrf:"test-token"})};')
 elif '/public/recording-editor.js' in url:route.fulfill(status=200,content_type='application/javascript',body=(root/'public/recording-editor.js').read_text())
 elif '/public/recording-editor.css' in url:route.fulfill(status=200,content_type='text/css',body=(root/'public/recording-editor.css').read_text())
 elif '/api/recording-timeline.php' in url:
  if 'action=get' in url:
   result={'ok':True,'project':{'session':{'id':session_id,'title':'Concert session','type':'live_event','status':'completed'},
    'revision':state['revision'],'timeline':{'offsets':state['offsets'],'switches':[]}},'media':media}
   route.fulfill(status=200,content_type='application/json',body=json.dumps(result))
  else:
   assert route.request.headers.get('x-csrf-token')=='test-token'
   data=json.loads(route.request.post_data)
   assert data['revision']==state['revision']
   state['revision']+=1;state['saves']+=1;state['offsets']=data['timeline']['offsets']
   route.fulfill(status=200,content_type='application/json',body=json.dumps({'ok':True,'edit':{'revision':state['revision'],'timeline':data['timeline']}}))
 elif '/api/recording-export.php' in url:
  if 'action=list' in url:
   jobs=[{'id':export_id,'status':'ready','revision':state['revision'],'durationMs':5000,
    'createdAt':'2026-10-25T10:30:00Z','error':''}] if state['queued'] else []
   route.fulfill(status=200,content_type='application/json',body=json.dumps({'ok':True,'exports':jobs}))
  elif 'action=queue' in url:
   assert route.request.headers.get('x-csrf-token')=='test-token'
   body=json.loads(route.request.post_data)
   assert body['session']==session_id and body['revision']==state['revision'] and body['masterAudio']==audio_id
   assert len(body['nonce'])==32 and all(c in '0123456789abcdef' for c in body['nonce'])
   state['queued']=True;state['queue']=body
   route.fulfill(status=201,content_type='application/json',body=json.dumps({'ok':True,'export':{'id':export_id,'status':'queued'}}))
  else: raise AssertionError('Unexpected export operation '+url)
 elif '/api/recording-media.php' in url:route.fulfill(status=404,body='Mock media stream')
 else:route.fulfill(status=404,body='Unknown request')

with sync_playwright() as playwright:
 browser=playwright.chromium.launch(headless=True)
 context=browser.new_context(viewport={'width':1280,'height':850})
 context.route('**/*',handler)
 page=context.new_page()
 page.on('pageerror',lambda error:errors.append(str(error)))
 page.goto('https://lucky.test/recording-editor.php?session='+session_id)
 expect(page.locator('#mc-session-name')).to_have_text('Concert session')
 expect(page.locator('#mc-export-audio option')).to_have_count(3)
 page.locator('#mc-export-open').click()
 expect(page.locator('#mc-export-dialog')).to_be_visible()
 expect(page.locator('#mc-export-start')).to_be_enabled()
 page.locator('#mc-export-close').click()
 page.locator('.mc-clip[data-asset-id="'+video_id+'"]').click()
 page.locator('[data-nudge="100"]').click()
 expect(page.locator('#mc-export-start')).to_be_disabled()
 page.locator('#mc-save').click()
 expect(page.locator('#mc-save')).to_contain_text('Saved')
 page.locator('#mc-export-open').click()
 expect(page.locator('#mc-export-start')).to_be_enabled()
 page.locator('#mc-export-audio').select_option(audio_id)
 page.locator('#mc-export-start').click()
 expect(page.locator('#mc-export-list a')).to_have_count(2)
 assert state['queued'] and state['saves']==1 and state['queue']['revision']==2
 assert page.locator('#mc-export-list a').first.get_attribute('href')=='api/recording-export.php?action=download&id='+export_id
 assert not errors,errors
 browser.close()
print('V5Q.29E4B MULTICAMERA MP4 EXPORT BROWSER CERTIFIED')
