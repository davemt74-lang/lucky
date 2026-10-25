"""Source-time marker buttons apply only to a selected unsaved timeline clip."""
import asyncio
from pathlib import Path
from playwright.async_api import async_playwright

ROOT=Path(__file__).resolve().parents[1]
def check(name,good):
 print(('PASS ' if good else 'FAIL ')+name,flush=True)
 if not good:raise AssertionError(name)

async def main():
 raw=(ROOT/'editor.php').read_text()
 panel=raw[raw.index('<section class="studio-pane video-ai-editor-pane"'):raw.index('</section><section class="studio-pane" id="studio-pane-filters"')+10]
 script=(ROOT/'public/editor-video-ai.js').read_text().replace('</script','<\\/script')
 async with async_playwright() as pw:
  browser=await pw.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox'])
  page=await browser.new_page();errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
  await page.set_content('''<!doctype html><html><body>'''+panel+'''<script>
    window.Lucky={getMe:async()=>({csrf:'demo'})};window.__calls=[];window.__edits=[];
    window.LuckyEditorAgent={snapshot:()=>({session:'a'.repeat(32),edits:[{id:'b'.repeat(32),instanceId:'source-v1'}],signature:'v1',analyzable:[
     {id:'b'.repeat(32),instanceId:'source-v1',kind:'video',title:'Camera one'}]}),
     applyCue:(id,time,action)=>{__edits.push({id,time,action});return 1},apply:()=>{throw Error('Not requested')}};
    window.fetch=async(url,opts={})=>{
     const action=new URL(String(url),'https://lucky.test').searchParams.get('action');__calls.push(action);
     if(action==='status')return new Response(JSON.stringify({ok:true,claude:{configured:true,model:'claude-haiku-5-5'},skills:[
       {id:'scene',name:'Scene-aware cuts',description:'Precise cuts',tools:['clip.trim']}]}));
     if(action==='analyze')return new Response(JSON.stringify({ok:true,id:'b'.repeat(32),localOnly:true,
       signals:{sceneCutsMs:[1500],silenceRanges:[{startMs:2600,endMs:3800}]}}));
    };
   </script><script>'''+script+'''</script></body></html>''',wait_until='domcontentloaded')
  await page.locator('#studio-pane-ai').evaluate('(e)=>e.hidden=false')
  await page.locator('#editor-ai-analyze').click()
  await page.wait_for_function("!document.getElementById('editor-ai-analyze').disabled")
  check('Scene marker offers reversible In, Out and Split',await page.locator('.video-ai-marker').first.locator('button').all_text_contents()==['Set In','Set Out','Split'])
  await page.locator('.video-ai-marker').first.get_by_role('button',name='Split').click()
  check('Split explicitly targets original source timestamp and timeline instance',await page.evaluate("JSON.stringify(__edits)==='[{\"id\":\"source-v1\",\"time\":1500,\"action\":\"split\"}]'"))
  await page.get_by_role('button',name='Set In at 3.8 seconds of Camera one').click()
  check('Quiet-end marker can set a new start boundary',await page.evaluate("__edits.length===2&&__edits[1].action==='in'&&__edits[1].time===3800"))
  check('No AI model request or automatic save is made by marker actions',await page.evaluate("__calls.every(x=>x==='status'||x==='analyze')"))
  check('Owner has not selected share-to-Claude checkbox',not await page.locator('#editor-ai-share-cues').is_checked())
  check('No browser script errors',not errors)
  await browser.close()
  print('V5Q.29C DIRECT TIMELINE CUE ACTIONS BROWSER PASSED',flush=True)

if __name__=='__main__':asyncio.run(main())
