/* Claim-scoped proof editor. Revision snapshots are saved on the server, not in browser storage. */
(() => {
 'use strict';
 const $=id=>document.getElementById(id), L=window.Lucky;
 const params=new URL(location.href).searchParams;
 // Only recover a recently and explicitly selected claim; never guess the latest prize.
 let cached=null;try{const value=JSON.parse(sessionStorage.getItem('lucky-proof-origin')||'null');if(value&&Date.now()-value.at<5*60*1000)cached=value;}catch(_){}
 const useCached=!params.has('session')&&!params.has('claim');
 const session=params.get('session')||(useCached?cached?.session:'')||'',claim=params.get('claim')||(useCached?cached?.claim:'')||'';
 const winMode=params.get('mode')==='win';
 const from=params.get('from')==='claims'?'claims':params.get('from')==='game'?'game':useCached&&cached?.from==='claims'?'claims':'game';
 const returnUrl=()=>!session?'game.php':from==='claims'?'claims.php?session='+encodeURIComponent(session):'game.php?session='+encodeURIComponent(session);
 // Explicitly clear remote proof mode before the host leaves. A slight delay gives
 // the UI receiver time to acknowledge the phone's Done/Back command first.
 async function clearProofState(){
  leaving=true;
  if(!/^[a-f0-9]{32}$/.test(session))return;
  try{const me=await L.getMe();await fetch('api/controller.php?action=proof_state',{
    method:'POST',credentials:'same-origin',keepalive:true,
    headers:{'Content-Type':'application/json','X-CSRF-Token':me.csrf},
    body:JSON.stringify({session,claim,open:false})
  });}catch(_){}
 }
 function leaveProof(url,fromPhone=false){
  // Keep the claim station visible until its controls acknowledge a phone request.
  clearProofState().finally(()=>{if(fromPhone)setTimeout(()=>location.assign(url),380);else location.assign(url);});
 }

 let busy=false,proofBusy=false,leaving=false,prizeVideoUrl='';
 const closeVideo=()=>{$('proof-prize-video-player').replaceChildren();$('proof-prize-video').hidden=true;};
 $('proof-prize-video-close').onclick=closeVideo;
 window.addEventListener('lucky:proof-phase',event=>{if(['recording','countdown','processing'].includes(event.detail?.phase))closeVideo();});
 function playPrizeVideo(){
  if(window.LuckyCapture?.isBusy?.())throw Error('Finish recording before playing the prize video.');
  let url;try{url=new URL(prizeVideoUrl,document.baseURI);}catch(_){throw Error('Upload a prize video first.');}
  if(!url.pathname.endsWith('/api/index.php')||url.searchParams.get('action')!=='prize_video'||
     !/^[a-f0-9]{32}$/.test(url.searchParams.get('id')||''))throw Error('Upload a prize video first.');
  url=new URL('api/index.php?action=prize_video&id='+url.searchParams.get('id'),document.baseURI);
  const base=new URL(document.baseURI);
  if(url.origin!==base.origin||!url.pathname.endsWith('/api/index.php')||
     url.searchParams.get('action')!=='prize_video'||!/^[a-f0-9]{32}$/.test(url.searchParams.get('id')||''))
     throw Error('Upload a prize video first.');
  closeVideo();const el=document.createElement('video');el.controls=true;el.autoplay=true;el.playsInline=true;el.src=url.href;
  $('proof-prize-video-player').append(el);$('proof-prize-video-title').textContent=$('capture-prize-label').textContent||'Prize video';$('proof-prize-video').hidden=false;
  el.play().catch(()=>feedback('Tap Play in the desktop video controls.'));
  return true;
 }

 async function publishProof(){if(leaving||proofBusy||!/^[a-f0-9]{32}$/.test(session)||!/^[a-f0-9]{32}$/.test(claim))return;
  proofBusy=true;try{const me=await L.getMe();const r=await fetch('api/controller.php?action=proof_state',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':me.csrf},body:JSON.stringify({session,claim,open:true,reviewCount:window.LuckyCapture?.reviewCount?.()||0,phase:window.LuckyCapture?.phase?.()||'live',consent:window.LuckyCapture?.consent?.()||false,phoneCamera:!!$('capture-phone-camera')?.checked})});if(!r.ok){const data=await r.json().catch(()=>({}));throw Error(data.error||'The phone proof controls could not synchronize.');}}catch(e){feedback('Phone controller: '+e.message,true);}finally{proofBusy=false;}}
 window.addEventListener('lucky:proof-review',publishProof);
 window.addEventListener('lucky:proof-phase',publishProof);
 $('capture-phone-camera')?.addEventListener('change',publishProof);
 setInterval(()=>{if(!leaving&&document.visibilityState==='visible'&&window.LuckyCapture?.hasClaim?.())publishProof();},950);
 setInterval(()=>{if(!leaving&&document.visibilityState==='visible'&&$('capture-phone-camera')?.checked&&window.LuckyCapture?.hasClaim?.())void window.LuckyCapture?.refreshClips?.();},4000);
 window.addEventListener('pagehide',()=>{const data=JSON.stringify({session,claim,open:false});L.getMe().then(me=>fetch('api/controller.php?action=proof_state',{method:'POST',credentials:'same-origin',keepalive:true,headers:{'Content-Type':'application/json','X-CSRF-Token':me.csrf},body:data})).catch(()=>{});});

 function feedback(message,bad=false){const e=$('proof-errors');e.hidden=!bad;e.textContent=bad?message:'';$('proof-state').textContent=message;}
 async function call(action,body){const me=await L.getMe();const r=await fetch('api/claim-proof.php?action='+encodeURIComponent(action),{
  method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json','X-CSRF-Token':me.csrf},body:JSON.stringify({session,claim,...body})
 });const data=await r.json();if(!r.ok||!data.ok)throw Error(data.error||'Proof update failed');return data;}
 async function load(){const r=await fetch(`api/claim-proof.php?session=${encodeURIComponent(session)}&claim=${encodeURIComponent(claim)}`,{credentials:'same-origin',cache:'no-store'});
  const data=await r.json();if(!r.ok||!data.ok)throw Error(data.error||'Cannot load proof revisions');
  const {status,revisions}=data;$('proof-state').textContent=status.needsRevision?`Needs revision${status.requestNote?' · '+status.requestNote:''}`:`${status.latestRevision} saved revision${status.latestRevision===1?'':'s'} · ${data.clipCount} current clips`;
  $('proof-request').textContent=status.needsRevision?'Update revision request':'Mark needs revision';
  const root=$('proof-revision-list');root.replaceChildren();if(!revisions.length)root.textContent='No revisions saved yet.';
  for(const rev of revisions){const item=document.createElement('details');item.className='proof-revision-item';const header=document.createElement('summary');header.textContent=`Revision ${rev.number} · ${rev.at} · ${rev.clips.length} clip${rev.clips.length===1?'':'s'}`;
   const note=document.createElement('p');note.textContent=rev.note||'No note';item.append(header,note);
   for(const c of rev.clips){const line=document.createElement('div');line.className='proof-revision-clip';const label=document.createElement('span');label.textContent=`${c.title} · ${c.kind}${c.angleLabel?' · '+c.angleLabel:''}`;
    const view=document.createElement('button');view.type='button';view.className='btn btn-outline proof-load-clip';view.textContent='▶ Load in player';view.onclick=()=>{try{window.LuckyCapture?.previewSavedClip(c);}catch(e){feedback(e.message,true);}};line.append(label,view);item.append(line);}
   root.append(item);}
 }
 async function save(action){if(busy)return;busy=true;$('proof-save').disabled=true;$('proof-request').disabled=true;
  try{await call(action,{note:$('proof-note').value});$('proof-note').value='';await load();if(action==='save')feedback('New proof revision saved. Earlier revisions remain available.');}
  catch(e){feedback(e.message,true);}finally{busy=false;$('proof-save').disabled=false;$('proof-request').disabled=false;}
 }
 async function init(){try{
   $('proof-return').href=returnUrl();
   $('proof-return').addEventListener('click',e=>{e.preventDefault();leaveProof(returnUrl());},{once:true});

   if($('proof-heading-context'))$('proof-heading-context').textContent=winMode?'YOU WON · RECORD PRIZE PROOF':'PRIZE PROOF · EDIT HISTORY';
   if(!/^[a-f0-9]{32}$/.test(session)||!/^[a-f0-9]{32}$/.test(claim))throw Error('Open proof editing from a claimed-prize entry.');
   const r=await L.api('session',{query:{id:session}});
   const p=(r.session?.claims||[]).find(c=>c.id===claim);if(!p)throw Error('Claim not found in selected game.');
   prizeVideoUrl=p.videoUrl||'';
   $('proof-prize-title').textContent=p.name;$('capture-prize-label').textContent=p.name;
   $('proof-prize-context').textContent=`${p.tier.toUpperCase()} · ${L.money(p.cents)} · ${p.at||'Claimed'}`;
   $('proof-return').href=returnUrl();
   const profile=r.session?.pools?.[p.tier]?.prizes?.find(x=>x.id===p.prizeId);
   // Initialize the verified claim first so camera discovery never loses its context.
   if($('capture-phone-camera'))$('capture-phone-camera').checked=false;
   window.LuckyCapture?.show(session,claim,p.name,profile?.proofAngles||[],r.session?.game||'spin-wheel');
   $('camera-enable').disabled=false;
   // Announce active proof NOW; a slow/failed revision-history request must not
   // leave the paired phone showing gameplay controls during camera capture.
   await publishProof();
   // Baseline snapshots are idempotent, and older proof clips stay protected.
   try{await call('baseline',{});await load();}
   catch(e){feedback('Proof history: '+e.message,true);}

   $('proof-revision-form').onsubmit=e=>{e.preventDefault();save('save');};
   $('proof-request').onclick=()=>save('request');
  }catch(e){feedback(e.message,true);$('proof-revision-form').hidden=true;}
 }
 window.LuckyHostRemote=async (command,sessionId,claimId)=>{
  if(command==='proof-edit'){
   if(!/^[a-f0-9]{32}$/.test(sessionId||'')||!/^[a-f0-9]{32}$/.test(claimId||''))return false;
   return {navigate:'proof.php?'+new URLSearchParams({session:sessionId,claim:claimId,from:'game'})};
  }
  if(command==='prize-video-play')return playPrizeVideo();
  if(command.startsWith('proof-'))return await window.LuckyCapture?.phoneAction(command)??false;
  if(command==='back'||command==='done'||command==='menu'){
   leaveProof(returnUrl(),true);return true;
  }
  if(command==='spin'){
   if(!winMode)return false;
   leaveProof('game.php?session='+encodeURIComponent(session)+'&spin=1',true);return true;
  }
  return ['up','down','left','right','select'].includes(command)?(window.LuckyConsole?.emit(command)??false):false;
 };
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
})();
