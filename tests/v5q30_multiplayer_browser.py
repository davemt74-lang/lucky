"""V5Q30: multiplayer setup, themed costume layers and optional player phones."""
import asyncio
from pathlib import Path
from playwright.async_api import async_playwright
from v5q6_proof_browser import html_page

BASE=Path(__file__).resolve().parents[1]
CID='a'*32
TID='b'*32
PID='c'*32
PHONE='d'*32
MOCK=f"""
window.Lucky={{$:id=>document.getElementById(id),api:async action=>{{
 if(action==='party_studio')return {{costumes:[{{id:'{CID}',name:'Explorer',items:[
  {{id:'helmet',name:'Helmet',category:'Head',layer:1,notes:'Silver'}},
  {{id:'boots',name:'Boots',category:'Feet',layer:2,notes:''}}]}}],
  themes:[{{id:'{TID}',name:'Space Party',costumeIds:['{CID}']}}],
  parties:[{{id:'{PID}',name:'Friday Party',themeId:'{TID}',attendees:[
   {{name:'Ava',role:'player',costumeId:'{CID}'}},
   {{name:'',role:'player',costumeId:''}},
   {{name:'DJ',role:'host',costumeId:''}},
   {{name:'Audience',role:'guest',costumeId:''}}]}}]}};
 if(action==='paired_phones')return {{phones:[{{id:'{PHONE}',name:'Ava’s phone'}}]}};
 throw Error('Unexpected action '+action);
}}}};
"""

async def main():
 checks=[];errors=[]
 def check(label,value):checks.append((label,bool(value)))
 async with async_playwright() as playwright:
  browser=await playwright.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox'])
  for width in (390,1280):
   page=await browser.new_page(viewport={'width':width,'height':850})
   page.on('pageerror',lambda error:errors.append(str(error)))
   js=(BASE/'public/game-setup.js').read_text()
   await page.set_content(html_page('game.php',js,MOCK),wait_until='domcontentloaded')
   await page.evaluate("() => { window.__setupResult=null; LuckyGameSetup.open().then(value=>{ window.__setupResult=value; }); }")
   await page.locator('#multiplayer-setup').wait_for(state='visible')
   check(f'{width} default single player and host',await page.locator('#setup-players .setup-person').count()==1 and await page.locator('#setup-hosts .setup-person').count()==1)
   await page.locator('#setup-player-count').fill('3')
   await page.locator('#setup-player-count').dispatch_event('change')
   check(f'{width} expanded player count',await page.locator('#setup-players .setup-person').count()==3)
   await page.locator('#setup-party').select_option(PID)
   check(f'{width} party populates exactly two players and one host',await page.locator('#setup-players .setup-person').count()==2 and await page.locator('#setup-hosts .setup-person').count()==1)
   check(f'{width} party theme linked',await page.locator('#setup-theme').input_value()==TID)
   check(f'{width} named party player loaded',await page.locator('#setup-players .setup-name').first.input_value()=='Ava')
   check(f'{width} costume retains itemized layers',await page.locator('#setup-players .setup-person').first.locator('.setup-costume-pieces li').all_inner_texts()==['1. Helmet · Head · Silver','2. Boots · Feet'])
   await page.locator('#setup-players .setup-phone').first.select_option(PHONE)
   await page.locator('#setup-confirm').click()
   await page.wait_for_function('window.__setupResult!==null')
   result=await page.evaluate('window.__setupResult')
   check(f'{width} confirmation preserves roster, costume and phone',result['partyId']==PID and result['themeId']==TID and len(result['players'])==2 and result['players'][0]['phonePairId']==PHONE and result['players'][0]['costumeId']==CID and result['players'][1]['name']=='')
   check(f'{width} no horizontal overflow',await page.evaluate('document.documentElement.scrollWidth<=innerWidth+1'))
   await page.close()
  await browser.close()
 check('no JavaScript exceptions',not errors)
 for name,passed in checks:print(('PASS' if passed else 'FAIL'),name,flush=True)
 if errors:print('JS exceptions:',errors)
 print('V5Q30 browser:',sum(passed for _,passed in checks),'/',len(checks))
 if not all(passed for _,passed in checks):raise SystemExit(1)

if __name__=='__main__':asyncio.run(main())
