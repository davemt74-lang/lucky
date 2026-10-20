(() => {
 'use strict';
 const L=window.Lucky,$=L.$;
 const wheel=new LuckyWheel($('live-wheel'));
 let proofStates={},proofSessionId='',proofPending=false;
 let currentOffer=null,offerBusy=false,offerRouted=new Set();
 let wheelOutcome=null;
 // Private saved videos, linked to an authenticated claim, are never sent to the TV.
 let thumbSession='',thumbs=new Map(),thumbLoading=false,thumbFetchedAt=0;
 const validId=id=>typeof id==='string'&&/^[a-f0-9]{32}$/.test(id);
 async function refreshThumbs(force=false){
  const sid=data?.view?.id;if(!validId(sid))return;
  if(thumbSession!==sid){thumbSession=sid;thumbs=new Map();thumbFetchedAt=0;}
  if(thumbLoading||(!force&&Date.now()-thumbFetchedAt<7000))return;
  thumbLoading=true;thumbFetchedAt=Date.now();
  try{
   const response=await fetch('api/claim-media.php?action=list&session='+encodeURIComponent(sid),
    {credentials:'same-origin',cache:'no-store'});
   if(!response.ok)throw Error('Private media unavailable');
   const result=await response.json();if(!result.ok||!Array.isArray(result.clips))throw Error('Invalid media listing');
   if(data?.view?.id!==sid)return;
   const found=new Map();
   // The most recently saved video for a claim is its current take.
   for(const c of result.clips)if(c.sessionId===sid&&validId(c.claimId)&&validId(c.id)&&c.kind==='video')
    found.set(c.claimId,c.id);
   const changed=found.size!==thumbs.size||[...found].some(([id,clip])=>thumbs.get(id)!==clip);
   thumbs=found;if(changed)render();
  }catch(_){/* No saved video yet or an installation awaiting upgrade. */}
  finally{thumbLoading=false;}
 }

 let data=null,busy=false,stopVoice=null,lastVersion=0,activeId='',viewerUrl='',syncedSpin=0,initialSync=true,syncPending=false;
 const msg=(s,bad=false)=>L.note(bad?'game-error':'game-message',s,bad);
 function toSlices(view){
   if(view?.pendingBonus)return (data?.session?.pools?.bonus?.prizes||[]).filter(p=>p.cents>0 && !(data?.session?.claims||[]).some(c=>c.prizeId===p.id)).map(p=>({...p,tier:'bonus',kind:'prize'}));
   const slices=(view?.slots||[]).map(p=>({name:p.name,cents:p.value,tier:p.tier,kind:p.tier?'prize':'empty'}));
   if(view?.status==='active'&&data?.session?.odds?.bonusChance>0 && (data?.session?.pools?.bonus?.prizes||[]).some(p=>p.cents>0 && !(data?.session?.claims||[]).some(c=>c.prizeId===p.id)))slices.push({name:'BONUS ROUND',kind:'trigger',tier:'bonus'});
   return slices;
 }
 function render(){
  const view=data?.view;const active=view?.status==='active';
  $('start-game').disabled=busy||active||view?.status==='paused';
  $('start-game').textContent=view?'▶ New game':'▶ Start Game';
  $('spin-game').disabled=busy||!active||view?.spinPending||currentOffer?.status==='pending';
  $('spin-game').textContent=busy?'✦ Spinning…':view?.pendingBonus?'✧ SPIN BONUS ROUND':view?.claims?.length?'↻ SPIN AGAIN':'✦ SPIN';
  $('pause-game').disabled=busy||!view||!['active','paused'].includes(view.status);
  $('pause-game').textContent=view?.status==='paused'?'▶ Resume':'Ⅱ Pause';
  $('end-game').disabled=busy||!view||!['active','paused'].includes(view.status);
  $('game-state').textContent=!view?'READY FOR NEW GAME':active?`ACTIVE GAME · ${view.spinCount} SPINS`:view.status==='paused'?'GAME PAUSED':'GAME ENDED';
  // The public TV view intentionally omits claim IDs. The signed-in session owns
  // the authoritative claim IDs required for proof capture and revision history.
  const actualClaims=data?.session?.claims||[];
   const visibleClaims=(view?.spinPending&&view.lastSpin?.type==='award')?actualClaims.slice(0,-1):actualClaims;
   const items=visibleClaims.slice().reverse();
  // Count the actual unclaimed prize values across all three configured tiers.
  // Pool budgets can include unused money and must not be treated as winnable prizes.
  const claimed=visibleClaims;
  const claimedKeys=new Set(claimed.map(c=>`${c.tier}:${c.prizeId}`));
  const pools=data?.session?.pools||{};
  const available=['tier1','tier2','bonus'].reduce((total,tier)=>total+(pools[tier]?.prizes||[])
    .reduce((sum,p)=>sum+(p.cents>0&&!claimedKeys.has(`${tier}:${p.id}`)?p.cents:0),0),0);
  const awarded=items.reduce((n,p)=>n+p.cents,0);
  $('claims-page-link').href=view?.id?'claims.php?session='+encodeURIComponent(view.id):'game.php';
  $('claims-page-link').hidden=!view?.id;
  $('session-totals').textContent=`${items.length} claimed · ${L.money(awarded)} awarded · ${L.money(available)} available to win`;
  const root=$('claim-list');
  const signature=JSON.stringify({session:view?.id||'',claims:items.map(c=>[c.id,c.name,c.tier,c.cents,
   proofStates[c.id]?.needsRevision||false,proofStates[c.id]?.latestRevision||0,
   thumbSession===view?.id?thumbs.get(c.id)||'':''])});
  // Keep thumbnail elements attached through frequent game-state polls.
  if(root.dataset.claimSignature!==signature){
   root.dataset.claimSignature=signature;root.replaceChildren();
   if(!items.length){const empty=document.createElement('p');empty.textContent='No prizes claimed yet. Spin the wheel to begin.';root.append(empty);}
   for(const c of items){
    const row=document.createElement('div');row.className='platform-claim claim-proof-row';row.dataset.claimId=c.id;
    const thumb=thumbSession===view?.id?thumbs.get(c.id):null;
    if(validId(thumb)){
     const preview=document.createElement('video');preview.className='claim-video-thumbnail';
     preview.muted=true;preview.defaultMuted=true;preview.playsInline=true;
     preview.preload='metadata';preview.setAttribute('aria-label',c.name+' recorded prize video');
     preview.src='api/claim-media.php?action=stream&id='+encodeURIComponent(thumb);
     preview.addEventListener('loadedmetadata',()=>{
      if(Number.isFinite(preview.duration)&&preview.duration>0.2){
       try{preview.currentTime=Math.min(0.15,preview.duration/2);}catch(_){}
      }
     },{once:true});row.append(preview);
    }
    const info=document.createElement('div');info.className='claim-proof-info';const name=document.createElement('strong');name.textContent=c.name;
    const amount=document.createElement('span');amount.textContent=`${c.tier.toUpperCase()} · ${L.money(c.cents)}`;info.append(name,amount);
    const state=proofStates[c.id];if(state?.needsRevision){const flag=document.createElement('span');flag.className='proof-revision-needed';flag.textContent='Needs revision';info.append(flag);}
    else if(state?.latestRevision){const flag=document.createElement('span');flag.textContent=`Proof v${state.latestRevision}`;info.append(flag);}
    const edit=document.createElement('a');if(!validId(c.id)){edit.textContent='Proof unavailable';edit.className='btn btn-outline';edit.setAttribute('aria-disabled','true');row.append(info,edit);root.append(row);continue;}
    edit.href=`proof.php?session=${encodeURIComponent(view.id)}&claim=${encodeURIComponent(c.id)}&from=game`;
    edit.addEventListener('click',()=>{try{sessionStorage.setItem('lucky-proof-origin',JSON.stringify({session:view.id,claim:c.id,from:'game',at:Date.now()}));}catch(_){}});
    edit.textContent=state?.needsRevision?'Revise proof':'Edit proof';edit.className='btn btn-outline';row.append(info,edit);root.append(row);
   }
  }
  if(!busy)wheel.setSlices(wheelOutcome&&wheelOutcome.session===view?.id&&wheelOutcome.spinCount===view?.lastSpin?.spinCount?wheelOutcome.slices:toSlices(view));
  if(data?.view?.id){$('open-game-editor').href='editor.php?session='+encodeURIComponent(data.view.id);}else $('open-game-editor').href='editor.php';
  const finished=view?.status==='ended';
  $('postgame-review').hidden=!finished;
  $('review-highlight').href=view?.id?'editor.php?session='+encodeURIComponent(view.id):'editor.php';
  if(data?.viewerToken){
    const url=new URL('display.php',document.baseURI);url.searchParams.set('session',view.id);url.searchParams.set('token',data.viewerToken);
    viewerUrl=url.href;$('share-display').hidden=false;$('viewer-link').value=viewerUrl;$('open-viewer').href=viewerUrl;
  }else {viewerUrl='';$('share-display').hidden=true;}
  $('display-game-status').textContent=!view?'No game started':view.status==='active'?'Active':view.status==='paused'?'Paused':view.status==='ended'?'Finished':'Waiting';
  $('display-spin-count').textContent=String(view?.spinCount||0);
  $('display-screen-status').textContent=viewerUrl?'Link ready':'Awaiting session';
  $('display-help').hidden=!!viewerUrl;
  queueWheelFit();
 }
 async function updateProofStates(){const session=data?.view?.id;if(!session){proofStates={};proofSessionId='';return;}
  if(proofPending)return;proofPending=true;try{const r=await fetch('api/claim-proof.php?action=summary&session='+encodeURIComponent(session),{credentials:'same-origin'});const out=await r.json();if(out.ok&&data?.view?.id===session){proofStates=out.states||{};proofSessionId=session;render();}}catch(_){/* Optional proof status recovers on next refresh. */}finally{proofPending=false;}}
 async function refresh(){const requested=new URL(location.href).searchParams.get('session');const r=requested&&/^[a-f0-9]{32}$/.test(requested)?{data:await L.api('session',{query:{id:requested}})}:await L.api('last');
  data=r.data||null;lastVersion=data?.view?.version||0;activeId=data?.view?.id||'';wheelOutcome=null;
  syncedSpin=data?.view?.lastSpin?.spinCount||0;initialSync=false;render();updateProofStates();refreshThumbs(true);
 }
 // The phone applies gameplay on the server. The desktop must animate the persisted
 // result instead of trying to replay the command and awarding the prize twice.
 async function revealSpin(session,spinCount){
  await L.api('spin_revealed',{body:{session,spinCount}});
  const response=await L.api('session',{query:{id:session}});
  if(data?.view?.id===session){data=response;lastVersion=response.view.version;}
 }
 async function syncGame(){if(busy||syncPending||initialSync)return;syncPending=true;
  try{
   const requested=new URL(location.href).searchParams.get('session');const result=requested&&/^[a-f0-9]{32}$/.test(requested)?{data:await L.api('session',{query:{id:requested}})}:await L.api('last'),fresh=result.data||null,view=fresh?.view;
   if(!view){if(data){data=null;activeId='';syncedSpin=0;lastVersion=0;render();}return;}
   const changedId=view.id!==activeId;
   if(!changedId&&view.version===lastVersion)return;
   const spin=view.lastSpin;
   const age=spin?.at?Date.now()-Date.parse(spin.at):Infinity;
   const shouldAnimate=!!spin&&(spin.spinCount>(changedId?0:syncedSpin))&&age>=-5000&&age<7500;
   activeId=view.id;lastVersion=view.version;data=fresh;updateProofStates();refreshThumbs();
   if(shouldAnimate){
    syncedSpin=spin.spinCount;busy=true;wheelOutcome={session:view.id,spinCount:spin.spinCount,slices:spin.slices};msg('The wheel is spinning…');render();
    try{
     L.beep('tick');await wheel.spinTo(spin.index,spin.slices,matchMedia('(prefers-reduced-motion: reduce)').matches?350:3900);
     await revealSpin(view.id,spin.spinCount);
     if(spin.type==='award'){
      const claim=data?.view?.claims?.[data.view.claims.length-1];
      if(claim){L.beep('win');msg(`🎉 ${claim.name} claimed from your phone!`);
       // The public view intentionally omits image/URL; prize details are still
       // available in the signed-in session snapshot.
       pollOffer();
      }
     }else if(spin.type==='bonus_trigger'){L.beep('bonus');msg('✧ BONUS ROUND! Spin again to reveal the prize.');}
    }finally{busy=false;render();}
   }else{syncedSpin=spin?.spinCount||0;render();}
  }catch(e){msg('Game sync retrying: '+e.message,true);}finally{syncPending=false;}
 }
 async function start(){if(busy||data?.view?.status==='active')return;
  // Request browser camera permission while Start Game has a real desktop click gesture.
  // Actual capture and history live exclusively on proof.php.
  if(navigator.mediaDevices?.getUserMedia){navigator.mediaDevices.getUserMedia({video:true,audio:false}).then(stream=>{
    stream.getTracks().forEach(t=>t.stop());try{sessionStorage.setItem('lucky-cameras-authorized','1');}catch(_){}
   }).catch(()=>{/* Permission can still be granted from the proof page. */});}
  busy=true;render();
  try{const r=await L.api('start',{body:{game:'spin-wheel'}});data=r.data;if(new URL(location.href).searchParams.has('session'))history.replaceState(null,'','game.php?session='+encodeURIComponent(data.view.id));activeId=data.view.id;lastVersion=data.view.version;syncedSpin=0;proofStates={};updateProofStates();refreshThumbs(true);msg('Game started! Spin whenever you are ready.');L.beep('bonus');L.speak('Welcome to Spin the Wheel. Start spinning!');}
  catch(e){msg(e.message,true);}finally{busy=false;render();}}
 async function decideOffer(choice){
  if(!currentOffer||currentOffer.status!=='pending'||!data?.view?.id)return;
  if(choice==='declined'&&!confirm('Decline this prize and end the game?'))return;
  try{await L.api('prize_decide',{body:{session:data.view.id,claim:currentOffer.claimId,choice}});await pollOffer();}
  catch(e){msg('Prize decision: '+e.message,true);}
 }
 async function pollOffer(){
  if(offerBusy||!data?.view?.id||busy)return;offerBusy=true;
  try{const result=await L.api('prize_offer',{query:{session:data.view.id}});currentOffer=result.offer||null;
   const stage=$('prize-offer-stage');if(stage){stage.hidden=currentOffer?.status!=='pending';if(!stage.hidden)$('prize-offer-description').textContent=currentOffer.name+' · '+L.money(currentOffer.cents);}
   if(currentOffer?.status==='accepted'&&currentOffer.claimId&&!offerRouted.has(currentOffer.claimId)){
    const when=Date.parse((currentOffer.decidedAt||'').replace(' ','T')+'Z');
    let prior='';try{prior=sessionStorage.getItem('lucky-proof-accepted-'+currentOffer.claimId)||'';}catch(_){}
    if(prior!=='opened'&&Number.isFinite(when)&&Date.now()-when<90000){
     // Phone acceptance commits on the server. The existing game snapshot may predate
     // that claim; reload before routing rather than silently leaving the phone waiting.
     const refreshed=await L.api('session',{query:{id:data.view.id}});
     if(refreshed.view?.id!==data.view.id)throw Error('The accepted prize belongs to another game.');
     const row=(refreshed.session?.claims||[]).find(x=>x.id===currentOffer.claimId);
     if(!row)throw Error('Accepted prize is not available yet; retrying the handoff.');
     data=refreshed;lastVersion=refreshed.view.version;offerRouted.add(row.id);
     try{sessionStorage.setItem('lucky-proof-accepted-'+row.id,'opened');}catch(_){}
     openPrize(row);
     return;
    }
   }
   if(currentOffer?.status==='declined'&&data.view.status!=='ended'){
    data=await L.api('session',{query:{id:data.view.id}});render();msg('Prize declined. Game ended.');
   }
   render();
  }catch(e){if(!/Unknown endpoint|not available/.test(e.message))msg('Prize acceptance sync: '+e.message,true);}
  finally{offerBusy=false;}
 }
 // One proof workspace for both a new win and a later Edit Proof click.
 function openPrize(c){
  const sid=data?.view?.id;
  if(!/^[a-f0-9]{32}$/.test(sid||'')||!/^[a-f0-9]{32}$/.test(c?.id||'')){
   msg('Prize was saved, but its proof link could not be verified. Use Edit Proof from the claim list.',true);return;
  }
  try{sessionStorage.setItem('lucky-proof-origin',JSON.stringify({session:sid,claim:c.id,from:'game',at:Date.now()}));}catch(_){}
  location.assign('proof.php?session='+encodeURIComponent(sid)+'&claim='+encodeURIComponent(c.id)+'&from=game&mode=win');
 }
 async function spin(){if(busy||!data||data.view.status!=='active')return;
  busy=true;msg('The wheel is spinning…');render();
  try{
   const id=data.view.id,r=await L.api('spin',{body:{id,idempotencyKey:L.idempotency()}});
   const event=r.result.event,animation=event.animation;
   wheelOutcome={session:id,spinCount:animation.spinCount,slices:animation.slices};
   L.beep('tick');await wheel.spinTo(animation.index,animation.slices,matchMedia('(prefers-reduced-motion: reduce)').matches?350:3900);
   await revealSpin(id,animation.spinCount);updateProofStates();syncedSpin=data.view.lastSpin?.spinCount||0;
   if(event.type==='bonus_trigger'){msg('✧ BONUS ROUND! Spin again to reveal the bonus prize.');L.beep('bonus');L.speak('Surprise bonus round!');}
   else {msg(`🎉 ${event.claim.name} · ${L.money(event.claim.cents)} won! Accept or decline on the connected phone.`);L.beep('win');pollOffer();
    if($('speak-winners').checked)L.speak(`Congratulations! You won ${event.claim.name}!`);}
  }catch(e){msg(e.message,true);}finally{busy=false;render();}
 }
 async function pause(){if(busy||!data||!['active','paused'].includes(data.view.status))return;busy=true;render();
  try{const action=data.view.status==='paused'?'resume':'pause',id=data.view.id;await L.api(action,{body:{id,idempotencyKey:L.idempotency()}});data=await L.api('session',{query:{id}});lastVersion=data.view.version;msg(action==='pause'?'Game paused.':'Game resumed.');}
  catch(e){msg(e.message,true);}finally{busy=false;render();}}
 async function end(){if(busy||!data||!['active','paused'].includes(data.view.status))return;
  if(!confirm('End this game? Claimed prizes remain in session history.'))return;
  busy=true;render();try{const id=data.view.id;await L.api('end',{body:{id,idempotencyKey:L.idempotency()}});data=await L.api('session',{query:{id}});lastVersion=data.view.version;msg('Game ended. Prizes are preserved in the session archive.');L.speak('Game over. Thanks for playing!');}
  catch(e){msg(e.message,true);}finally{busy=false;render();}
 }
 async function voiceInput(text){
  const phrase=text.toLowerCase().trim();
  const action=window.LuckyCommands?.parse(phrase)||{type:'unknown'};
  const tell=s=>L.note('voice-status',s);
  switch(action.type){
    case 'spin':return spin();
    case 'select':return window.LuckyConsole?.emit('select');
    case 'up':case 'down':case 'left':case 'right':return window.LuckyConsole?.emit(action.type);
    case 'back':return window.LuckyConsole?.emit('back');
    case 'menu':return window.LuckyConsole?.emit('menu');
    case 'pause':case 'resume':return pause();
    case 'start':return start();
    case 'end':return end();
    case 'music-on':case 'music-play':if(!L.pref.musicOn)await L.toggleMusic();tell('Music on.');return;
    case 'music-off':case 'music-pause':L.stopMusic();tell('Music off.');return;
    case 'music-next':await L.nextMusic(1);tell('Playing next song.');return;
    case 'music-previous':await L.nextMusic(-1);tell('Playing previous song.');return;
    case 'music-title':{const t=L.tracks.find(x=>x.title.toLowerCase()===action.title.toLowerCase());
      if(t){await L.playMusic(t.id);tell('Playing '+t.title);return;}tell('Song not found.');return;}
    case 'mute':L.audioToggleMute(true);tell('Muted.');return;
    case 'unmute':L.audioToggleMute(false);tell('Unmuted.');return;
    case 'volume-set':{
      const channel=action.channel==='voice'?'speech':action.channel;
      if(channel==='master')for(const ch of ['music','effects','speech'])L.volume(ch,action.value);
      else L.volume(channel,action.value);
      tell('Volume '+action.value+' percent.');return;
    }
    case 'volume-step':{
      const channel=action.channel==='voice'?'speech':action.channel;
      if(channel==='master')for(const ch of ['music','effects','speech'])L.volume(ch,L.pref[ch+'Volume']+action.step);
      else L.volume(channel,L.pref[channel+'Volume']+action.step);
      tell('Volume adjusted.');return;
    }
    case 'speech-on':$('speak-winners').checked=true;tell('Spoken awards enabled.');return;
    case 'speech-off':$('speak-winners').checked=false;tell('Spoken awards disabled.');return;
    case 'effects-on':L.volume('effects',75);tell('Sound effects enabled.');return;
    case 'effects-off':L.volume('effects',0);tell('Sound effects disabled.');return;
    case 'remaining':tell(`${data?.view?.slots?.filter(s=>s.tier).length||0} live prizes on the wheel.`);return;
    case 'now-playing':tell(L.pref.musicOn?'Music is playing.':'Music is off.');return;
    case 'list-songs':tell(L.tracks.map(t=>t.title).join(', ')||'No songs in the library.');return;
    case 'home':location.href='index.php';return;
    case 'studio':location.href='admin.php';return;
    case 'library':location.href='admin.php?tab=music';return;
    case 'claims':location.href='admin.php?tab=history';return;
    case 'show-on':document.body.classList.add('game-show-mode');tell('Presentation mode enabled.');return;
    case 'show-off':document.body.classList.remove('game-show-mode');tell('Presentation mode disabled.');return;
    case 'close-prize':return window.LuckyConsole?.emit('done');
    case 'help':tell('Say start game, spin, pause game, music on, next song, volume 50, or end game.');return;
    case 'stop-listening':$('voice-toggle').click();return;
    default:
      if(/^(pause game|pause the game)$/.test(phrase)&&data?.view?.status==='active')return pause();
      if(/^(resume game|continue game)$/.test(phrase)&&data?.view?.status==='paused')return pause();
      tell(`Heard “${text}” — command not recognized.`);
  }
 }
 // A phone may request interface actions, but it never calls prize award APIs directly.
 // The logged-in host browser retains control of the session and all confirmations.
 async function remoteCommand(command){
   if(command.startsWith('proof-'))return false; // Proof commands belong to proof.php only.
   if(busy&&['spin','start','end','pause','resume'].includes(command))return false;
   const exactVolume=command.match(/^volume-(100|[0-9]{1,2})$/);if(exactVolume){const v=L.volume('music',Number(exactVolume[1]));$('music-volume').value=v;$('music-value').textContent=v+'%';return true;}
   if(command.startsWith('music-find-')){const slug=command.slice(11),normalize=t=>String(t).toLowerCase().normalize('NFKD').replace(/[\u0300-\u036f]/g,'').replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'');const song=L.tracks.find(t=>normalize(t.title)===slug);if(!song)throw Error('Song not found in music library.');await L.playMusic(song.id);return true;}
   switch(command){
     case 'menu':return window.LuckyConsole?.toggleMenu()??false;
     case 'back':window.LuckyConsole?.closeMenu();return true;
     case 'up':case 'down':case 'left':case 'right':case 'select':return window.LuckyConsole?.emit(command)??false;
     case 'done':window.LuckyConsole?.closeMenu();return true;
     case 'music-play':if(!L.pref.musicOn)await L.toggleMusic();return true;
     case 'music-pause':L.stopMusic();return true;
     case 'music-previous':if(!L.tracks.length)throw Error('No songs available.');await L.nextMusic(-1);return true;
     case 'effects-on':L.volume('effects',75);$('effects-volume').value=75;$('effects-value').textContent='75%';return true;
     case 'effects-off':L.volume('effects',0);$('effects-volume').value=0;$('effects-value').textContent='0%';return true;
     case 'speech-on':$('speak-winners').checked=true;return true;
     case 'speech-off':$('speak-winners').checked=false;return true;
     case 'home':location.href='index.php';return true;
     case 'volume-set':return false;
     case 'music-on':
       if(!L.pref.musicOn)await L.toggleMusic();
       $('music-toggle').textContent='♪ Music on';return true;
     case 'music-off':L.stopMusic();$('music-toggle').textContent='♪ Music off';return true;
     case 'music-next':if(!L.tracks.length)throw Error('Add a track to the Music Library first.');await L.nextMusic(1);return true;
     case 'volume-up':case 'volume-down':{
       const v=L.volume('music',L.pref.musicVolume+(command==='volume-up'?10:-10));$('music-volume').value=v;$('music-value').textContent=v+'%';return true;}
     case 'mute':L.audioToggleMute(true);msg('Audio muted from phone.');return true;
     case 'unmute':L.audioToggleMute(false);msg('Audio unmuted from phone.');return true;
     default:return false;
   }
 }
 const hostTabs=['game','music','phones','display'];
 // Put the existing accessible tabs directly into the on-screen page header.
 // Standalone UI fixtures without the server header retain the same controls.
 const topBar=document.querySelector('body.game-fullwidth .header');
 const tabs=document.querySelector('.host-main-tabs');
 if(topBar&&tabs){
  const nav=topBar.querySelector('.nav');
  if(nav)topBar.insertBefore(tabs,nav);else topBar.append(tabs);
  tabs.classList.add('host-header-tabs');
  document.querySelector('.host-compact-top')?.remove();
 }
 let wheelFitScheduled=false;
 function fitWheelViewport(){
  wheelFitScheduled=false;
  const wrap=document.querySelector('#host-panel-game .platform-wheel-wrap');
  if(!wrap||$('host-panel-game')?.hidden)return;
  const controls=$('spin-game')?.closest('.platform-control-row');
  const top=wrap.getBoundingClientRect().top;
  const controlsHeight=controls?.getBoundingClientRect().height||54;
  const offer=$('prize-offer-stage');
  const offerHeight=offer&&!offer.hidden?offer.getBoundingClientRect().height+12:0;
  const available=Math.floor(innerHeight-top-controlsHeight-offerHeight-76);
  const width=wrap.parentElement?.clientWidth||innerWidth;
  wrap.style.setProperty('--wheel-viewport-size',Math.max(160,Math.min(available,width-20,780))+'px');
 }
 function queueWheelFit(){
  if(wheelFitScheduled)return;
  wheelFitScheduled=true;
  requestAnimationFrame(fitWheelViewport);
 }
 window.addEventListener('resize',queueWheelFit);
 function setHostTab(which,{focus=false}={}){
   if(!hostTabs.includes(which))return false;
   for(const option of hostTabs){const active=option===which,tab=$('host-tab-'+option),panel=$('host-panel-'+option);
     tab.setAttribute('aria-selected',String(active));tab.tabIndex=active?0:-1;panel.hidden=!active;
   }
   if(focus)$('host-tab-'+which).focus();
   if(which==='game')queueWheelFit();
   return true;
 }
 for(const option of hostTabs){const tab=$('host-tab-'+option);
   tab.onclick=()=>setHostTab(option);
   tab.onkeydown=e=>{if(!['ArrowLeft','ArrowRight','Home','End'].includes(e.key))return;
     e.preventDefault();e.stopPropagation();const i=hostTabs.indexOf(option);
     const next=e.key==='Home'?hostTabs[0]:e.key==='End'?hostTabs.at(-1):hostTabs[(i+(e.key==='ArrowRight'?1:-1)+hostTabs.length)%hostTabs.length];
     setHostTab(next,{focus:true});
   };
 }
 window.LuckyHostTabs=Object.freeze({activate:setHostTab,items:hostTabs});
 queueWheelFit();
 
 window.addEventListener('lucky:paired-phones',e=>{const count=$('host-phone-count');if(count)count.textContent=Number(e.detail?.count||0)>0?String(e.detail.count):'';});
 window.LuckyHostRemote=remoteCommand;
 $('prize-offer-accept').onclick=()=>decideOffer('accepted');$('prize-offer-decline').onclick=()=>decideOffer('declined');
 $('start-game').onclick=start;$('spin-game').onclick=spin;$('pause-game').onclick=pause;$('end-game').onclick=end;
 $('copy-viewer').onclick=()=>{navigator.clipboard?.writeText(viewerUrl).then(()=>msg('Display link copied.')).catch(()=>{$('viewer-link').select();});};
 $('voice-toggle').onclick=()=>{if(stopVoice){stopVoice();stopVoice=null;$('voice-toggle').textContent='🎙 Enable listening';L.note('voice-status','Microphone off.');return;}
   stopVoice=L.listen(text=>voiceInput(text).catch(e=>L.note('voice-status',e.message,true)),m=>L.note('voice-status',m));$('voice-toggle').textContent='⏹ Stop listening';};
 Promise.all([refresh(),L.loadTracks()]).then(()=>{
  L.bindMusic();
  const params=new URLSearchParams(location.search);
  if(params.get('spin')==='1'){
   params.delete('spin');history.replaceState(null,'',location.pathname+(params.size?'?'+params.toString():''));
   if(data?.view?.status==='active')spin();
  }
 }).catch(e=>msg(e.message,true));
 setInterval(syncGame,900);setInterval(pollOffer,800);
 setInterval(refreshThumbs,8000);
 window.addEventListener('focus',()=>refreshThumbs(true));
 document.addEventListener('visibilitychange',()=>{if(!document.hidden)refreshThumbs(true);});
})();
