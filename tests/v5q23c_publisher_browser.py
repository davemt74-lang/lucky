"""V5Q.23C verify per-game trust UI, manual checks, disabled SDK releases."""
import asyncio
from pathlib import Path
from playwright.async_api import async_playwright

ROOT=Path(__file__).resolve().parents[1]
JS=(ROOT/'public/game-catalog.js').read_text()

HTML='''<!doctype html><html><head><meta charset="utf-8"></head><body>
<script>
window.__calls=[];
window.__channels=[
 {game:'spin-wheel',publisher:'Lucky',url:'https://lucky.example/updates.json',connected:true,keyFingerprint:'a'.repeat(64)},
 {game:'table-trivia',publisher:'Trivia',url:'https://trivia.example/updates.json',connected:true,keyFingerprint:'b'.repeat(64)}
];
window.confirm=()=>true;
window.Lucky={api:async (action,{body}={})=>{
 window.__calls.push({action,body});
 if(action==='game_catalog')return {games:[
  {id:'spin-wheel',title:'Spin the Wheel',publisherName:'Lucky',images:{gallery:[]},enabled:true,
    builtIn:true,status:'ready',controllerMode:'custom',playersMin:1,playersMax:8},
  {id:'table-trivia',title:'Table Trivia',publisherName:'Trivia',images:{gallery:[]},enabled:true,
    builtIn:false,status:'ready',controllerMode:'custom',playersMin:1,playersMax:8}
 ]};
 if(action==='game_packages')return {packages:[],active:{},issues:[]};
 if(action==='game_publisher_channels')return {channels:__channels,sdkVersion:'1.0.0'};
 if(action==='game_update_connection')return {connection:{connected:false}};
 if(action==='game_publisher_check')return {updates:{releases:[
  {game:body.game,version:'1.2.0',sdkMin:'1.0.0',compatible:true,bytes:1234,sha256:'c'.repeat(64),notes:'Safe update'},
  {game:body.game,version:'9.0.0',sdkMin:'9.0.0',compatible:false,bytes:1234,sha256:'d'.repeat(64),notes:'Future SDK'}
 ]}};
 if(action==='game_update_stage')return {package:{title:'Table Trivia',version:body.version}};
 if(action==='game_publisher_connect'){
  if(!body.confirmReplace)throw Error('Explicitly confirm the publisher feed or signing key change.');
  return {channel:{game:body.game}};
 }
 if(action==='game_publisher_disconnect'){
  window.__channels=__channels.filter(c=>c.game!==body.game);
  return {channel:{game:body.game,connected:false}};
 }
 throw Error('Unexpected action: '+action);
}};
const declarations={
 'catalog-editor':'form','catalog-new-game':'button','catalog-game-list':'div',
 'catalog-save-status':'p','catalog-id':'input','catalog-editor-heading':'h3',
 'catalog-execution-status':'p','catalog-gallery':'div','catalog-min':'input',
 'catalog-max':'input','catalog-controller':'select','catalog-enabled':'input',
 'game-package-refresh':'button','game-package-upload':'form',
 'game-package-status':'p','game-package-health':'p','game-package-list':'div',
 'game-update-connect-form':'form','game-update-connection-status':'div',
 'game-update-check':'button','game-update-disconnect':'button',
 'game-update-url':'input','game-update-public-key':'textarea',
 'game-update-token':'input','game-update-message':'p','game-update-releases':'div',
 'game-publisher-form':'form','game-publisher-name':'input',
 'game-publisher-game':'select','game-publisher-url':'input',
 'game-publisher-key':'textarea','game-publisher-token':'input',
 'game-publisher-status':'p','game-publisher-list':'div','game-publisher-releases':'div'
};
for(const field of ['title','tagline','description','publisher','publisher-website','category','version','age'])
 declarations['catalog-'+field]='input';
for(const slot of ['cover','hero','logo'])declarations['catalog-'+slot+'-preview']='img';
for(const [id,tag] of Object.entries(declarations)){
 const el=document.createElement(tag);el.id=id;
 if(tag==='form')el.action='#';
 if(id==='game-publisher-game')el.required=true;
 document.body.append(el);
}
document.getElementById('catalog-controller').innerHTML='<option value="custom">custom</option>';
</script><script>APP_CODE</script></body></html>'''.replace('APP_CODE',JS.replace('</script','<\\/script'))

async def main():
    checks=[];errors=[]
    def ck(label,ok):checks.append((label,bool(ok)))
    async with async_playwright() as pw:
        browser=await pw.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox'])
        page=await browser.new_page()
        page.on('pageerror',lambda err:errors.append(str(err)))
        await page.route('https://lucky.test/admin.php',
                         lambda route:route.fulfill(status=200,content_type='text/html',body=HTML))
        await page.goto('https://lucky.test/admin.php')
        await page.wait_for_function("document.querySelectorAll('#game-publisher-list article').length===2")
        ck('Two independent publisher channel cards',await page.locator('#game-publisher-list article').count()==2)
        ck('Publisher game selector includes all catalog games',await page.locator('#game-publisher-game option').count()==2)
        ck('Public cards expose key fingerprint but never access tokens',
           'Pinned key' in await page.locator('#game-publisher-list').inner_text() and
           'private-feed-token' not in await page.locator('#game-publisher-list').inner_text())
        await page.locator('#game-publisher-list article').nth(1).get_by_role('button',name='Check game updates').click()
        await page.wait_for_function("document.querySelectorAll('#game-publisher-releases article').length===2")
        rows=page.locator('#game-publisher-releases article')
        ck('Signed releases stay scoped to selected game',
           all('table-trivia' in x for x in await rows.locator('strong').all_inner_texts()))
        ck('Future SDK release cannot stage',await rows.nth(1).locator('button').is_disabled())
        ck('Compatible release remains stageable',await rows.first.locator('button').is_enabled())
        await rows.first.locator('button').click()
        await page.wait_for_function("__calls.some(x=>x.action==='game_update_stage')")
        ck('Download command supplies game+version but no URL or key',
           await page.evaluate("""() => __calls.some(x=>x.action==='game_update_stage'
              &&x.body.game==='table-trivia'&&x.body.version==='1.2.0'
              &&!('url' in x.body)&&!('publicKey' in x.body))"""))
        await page.select_option('#game-publisher-game','table-trivia')
        await page.locator('#game-publisher-name').fill('New Trivia')
        await page.locator('#game-publisher-url').fill('https://new.example/updates.json')
        await page.locator('#game-publisher-key').fill('-----BEGIN PUBLIC KEY-----\\nmock\\n-----END PUBLIC KEY-----')
        await page.locator('#game-publisher-form').evaluate("(form) => form.requestSubmit()")
        await page.wait_for_function("__calls.some(x=>x.action==='game_publisher_connect'&&x.body.confirmReplace===true)")
        ck('Trust-key replacement requires explicit second confirmation',await page.evaluate("""() =>
          __calls.filter(x=>x.action==='game_publisher_connect').length===2
          &&__calls.filter(x=>x.action==='game_publisher_connect')[1].body.confirmReplace===true"""))
        await page.locator('#game-publisher-list article').nth(1).get_by_role('button',name='Disconnect this publisher').click()
        await page.wait_for_function("document.querySelectorAll('#game-publisher-list article').length===1")
        ck('Disconnecting one publisher retains other game trust',
           'spin-wheel' in await page.locator('#game-publisher-list').inner_text())
        ck('No uncaught browser runtime errors',not errors)
        await browser.close()
    for label,yes in checks:print(('PASS ' if yes else 'FAIL ')+label,flush=True)
    print('V5Q.23C Admin Publisher UI:',sum(1 for _,v in checks if v),'/',len(checks))
    if not all(v for _,v in checks):raise SystemExit(1)

if __name__=='__main__':asyncio.run(main())
