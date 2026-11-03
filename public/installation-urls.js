/* Installation-aware addresses: share on the trusted LAN; operate on this origin. */
(() => {
 'use strict';
 const routes=new Set(['controller.php','display.php','package-controller.php','live-view.php']);
 const actions=new Set(['prize_image','prize_video','track']);
 const noLan='HomeServer sharing is unavailable. Connect this computer to your private network, restart HomeServer if needed, then reload this page.';
 const invalidShare='Use a sharing link from this Lucky installation. If the HomeServer network address changed, reload this page.';
 const meta=name=>document.querySelector('meta[name="'+name+'"]')?.content||'';
 const now=()=>performance.now();
 let trustedOrigin='',expiresAt=0,expiryTimer=null,refreshPending=null,refreshController=null,refreshVersion=0;
 function deployment(){
  const value=meta('lucky-deployment');
  if(value===''||value==='cloud')return 'cloud';
  if(value==='homeserver')return value;
  throw Error('The installation sharing configuration is invalid. Reload this page.');
 }
 function canonicalOrigin(raw){
  if(typeof raw!=='string')throw Error(noLan);
  const match=/^https:\/\/((?:\d{1,3}\.){3}\d{1,3}):8843$/.exec(raw);
  if(!match)throw Error(noLan);
  const octets=match[1].split('.');
  if(octets.some(part=>!/^(0|[1-9]\d{0,2})$/.test(part)||Number(part)>255))throw Error(noLan);
  const [a,b]=octets.map(Number);
  if(!(a===10||a===172&&b>=16&&b<=31||a===192&&b===168))throw Error(noLan);
  return new URL(raw).origin;
 }
 function announce(){window.dispatchEvent(new CustomEvent('lucky:installation-urls',{detail:{available:!!trustedOrigin,lanOrigin:trustedOrigin}}));}
 function invalidate(){
  trustedOrigin='';expiresAt=0;clearTimeout(expiryTimer);expiryTimer=null;announce();
 }
 function lease(raw,seconds,elapsed=0){
  const valid=canonicalOrigin(raw);
  if(!Number.isInteger(seconds)||seconds<=0||seconds>30||elapsed>=seconds*1000)throw Error(noLan);
  const changed=trustedOrigin!==valid||now()>=expiresAt;
  trustedOrigin=valid;expiresAt=now()+seconds*1000-elapsed;
  clearTimeout(expiryTimer);expiryTimer=setTimeout(invalidate,Math.max(0,expiresAt-now()));
  if(changed)announce();
 }
 function origin(){
  if(trustedOrigin&&now()>=expiresAt)invalidate();
  if(!trustedOrigin)throw Error(noLan);
  return trustedOrigin;
 }
 /** Read-only current-origin network lease; never move host credentials to LAN. */
 function refresh(){
  if(deployment()!=='homeserver')return Promise.resolve(false);
  if(refreshPending)return refreshPending;
  const version=++refreshVersion;
  refreshPending=(async()=>{
   const started=now(),controller=new AbortController();let timeout;
   refreshController=controller;
   try{
    const bounded=new Promise((_,reject)=>{timeout=setTimeout(()=>{controller.abort();reject(Error(noLan));},4000);});
    const work=(async()=>{
     const response=await fetch(new URL('api/installation-urls.php',document.baseURI).href,
      {credentials:'same-origin',cache:'no-store',redirect:'error',signal:controller.signal});
     if(!response.ok)throw Error(noLan);
     return response.json();
    })();
    const result=await Promise.race([work,bounded]);
    if(version!==refreshVersion)return false;
    if(result?.ok!==true||result.deployment!=='homeserver')throw Error(noLan);
    lease(result.lanOrigin,result.leaseSeconds,Math.max(0,now()-started));return true;
   }catch(_){if(version===refreshVersion)invalidate();return false;}
   finally{clearTimeout(timeout);if(version===refreshVersion){refreshPending=null;refreshController=null;}}
  })();
  return refreshPending;
 }
 function locationBase(){
  const base=new URL(document.baseURI);
  if(!['https:','http:'].includes(base.protocol)||base.username||base.password)throw Error(invalidShare);
  return base;
 }
 function parse(raw){
  if((typeof raw!=='string'&&!(raw instanceof URL))||String(raw).length>4096||/[\x00-\x20\x7f]/.test(String(raw)))throw Error(invalidShare);
  const url=new URL(String(raw),document.baseURI);
  if(!['https:','http:'].includes(url.protocol)||url.username||url.password||url.hash)throw Error(invalidShare);
  return url;
 }
 function sharePath(url,base){
  const directory=new URL('.',base).pathname;
  if(!routes.has(url.pathname.slice(directory.length))||!url.pathname.startsWith(directory))throw Error(invalidShare);
 }
 /** Returns a URL; query capabilities stay on this installation, never a third party. */
 function share(raw){
  const base=locationBase(),mode=deployment(),target=mode==='homeserver'?origin():base.origin;
  const url=parse(raw);sharePath(url,base);
  if(url.origin!==base.origin&&!(mode==='homeserver'&&url.origin===target))throw Error(invalidShare);
  return new URL(url.pathname+url.search,target);
 }
 /** Accept a trusted LAN invite locally without moving host authentication/cookies. */
 function localShare(raw){
  const base=locationBase(),url=parse(raw);sharePath(url,base);
  if(url.origin!==base.origin&&(deployment()!=='homeserver'||url.origin!==origin()))throw Error(invalidShare);
  return new URL(url.pathname+url.search,base.origin);
 }
 function mediaPath(url,base){
  if(url.pathname!==new URL('api/index.php',base).pathname||url.hash||url.username||url.password)return false;
  const entries=[...url.searchParams.entries()];
  return entries.length===2&&entries.filter(([key])=>key==='action').length===1&&entries.filter(([key])=>key==='id').length===1&&
   actions.has(url.searchParams.get('action'))&&/^[a-f0-9]{32}$/.test(url.searchParams.get('id')||'');
 }
 function mediaOrigin(url,base){
  if(url.origin===base.origin)return true;
  if(deployment()!=='homeserver')return false;
  // Only the actual historical HomeServer HTTP endpoint, not arbitrary local
  // services, private IPs, ports, foreign sites, or a matching pathname alone.
  if(url.protocol==='http:'&&url.port==='8833'&&['localhost','127.0.0.1'].includes(url.hostname))return true;
  try{return url.origin===origin();}catch(_){return false;}
 }
 /** Preserve external media verbatim. Repair only exact known installation uploads. */
 function media(raw){
  if(typeof raw!=='string'||raw==='')return raw;
  try{
   const base=locationBase(),url=parse(raw);
   if(mediaPath(url,base)&&mediaOrigin(url,base))return new URL(url.pathname+url.search,base.origin).href;
  }catch(_){/* Invalid/unrelated legacy values are never relabeled as local assets. */}
  return raw;
 }
 /** HomeServer assets are portable; hosted installs retain their absolute URLs. */
 function storeMedia(raw){
  const base=locationBase(),url=parse(raw);
  if(!mediaPath(url,base)||!mediaOrigin(url,base))
   throw Error('The uploaded media address is not a supported asset from this installation.');
  const result=new URL(url.pathname,base.origin);
  result.searchParams.set('action',url.searchParams.get('action'));result.searchParams.set('id',url.searchParams.get('id'));
  if(deployment()==='cloud')return result.href;
  if(!/^\/(?:[A-Za-z0-9._~-]+\/)*api\/index\.php$/.test(result.pathname))
   throw Error('The uploaded media address is not a supported asset from this installation.');
  return result.pathname+result.search;
 }
 window.LuckyInstallationUrls=Object.freeze({share,localShare,media,storeMedia,refresh});
 if(meta('lucky-deployment')==='homeserver'){
  const rawSeconds=meta('lucky-lan-lease-seconds');
  // Metadata was minted before this navigation completed. Do not grant a new
  // lifetime merely because the deferred helper or its HTML arrived late.
  try{lease(meta('lucky-lan-origin'),/^(?:[0-9]|[12][0-9]|30)$/.test(rawSeconds)?Number(rawSeconds):0,Math.max(0,now()));}catch(_){invalidate();}
  void refresh();setInterval(()=>void refresh(),10000);
  const resume=()=>{
   // Discard any response started before a freeze/sleep; some platforms pause
   // their monotonic clock during sleep, so elapsed-time checks alone cannot
   // certify that an old in-flight reply represents the current network.
   ++refreshVersion;refreshController?.abort();refreshController=null;refreshPending=null;
   invalidate();void refresh();
  };
  document.addEventListener('visibilitychange',()=>{if(document.visibilityState==='visible')resume();});
  window.addEventListener('pageshow',event=>{if(event.persisted)resume();});
 }
})();
