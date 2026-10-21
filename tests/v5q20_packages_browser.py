"""V5Q.20 prize tabs, clone/save/activation across desktop and phone sizes."""
import asyncio
from playwright.async_api import async_playwright
from v5p_pairing import make_html
from v5q16_admin_browser import MOCK
ORIGINAL='a'*32
NEW='b'*32

PACKAGE_MOCK="""<script>
const existingFetch=window.fetch;
const ORIGINAL='a'.repeat(32),NEW='b'.repeat(32);
window.packageCalls=[];
window.packageItems=[
 {id:ORIGINAL,name:'Original Prizes',enabled:true,playerSelectable:true,publicationStatus:'published',version:1,config:structuredClone(cfg.config)},
 {id:NEW,name:'Family Night',enabled:true,playerSelectable:true,publicationStatus:'published',version:1,config:structuredClone(cfg.config)}
];
packageItems[1].config.pools.tier1.prizes[0].name='Family Prize';
window.packageSelected=ORIGINAL;
window.fetch=async(url,opts={})=>{
 const action=new URL(String(url),'https://example.test/').searchParams.get('action');
 if(action==='prize_packages')return new Response(JSON.stringify({ok:true,selected:packageSelected,packages:packageItems}));
 if(['package_save','package_create','package_select','package_delete','package_publish'].includes(action)){
  const data=JSON.parse(opts.body||'{}');packageCalls.push({action,data});
  if(action==='package_create'){
   const next='c'.repeat(32);
   const selected=packageItems.find(p=>p.id===(data.cloneFrom||packageSelected));
   packageItems.push({id:next,name:data.name,enabled:true,playerSelectable:true,publicationStatus:'draft',version:1,budgetCents:data.budgetCents??null,config:structuredClone(selected.config)});
   return new Response(JSON.stringify({ok:true,package:packageItems.at(-1)}));
  }
  if(action==='package_save'){
   const p=packageItems.find(p=>p.id===data.id);
   if(!p||p.version!==data.version)return new Response(JSON.stringify({ok:false,error:'Conflict'}),{status:409});
   Object.assign(p,{name:data.name,config:data.config,budgetCents:data.budgetCents,enabled:data.enabled,playerSelectable:data.playerSelectable,version:p.version+1});
   return new Response(JSON.stringify({ok:true,package:p}));
  }
  if(action==='package_publish'){
   const p=packageItems.find(x=>x.id===data.id);
   if(p.version!==data.version)return new Response(JSON.stringify({ok:false,error:'Conflict'}),{status:409});
   p.publicationStatus=data.published?'published':'draft';p.version++;
   return new Response(JSON.stringify({ok:true,package:{id:p.id,publicationStatus:p.publicationStatus,version:p.version}}));
  }
  if(action==='package_select'){packageSelected=data.id;return new Response(JSON.stringify({ok:true,package:{selected:packageSelected}}));}
  packageItems=packageItems.filter(p=>p.id!==data.id);
  return new Response(JSON.stringify({ok:true,package:{deleted:true}}));
 }
 return existingFetch(url,opts);
};
</script>"""

async def main():
 checks=[];errors=[]
 def check(name,value):checks.append((name,bool(value)))
 async with async_playwright() as pw:
  browser=await pw.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox'])
  for width in (1440,375):
   page=await browser.new_page(viewport={'width':width,'height':850},reduced_motion='reduce')
   page.set_default_timeout(10000)
   page.on('pageerror',lambda e:errors.append(str(e)))
   html=make_html('admin').replace('</head>',MOCK+PACKAGE_MOCK+'</head>',1)
   await page.set_content(html,wait_until='domcontentloaded')
   await page.wait_for_function("document.querySelectorAll('#prize-package-tabs button').length===2")
   check(f'{width}: packages visible as tabs',await page.locator('#prize-package-tabs button').count()==2)
   check(f'{width}: plus next to package tabs',await page.locator('#add-package').is_visible())
   check(f'{width}: Save is inside tab toolbar',await page.locator('.prize-package-header #save-prizes').is_visible())
   check(f'{width}: no redundant heading above package tabs',await page.locator('#tab-prizes>.card-title-row, #tab-prizes>p').count()==0)
   await page.locator('[data-package-id="'+NEW+'"]').click()
   check(f'{width}: each tab loads own prizes',await page.locator('#prize-items input[aria-label="Prize name"]').first.input_value()=='Family Prize')
   await page.locator('#tier-budget').fill('60')
   await page.locator('#tier-budget').dispatch_event('change')
   await page.locator('#save-prizes').click()
   await page.wait_for_function("packageCalls.some(c=>c.action==='package_save')")
   check(f'{width}: budget saved only to selected package',await page.evaluate("packageItems.find(p=>p.id===NEW).config.pools.tier1.budgetCents===6000 && packageItems.find(p=>p.id===ORIGINAL).config.pools.tier1.budgetCents===1500"))
   await page.locator('[data-package-id="'+ORIGINAL+'"]').click()
   check(f'{width}: switching back preserves original',await page.locator('#tier-budget').input_value()=='15.00')
   await page.locator('#add-package').click()
   check(f'{width}: custom package modal opens',await page.locator('#prize-package-modal').is_visible())
   await page.locator('#prize-package-modal-name').fill('Game Night Special')
   await page.locator('#prize-package-modal-budget').fill('1.00')
   await page.locator('#prize-package-modal-submit').click()
   check(f'{width}: invalid cap stays in custom modal',await page.locator('#prize-package-modal').is_visible() and await page.locator('#prize-package-modal-error').is_visible())
   await page.locator('#prize-package-modal-budget').fill('1000.00')
   await page.locator('#prize-package-modal-submit').click()
   await page.wait_for_function("document.querySelectorAll('#prize-package-tabs button').length===3")
   check(f'{width}: plus creates new complete tier layer',await page.locator('#package-name').input_value()=='Game Night Special' and await page.locator('#prize-items input[aria-label="Prize name"]').count()==1)
   check(f'{width}: optional package cap initialized',await page.locator('#package-budget').input_value()=='1000.00')
   check(f'{width}: new package starts Draft',await page.locator('#package-publication-status').inner_text()=='Draft — not available to players')
   check(f'{width}: cannot select Draft for game',await page.locator('#package-activate').is_disabled())
   await page.locator('#package-player-choice').uncheck()
   await page.locator('#package-budget').fill('1250.00')
   await page.locator('#save-prizes').click()
   await page.wait_for_function("packageItems.find(p=>p.id==='c'.repeat(32)).playerSelectable===false")
   await page.locator('#package-publish').click()
   await page.wait_for_function("packageItems.find(p=>p.id==='c'.repeat(32)).publicationStatus==='published'")
   await page.wait_for_function("document.querySelector('#package-publication-status')?.dataset.status==='published'")
   check(f'{width}: Publish changes the package status',await page.locator('#package-publication-status').inner_text()=='Published — available if enabled')
   check(f'{width}: overall package budget saved with tab',await page.evaluate("packageItems.find(p=>p.id==='c'.repeat(32)).budgetCents===125000"))
   check(f'{width}: player permissions saved with package',await page.evaluate("packageItems.find(p=>p.id==='c'.repeat(32)).playerSelectable===false"))
   await page.locator('#package-activate').click()
   await page.wait_for_function("packageSelected==='c'.repeat(32)")
   check(f'{width}: next-game selection is separate from tab editing',await page.evaluate("packageCalls.some(c=>c.action==='package_select'&&c.data.id==='c'.repeat(32))"))
   check(f'{width}: selected tab marked',await page.locator('.package-live-choice').count()==1)
   if width<500:
    check('phone-width prize page has no horizontal body scroll',await page.evaluate("document.documentElement.scrollWidth<=innerWidth+2"))
   await page.close()
  await browser.close()
 check('no browser exceptions',not errors)
 for name,value in checks:print(('PASS' if value else 'FAIL')+' '+name,flush=True)
 if errors:print('EXCEPTIONS:',errors)
 print('PRIZE PACKAGE UI',sum(v for _,v in checks),'/',len(checks),flush=True)
 if not all(v for _,v in checks):raise SystemExit(1)

if __name__=='__main__':asyncio.run(main())
