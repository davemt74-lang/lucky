"""V5Q31: mobile/desktop master costume action selection and item ID persistence."""
import asyncio
from pathlib import Path
from playwright.async_api import async_playwright
from v5q6_proof_browser import html_page

BASE=Path(__file__).resolve().parents[1]
ACTION='a'*32
COSTUME='b'*32
MOCK=f"""
window.__saved={{actions:[{{id:'equip',name:'Equip',kind:'equip'}},{{id:'remove',name:'Remove',kind:'remove'}}],costumes:[],themes:[],parties:[]}};
window.__payload=null;
window.Lucky={{$:id=>document.getElementById(id),api:async (type,opts={{}})=>{{
 const b=opts.body||{{}};
 if(type==='party_studio')return structuredClone(__saved);
 if(type==='costume_action_save'){{
  __saved.actions.push({{id:'{ACTION}',name:b.name,description:b.description,kind:'cue'}});return {{record:{{id:'{ACTION}'}}}};
 }}
 if(type==='party_studio_save'){{
  __payload=structuredClone(b);
  const item={{...b,id:b.id||'{COSTUME}'}};
  __saved.costumes=__saved.costumes.filter(x=>x.id!==item.id);__saved.costumes.push(item);
  return {{record:{{id:item.id}}}};
 }}
 throw Error('Unexpected '+type);
}}}};
"""

async def main():
 checks=[];errors=[]
 def check(label,value):checks.append((label,bool(value)))
 async with async_playwright() as playwright:
  browser=await playwright.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox'])
  for width in (390,1280):
   page=await browser.new_page(viewport={'width':width,'height':900})
   page.on('pageerror',lambda error:errors.append(str(error)))
   doc=html_page('admin.php',(BASE/'public/party-studio.js').read_text(),MOCK)
   doc=doc.replace('</style>','\n'+(BASE/'public/party-studio.css').read_text()+'</style>')
   await page.set_content(doc,wait_until='domcontentloaded')
   await page.evaluate("document.getElementById('tab-costumes').hidden=false")
   await page.locator('#studio-actions-list .party-studio-action').first.wait_for()
   check(f'{width} built-in actions',await page.locator('#studio-actions-list .party-studio-action').count()==2)
   await page.locator('#studio-action-new').click()
   await page.locator('#studio-actions-form [name=name]').fill('Reveal visor')
   await page.locator('#studio-actions-form [name=description]').fill('Hold for camera')
   await page.locator('#studio-actions-form button[type=submit]').click()
   check(f'{width} master action created',await page.locator('#studio-actions-list .party-studio-action').count()==3)
   await page.locator('[data-studio-add=item]').click()
   await page.locator('#studio-costumes-form [name=name]').fill('Astronaut')
   await page.locator('#studio-costume-items [name=itemName]').fill('Helmet')
   check(f'{width} multiple action checkboxes',await page.locator('#studio-costume-items [name=itemAction]').count()==3)
   await page.locator(f'#studio-costume-items [name=itemAction][value="{ACTION}"]').check()
   await page.locator('#studio-costumes-form button[type=submit]').click()
   payload=await page.evaluate('window.__payload')
   check(f'{width} three action IDs saved',payload['items'][0]['actionIds']==['equip','remove',ACTION])
   stable=payload['items'][0]['id']
   await page.locator('#studio-costumes-list .party-studio-record').first.click()
   await page.locator('#studio-costumes-form button[type=submit]').click()
   second=await page.evaluate('window.__payload')
   check(f'{width} action IDs and item ID stable on edit',second['items'][0]['id']==stable and ACTION in second['items'][0]['actionIds'])
   check(f'{width} no horizontal overflow',await page.evaluate('document.documentElement.scrollWidth <= innerWidth+1'))
   await page.close()
  await browser.close()
 check('no JavaScript exceptions',not errors)
 for label,passed in checks:print(('PASS' if passed else 'FAIL'),label,flush=True)
 if errors:print('JS exceptions:',errors)
 print('V5Q31 browser:',sum(ok for _,ok in checks),'/',len(checks))
 if not all(ok for _,ok in checks):raise SystemExit(1)

if __name__=='__main__':asyncio.run(main())
