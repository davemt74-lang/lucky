"""Claude admin key flow and explicit video-editor plan approval with mock responses."""
import asyncio,re
from pathlib import Path
from playwright.async_api import async_playwright

ROOT=Path(__file__).resolve().parents[1]
def check(label,condition):
 print(('PASS ' if condition else 'FAIL ')+label,flush=True)
 if not condition:raise AssertionError(label)

async def main():
 async with async_playwright() as pw:
  browser=await pw.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox'])
  admin=(ROOT/'admin.php').read_text()
  errors=[]
  admin_script=(ROOT/'public/admin-video-ai.js').read_text().replace('</script','<\\/script')
  page=await browser.new_page()
  page.on('pageerror',lambda exc:errors.append(str(exc)))
  await page.set_content('''<!doctype html><html><body>'''+admin[admin.index('<section id="tab-ai"'):admin.index('<section id="tab-history"')]+'''<script>
    window.Lucky={getMe:async()=>({csrf:'demo'})};
    window.__calls=[];window.__hasKey=false;
    window.fetch=async(url,opts={})=>{
      const action=new URL(String(url),'https://lucky.test/').searchParams.get('action');
      const body=opts.body?JSON.parse(opts.body):{};__calls.push({action,body,headers:opts.headers});
      if(action==='settings_save')__hasKey=true;
      if(action==='settings_remove')__hasKey=false;
      return new Response(JSON.stringify({ok:true,claude:{configured:__hasKey,model:body.model||'claude-haiku-5-5'},connected:true,
       skills:[{id:'highlight',name:'Highlight reel',description:'Pacing',tools:['timeline.reorder']}]}));
    };window.confirm=()=>true;
   </script><script>'''+admin_script+'''</script></body></html>''',wait_until='domcontentloaded')
  await page.locator('#video-ai-key').fill('sk-ant-example12345678901234567890')
  await page.locator('#video-ai-settings-form button[type=submit]').click()
  await page.locator('#video-ai-state').wait_for(state='visible')
  await page.wait_for_function("window.__hasKey && !document.getElementById('video-ai-test').disabled")
  check('Key saved only through authenticated POST',await page.evaluate("__calls.some(x=>x.action==='settings_save'&&x.headers['X-CSRF-Token']==='demo')"))
  check('Key immediately cleared from form',await page.locator('#video-ai-key').input_value()=='')
  check('No saved API key returned to DOM',await page.locator('body').evaluate("e=>!e.textContent.includes('sk-ant-example12345678901234567890')"))
  await page.locator('#video-ai-test').click()
  await page.wait_for_function("__calls.some(x=>x.action==='test')")
  await page.wait_for_function("!document.getElementById('video-ai-test').disabled")
  check('Connection test is explicitly requested',await page.evaluate("__calls.filter(x=>x.action==='test').length===1"))
  await page.locator('#video-ai-disconnect').click()
  await page.wait_for_function("!window.__hasKey")
  check('Disconnect deletes credential',await page.evaluate("__calls.some(x=>x.action==='settings_remove'&&x.body.confirm===true)"))
  check('Admin key UI has no script errors',not errors)

  script=(ROOT/'public/editor-video-ai.js').read_text().replace('</script','<\\/script')
  source=(ROOT/'editor.php').read_text()
  fragment=source[source.index('<section class="studio-pane video-ai-editor-pane"'):source.index('</section><section class="studio-pane" id="studio-pane-filters"')+10]
  e=await browser.new_page();editor_errors=[];e.on('pageerror',lambda exc:editor_errors.append(str(exc)))
  await e.set_content('''<!doctype html><html><body>'''+fragment+'''<script>
    window.Lucky={getMe:async()=>({csrf:'demo'})};window.__apply=[];window.__asked=[];window.__version=1;
    window.LuckyEditorAgent={snapshot:()=>({session:'a'.repeat(32),edits:[{id:'b'.repeat(32),instanceId:'seg-a',title:'Demo'}],signature:String(__version)}),
      apply:(proposal,signature)=>{if(signature!==String(__version))throw Error('Timeline changed');__apply.push(proposal);return proposal.operations.length;}};
    window.fetch=async(url,opts={})=>{const action=new URL(String(url),'https://lucky.test/').searchParams.get('action');
      if(action==='status')return new Response(JSON.stringify({ok:true,claude:{configured:true,model:'claude-haiku-5-5'},skills:[
       {id:'highlight',name:'Highlight reel',description:'Pacing and cuts',tools:['clip.trim']}]}));
      const request=JSON.parse(opts.body);__asked.push(request);
      return new Response(JSON.stringify({ok:true,proposal:{summary:'Tighten the first clip',operations:[{tool:'clip.trim',args:{instanceId:'seg-a',startMs:1000,endMs:3000}}]}}));
    };
    </script><script>'''+script+'''</script></body></html>''',wait_until='domcontentloaded')
  await e.wait_for_function("!document.getElementById('editor-ai-generate').disabled")
  await e.locator('#editor-ai-instruction').fill('Shorten this game highlight')
  await e.locator('#editor-ai-generate').click()
  await e.locator('#editor-ai-review').wait_for(state='visible')
  check('Only metadata + explicit instruction sent',await e.evaluate("__asked.length===1&&__asked[0].edits[0].instanceId==='seg-a'&&!JSON.stringify(__asked[0]).includes('src')"))
  check('Model proposal cannot apply itself',await e.evaluate("__apply.length===0"))
  await e.locator('#editor-ai-apply').click()
  check('Clicking Apply changes local unsaved edit only',await e.evaluate("__apply.length===1"))
  await e.locator('#editor-ai-generate').click()
  await e.locator('#editor-ai-review').wait_for(state='visible')
  await e.evaluate('__version=2')
  await e.locator('#editor-ai-apply').click()
  check('Stale proposal rejected without applying',await e.evaluate("__apply.length===1"))
  check('Editor AI UI has no script errors',not editor_errors)
  await browser.close()
  print('V5Q.28A CLAUDE VIDEO EDITOR BROWSER CONTRACT PASSED',flush=True)

if __name__=='__main__':asyncio.run(main())
