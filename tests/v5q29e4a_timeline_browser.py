"""Browser acceptance for shared-angle timeline, non-destructive nudge and save."""
from pathlib import Path
import json
from playwright.sync_api import sync_playwright, expect

root=Path(__file__).resolve().parents[1]
fragment=(root/'recording-editor.php').read_text().split('?>',1)[1].split('<?php shellEnd(); ?>',1)[0]
html='<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body>'+fragment+'</body></html>'
script=(root/'public/recording-editor.js').read_text()
css=(root/'public/recording-editor.css').read_text()
session_id='a'*32
first,second='b'*32,'c'*32
src1,src2='d'*32,'e'*32
state={'revision':0,'timeline':{'offsets':[],'switches':[]},'saves':0,'conflict':False}
media=[
 {'id':first,'take_id':'f'*32,'source_id':src1,'source_name':'Stage left','take_title':'Act 1','take_index':1,'kind':'video','title':'Camera left','duration_ms':5000,'initial_global_offset_ms':0,'sync_confidence':1},
 {'id':second,'take_id':'f'*32,'source_id':src2,'source_name':'Stage right','take_title':'Act 1','take_index':1,'kind':'video','title':'Camera right','duration_ms':5000,'initial_global_offset_ms':500,'sync_confidence':1},
]

def respond(route):
    url=route.request.url
    if 'recording-editor.php' in url: route.fulfill(status=200,content_type='text/html',body=html)
    elif '/public/client.js' in url:route.fulfill(status=200,content_type='application/javascript',body='window.Lucky={getMe:async()=>({csrf:"test-csrf"})};')
    elif '/public/recording-editor.js' in url:route.fulfill(status=200,content_type='application/javascript',body=script)
    elif '/public/recording-editor.css' in url:route.fulfill(status=200,content_type='text/css',body=css)
    elif '/api/recording-timeline.php' in url:
        if 'action=get' in url:
            reply={'ok':True,'project':{'session':{'id':session_id,'title':'Festival multicamera','type':'live_event','status':'completed'},'revision':state['revision'],'timeline':state['timeline']},'media':media}
            route.fulfill(status=200,content_type='application/json',body=json.dumps(reply))
        elif 'action=save' in url:
            assert route.request.headers.get('x-csrf-token')=='test-csrf'
            payload=json.loads(route.request.post_data)
            assert payload['id']==session_id
            if state['conflict']:
                route.fulfill(status=409,content_type='application/json',body=json.dumps({'ok':False,'error':'Another editor changed this timeline. Reload before saving.'}))
                return
            assert payload['revision']==state['revision']
            state['revision']+=1;state['timeline']=payload['timeline'];state['saves']+=1
            route.fulfill(status=200,content_type='application/json',body=json.dumps({'ok':True,'edit':{'revision':state['revision'],'timeline':state['timeline']}}))
        else:raise AssertionError('Unknown edit action')
    elif '/api/recording-media.php' in url:route.fulfill(status=404,body='Mocked media stream')
    else:route.fulfill(status=404,body='Missing mock')

with sync_playwright() as playwright:
    browser=playwright.chromium.launch(headless=True)
    context=browser.new_context(viewport={'width':1280,'height':850})
    context.route('**/*',respond)
    page=context.new_page();page.goto('https://lucky.test/recording-editor.php?session='+session_id)
    expect(page.locator('#mc-session-name')).to_have_text('Festival multicamera')
    expect(page.locator('.mc-lane')).to_have_count(2)
    page.locator('#mc-scrubber').evaluate("e=>{e.value='1000';e.dispatchEvent(new Event('input',{bubbles:true}));}")
    expect(page.locator('#mc-angles button')).to_have_count(2)
    page.locator('.mc-clip[data-asset-id="'+second+'"]').click()
    expect(page.locator('#mc-offset')).to_have_value('0.50')
    page.locator('[data-nudge="100"]').click()
    expect(page.locator('#mc-offset')).to_have_value('0.60')
    page.locator('#mc-inspector-close').click()
    page.locator('#mc-angles [data-angle-id="'+second+'"]').click()
    expect(page.locator('.mc-cut')).to_have_count(1)
    page.locator('#mc-save').click()
    expect(page.locator('#mc-save')).to_have_text('✓ Saved')
    assert state['saves']==1 and state['timeline']['offsets']==[{'assetId':second,'offsetMs':600}],state
    assert state['timeline']['switches']==[{'atMs':1000,'assetId':second}],state
    page.reload();expect(page.locator('.mc-cut')).to_have_count(1)
    page.locator('.mc-clip[data-asset-id="'+second+'"]').click()
    expect(page.locator('#mc-offset')).to_have_value('0.60')
    state['conflict']=True;page.locator('[data-nudge="100"]').click();page.locator('#mc-save').click()
    expect(page.locator('#mc-status')).to_contain_text('Another editor changed')
    assert state['saves']==1,'Conflict must not overwrite edit'
    browser.close()

print('V5Q.29E4A MULTICAMERA TIMELINE BROWSER CERTIFIED')
