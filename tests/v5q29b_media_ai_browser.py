"""Explicit consent and local-only analysis for private prize recordings."""
import asyncio
from pathlib import Path
from playwright.async_api import async_playwright

ROOT=Path(__file__).resolve().parents[1]
def check(label,ok):
 print(('PASS ' if ok else 'FAIL ')+label,flush=True)
 if not ok:raise AssertionError(label)

async def main():
 src=(ROOT/'editor.php').read_text()
 fragment=src[src.index('<section class="studio-pane video-ai-editor-pane"'):src.index('</section><section class="studio-pane" id="studio-pane-filters"')+10]
 script=(ROOT/'public/editor-video-ai.js').read_text().replace('</script','<\\/script')
 async with async_playwright() as pw:
  browser=await pw.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox'])
  page=await browser.new_page();errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
  await page.set_content('''<!doctype html><html><body>'''+fragment+'''<script>
   window.Lucky={getMe:async()=>({csrf:'private-admin-csrf'})};
   window.__requests=[];window.__applied=[];
   window.LuckyEditorAgent={snapshot:()=>({session:'a'.repeat(32),signature:'v1',edits:[
    {id:'b'.repeat(32),instanceId:'first-cut',title:'Private prize movie'},
    {id:'c'.repeat(32),instanceId:'second-cut',title:'Voice recording'}],analyzable:[
    {id:'b'.repeat(32),kind:'video',title:'Private prize movie'},
    {id:'c'.repeat(32),kind:'audio',title:'Voice recording'}]}),
    apply:p=>{__applied.push(p);return p.operations.length}};
   window.fetch=async(url,opts={})=>{
    const action=new URL(String(url),'https://lucky.test/').searchParams.get('action');
    const body=opts.body?JSON.parse(opts.body):{};__requests.push({action,body,csrf:opts.headers?.['X-CSRF-Token']});
    if(action==='status')return new Response(JSON.stringify({ok:true,claude:{configured:true,model:'claude-haiku-5-5'},skills:[
      {id:'highlight',name:'Highlight reel',description:'Shorter',tools:['clip.trim']}]}));
    if(action==='analyze')return new Response(JSON.stringify({ok:true,id:body.id,localOnly:true,
      signals:{sceneCutsMs:[1000,3300],silenceRanges:[{startMs:200,endMs:900}]}}));
    if(action==='plan')return new Response(JSON.stringify({ok:true,timingCuesShared:body.includeTimingCues?2:0,
      proposal:{summary:'Shorten first clip',operations:[{tool:'clip.trim',args:{instanceId:'first-cut',startMs:1000,endMs:3000}}]}}));
   };
  </script><script>'''+script+'''</script></body></html>''',wait_until='domcontentloaded')
  await page.locator('#studio-pane-ai').evaluate('(e)=>e.hidden=false')
  await page.wait_for_function("!document.getElementById('editor-ai-generate').disabled")
  check('No recording-derived cue sharing by default',not await page.locator('#editor-ai-share-cues').is_checked())
  await page.locator('#editor-ai-generate').click()
  await page.locator('#editor-ai-review').wait_for(state='visible')
  check('First Claude request explicitly opts out of timing cues',await page.evaluate("__requests.find(x=>x.action==='plan')?.body.includeTimingCues===false"))
  await page.locator('#editor-ai-analyze').click()
  await page.wait_for_function("__requests.filter(x=>x.action==='analyze').length===2 && !document.getElementById('editor-ai-analyze').disabled")
  check('Only same-origin POST and session-bound IDs used for local analysis',await page.evaluate("__requests.filter(x=>x.action==='analyze').every(x=>x.csrf==='private-admin-csrf' && x.body.session==='a'.repeat(32) && /^[bc]{32}$/.test(x.body.id))"))
  check('Analysis itself does not make a second Claude request',await page.evaluate("__requests.filter(x=>x.action==='plan').length===1"))
  check('Result shows two analyzed local recordings',await page.locator('#editor-ai-analysis-results li').count()==2)
  await page.locator('#editor-ai-share-cues').check()
  await page.locator('#editor-ai-generate').click()
  await page.wait_for_function("__requests.filter(x=>x.action==='plan').length===2")
  check('Second Claude plan explicitly opts in to derived cues',await page.evaluate("__requests.filter(x=>x.action==='plan')[1].body.includeTimingCues===true"))
  check('No raw media, frames, or transcript supplied to Claude API',await page.evaluate("__requests.filter(x=>x.action==='plan').every(x=>!JSON.stringify(x.body).match(/transcript|data:|mediaUrl|videoFile|audioFile|src/))"))
  check('Claude proposals still require owner Apply',await page.evaluate('__applied.length===0'))
  check('Browser UI raised no script errors',not errors)
  await browser.close()
  print('V5Q.29B PRIVATE MEDIA AI BROWSER CONSENT PASSED',flush=True)

if __name__=='__main__':asyncio.run(main())
