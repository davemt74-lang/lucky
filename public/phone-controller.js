(() => {
 'use strict';const $=id=>document.getElementById(id),storageKey='lucky-phone-controller-v5p';
 const params=new URLSearchParams(location.search||(location.protocol==='about:'?new URL(document.baseURI).search:''));let state=null,busy=false,active=false,game=null,gameVersion=-1,pendingCommand=null,feedback=null;let speech=null,listening=false,statusBusy=false,commandQueue=[];
 let noticeTimer=null,failures=0,proofState={active:false},hadUpgradeWarning=false,lastConnectionError='';
 const message=(m,warning=false)=>{const e=$('phone-status');if(!e)return;clearTimeout(noticeTimer);e.textContent=m;e.classList.toggle('platform-error',warning);
  if(active)noticeTimer=setTimeout(()=>{e.textContent='';e.classList.remove('platform-error');},warning?6500:2300);
 };
 const show=(name)=>{for(const id of ['phone-join','phone-wait','phone-controls','phone-dashboard'])$(id).hidden=id!==name;
  $('phone-disconnect').hidden=name==='phone-join';};
 const safeName=()=>String($('phone-name').value||'').trim().slice(0,48);
 function forget(){lastPrizeOffer='';clearPrizeVideo();try{window.speechSynthesis?.cancel();}catch(_){}toggleListening(false);commandQueue=[];feedback=null;pendingCommand=null;game=null;gameVersion=-1;updateProof(null);state=null;try{sessionStorage.removeItem(storageKey);}catch(_){}active=false;show('phone-join');message('Connection expired. Scan a new QR code on the host screen.',true);}
 async function request(action,body={},authenticated=true){const headers={'Content-Type':'application/json'};
  if(authenticated){if(!state)throw Error('Phone not paired.');headers['X-Controller-Token']=state.token;body={id:state.id,...body};}
  const response=await fetch('api/controller.php?action='+encodeURIComponent(action),{method:'POST',headers,credentials:'omit',cache:'no-store',body:JSON.stringify(body)});
  let data;try{data=await response.json();}catch(_){throw Error(`Game server returned HTTP ${response.status}. Check the hosting error log.`);}
  if(!response.ok||!data.ok)throw Error((data.error||'Connection failed.')+` (HTTP ${response.status})`);return data;
 }
 let dashboardGames=[],dashboardLoading=false,dashboardRequested=location.hash==='#dashboard';
 function updateDashboardPackages(){
  const picker=$('phone-dashboard-package-picker'),select=$('phone-dashboard-package-select');
  if(!picker||!select)return;
  const options=(game?.packages||[]).filter(p=>p.enabled&&p.playerSelectable&&(p.publicationStatus||'published')==='published');
  picker.hidden=options.length<2||!!game?.upgradeRequired;
  const signature=JSON.stringify(options.map(p=>[p.id,p.name]));
  if(select.dataset.signature!==signature){
   select.dataset.signature=signature;select.replaceChildren();
   for(const p of options)select.add(new Option(p.name,p.id));
  }
  if(options.some(p=>p.id===game?.selectedPackage))select.value=game.selectedPackage;
  select.disabled=!active||busy||game?.status==='active';
 }
 function dashboardNote(text,bad=false){const e=$('phone-dashboard-status');if(e){e.textContent=text;e.classList.toggle('platform-error',bad);}}
 async function loadDashboard(){
  if(dashboardLoading||!active)return;
  dashboardLoading=true;
  try{
   const result=await request('dashboard_games');
   dashboardGames=Array.isArray(result.games)?result.games:[];
   const root=$('phone-dashboard-games');root.replaceChildren();
   for(const row of dashboardGames){
    const card=document.createElement('article');card.className='surface phone-dashboard-tile';
    const icon=document.createElement('span');icon.className='phone-dashboard-icon';icon.textContent='✦';icon.setAttribute('aria-hidden','true');
    const name=document.createElement('h2');name.textContent=row.title;
    const publisher=document.createElement('p');publisher.textContent=row.publisher+(row.hasPhone?' · Phone controls':' · Desktop controls');
    const btn=document.createElement('button');btn.type='button';btn.className='btn btn-primary';
    btn.textContent='▶ Start '+row.title;btn.addEventListener('click',()=>launchFromPhone(row.id,btn));
    card.append(icon,name,publisher,btn);root.append(card);
   }
   dashboardNote(dashboardGames.length?'Select a game to begin on your desktop or TV.':'No games are available yet.');
  }catch(e){dashboardNote('Game library unavailable: '+e.message,true);}
  finally{dashboardLoading=false;}
 }
 let launchingGame=false;
 async function launchFromPhone(id,button){
  if(!active||launchingGame||!dashboardGames.some(g=>g.id===id))return;
  launchingGame=true;button.disabled=true;dashboardNote('Starting the selected game on the desktop…');
  const nonce=(crypto.randomUUID?.().replace(/-/g,'')||'ph'+Date.now().toString(36)+Math.random().toString(36).slice(2)).padEnd(14,'0');
  try{
   const res=await request('dashboard_launch',{game:id,nonce,confirm:true});
   const out=res.launch;
   if(!out||out.game!==id||!/^[a-f0-9]{32}$/.test(out.id))throw Error('The game launch receipt was invalid.');
   dashboardRequested=false;
   if(out.game==='spin-wheel'){
    dashboardNote('Spin the Wheel started. Opening phone controls…');
    show('phone-controls');await status();return;
   }
   const selected=dashboardGames.find(row=>row.id===out.game);
   if(selected?.hasPhone===false){
    dashboardRequested=true;
    dashboardNote(out.game+' started on your TV. This game uses desktop controls; finish it on the computer before choosing another.');
    return;
   }
   if(!/^[a-f0-9]{48}$/.test(out.viewer))throw Error('The custom game controller link is unavailable.');
   sessionStorage.setItem('lucky-package-controller-v1',JSON.stringify({
    id:state.id,token:state.token,session:out.id,viewer:out.viewer
   }));
   dashboardNote('Game started. Opening its own phone controller…');
   location.assign('package-controller.php?'+new URLSearchParams({session:out.id,viewer:out.viewer}));
  }catch(e){dashboardNote('Could not start the game: '+e.message,true);}
  finally{launchingGame=false;button.disabled=false;}
 }
 async function status(){if(!state||busy||statusBusy)return;statusBusy=true;
  try{const data=await request('status',pendingCommand?{commandId:pendingCommand.id}:{});if(data.status==='paired'){const wasActive=active,wasFailing=failures>=3;failures=0;active=true;
    const needsDashboard=dashboardRequested||(!data.game?.upgradeRequired&&!data.proof?.active&&['ready','saved','ended'].includes(data.game?.status));
    if(needsDashboard){
     show('phone-dashboard');game=data.game;clearPrizeVideo();updateProof(null);updateDashboardPackages();
     if(!wasActive||!dashboardGames.length)void loadDashboard();
    }else{show('phone-controls');updateGame(data.game);updateProof(data.proof);}
    if((!wasActive||wasFailing)&&!data.game?.upgradeRequired)message(wasFailing?'Connected again.':'Phone connected.');if(pendingCommand){
    if(data.delivery==='executed'){feedback={text:`${pendingCommand.label} completed on game screen.`,until:Date.now()+6500};message(feedback.text);appendChat(feedback.text);pendingCommand=null;}
    else if(data.delivery==='failed'){const err=`${pendingCommand.label} failed: ${data.reason||'The screen could not perform this action.'}`;message(err,true);appendChat(err);pendingCommand=null;}
    else if(data.delivery==='expired'){const err=`${pendingCommand.label} was not executed. Open the game/TV screen and try again.`;message(err,true);appendChat(err);pendingCommand=null;}
    else message(`Waiting for game screen to execute ${pendingCommand.label}…`);
   }}
   else if(data.status==='pending'){active=false;show('phone-wait');message('Waiting for host approval…');}
   else {forget();}}
  catch(e){if(/invalid|disconnected|expired|authorization/i.test(e.message))forget();else {failures++;if(failures>=3&&(failures===3||e.message!==lastConnectionError)){lastConnectionError=e.message;message('Connection interrupted: '+e.message+' Retrying…',true);}}}
  finally{statusBusy=false;if(!pendingCommand&&commandQueue.length&&active&&!busy){const next=commandQueue.shift();setTimeout(()=>send(next),0);}}
 }
 async function join(ev){ev.preventDefault();const name=safeName();if(!name){message('Please enter a phone name.',true);return;}
  const id=params.get('invite')||'',key=params.get('key')||'',session=params.get('session')||'',viewer=params.get('viewer')||'';
  busy=true;$('phone-join-form').querySelector('button').disabled=true;
  try{const fromTv=!!session&&!!viewer;const result=await request(fromTv?'claim_display':'claim',fromTv?{session,viewer,name}:{id,key,name},false);
   state={id:result.id,token:result.controllerToken};try{sessionStorage.setItem(storageKey,JSON.stringify(state));}catch(_){}
   if(location.protocol!=='about:')history.replaceState(null,'','controller.php');show('phone-wait');message('Connection requested. The host must approve your phone.');
  }catch(e){message(e.message,true);}finally{busy=false;$('phone-join-form').querySelector('button').disabled=false;}
 }

 let lastPrizeOffer='',prizeMedia=null;
 function safePrizeVideo(raw){
  if(typeof raw!=='string'||!raw||raw.length>2048)return null;
  let url;try{url=new URL(raw);}catch(_){return null;}
  if(url.protocol!=='https:'||url.username||url.password)return null;
  if(['.mp4','.webm','.m4v','.ogv'].some(ext=>url.pathname.toLowerCase().endsWith(ext)))return {kind:'video',src:url.href};
  const host=url.hostname.toLowerCase();let id='';
  if(host==='youtu.be'||host==='www.youtu.be')id=url.pathname.split('/')[1]||'';
  else if(['youtube.com','www.youtube.com','m.youtube.com','youtube-nocookie.com','www.youtube-nocookie.com'].includes(host)){
   id=url.pathname==='/watch'?(url.searchParams.get('v')||''):((url.pathname.startsWith('/embed/')||url.pathname.startsWith('/shorts/'))?(url.pathname.split('/')[2]||''):'');
  }
  if(/^[A-Za-z0-9_-]{11}$/.test(id))return {kind:'iframe',src:'https://www.youtube-nocookie.com/embed/'+id+'?autoplay=1&mute=1&playsinline=1'};
  if(['vimeo.com','www.vimeo.com','player.vimeo.com'].includes(host)){
   const parts=url.pathname.split('/').filter(Boolean);id=((parts.length===1||parts.length===2&&parts[0]==='video')&&/^[0-9]{1,12}$/.test(parts[parts.length-1]||''))?parts[parts.length-1]:'';
   if(id)return {kind:'iframe',src:'https://player.vimeo.com/video/'+id+'?autoplay=1&muted=1'};
  }
  return null;
 }
 function speakOffer(offer){
  if(!offer)return;
  if(!window.speechSynthesis||typeof SpeechSynthesisUtterance!=='function'){message('Speech unavailable. Your prize is shown below.',true);return;}
  try{window.speechSynthesis.cancel();const line=new SpeechSynthesisUtterance('Congratulations! You won '+offer.name+'!');
   line.lang='en-US';line.rate=.93;window.speechSynthesis.speak(line);
  }catch(_){message('Tap Hear prize again to allow phone speech.',true);}
 }
 function clearPrizeVideo(){
  const target=$('phone-offer-media');if(target){target.replaceChildren();target.hidden=true;}
  prizeMedia=null;$('phone-offer-play-video').hidden=true;
 }
 function showPrizeVideo(entry){
  clearPrizeVideo();if(!entry)return;
  const target=$('phone-offer-media');let player;
  if(entry.kind==='video'){player=document.createElement('video');player.src=entry.src;player.playsInline=true;player.autoplay=true;player.muted=true;player.controls=true;player.preload='metadata';}
  else {player=document.createElement('iframe');player.src=entry.src;player.title='Prize video';player.loading='eager';player.referrerPolicy='no-referrer';
   player.allow='autoplay; encrypted-media; picture-in-picture';player.setAttribute('sandbox','allow-scripts allow-same-origin allow-presentation');}
  player.className='phone-prize-player';target.replaceChildren(player);target.hidden=false;
  prizeMedia=player;$('phone-offer-play-video').hidden=false;
  if(entry.kind==='video')player.play().catch(()=>{});
 }
 function presentPrize(offer){
  if(!offer||offer.status!=='pending'){if(lastPrizeOffer){lastPrizeOffer='';clearPrizeVideo();}return;}
  const key=String(game?.id||'')+':'+String(offer.claimId||'');
  if(lastPrizeOffer===key)return;
  lastPrizeOffer=key;showPrizeVideo(safePrizeVideo(offer.videoUrl||''));
  const storage='lucky-prize-announced-'+key;
  let seen=false;try{seen=sessionStorage.getItem(storage)==='1';}catch(_){}
  if(!seen){try{sessionStorage.setItem(storage,'1');}catch(_){}speakOffer(offer);}
 }
 function applyMode(){
  const proof=!!proofState?.active,offer=!proof&&game?.offer?.status==='pending';
  const gameShell=$('phone-gameplay-shell'),proofMode=$('phone-proof-mode'),audio=$('phone-game-audio'),offerMode=$('phone-prize-offer');
  if(gameShell)gameShell.hidden=proof||offer;
  if(proofMode)proofMode.hidden=!proof;
  if(audio)audio.hidden=proof||offer;
  if(offerMode)offerMode.hidden=!offer;
  if(offer&&$('phone-offer-name'))$('phone-offer-name').textContent=game.offer.name+' · $'+(Number(game.offer.cents)/100).toFixed(2);
  presentPrize(offer?game.offer:null);
 }
 function updateGame(current){
  if(!current)return;game=current;applyMode();
  const picker=$('phone-package-picker'),select=$('phone-package-select');
  if(picker&&select){
   const canChoose=['ready','saved','ended'].includes(current.status);
   const options=(current.packages||[]).filter(p=>p.enabled&&p.playerSelectable&&(p.publicationStatus||'published')==='published');
   picker.hidden=!canChoose||options.length<2||!!current.offer;
   const signature=JSON.stringify(options.map(p=>[p.id,p.name]));
   if(select.dataset.signature!==signature){
    select.dataset.signature=signature;select.replaceChildren();
    for(const p of options)select.add(new Option(p.name,p.id));
   }
   const available=options.find(p=>p.id===current.selectedPackage);
   if(available)select.value=current.selectedPackage;
   select.disabled=!active||!canChoose||busy;
  }
  const panel=$('phone-game-state');if(!panel)return;
  if(current.upgradeRequired){
   panel.textContent='HOST DATABASE UPDATE REQUIRED · On desktop, sign in and run upgrade.php. Your phone is still paired.';
   const start=document.querySelector('[data-phone="start"]'),spin=document.querySelector('[data-phone="spin"]');
   if(start)start.disabled=true;if(spin)spin.disabled=true;
   if(!hadUpgradeWarning){message('Phone paired, but the host must run upgrade.php before gameplay.',true);hadUpgradeWarning=true;}
   return;
  }
  if(hadUpgradeWarning){hadUpgradeWarning=false;message('Database upgrade detected. Phone ready.');}
  const status=current.status==='active'?'PLAYING':current.status==='paused'?'PAUSED':current.status==='ended'?'GAME ENDED':'READY';
  const phase=current.pendingBonus?' · BONUS ROUND':'';
  panel.textContent=`${status}${phase} · ${current.spinCount||0} spins · ${current.claimedCount||0} claimed · ${current.remaining||0} live prizes`+(current.lastPrize?` · Latest: ${current.lastPrize}`:'')+(current.spinning?' · Spinning…':'');
  const start=document.querySelector('[data-phone="start"]'),spin=document.querySelector('[data-phone="spin"]'),pause=document.querySelector('[data-phone="pause"]');
  if(start)start.disabled=!(current.canStart||current.status==='ready');
  if(spin){spin.disabled=!(current.canSpin||current.status==='ready');spin.querySelector('span').textContent=current.status==='ready'?'START':current.pendingBonus?'BONUS SPIN':current.spinning?'SPINNING':'SPIN';}
  if(pause){pause.disabled=!['active','paused'].includes(current.status);pause.textContent=current.status==='paused'?'▶ Resume':'Ⅱ Pause';}
 }
 function updateProof(proof){
  const accordion=$('phone-proof-controls');if(!accordion)return;
  proofState=proof||{active:false};const showing=!!proofState?.active;applyMode();

  // The proof controls are the only primary controls while the desktop proof page is active.
  // Keep the accordion closed on first entry; preserve its user-selected state while active.
  if(accordion.hidden!==!showing){accordion.hidden=!showing;accordion.open=showing;}
  const phase=proofState.phase||(Number(proofState.reviewCount)>0?'review':'live');
  const reviewing=showing&&(phase==='review'||Number(proofState.reviewCount)>0&&phase==='live');
  const opening=phase==='opening',upgrade=phase==='upgrade';
  const recording=phase==='recording',processing=phase==='processing',saving=phase==='saving',saved=phase==='saved';
  const consent=$('phone-proof-consent');if(consent){consent.checked=!!proofState.consent;consent.disabled=opening||upgrade||recording||processing||saving;}
  const label=$('phone-proof-phase');
  if(label)label.textContent=recording?'● Recording…':processing?'Processing video…':reviewing?'Review recorded video · Save when ready':saving?'Saving video to the timeline…':saved?'✓ Video saved to the editor timeline':phase==='upgrade'?'Host upgrade required: run upgrade.php before recording.':opening?'Opening the desktop proof editor…': 'Live camera ready · Consent is controlled on this phone';
  const context=$('phone-proof-context');if(context)context.textContent=opening?'Opening prize proof editor on the computer…':'Prize proof · Computer camera';
  const record=$('phone-proof-recording');if(record)record.hidden=opening||upgrade||reviewing||processing||saving||saved;
  const review=$('phone-proof-review');if(review)review.hidden=!reviewing;
  const recordButton=document.querySelector('[data-phone="proof-record"]');
  if(recordButton){recordButton.textContent=recording?'● Recording…':'● Record';recordButton.disabled=opening||upgrade||recording||!proofState.consent;}
  const stopButton=document.querySelector('[data-phone="proof-stop"]');if(stopButton){stopButton.hidden=!recording;stopButton.disabled=!recording;}
  const saveButton=document.querySelector('[data-phone="proof-save"]');if(saveButton)saveButton.disabled=!reviewing;
  const approved=showing&&Number(proofState.savedCount)>0;
  if($('phone-proof-saved'))$('phone-proof-saved').hidden=reviewing||recording||processing||saving||!approved;
 }
 async function send(action){if(!active)return;
  if(busy||pendingCommand){if(commandQueue.length>=16){message('Too many pending controls. Wait for the screen to respond.',true);return;}commandQueue.push(action);message(`Waiting… (${commandQueue.length} queued)`);return;}
  if(action==='end'&&!confirm('End this game? The claimed prizes will be saved.'))return;
  if(action==='prize-decline'&&!confirm('Decline this prize and end the game?'))return;
  busy=true;
  try{const nonce=(crypto.randomUUID?.()||`${Date.now()}_${Math.random().toString(36).slice(2)}`).replaceAll('.','_');
   const result=await request('command',{command:action,nonce,confirm:action==='end'});
   if(result.game)updateGame(result.game);
    if(result.applied&&result.effect==='prize_accepted')updateProof({active:true,phase:'opening',claimId:result.game?.offer?.claimId||''});
   if(navigator.vibrate)navigator.vibrate(20);
   if(result.applied){pendingCommand=null;feedback=null;const notice=result.effect==='started'?'Game started — press Spin to play.':result.effect==='award'?'Wheel spinning… Watch the desktop for the result.':result.effect==='bonus_trigger'?'Bonus round activated on screen.':`Game ${result.effect||'updated'}.`;message(notice);appendChat(notice);}
   else if(result.commandId){pendingCommand={id:result.commandId,label:action.replaceAll('-',' ')};message(`Waiting for game screen to execute ${pendingCommand.label}…`);appendChat(`Waiting for game screen to execute ${pendingCommand.label}…`);}
   else message('Command was not confirmed by the game screen.',true);
  }catch(e){message(e.message,true);appendChat(e.message);if(/authorization|expired|disconnected/i.test(e.message))forget();}
  finally{busy=false;}
 }
 const appendChat=()=>{};
 const slug=title=>String(title).normalize('NFKD').toLowerCase().replace(/[\u0300-\u036f]/g,'').replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'').slice(0,80);
 function parseCommand(text){
  if(/^(accept|accept prize|keep prize|yes)$/i.test(text.trim()))return 'prize-accept';
  if(/^(decline|decline prize|reject prize|no)$/i.test(text.trim()))return 'prize-decline';
  const parsed=window.LuckyCommands?.parse(text)||{type:'unknown'};
  const map={'close-prize':'done','spin':'spin','start':'start','end':'end','pause':'pause','resume':'resume','select':'select','up':'up','down':'down','left':'left','right':'right','menu':'menu','back':'back','music-on':'music-on','music-off':'music-off','music-play':'music-play','music-pause':'music-pause','music-next':'music-next','music-previous':'music-previous','mute':'mute','unmute':'unmute','effects-on':'effects-on','effects-off':'effects-off','speech-on':'speech-on','speech-off':'speech-off','home':'home'};
  if(map[parsed.type])return map[parsed.type];
  if(parsed.type==='volume-set'&&['music','master'].includes(parsed.channel))return 'volume-'+parsed.value;
  if(parsed.type==='volume-step'&&['music','master'].includes(parsed.channel))return parsed.step>0?'volume-up':'volume-down';
  if(parsed.type==='music-title'&&slug(parsed.title))return 'music-find-'+slug(parsed.title);
  if(parsed.type==='help')return 'help';
  return null;
 }
 async function submitPhrase(text,spoken=false){text=String(text||'').trim();if(!text)return;appendChat(text, 'user');
  if(!active){appendChat('Connect and get host approval before sending game commands.');return;}
   const action=parseCommand(text);if(action==='help'){appendChat('Say: spin, start game, done, select, up/down/left/right, pause game, resume game, next song, mute, volume 50, or end game.');return;}
  if(!action){appendChat('Command not recognized. Try “spin”, “done”, “move right”, “volume 50”, or “help”.');return;}
  try{await send(action);}catch(e){appendChat(e.message);}
 }
 const SpeechRecognition=window.SpeechRecognition||window.webkitSpeechRecognition;
 const mic=$('phone-mic-toggle'),voiceStatus=$('phone-voice-status');
 function toggleListening(on){
  if(!on){listening=false;try{speech?.stop();}catch(_){};mic?.setAttribute('aria-pressed','false');mic?.classList.remove('phone-voice-active');if(voiceStatus)voiceStatus.textContent='Microphone off.';return;}
  if(!SpeechRecognition){voiceStatus.textContent='Voice recognition unavailable in this browser.';return;}
  if(!window.isSecureContext){voiceStatus.textContent='Voice control needs HTTPS or localhost.';return;}
  if(!active){voiceStatus.textContent='Connect your phone before enabling listening.';return;}
  try{
   speech=new SpeechRecognition();speech.lang='en-US';speech.interimResults=false;speech.continuous=false;
   speech.onresult=event=>{const words=Array.from(event.results).filter(r=>r.isFinal).map(r=>r[0]?.transcript||'').join(' ').trim();if(words){voiceStatus.textContent='Heard: '+words;const parsed=window.LuckyCommands?.parse(words);if(parsed?.type==='stop-listening')toggleListening(false);else submitPhrase(words,true);}};
   speech.onerror=event=>{voiceStatus.textContent='Microphone: '+(event.error||'error');if(['not-allowed','service-not-allowed','audio-capture'].includes(event.error))toggleListening(false);};
   speech.onend=()=>{if(listening&&document.visibilityState==='visible'){try{speech.start();}catch(_){toggleListening(false);}}};
   listening=true;speech.start();mic?.setAttribute('aria-pressed','true');mic?.classList.add('phone-voice-active');voiceStatus.textContent='Listening…';
  }catch(e){toggleListening(false);voiceStatus.textContent='Could not start microphone: '+e.message;}
 }
 mic?.addEventListener('click',()=>toggleListening(!listening));
 document.addEventListener('visibilitychange',()=>{if(document.visibilityState==='hidden'&&listening)toggleListening(false);});
 $('phone-offer-speak').addEventListener('click',()=>{if(game?.offer?.status==='pending')speakOffer(game.offer);});
 $('phone-offer-play-video').addEventListener('click',()=>{
  if(!prizeMedia)return;
  if(prizeMedia.tagName==='VIDEO'){
   prizeMedia.muted=false;
   prizeMedia.play().catch(()=>message('Tap Play in the player to start the video.',true));
  }else{const src=prizeMedia.src;prizeMedia.src=src;}
 });
 $('phone-join-form').addEventListener('submit',join);
 $('phone-dashboard-package-select')?.addEventListener('change',async event=>{
  if(!active||busy||!['ready','saved','ended'].includes(game?.status))return;
  const target=event.target.value;busy=true;
  try{
   const out=await request('select_package',{package:target});
   game.selectedPackage=out.selected;updateDashboardPackages();
   dashboardNote('Prize package saved for your next Spin the Wheel game.');
  }catch(e){dashboardNote(e.message,true);updateDashboardPackages();}
  finally{busy=false;updateDashboardPackages();}
 });
 $('phone-package-select').addEventListener('change',async event=>{
  if(!active||busy||!['ready','saved','ended'].includes(game?.status))return;
  const target=event.target.value;busy=true;
  try{const out=await request('select_package',{package:target});
   if(game)game.selectedPackage=out.selected;message('Prize package ready for the next game.');
   appendChat('Prize package selected for the next game.');}
  catch(e){message(e.message,true);if(game)updateGame(game);}
  finally{busy=false;}
 });
 document.querySelectorAll('[data-phone]').forEach(button=>button.addEventListener('click',()=>send(button.dataset.phone)));
 $('phone-proof-consent')?.addEventListener('change',e=>send(e.target.checked?'proof-consent-on':'proof-consent-off'));
 $('phone-disconnect').addEventListener('click',async()=>{try{await request('disconnect');}catch(_){}forget();});
 try{const existing=JSON.parse(sessionStorage.getItem(storageKey)||'null');if(existing&&/^[a-f0-9]{32}$/.test(existing.id)&&/^[a-f0-9]{64}$/.test(existing.token))state=existing;}catch(_){}
 if(state){if(location.protocol!=='about:')history.replaceState(null,'','controller.php');show('phone-wait');status();}
 else if((/^[a-f0-9]{32}$/.test(params.get('invite')||'')&&/^[a-f0-9]{64}$/.test(params.get('key')||''))||(/^[a-f0-9]{32}$/.test(params.get('session')||'')&&/^[a-f0-9]{48}$/.test(params.get('viewer')||''))){show('phone-join');message('Scan complete. Enter your phone name to request approval.');}
 else {show('phone-join');$('phone-join-form').hidden=true;message('Scan the QR code displayed by the host to connect.',true);}
 setInterval(()=>{if(state)status();},600);
})();
