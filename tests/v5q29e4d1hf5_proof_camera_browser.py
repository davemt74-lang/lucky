"""Proof phone-camera activation follows the current prize package and stays consent-gated."""
import asyncio
import re
from pathlib import Path
from playwright.async_api import async_playwright

ROOT = Path(__file__).resolve().parents[1]
markup = (ROOT / 'proof.php').read_text().split('?>', 1)[1]
script = (ROOT / 'public/proof.js').read_text().replace('</script', '<\\/script')
mock = """<script>
window.__proofPublishes = [];window.__show = false;
window.Lucky = {
 money: cents => '$' + (cents/100).toFixed(2),
 api: async (action) => {
  if (action === 'session') return {session:{
   id:'a'.repeat(32),game:'spin-wheel',prizePackage:new URL(location.href).searchParams.get('case') === 'legacy'?null:{id:'c'.repeat(32),phoneCameraEnabled:new URL(location.href).searchParams.get('case') === 'fallback'},
   claims:[{id:'b'.repeat(32),name:'Test prize',tier:'standard',cents:1500,at:'Today',prizeId:'d'.repeat(32)}]
  }};
  if (action === 'prize_packages') {
   if (new URL(location.href).searchParams.get('case') === 'fallback') throw Error('Offline');
   return {selected:'c'.repeat(32),packages:[{id:'c'.repeat(32),phoneCameraEnabled:['enabled','legacy'].includes(new URL(location.href).searchParams.get('case'))}]};
  }
  return {};
 },
 getMe: async () => ({csrf:'test'})
};
window.LuckyCapture = {
 show: () => {window.__show=true;}, hasClaim: () => !!window.__show, reviewCount: () => 0,
 phase: () => 'live', consent: () => false, refreshClips: () => {}
};
window.fetch = async (url, opts={}) => {
 if (String(url).includes('proof_state')) __proofPublishes.push(JSON.parse(opts.body));
 return new Response(JSON.stringify({ok:true,status:{latestRevision:0,needsRevision:false},revisions:[],clipCount:0}));
};
</script>"""
markup = re.sub(r'<script src="public/[^"]+"[^>]*></script>', '', markup)
markup = markup.replace('<?php shellEnd(); ?>', '')
markup = markup.replace('</dialog>', '</dialog>' + mock + '<script>' + script + '</script>', 1)

async def main():
 checks = []
 errors = []
 def check(label, ok):
  checks.append((label, bool(ok)))
 async with async_playwright() as pw:
  browser = await pw.chromium.launch(executable_path='/usr/bin/chromium', headless=True, args=['--no-sandbox'])
  for case, expected in [('enabled',True),('disabled',False),('legacy',True),('fallback',True)]:
   page = await browser.new_page()
   page.on('pageerror', lambda err: errors.append(str(err)))
   await page.route('https://lucky.test/proof.php*', lambda route: route.fulfill(body=markup, content_type='text/html'))
   await page.goto('https://lucky.test/proof.php?session='+('a'*32)+'&claim='+('b'*32)+'&case='+case)
   await page.wait_for_function('window.__proofPublishes.length > 0', timeout=6000)
   checked = await page.locator('#capture-phone-camera').is_checked()
   published = await page.evaluate('window.__proofPublishes[0].phoneCamera')
   check(case + ': host checkbox tracks live package setting', checked == expected)
   check(case + ': live proof permission matches package', published == expected)
   await page.close()
  await browser.close()
 check('No browser JavaScript errors', not errors)
 for label, ok in checks:
  print(('PASS ' if ok else 'FAIL ') + label, flush=True)
 if errors: print('Errors:', errors)
 print('HF5 proof camera defaults:', sum(ok for _,ok in checks), '/', len(checks), flush=True)
 if not all(ok for _,ok in checks): raise SystemExit(1)

if __name__ == '__main__':
 asyncio.run(main())
