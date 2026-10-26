"""E4C phone UX: shoot brief, profile permissions, and private upload restrictions."""
from pathlib import Path
import json
from playwright.sync_api import sync_playwright, expect

root=Path(__file__).resolve().parents[1]
html='<!doctype html>'+(root/'camera.php').read_text().split('<!doctype html>',1)[1]
js=(root/'public/recording-camera.js').read_text()
pair_id='a'*32
token='b'*64
recording_id='c'*32
calls={'uploads':0}

profile={'id':recording_id,'title':'Game night story','type':'production','status':'active','clips':0,
 'takeId':None,'brief':'Capture three views of the winner','shotList':['Wide angle','Winner close-up'],
 'tags':['game','highlight'],'location':'Living room','eventDate':'2026-10-25',
 'capture':{'multicameraEnabled':False,'maxCameras':1,'phoneCameraEnabled':True,
   'video':False,'photo':True,'audio':True,'countdownSeconds':5}}

def response(route):
 url=route.request.url
 if url.endswith('camera.php'):route.fulfill(status=200,content_type='text/html',body=html)
 elif '/public/recording-camera.js' in url:route.fulfill(status=200,content_type='application/javascript',body=js)
 elif '/public/' in url:route.fulfill(status=200,content_type='text/css',body='')
 elif '/api/recording-phone.php?action=list' in url:
  route.fulfill(status=200,content_type='application/json',body=json.dumps({'ok':True,'sessions':[profile]}))
 elif '/api/recording-phone.php?action=upload' in url:
  calls['uploads']+=1
  assert b'name="kind"\r\n\r\nphoto' in route.request.post_data_buffer
  route.fulfill(status=201,content_type='application/json',body=json.dumps({'ok':True,'asset':{'kind':'photo','id':'d'*32}}))
 else:route.fulfill(status=404,body='Not found')

with sync_playwright() as playwright:
 browser=playwright.chromium.launch(headless=True)
 context=browser.new_context(viewport={'width':390,'height':844},is_mobile=True,has_touch=True)
 context.add_init_script(f"sessionStorage.setItem('lucky-phone-controller-v5p',JSON.stringify({json.dumps({'id':pair_id,'token':token})}));")
 context.route('**/*',response)
 page=context.new_page();errors=[]
 page.on('pageerror',lambda e:errors.append(str(e)))
 page.goto('https://lucky.test/camera.php')
 expect(page.locator('#session-camera-profile-title')).to_contain_text('Content campaign')
 expect(page.locator('#session-camera-profile-brief')).to_have_text('Capture three views of the winner')
 expect(page.locator('#session-camera-profile-shots li')).to_have_count(2)
 expect(page.locator('[data-session-mode="video"]')).to_be_disabled()
 expect(page.locator('[data-session-mode="photo"]')).to_have_attribute('aria-pressed','true')
 expect(page.locator('[data-session-mode="audio"]')).to_be_enabled()
 expect(page.locator('#session-camera-profile-details')).to_contain_text('Max 1 camera')
 page.locator('#session-camera-consent').check()
 page.locator('#session-camera-file').set_input_files({'name':'winner.jpg','mimeType':'image/jpeg','buffer':b'PHOTO_FIXTURE'})
 expect(page.locator('#session-camera-send')).to_be_visible()
 page.locator('#session-camera-send').click()
 expect(page.locator('#session-camera-message')).to_contain_text('Saved photo')
 assert calls['uploads']==1
 page.locator('#session-camera-file').set_input_files({'name':'not-allowed.mp4','mimeType':'video/mp4','buffer':b'VIDEO_FIXTURE'})
 expect(page.locator('#session-camera-message')).to_contain_text('does not permit video uploads')
 assert calls['uploads']==1 and not errors,errors
 browser.close()
print('V5Q.29E4C PROFILE PHONE BROWSER CERTIFIED')
