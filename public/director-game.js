/* V5Q32A: server-synchronized Spin the Wheel control for a separate Director computer. */
(() => {
 'use strict';
 const $=id=>document.getElementById(id);
 if(!$('director-game-panel')||!window.Lucky)return;
 const L=window.Lucky;
 const validId=id=>typeof id==='string'&&/^[a-f0-9]{32}$/.test(id);
 const params=new URL(location.href).searchParams;
 let gameId=validId(params.get('game'))?params.get('game'):'';
 let context=null,requestPending=false,refreshPending=false,signature='',lastClaim='',operatorDirty=false;
 let cameraSignature='',lastCamera='',rundownDirty=false,rundownSource='',draftSteps=[];
 let lastStudioAttempt=0,openingRecording='';
 const feedback=(message,error=false)=>{
  const node=$('director-game-feedback');node.textContent=message;node.classList.toggle('is-error',error);
 };
 const active=()=>context&&['active','paused'].includes(context.game?.status);
 const label=(id,message)=>{const node=$(id);if(node)node.textContent=message;};
 const cueNames=[['turn_intro','Introduce turn'],['prize_reveal','Reveal prize'],
  ['reaction','Player reaction'],['costume_focus','Costume close-up'],
  ['celebration','Celebration'],['scene_cut','Scene change']];
 const triggers=[['manual','Manual'],['spin_start','Wheel starts'],
  ['prize_reveal','Prize revealed'],['costume_action','Costume action'],['next_player','Next player']];
 const optionSelect=(items,value)=>{
  const select=document.createElement('select');for(const [id,name] of items)select.add(new Option(name,id));
  select.value=value;return select;
 };
 function paintRundown(){
  const root=$('director-rundown-steps');root.replaceChildren();
  for(const [index,step] of draftSteps.entries()){
   const li=document.createElement('li');li.className='director-rundown-step';
   const title=document.createElement('strong');title.textContent=`${index+1}. ${step.cue.replaceAll('_',' ')}`;
   const trigger=optionSelect(triggers,step.trigger),cue=optionSelect(cueNames,step.cue);
   const duration=document.createElement('input');duration.type='number';duration.min='3';duration.max='45';duration.step='1';duration.value=step.durationSec;
   const detail=document.createElement('input');detail.type='text';detail.maxLength=120;detail.placeholder='Optional on-screen detail';detail.value=step.detail||'';
   const enabled=document.createElement('input');enabled.type='checkbox';enabled.checked=step.enabled;
   const wrap=(name,field)=>{const label=document.createElement('label');label.textContent=name;label.append(field);return label;};
   const actions=document.createElement('div');actions.className='director-rundown-row-actions';
   const button=(name,action,disabled=false)=>{const b=document.createElement('button');b.type='button';b.className='btn btn-outline';b.textContent=name;b.disabled=disabled||requestPending;b.addEventListener('click',action);actions.append(b);};
   const changed=()=>{step.trigger=trigger.value;step.cue=cue.value;step.durationSec=Number(duration.value);step.detail=detail.value;step.enabled=enabled.checked;
    title.textContent=`${index+1}. ${step.cue.replaceAll('_',' ')}`;rundownDirty=true;renderRundownControls();};
   for(const input of [trigger,cue,duration,detail,enabled])input.addEventListener('change',changed);
   const move=offset=>{const target=index+offset;[draftSteps[index],draftSteps[target]]=[draftSteps[target],draftSteps[index]];rundownDirty=true;paintRundown();renderRundownControls();};
   button('↑',()=>move(-1),index===0);button('↓',()=>move(1),index===draftSteps.length-1);
   button('Remove',()=>{draftSteps.splice(index,1);rundownDirty=true;paintRundown();renderRundownControls();},draftSteps.length===1);
   li.append(title,wrap('Trigger',trigger),wrap('Cue',cue),wrap('Seconds',duration),wrap('Detail',detail),wrap('Enabled',enabled),actions);
   root.append(li);
  }
 }
 function renderRundownControls(){
  const r=context?.rundown||{},available=Boolean(active()&&!requestPending);
  label('director-rundown-status',!r.enabled?'AUTO OFF':r.paused?'AUTO PAUSED':'● AUTO ON');
  $('director-rundown-save').disabled=!available||!rundownDirty;
  $('director-rundown-add').disabled=!available||draftSteps.length>=12;
  $('director-rundown-revert').disabled=!available||!rundownDirty;
  $('director-rundown-enable').disabled=!available||rundownDirty;
  $('director-rundown-enable').textContent=r.enabled?'Disable AUTO':'Enable AUTO';
  $('director-rundown-pause').disabled=!available||rundownDirty||!r.enabled;
  $('director-rundown-pause').textContent=r.paused?'Resume AUTO':'Pause AUTO';
  for(const key of ['next','skip','replay'])$('director-rundown-'+key).disabled=!available||rundownDirty||(key==='replay'&&!r.lastStepId);
 }
 function syncRundown(){
  const source=JSON.stringify(context?.rundown?.steps||[]);
  if(!rundownDirty&&source!==rundownSource){rundownSource=source;draftSteps=JSON.parse(source);paintRundown();}
  renderRundownControls();
 }
 function syncBroadcast(){
  const live=$('director-live-url')?.value||'';
  const stable=$('director-show-url')?.value||'';
  const url=live||stable;
  const onAir=Boolean(window.LuckyDirectorPublisher?.state?.().onAir&&live);
  const field=$('director-game-broadcast-url');if(field.value!==url)field.value=url;
  label('director-game-on-air',onAir?'● ON AIR':'OFF AIR');
  $('director-game-on-air').classList.toggle('is-live',onAir);
  $('director-game-broadcast-copy').disabled=!url;
  const open=$('director-game-broadcast-open');open.href=url||'live-view.php';
  open.setAttribute('aria-disabled',String(!url));
  label('director-game-broadcast-help',onAir?
   'This link shows the final Program output. Private invitations must be shared only with intended viewers.':
   stable?'Permanent public show page · OFF AIR until Program is published publicly. Private broadcasts need a separate invitation.':
   'Open a recording session to create its permanent show URL. Publishing still requires explicit consent.');
 }

 function fillClaims(){
  const select=$('director-game-claim'),claims=context?.game?.claims||[];
  const old=select.value,newest=claims.at(-1)?.id||'';
  const selected=claims.some(c=>c.id===old)?old:newest;
  const chooseNew=Boolean(newest&&newest!==lastClaim);
  select.replaceChildren(new Option(claims.length?'Choose a prize':'No awarded prizes yet',''));
  for(const claim of claims)select.add(new Option(`${claim.playerName||'Player'} · ${claim.name}`,claim.id));
  select.value=chooseNew?newest:selected;
  if(chooseNew){operatorDirty=false;lastClaim=newest;}
  if(!claims.length)lastClaim='';
 }
 function fillOperators(){
  const select=$('director-game-operator'),claimId=$('director-game-claim').value;
  const assigned=context?.assignments?.[claimId]||'';
  const old=select.value;
  select.replaceChildren(new Option('No cameraman assigned',''));
  for(const operator of context?.operators||[]){
   const option=new Option(`${operator.name}${operator.approved?' · connected':' · not approved'}`,operator.pairId);
   option.disabled=!operator.approved;select.add(option);
  }
  const oldValid=old===''||(context?.operators||[]).some(o=>o.pairId===old&&o.approved);
  const candidate=operatorDirty&&oldValid?old:assigned;
  select.value=[...select.options].some(o=>o.value===candidate&&!o.disabled)?candidate:'';
 }
 function fillCostume(){
  const select=$('director-game-costume-item');if(!select)return;
  const current=context?.game?.currentPlayer;
  const items=[...(current?.costume?.items||[]),...(current?.prizeCostumeItems||[])];
  const old=select.value;
  select.replaceChildren(new Option('Costume spotlight · general',''));
  for(const item of items)select.add(new Option(`${current.name} · ${item.name}`,item.id));
  select.value=items.some(item=>item.id===old)?old:'';
 }
 function refreshCameras(){
  const camera=window.LuckyCameraDirector;
  const sources=camera?.sources?.()||[];
  const select=$('director-game-camera-source');if(!select)return;
  const nextSignature=JSON.stringify(sources);
  if(nextSignature!==cameraSignature){
   cameraSignature=nextSignature;
   const chosen=select.value;
   select.replaceChildren(new Option(sources.length?'Choose a camera / clip':'Connect a camera or load media',''));
   for(const source of sources)select.add(new Option(source.label,source.id));
   select.value=sources.some(source=>source.id===chosen)?chosen:'';
  }
  const ready=Boolean(active()&&context?.recording?.id&&
   window.LuckyDirectorStudio?.state?.().recordingSession===context.recording.id);
  $('director-game-preview').disabled=!ready||!select.value||requestPending;
  $('director-game-take').disabled=!ready||!select.value||requestPending;
  const program=camera?.state?.().program||'';
  if(program!==lastCamera){lastCamera=program;
   label('director-game-camera-status',program?
    `Program: ${sources.find(source=>source.id===program)?.label||'connected source'}. Publish in Live View only with participant consent.`:
    'Program off. Choose a source, cue Preview, then Take.');
  }
 }
 function renderMoments(){
  const moments=context?.moments||[];
  label('director-game-moment-count',`${moments.length} moment${moments.length===1?'':'s'}`);
  const root=$('director-game-moments');root.replaceChildren();
  for(const moment of moments.slice(-40).reverse()){
   const entry=document.createElement('li'),title=document.createElement('strong'),meta=document.createElement('small');
   title.textContent=moment.label||moment.type||'Game event';
   let stamp='';try{stamp=new Date(moment.at).toLocaleTimeString([], {hour:'numeric',minute:'2-digit',second:'2-digit'});}catch(_){}
   const offset=Number(moment.offsetMs);
   const clock=moment.offsetMs!==null&&Number.isFinite(offset)
    ?` · estimated ${Math.floor(offset/60000)}:${String(Math.floor(offset%60000/1000)).padStart(2,'0')} in take`
    :' · outside recorded take';
   meta.textContent=`${stamp}${clock}`;
   entry.append(title,meta);root.append(entry);
  }
  if(!moments.length){const item=document.createElement('li');item.textContent='Game, prize, costume and show moments will appear here.';root.append(item);}
 }
 function updateCapture(){
  const studio=window.LuckyDirectorStudio?.state?.();
  const linked=Boolean(context?.recording?.id&&studio?.recordingSession===context.recording.id);
  const start=$('director-studio-take-start'),stop=$('director-studio-take-stop');
  $('director-game-record-start').disabled=!linked||!start||start.disabled;
  $('director-game-record-stop').disabled=!linked||!stop||stop.disabled;
 }
 function maybeOpenRecording(){
  const id=context?.recording?.id;
  if(!validId(id))return;
  if(window.LuckyDirectorStudio?.state?.().recordingSession===id)return;
  if(Date.now()-lastStudioAttempt<5000||openingRecording===id)return;
  if(![...$('director-studio-session').options].some(option=>option.value===id))return;
  lastStudioAttempt=Date.now();openingRecording=id;
  Promise.resolve(window.LuckyDirectorStudio?.open(id)).catch(()=>{}).finally(()=>{openingRecording='';updateCapture();});
 }
 function render(){
  const state=context?.game;
  const status=state?`${state.status.toUpperCase()} · ${state.players.length} players / ${state.hosts.length} hosts`:'No Spin the Wheel session';
  const player=state?.currentPlayer?.name||'—',prize=state?.latestPrize?.name||'Awaiting first prize';
  label('director-game-status',status);
  label('director-game-current-player',player);
  label('director-game-latest-prize',prize);
  label('director-workspace-game-name',state?[state.party,state.theme].filter(Boolean).join(' · ')||'Spin the Wheel':'Waiting for Spin the Wheel…');
  label('director-workspace-game-player',state?`Turn: ${player}`:'—');
  label('director-workspace-game-prize',state?`Prize: ${prize}`:'—');
  const stateSignature=JSON.stringify({game:state,operators:context?.operators,assignments:context?.assignments,moments:context?.moments,recording:context?.recording});
  if(stateSignature!==signature){
   signature=stateSignature;
   fillClaims();fillOperators();fillCostume();renderMoments();
  }
  $('director-game-open-wheel').href=state?`game.php?session=${encodeURIComponent(state.id)}`:'game.php';
  $('director-game-open-editor').href=context?.recording?.id
   ?`recording-editor.php?session=${encodeURIComponent(context.recording.id)}`:'admin.php?tab=recordings';
  $('director-game-assign').disabled=!active()||!$('director-game-claim').value||requestPending;
  $('director-game-next').disabled=!active()||state.players.length<2||requestPending;
  $('director-game-cues').querySelectorAll('button').forEach(button=>{button.disabled=!active()||requestPending;});
  $('director-game-clear-presentation').disabled=!active()||!context?.presentation||requestPending;
  syncRundown();syncBroadcast();
  maybeOpenRecording();updateCapture();
  refreshCameras();
 }
 async function refresh(manual=false){
  if(refreshPending||requestPending)return;
  refreshPending=true;
  try{
   const options=gameId?{query:{session:gameId}}:{};
   const response=await L.api('director_context',options);
   context=response.context||null;
   if(context?.game?.id)gameId=context.game.id;
   if(manual)feedback(context?'Live game refreshed. This screen follows changes made on the game computer.':'No active Spin the Wheel game found.');
   render();
  }catch(e){feedback(`Game sync: ${e.message}`,true);}
  finally{refreshPending=false;}
 }
 async function act(action,fields={}){
  if(!active()||requestPending)return false;
  requestPending=true;render();
  try{
   const response=await L.api('director_action',{body:{session:gameId,expectedVersion:context.game.version,
    requestKey:L.idempotency(),action,note:$('director-game-note').value.trim(),...fields}});
   context=response.context;gameId=context.game.id;signature='';operatorDirty=false;render();
   feedback(response.changed?'Show action saved and synchronized with the game screen.':'No change needed.');
   return true;
  }catch(e){feedback(`${e.message} Refreshing the synchronized game state…`,true);return false;}
  finally{requestPending=false;render();void refresh();}
 }
 for(const [key,name] of cueNames){
  const button=document.createElement('button');button.type='button';button.className='btn btn-outline';button.textContent=name;
  button.addEventListener('click',()=>void act('cue',{cue:key,claimId:$('director-game-claim').value||'',
   itemId:key==='costume_focus'?$('director-game-costume-item').value:''}));
  $('director-game-cues').append(button);
 }
 $('director-game-claim').addEventListener('change',()=>{operatorDirty=false;fillOperators();render();});
 $('director-game-operator').addEventListener('change',()=>{operatorDirty=true;});
 $('director-game-assign').addEventListener('click',()=>void act('assign_camera',{
  claimId:$('director-game-claim').value,pairId:$('director-game-operator').value}));
 $('director-game-next').addEventListener('click',()=>void act('next_player',{claimId:''}));
 $('director-game-clear-presentation').addEventListener('click',()=>void act('clear_presentation',{claimId:''}));
 const rundownControl=command=>{
  if(rundownDirty){feedback('Save or discard timeline edits before controlling the show.',true);return;}
  void act('rundown_control',{command,claimId:''});
 };
 $('director-rundown-add').addEventListener('click',()=>{
  if(draftSteps.length>=12)return;
  draftSteps.push({id:'step_'+Math.random().toString(36).slice(2,12),trigger:'manual',cue:'reaction',durationSec:8,enabled:true,detail:''});
  rundownDirty=true;paintRundown();renderRundownControls();
 });
 $('director-rundown-save').addEventListener('click',async()=>{
  if(!draftSteps.length||draftSteps.some(s=>!Number.isInteger(s.durationSec)||s.durationSec<3||s.durationSec>45)){
   feedback('Each timeline step needs a duration from 3 to 45 seconds.',true);return;
  }
  if(await act('rundown_save',{steps:draftSteps,claimId:''})){
   rundownDirty=false;rundownSource='';syncRundown();
  }
 });
 $('director-rundown-revert').addEventListener('click',()=>{rundownDirty=false;rundownSource='';syncRundown();});
 $('director-rundown-enable').addEventListener('click',()=>rundownControl(context?.rundown?.enabled?'disable':'enable'));
 $('director-rundown-pause').addEventListener('click',()=>rundownControl(context?.rundown?.paused?'resume':'pause'));
 for(const command of ['next','skip','replay'])$('director-rundown-'+command).addEventListener('click',()=>rundownControl(command));
 $('director-game-broadcast-copy').addEventListener('click',async()=>{
  const url=$('director-game-broadcast-url').value;if(!url)return;
  try{await navigator.clipboard.writeText(url);feedback('Show viewing URL copied.');}
  catch(_){$('director-game-broadcast-url').select();feedback('Select and copy the broadcast URL from the field.');}
 });
 $('director-game-camera-source').addEventListener('change',refreshCameras);
 $('director-game-preview').addEventListener('click',()=>{
  const id=$('director-game-camera-source').value;
  if(!id||!window.LuckyCameraDirector?.select?.(id))feedback('The selected Preview source is no longer available.',true);
  else feedback('Preview cued privately. Take to Program to switch the visible feed.');
 });
 $('director-game-take').addEventListener('click',()=>{
  const select=$('director-game-camera-source'),id=select.value;
  const transition=$('director-game-transition').value;
  const camera=window.LuckyCameraDirector;
  if(!id||!camera?.select?.(id)||!camera?.take?.(transition)){
   feedback('Unable to take this source to Program. Check the connected camera and recording session.',true);return;
  }
  // The camera switch is real and local to the Director compositor. Record a
  // server cue afterward so the other computer and editor can see when it cut.
  void act('program_cut',{claimId:'',sourceLabel:select.selectedOptions[0]?.text.slice(0,100)||'Camera',transition});
 });
 $('director-game-refresh').addEventListener('click',()=>void refresh(true));
 $('director-game-record-start').addEventListener('click',()=>{
  updateCapture();if(!$('director-game-record-start').disabled)$('director-studio-take-start').click();
 });
 $('director-game-record-stop').addEventListener('click',()=>{
  updateCapture();if(!$('director-game-record-stop').disabled)$('director-studio-take-stop').click();
 });
 $('director-game-open-wheel').target='_blank';$('director-game-open-wheel').rel='noopener noreferrer';
 $('director-game-open-editor').target='_blank';$('director-game-open-editor').rel='noopener noreferrer';
 window.addEventListener('lucky:director-take-start',updateCapture);
 window.addEventListener('lucky:director-take-stop',updateCapture);
 window.addEventListener('lucky:director-studio-open',updateCapture);
 window.addEventListener('lucky:director-studio-close',updateCapture);
 render();void refresh(true);
 if(validId(params.get('game')))document.querySelector('[data-director-panel="game"]')?.click();
 setInterval(()=>{if(!document.hidden)void refresh();},1400);
 setInterval(updateCapture,1000);
 setInterval(refreshCameras,900);
 setInterval(syncBroadcast,900);
})();
