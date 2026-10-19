(() => {
 'use strict';
 const L=window.Lucky,$=L.$;
 const wheel=new LuckyWheel($('live-wheel'));
 let proofStates={},proofSessionId='',proofPending=false;
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
  $('spin-game').disabled=busy||!active;
  $('spin-game').textContent=busy?'✦ Spinning…':view?.pendingBonus?'✧ SPIN BONUS ROUND':view?.claims?.length?'↻ SPIN AGAIN':'✦ SPIN';
  $('pause-game').disabled=busy||!view||!['active','paused'].includes(view.status);
  $('pause-game').textContent=view?.status==='paused'?'▶ Resume':'Ⅱ Pause';
  $('end-game').disabled=busy||!view||!['active','paused'].includes(view.status);
  $('game-state').textContent=!view?'READY FOR NEW GAME':active?`ACTIVE GAME · ${view.spinCount} SPINS`:view.status==='paused'?'GAME PAUSED':'GAME ENDED';
  const items=(view?.claims||[]).slice().reverse();$('session-totals').textContent=`${items.length} claimed · ${L.money(items.reduce((n,p)=>n+p.cents,0))} awarded`;
  const root=$('claim-list');root.replaceChildren();if(!items.length){const empty=document.createElement('p');empty.textContent='No prizes claimed yet. Spin the wheel to begin.';root.append(empty);}
  for(const c of items){const row=document.createElement('div');row.className='platform-claim claim-proof-row';const info=document.createElement('div');info.className='claim-proof-info';const name=document.createElement('strong');name.textContent=c.name;
   const amount=document.createElement('span');amount.textContent=`${c.tier.toUpperCase()} · ${L.money(c.cents)}`;info.append(name,amount);
   const state=proofStates[c.id];if(state?.needsRevision){const flag=document.createElement('span');flag.className='proof-revision-needed';flag.textContent='Needs revision';info.append(flag);}else if(state?.latestRevision){const flag=document.createElement('span');flag.textContent=`Proof v${state.latestRevision}`;info.append(flag);}
   const edit=document.createElement('a');edit.href=`proof.php?session=${encodeURIComponent(view.id)}&claim=${encodeURIComponent(c.id)}`;edit.textContent=state?.needsRevision?'Revise proof':'Edit proof';edit.className='btn btn-outline';row.append(info,edit);root.append(row);}
  if(!busy)wheel.setSlices(toSlices(view));
  if(data?.view?.id){$('open-game-editor').href='editor.php?session='+encodeURIComponent(data.view.id);}else $('open-game-editor').href='editor.php';
  const finished=view?.status==='ended';
  $('postgame-review').hidden=!finished;
  $('review-highlight').href=view?.id?'editor.php?session='+encodeURIComponent(view.id):'editor.php';
  if(data?.viewerToken){
    const url=new URL('display.php',document.baseURI);url.searchParams.set('session',view.id);url.searchParams.set('token',data.viewerToken);
    viewerUrl=url.href;$('share-display').hidden=false;$('viewer-link').value=viewerUrl;$('open-viewer').href=viewerUrl;
  }else $('share-display').hidden=true;
 }
 async function updateProofStates(){const session=data?.view?.id;if(!session){proofStates={};proofSessionId='';return;}
  if(proofPending)return;proofPending=true;try{const r=await fetch('api/claim-proof.php?action=summary&session='+encodeURIComponent(session),{credentials:'same-origin'});const out=await r.json();if(out.ok&&data?.view?.id===session){proofStates=out.states||{};proofSessionId=session;render();}}catch(_){/* Optional proof status recovers on next refresh. */}finally{proofPending=false;}}
 async function refresh(){const r=await L.api('last');
  data=r.data||null;lastVersion=data?.view?.version||0;activeId=data?.view?.id||'';
  syncedSpin=data?.view?.lastSpin?.spinCount||0;initialSync=false;render();updateProofStates();
 }
 // The phone applies gameplay on the server. The desktop must animate the persisted
 // result instead of trying to replay the command and awarding the prize twice.
 async function syncGame(){if(busy||syncPending||initialSync)return;syncPending=true;
  try{
   const result=await L.api('last'),fresh=result.data||null,view=fresh?.view;
   if(!view){if(data){data=null;activeId='';syncedSpin=0;lastVersion=0;render();}return;}
   const changedId=view.id!==activeId;
   if(!changedId&&view.version===lastVersion)return;
   const spin=view.lastSpin;
   const age=spin?.at?Date.now()-Date.parse(spin.at):Infinity;
   const shouldAnimate=!!spin&&(spin.spinCount>(changedId?0:syncedSpin))&&age>=-5000&&age<7500;
   activeId=view.id;lastVersion=view.version;data=fresh;updateProofStates();
   if(shouldAnimate){
    syncedSpin=spin.spinCount;busy=true;msg('A phone started a wheel spin. Syncing the result…');render();
    try{
     L.beep('tick');await wheel.spinTo(spin.index,spin.slices,matchMedia('(prefers-reduced-motion: reduce)').matches?350:3900);
     if(spin.type==='award'){
      const claim=view.claims?.[view.claims.length-1];
      if(claim){L.beep('win');msg(`🎉 ${claim.name} claimed from your phone!`);
       // The public view intentionally omits image/URL; prize details are still
       // available in the signed-in session snapshot.
       const full=fresh.session?.claims?.[fresh.session.claims.length-1];if(full)openPrize(full);
      }
     }else if(spin.type==='bonus_trigger'){L.beep('bonus');msg('✧ BONUS ROUND! Spin again to reveal the prize.');}
    }finally{busy=false;render();}
   }else{syncedSpin=spin?.spinCount||0;render();}
  }catch(e){msg('Game sync retrying: '+e.message,true);}finally{syncPending=false;}
 }
 async function start(){if(busy||data?.view?.status==='active')return;busy=true;render();
  try{const r=await L.api('start',{body:{game:'spin-wheel'}});data=r.data;activeId=data.view.id;lastVersion=data.view.version;syncedSpin=0;proofStates={};updateProofStates();msg('Game started! Spin whenever you are ready.');L.beep('bonus');L.speak('Welcome to Spin the Wheel. Start spinning!');}
  catch(e){msg(e.message,true);}finally{busy=false;render();}}
 function openPrize(c){const dialog=$('win-dialog');$('win-name').textContent=c.name;$('win-value').textContent=L.money(c.cents);
  $('win-tier').textContent=c.tier==='bonus'?'BONUS PRIZE':c.tier==='tier2'?'TIER 2':'TIER 1';
  const link=$('win-link');link.hidden=!c.url;if(c.url)link.href=c.url;else link.removeAttribute('href');
  const img=$('win-image');img.hidden=!c.image;if(c.image)img.src=c.image;else img.removeAttribute('src');
  if(!dialog.open)dialog.showModal();const prizeProfile=data?.session?.pools?.[c.tier]?.prizes?.find(p=>p.id===c.prizeId);window.LuckyCapture?.show(data?.view?.id||'',c.id,c.name,prizeProfile?.proofAngles||[]);$('spin-again')?.focus();
 }
 async function spin(){if(busy||!data||data.view.status!=='active')return;
  if($('win-dialog').open)$('win-dialog').close();busy=true;msg('The wheel is spinning…');render();
  try{
   const id=data.view.id,r=await L.api('spin',{body:{id,idempotencyKey:L.idempotency()}});
   const event=r.result.event,animation=event.animation;
   L.beep('tick');await wheel.spinTo(animation.index,animation.slices,matchMedia('(prefers-reduced-motion: reduce)').matches?350:3900);
   data=(await L.api('session',{query:{id}}));updateProofStates();lastVersion=data.view.version;syncedSpin=data.view.lastSpin?.spinCount||0;
   if(event.type==='bonus_trigger'){msg('✧ BONUS ROUND! Spin again to reveal the bonus prize.');L.beep('bonus');L.speak('Surprise bonus round!');}
   else {msg(`🎉 ${event.claim.name} · ${L.money(event.claim.cents)} claimed!`);L.beep('win');openPrize(event.claim);
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
   if(busy&&['spin','start','end','pause','resume'].includes(command))return false;
   const exactVolume=command.match(/^volume-(100|[0-9]{1,2})$/);if(exactVolume){const v=L.volume('music',Number(exactVolume[1]));$('music-volume').value=v;$('music-value').textContent=v+'%';return true;}
   if(command.startsWith('music-find-')){const slug=command.slice(11),normalize=t=>String(t).toLowerCase().normalize('NFKD').replace(/[\u0300-\u036f]/g,'').replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'');const song=L.tracks.find(t=>normalize(t.title)===slug);if(!song)throw Error('Song not found in music library.');await L.playMusic(song.id);return true;}
   switch(command){
     case 'menu':return window.LuckyConsole?.toggleMenu()??false;
     case 'back':if($('win-dialog').open){$('win-dialog').close();return true;}
       return window.LuckyConsole?.emit('back')??false;
     case 'up':case 'down':case 'left':case 'right':case 'select':return window.LuckyConsole?.emit(command)??false;
     case 'done':return window.LuckyConsole?.emit('done')??false;
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
 function setHostTab(which){for(const option of ['music','phones']){const active=option===which;const tab=$('host-tab-'+option),panel=$('host-panel-'+option);tab.setAttribute('aria-selected',String(active));tab.tabIndex=active?0:-1;panel.hidden=!active;}}
 for(const option of ['music','phones']){$('host-tab-'+option).onclick=()=>setHostTab(option);$('host-tab-'+option).onkeydown=e=>{if(e.key!=='ArrowLeft'&&e.key!=='ArrowRight')return;e.preventDefault();const next=option==='music'?'phones':'music';setHostTab(next);$('host-tab-'+next).focus();};}
 window.addEventListener('lucky:paired-phones',e=>{$('connected-phone-count').textContent=e.detail?.count?`(${e.detail.count})`:'';});
 window.LuckyHostRemote=remoteCommand;
 $('start-game').onclick=start;$('spin-game').onclick=spin;$('pause-game').onclick=pause;$('end-game').onclick=end;
 $('copy-viewer').onclick=()=>{navigator.clipboard?.writeText(viewerUrl).then(()=>msg('Display link copied.')).catch(()=>{$('viewer-link').select();});};
 $('close-win').onclick=()=>$('win-dialog').close();$('close-win-top').onclick=()=>$('win-dialog').close();$('spin-again').onclick=()=>{ $('win-dialog').close();spin();};
 $('voice-toggle').onclick=()=>{if(stopVoice){stopVoice();stopVoice=null;$('voice-toggle').textContent='🎙 Enable listening';L.note('voice-status','Microphone off.');return;}
   stopVoice=L.listen(text=>voiceInput(text).catch(e=>L.note('voice-status',e.message,true)),m=>L.note('voice-status',m));$('voice-toggle').textContent='⏹ Stop listening';};
 Promise.all([refresh(),L.loadTracks()]).then(()=>L.bindMusic()).catch(e=>msg(e.message,true));
 setInterval(syncGame,900);
})();
