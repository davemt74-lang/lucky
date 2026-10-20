(() => {
 'use strict';const $=id=>document.getElementById(id),storageKey='lucky-phone-controller-v5p';
 const params=new URLSearchParams(location.search||(location.protocol==='about:'?new URL(document.baseURI).search:''));let state=null,busy=false,active=false,game=null,gameVersion=-1,pendingCommand=null,feedback=null;let speech=null,listening=false,statusBusy=false,commandQueue=[];
 let noticeTimer=null,failures=0,proofState={active:false},hadUpgradeWarning=false,lastConnectionError='';
 const message=(m,warning=false)=>{const e=$('phone-status');if(!e)return;clearTimeout(noticeTimer);e.textContent=m;e.classList.toggle('platform-error',warning);
  if(active)noticeTimer=setTimeout(()=>{e.textContent='';e.classList.remove('platform-error');},warning?6500:2300);
 };
 const show=(name)=>{for(const id of ['phone-join','phone-wait','phone-controls'])$(id).hidden=id!==name;$('phone-disconnect').hidden=name==='phone-join';};
 const safeName=()=>String($('phone-name').value||'').trim().slice(0,48);
 function forget(){toggleListening(false);commandQueue=[];feedback=null;pendingCommand=null;game=null;gameVersion=-1;updateProof(null);state=null;try{sessionStorage.removeItem(storageKey);}catch(_){}active=false;show('phone-join');message('Connection expired. Scan a new QR code on the host screen.',true);}
 async function request(action,body={},authenticated=true){const headers={'Content-Type':'application/json'};
  if(authenticated){if(!state)throw Error('Phone not paired.');headers['X-Controller-Token']=state.token;body={id:state.id,...body};}
  const response=await fetch('api/controller.php?action='+encodeURIComponent(action),{method:'POST',headers,credentials:'omit',cache:'no-store',body:JSON.stringify(body)});
  let data;try{data=await response.json();}catch(_){throw Error(`Game server returned HTTP ${response.status}. Check the hosting error log.`);}
  if(!response.ok||!data.ok)throw Error((data.error||'Connection failed.')+` (HTTP ${response.status})`);return data;
 }
 async function status(){if(!state||busy||statusBusy)return;statusBusy=true;
  try{const data=await request('status',pendingCommand?{commandId:pendingCommand.id}:{});if(data.status==='paired'){const wasActive=active,wasFailing=failures>=3;failures=0;active=true;show('phone-controls');updateGame(data.game);updateProof(data.proof);if((!wasActive||wasFailing)&&!data.game?.upgradeRequired)message(wasFailing?'Connected again.':'Phone connected.');if(pendingCommand){
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
 function applyMode(){
  const proof=!!proofState?.active,offer=!proof&&game?.offer?.status==='pending';
  const gameShell=$('phone-gameplay-shell'),proofMode=$('phone-proof-mode'),audio=$('phone-game-audio'),offerMode=$('phone-prize-offer');
  if(gameShell)gameShell.hidden=proof||offer;
  if(proofMode)proofMode.hidden=!proof;
  if(audio)audio.hidden=proof||offer;
  if(offerMode)offerMode.hidden=!offer;
  if(offer&&$('phone-offer-name'))$('phone-offer-name').textContent=game.offer.name+' · $'+(Number(game.offer.cents)/100).toFixed(2);
 }
 function updateGame(current){
  if(!current)return;game=current;applyMode();
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
 $('phone-join-form').addEventListener('submit',join);
 document.querySelectorAll('[data-phone]').forEach(button=>button.addEventListener('click',()=>send(button.dataset.phone)));
 $('phone-proof-consent')?.addEventListener('change',e=>send(e.target.checked?'proof-consent-on':'proof-consent-off'));
 $('phone-disconnect').addEventListener('click',async()=>{try{await request('disconnect');}catch(_){}forget();});
 try{const existing=JSON.parse(sessionStorage.getItem(storageKey)||'null');if(existing&&/^[a-f0-9]{32}$/.test(existing.id)&&/^[a-f0-9]{64}$/.test(existing.token))state=existing;}catch(_){}
 if(state){if(location.protocol!=='about:')history.replaceState(null,'','controller.php');show('phone-wait');status();}
 else if((/^[a-f0-9]{32}$/.test(params.get('invite')||'')&&/^[a-f0-9]{64}$/.test(params.get('key')||''))||(/^[a-f0-9]{32}$/.test(params.get('session')||'')&&/^[a-f0-9]{48}$/.test(params.get('viewer')||''))){show('phone-join');message('Scan complete. Enter your phone name to request approval.');}
 else {show('phone-join');$('phone-join-form').hidden=true;message('Scan the QR code displayed by the host to connect.',true);}
 setInterval(()=>{if(state)status();},600);
})();
