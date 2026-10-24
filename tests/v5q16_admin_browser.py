"""V5Q16: admin left sidebar/profile header and universal session actions."""
import asyncio
from playwright.async_api import async_playwright
from v5p_pairing import make_html

MOCK="""<script>
const __oldFetch=window.fetch;
const SAMPLE='a'.repeat(32),SAVED='b'.repeat(32),FINISHED='c'.repeat(32);
window.__sessionRows=[
 {id:SAMPLE,game:'spin-wheel',status:'active',version:1,createdAt:'2026-10-20 01:00:00',spins:2,claims:1,awardedCents:1500},
 {id:SAVED,game:'spin-wheel',status:'saved',version:3,createdAt:'2026-10-19 01:00:00',spins:1,claims:1,awardedCents:500},
 {id:FINISHED,game:'spin-wheel',status:'ended',version:4,createdAt:'2026-10-18 01:00:00',endedAt:'2026-10-18 02:00:00',spins:4,claims:2,awardedCents:1200}
];
window.__sessionActions=[];
const cfg={config:{pools:{tier1:{budgetCents:1500,prizes:[{id:'one',name:'Test',cents:1500,url:'',image:'',videoUrl:'',proofAngles:[]}]},tier2:{budgetCents:0,prizes:[]},bonus:{budgetCents:0,prizes:[]}},odds:{bonusChance:12,tier1Weight:1,tier2Weight:1,bonusWeight:1}},intro:{title:'Welcome',subtitle:'',trackId:'',volume:70,announcement:true},theme:{accent:'#f6cb74',background:'#0d1423'}};
window.fetch=async(url,opts={})=>{
 const act=new URL(String(url),'https://example.test/').searchParams.get('action');
 if(act==='manifest')return new Response(JSON.stringify({ok:true,games:[{id:'spin-wheel',name:'Spin the Wheel',status:'ready',path:'game.php?game=spin-wheel'},{id:'game-2',name:'Game 2',status:'upcoming',path:null}]}));
 if(act==='settings')return new Response(JSON.stringify({ok:true,settings:cfg}));
 if(act==='history')return new Response(JSON.stringify({ok:true,history:[]}));
 if(act==='sessions')return new Response(JSON.stringify({ok:true,sessions:__sessionRows}));
 if(['save_session','resume_saved','finish_saved','end','start','delete_session'].includes(act)){
  const input=JSON.parse(opts.body||'{}');__sessionActions.push({act,input});
  if(act==='delete_session'){
   const index=__sessionRows.findIndex(x=>x.id===input.id);
   if(index>=0)__sessionRows.splice(index,1);
   return new Response(JSON.stringify({ok:true,deleted:true,removedFiles:0,cleanupWarnings:0}));
  }
  if(act==='start'){
   const item={id:'d'.repeat(32),game:input.game,status:'active',version:1,createdAt:'2026-10-20 02:00:00',spins:0,claims:0,awardedCents:0};
   __sessionRows.unshift(item);return new Response(JSON.stringify({ok:true,data:{view:{id:item.id}}}));
  }
  const item=__sessionRows.find(x=>x.id===input.id);
  if(item)item.status=act==='save_session'?'saved':act==='resume_saved'?'active':'ended';
  return new Response(JSON.stringify({ok:true,result:{view:item}}));
 }
 return __oldFetch(url,opts);
};
</script>"""
async def main():
 checks=[];errors=[]
 def ck(name,good):checks.append((name,bool(good)))
 async with async_playwright() as pw:
  browser=await pw.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox'])
  for width in (1440,375):
   page=await browser.new_page(viewport={'width':width,'height':900},reduced_motion='reduce')
   page.set_default_timeout(6000)
   page.on('pageerror',lambda e:errors.append(str(e)))
   html=make_html('admin').replace('</head>',MOCK+'</head>',1)
   await page.set_content(html,wait_until='domcontentloaded')
   await page.wait_for_function("document.querySelector('#session-game-filter').options.length===2")
   ck(f'{width}: repeated admin hero and global title removed',await page.locator('.admin-global-heading,.admin-workspace-header').count()==0 and await page.get_by_text('PLATFORM CONTROL CENTER').count()==0)
   ck(f'{width}: shared photo/avatar account dropdown',await page.locator('.header .account-menu .profile-avatar').count()==1)
   ck(f'{width}: account dropdown provides profile and secure logout',await page.locator('.header .account-dropdown a[href="account.php"]').count()==1 and await page.locator('.header .account-dropdown input[name=csrf]').count()==1)
   ck(f'{width}: sidebar includes AI Studio, cameras, game catalog, video gallery and legacy categories',await page.locator('.admin-console-sidebar [data-tab]').count()==9 and all([await page.locator(f'[data-tab={name}]').count()==1 for name in ('ai','games','cameras','sessions','videos')]))
   if width<=960:
    ck('mobile sidebar initially collapsed',await page.locator('#admin-console-sidebar').is_hidden())
    await page.locator('#admin-nav-toggle').click()
    ck('mobile section toggle expands sidebar',await page.locator('#admin-console-sidebar').is_visible())
   else:ck('desktop sidebar visible',await page.locator('#admin-console-sidebar').is_visible())
   await page.locator('[data-tab=sessions]').click()
   ck(f'{width}: sessions view opens',await page.locator('#tab-sessions').is_visible() and await page.locator('#tab-prizes').is_hidden())
   ck(f'{width}: active/saved/finished displayed',await page.locator('.admin-session-card').count()==3)
   ck(f'{width}: every status has a delete action',await page.locator('[data-session-action=delete_session]').count()==3)
   await page.locator('[data-session-action=save_session]').first.click()
   await page.wait_for_function("document.querySelectorAll('.admin-session-status[data-status=saved]').length===2")
   ck(f'{width}: save action uses idempotent API',await page.evaluate("__sessionActions.some(x=>x.act==='save_session'&&x.input.id.length===32&&x.input.idempotencyKey.length>=12)"))
   # A saved session can be resumed; another running session would be rejected by server.
   await page.locator('[data-session-action=resume_saved]').first.click()
   await page.wait_for_function("document.querySelectorAll('.admin-session-status[data-status=active]').length===1")
   ck(f'{width}: resume-saved action routed through engine',await page.evaluate("__sessionActions.some(x=>x.act==='resume_saved')"))
   ck(f'{width}: finished session has no resume action',await page.locator('.admin-session-card').last.locator('[data-session-action=resume_saved]').count()==0)
   page.once('dialog',lambda dialog: asyncio.create_task(dialog.accept('DELETE '+'c'*32)))
   await page.locator('.admin-session-card').last.locator('[data-session-action=delete_session]').click()
   await page.wait_for_function("document.querySelectorAll('.admin-session-card').length===2")
   ck(f'{width}: delete includes exact confirmation and version',await page.evaluate("__sessionActions.some(x=>x.act==='delete_session'&&x.input.confirmation==='DELETE '+ 'c'.repeat(32)&&x.input.expectedVersion===4)"))
   if width<=960:
    ck('mobile admin fits viewport without horizontal overflow',await page.evaluate("document.documentElement.scrollWidth<=innerWidth+2"))
   await page.close()
  await browser.close()
 ck('no browser runtime errors',not errors)
 for name,good in checks:print(('PASS' if good else 'FAIL')+' '+name,flush=True)
 if errors:print('Browser errors:',errors,flush=True)
 print('V5Q16 admin:',sum(v for _,v in checks),'/',len(checks),flush=True)
 if not all(good for _,good in checks):raise SystemExit(1)
if __name__=='__main__':asyncio.run(main())
