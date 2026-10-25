(() => {
 'use strict';const $=id=>document.getElementById(id),storageKey='lucky-phone-controller-v5p';
 const params=new URLSearchParams(location.search||(location.protocol==='about:'?new URL(document.baseURI).search:''));let state=null,busy=false,active=false,game=null,gameVersion=-1,pendingCommand=null,feedback=null;let speech=null,listening=false,statusBusy=false,commandQueue=[];
 let currentMovie=null,movieBusy=false,claimedPrizes=[],claimHistorySession='';
 let noticeTimer=null,failures=0,proofState={active:false},hadUpgradeWarning=false,lastConnectionError='';
 const message=(m,warning=false)=>{const e=$('phone-status');if(!e)return;clearTimeout(noticeTimer);e.textContent=m;e.classList.toggle('platform-error',warning);
  if(active)noticeTimer=setTimeout(()=>{e.textContent='';e.classList.remove('platform-error');},warning?6500:2300);
 };
 const setConnection=(kind,label)=>{const el=$('phone-connection-state');if(el){el.dataset.state=kind;el.textContent=label;}};
 let visibleScreen='phone-join';
 const liveGame=()=>!!game&&['active','paused'].includes(game.status);
 const mayGoHome=()=>active&&!currentMovie&&!proofState?.active&&!liveGame()&&game?.offer?.status!=='pending';
 const mayGoPrize=()=>active&&!proofState?.active&&game?.offer?.status==='pending';
 const mayGoCamera=()=>active&&!!proofState?.active;
 const mayGoGame=()=>active&&!currentMovie&&!!game&&(liveGame()||!!game.upgradeRequired||mayGoPrize()||mayGoCamera());
 function syncNav(){
  const paired=active&&!!state,navbar=$('phone-bottom-nav'),arrows=$('phone-arrows-toggle');
  if(navbar)navbar.hidden=!paired;
  if(arrows){arrows.disabled=!paired||!!currentMovie||!!proofState?.active||mayGoPrize();if(arrows.disabled&&$('phone-arrows-dialog')?.open)$('phone-arrows-dialog').close();}
  const availability={home:mayGoHome(),game:mayGoGame(),prize:mayGoPrize()||claimedPrizes.length>0,camera:mayGoCamera(),more:paired};
  const current=visibleScreen==='phone-dashboard'?'home':visibleScreen==='phone-movie'?'more':mayGoCamera()?'camera':mayGoPrize()?'prize':'game';
  document.querySelectorAll('[data-phone-view]').forEach(button=>{
   const name=button.dataset.phoneView;button.disabled=!availability[name];
   const selected=current===name&&availability[name];button.classList.toggle('is-active',selected);
   if(selected)button.setAttribute('aria-current','page');else button.removeAttribute('aria-current');
  });
  const note=$('phone-menu-context');
  if(note)note.textContent=currentMovie?'Movie preview is active on the host. Stop playback before choosing another game.':
   mayGoCamera()?'Finish the recording or proof review before returning to the game library.':
   mayGoPrize()?'Accept or decline your pending prize to continue.':
   liveGame()?'End or save the current game before selecting another.':'Your private home arcade.';
  const claimsTab=$('phone-menu-tab-claims');if(claimsTab)claimsTab.disabled=!paired||!claimedPrizes.length;
  const count=$('phone-menu-claims-count');if(count)count.textContent=claimedPrizes.length?`(${claimedPrizes.length})`:'';
 }
 const show=(name)=>{visibleScreen=name;
  for(const id of ['phone-join','phone-wait','phone-controls','phone-dashboard','phone-movie'])$(id).hidden=id!==name;
  $('phone-disconnect').hidden=name==='phone-join';
  setConnection(name==='phone-join'?'disconnected':name==='phone-wait'?'waiting':'connected',name==='phone-join'?'● Not connected':name==='phone-wait'?'◌ Awaiting approval':'● Connected');
  syncNav();
 };
 const safeName=()=>String($('phone-name').value||'').trim().slice(0,48);
 function forget(){const menu=$('phone-quick-menu');if(menu?.open)menu.close();const arrows=$('phone-arrows-dialog');if(arrows?.open)arrows.close();currentMovie=null;claimedPrizes=[];claimHistorySession='';renderClaimHistory();lastPrizeOffer='';clearPrizeVideo();try{window.speechSynthesis?.cancel();}catch(_){}toggleListening(false);commandQueue=[];feedback=null;pendingCommand=null;game=null;gameVersion=-1;updateProof(null);state=null;try{sessionStorage.removeItem(storageKey);}catch(_){}active=false;show('phone-join');message('Connection expired. Scan a new QR code on the host screen.',true);}
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
  const cover=$('phone-dashboard-package-image');if(cover){const p=options.find(x=>x.id===select.value);
   if(p?.image){cover.src=p.image;cover.hidden=false;cover.onerror=()=>{cover.hidden=true;};}
   else{cover.hidden=true;cover.removeAttribute('src');}}

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
     claimedPrizes=Array.isArray(data.claims)?data.claims:[];claimHistorySession=typeof data.claimSession==='string'?data.claimSession:'';renderClaimHistory();
    const movie=data.moviePreview;
    if(movie&&movie.session&&['playing','paused'].includes(movie.state)){
     currentMovie=movie;show('phone-movie');
     $('phone-movie-state').textContent=movie.state==='playing'?'Playing the full movie on desktop':'Movie paused on desktop';
     $('phone-movie-play').classList.toggle('is-active',movie.state==='playing');
     $('phone-movie-pause').classList.toggle('is-active',movie.state==='paused');
     if(!wasActive)message('Approved phone connected to movie preview.');
    }else {currentMovie=null;
    const needsDashboard=dashboardRequested||(!data.game?.upgradeRequired&&!data.proof?.active&&['ready','saved','ended'].includes(data.game?.status));
    if(needsDashboard){
     show('phone-dashboard');game=data.game;clearPrizeVideo();updateProof(null);updateDashboardPackages();
     if(!wasActive||!dashboardGames.length)void loadDashboard();
    }else{show('phone-controls');updateGame(data.game);updateProof(data.proof);}}
    if((!wasActive||wasFailing)&&!data.game?.upgradeRequired)message(wasFailing?'Connected again.':'Phone connected.');if(pendingCommand){
    if(data.delivery==='executed'){feedback={text:`${pendingCommand.label} completed on game screen.`,until:Date.now()+6500};message(feedback.text);appendChat(feedback.text);pendingCommand=null;}
    else if(data.delivery==='failed'){const err=`${pendingCommand.label} failed: ${data.reason||'The screen could not perform this action.'}`;message(err,true);appendChat(err);pendingCommand=null;}
    else if(data.delivery==='expired'){const err=`${pendingCommand.label} was not executed. Open the game/TV screen and try again.`;message(err,true);appendChat(err);pendingCommand=null;}
    else message(`Waiting for game screen to execute ${pendingCommand.label}…`);
   }}
   else if(data.status==='pending'){active=false;show('phone-wait');message('Waiting for host approval…');}
   else {forget();}}
  catch(e){if(/invalid|disconnected|expired|authorization/i.test(e.message))forget();else {failures++;setConnection('interrupted','! Reconnecting');if(failures>=3){window.dispatchEvent(new CustomEvent('lucky:phone-proof-status',{detail:{active:false,phoneCameraAllowed:false,sessionId:'',claimId:''}}));}if(failures>=3&&(failures===3||e.message!==lastConnectionError)){lastConnectionError=e.message;message('Connection interrupted: '+e.message+' Retrying…',true);}}}
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

 let lastPrizeOffer='';let declining=false;
 const prizeCents=offer=>Math.max(0,Math.round(Number(offer?.cents)||0));
 function prizeDetails(offer,prefix){
  $(prefix+'-name').textContent=offer?.name||'Prize';
  $(prefix+'-value').textContent='$'+(prizeCents(offer)/100).toFixed(2);
  const tier=$(prefix+'-tier'),value=String(offer?.tier||'').trim();
  tier.hidden=!value;tier.textContent=value?value.replace(/([a-z])([0-9])/i,'$1 $2').replace(/-/g,' ').toUpperCase()+' PRIZE':'';
 }
 function lockDecision(locked){document.querySelectorAll('#phone-prize-offer [data-phone^="prize-"]').forEach(btn=>{btn.disabled=locked;});}
 async function confirmDecline(){
  if(declining||!active||game?.offer?.status!=='pending')return;
  const dialog=$('phone-decline-dialog');if(!dialog)return;
  declining=true;
  const accepted=await new Promise(resolve=>{
   let done=false;
   const close=value=>{if(done)return;done=true;dialog.close();resolve(value);};
   $('phone-decline-cancel').onclick=()=>close(false);
   $('phone-decline-confirm').onclick=()=>close(true);
   dialog.oncancel=event=>{event.preventDefault();close(false);};
   dialog.showModal();$('phone-decline-cancel').focus();
  });
  declining=false;if(accepted&&game?.offer?.status==='pending')await send('prize-decline',true);
 }
 function hasPrizeUpload(raw){
  if(typeof raw!=='string'||!raw||raw.length>2048)return false;
  try{const url=new URL(raw,document.baseURI),base=new URL(document.baseURI);
   return url.origin===base.origin&&url.pathname.endsWith('/api/index.php')&&
    url.searchParams.get('action')==='prize_video'&&/^[a-f0-9]{32}$/.test(url.searchParams.get('id')||'');
  }catch(_){return false;}
 }
 function speakOffer(offer){
  if(!offer)return;
  if(!window.speechSynthesis||typeof SpeechSynthesisUtterance!=='function'){message('Speech unavailable. Your prize is shown below.',true);return;}
  try{window.speechSynthesis.cancel();const line=new SpeechSynthesisUtterance(offer.description||('Congratulations! You won '+offer.name+'!'));
   line.lang='en-US';line.rate=.93;window.speechSynthesis.speak(line);
  }catch(_){message('Tap Hear prize again to allow phone speech.',true);}
 }
 function clearPrizeVideo(){
  const photo=$('phone-offer-photo');if(photo){photo.removeAttribute('src');photo.hidden=true;}
  $('phone-offer-play-video').hidden=true;
 }
 function showPrizeVideo(offer){
  clearPrizeVideo();if(!offer)return;
  const raw=String(offer.image||'');
  if(raw){try{const src=new URL(raw,document.baseURI);
    if(['https:','http:'].includes(src.protocol)&&!src.username&&!src.password){
     const photo=$('phone-offer-photo');photo.src=src.href;photo.hidden=false;photo.onerror=()=>{photo.hidden=true;};
    }
   }catch(_){/* Invalid claim artwork is never used as markup. */}}
  $('phone-offer-play-video').hidden=!hasPrizeUpload(offer.videoUrl);
 }
 function presentPrize(offer){
  if(!offer||offer.status!=='pending'){if(lastPrizeOffer){lastPrizeOffer='';clearPrizeVideo();}return;}
  const key=String(game?.id||'')+':'+String(offer.claimId||'');
  if(lastPrizeOffer===key)return;
  lastPrizeOffer=key;showPrizeVideo(offer);
  const storage='lucky-prize-announced-'+key;
  let seen=false;try{seen=sessionStorage.getItem(storage)==='1';}catch(_){}
  if(!seen){try{sessionStorage.setItem(storage,'1');}catch(_){}speakOffer(offer);}
 }
 function applyMode(){
  const proof=!!proofState?.active,offer=!proof&&game?.offer?.status==='pending';
  const proofOffer=proof&&game?.offer?.status==='accepted'?game.offer:null;
  const proofCard=$('phone-proof-prize');if(proofCard){proofCard.hidden=!proofOffer;
   if(proofOffer){prizeDetails(proofOffer,'phone-proof-prize');
    $('phone-proof-prize-description').textContent=proofOffer.description||'';
    const img=$('phone-proof-prize-photo');let image=null;try{image=new URL(proofOffer.image||'',document.baseURI);}catch(_){}
    if(image&&['https:','http:'].includes(image.protocol)&&!image.username&&!image.password){img.src=image.href;img.hidden=false;img.onerror=()=>{img.hidden=true;};}
    else{img.hidden=true;img.removeAttribute('src');}
    $('phone-proof-play-video').hidden=!hasPrizeUpload(proofOffer.videoUrl);
   }
  }
  const gameShell=$('phone-gameplay-shell'),proofMode=$('phone-proof-mode'),offerMode=$('phone-prize-offer');
  if(gameShell)gameShell.hidden=proof||offer;
  if(proofMode)proofMode.hidden=!proof;
  
  if(offerMode)offerMode.hidden=!offer;
  if(offer)prizeDetails(game.offer,'phone-offer');
  if($('phone-offer-description'))$('phone-offer-description').textContent=offer?game.offer.description||'':'';
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
  const wheel=$('phone-wheel-face');if(wheel)wheel.dataset.phase=current.spinning?'spinning':current.status||'ready';
  const hint=$('phone-wheel-hint');if(hint)hint.textContent=current.spinning?'Wheel spinning on the TV…':
   current.status==='paused'?'Game paused on the host':'Watch the wheel on the TV';
  syncNav();
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
  const allowed=showing&&!!proofState.phoneCameraAllowed&&!!proofState.consent&&!!proofState.sessionId&&!!proofState.claimId;
  const captureEntry=$('phone-capture-entry');if(captureEntry)captureEntry.hidden=!showing;
  const captureButton=$('phone-capture-open');if(captureButton)captureButton.disabled=!allowed;
  const captureNote=$('phone-capture-entry-note');if(captureNote)captureNote.textContent=!proofState.phoneCameraAllowed?'Ask the host to check “Use phone camera” in desktop proof permissions.':!proofState.consent?'Check recording consent below to enable the phone camera.':'Phone capture permitted · Your files go to this prize.';
  window.dispatchEvent(new CustomEvent('lucky:phone-proof-status',{detail:{active:showing,phoneCameraAllowed:allowed,sessionId:proofState.sessionId||'',claimId:proofState.claimId||'',phase:proofState.phase||'live'}}));

  // The proof controls are the only primary controls while the desktop proof page is active.
  // Keep the accordion closed on first entry; preserve its user-selected state while active.
  if(accordion.hidden!==!showing){accordion.hidden=!showing;accordion.open=showing;}
  const phase=proofState.phase||(Number(proofState.reviewCount)>0?'review':'live');
  const reviewing=showing&&(phase==='review'||Number(proofState.reviewCount)>0&&phase==='live');
  const opening=phase==='opening',countdown=phase==='countdown',upgrade=phase==='upgrade';
  const recording=phase==='recording',processing=phase==='processing',saving=phase==='saving',saved=phase==='saved';
  const consent=$('phone-proof-consent');if(consent){consent.checked=!!proofState.consent;consent.disabled=opening||upgrade||recording||processing||saving;}
  const label=$('phone-proof-phase');
  if(label)label.textContent=countdown?'Recording starts after the desktop countdown…':recording?'● Recording…':processing?'Processing video…':reviewing?'Review recorded video · Save when ready':saving?'Saving video to the timeline…':saved?'✓ Video saved to the editor timeline':phase==='upgrade'?'Host upgrade required: run upgrade.php before recording.':opening?'Opening the desktop proof editor…': 'Live camera ready · Consent is controlled on this phone';
  const context=$('phone-proof-context');if(context)context.textContent=opening?'Opening prize proof editor on the computer…':'Prize proof · Computer camera';
  const countdownNotice=$('phone-proof-countdown');if(countdownNotice)countdownNotice.hidden=!countdown;
  const record=$('phone-proof-recording');if(record)record.hidden=opening||upgrade||reviewing||processing||saving||saved;
  const review=$('phone-proof-review');if(review)review.hidden=!reviewing;
  const recordButton=document.querySelector('[data-phone="proof-record"]');
  if(recordButton){recordButton.textContent=countdown?'Starting soon…':recording?'● Recording…':'● Record';recordButton.disabled=opening||upgrade||countdown||recording||!proofState.consent;}
  const stopButton=document.querySelector('[data-phone="proof-stop"]');if(stopButton){stopButton.hidden=!(countdown||recording);stopButton.textContent=countdown?'✕ Cancel countdown':'■ Stop recording';stopButton.disabled=!(countdown||recording);}
  const saveButton=document.querySelector('[data-phone="proof-save"]');if(saveButton)saveButton.disabled=!reviewing;
  const banner=$('phone-proof-live-banner');if(banner){banner.hidden=!showing;banner.dataset.phase=phase;}
  const title=$('phone-proof-live-title');if(title)title.textContent=countdown?'Recording countdown on the computer…':
   recording?'● REC · Recording on the computer':processing?'Processing video…':
   reviewing?'Review your clip on the computer':saving?'Saving clip to the timeline…':
   saved?'✓ Video saved to the timeline':opening?'Opening proof on the computer…':'Desktop camera ready';
  syncNav();
  const approved=showing&&Number(proofState.savedCount)>0;
  if($('phone-proof-saved'))$('phone-proof-saved').hidden=reviewing||recording||processing||saving||!approved;
 }
 async function send(action,confirmed=false){if(!active)return;
  if(action==='prize-decline'&&!confirmed){await confirmDecline();return;}
  // A prize decision is never queued or replayed when another request is outstanding.
  if((action==='prize-accept'||action==='prize-decline')&&(busy||pendingCommand||commandQueue.length)){message('Complete the previous action before choosing your prize.',true);return;}
  if(busy||pendingCommand){if(commandQueue.length>=16){message('Too many pending controls. Wait for the screen to respond.',true);return;}commandQueue.push(action);message(`Waiting… (${commandQueue.length} queued)`);return;}
  if(action==='end'&&!confirm('End this game? The claimed prizes will be saved.'))return;
  if(action==='prize-decline'&&!confirmed)return;
  busy=true;if(action==='prize-accept'||action==='prize-decline')lockDecision(true);
   try{const proofClaim=action.match(/^proof-edit-([a-f0-9]{32})$/);
    const randomNonce=(crypto.randomUUID?.()||`${Date.now()}_${Math.random().toString(36).slice(2)}`).replaceAll('.','_');
    // Bind the exact claim to the idempotent request without expanding the 24-char command column.
    const nonce=proofClaim?`${proofClaim[1]}_${randomNonce}`:randomNonce;
    const result=await request('command',{command:proofClaim?'proof-edit':action,nonce,confirm:action==='end'});
   if(result.game)updateGame(result.game);
    if(result.applied&&result.effect==='prize_accepted')updateProof({active:true,phase:'opening',claimId:result.game?.offer?.claimId||''});
   if(navigator.vibrate)navigator.vibrate(20);
   if(result.applied){pendingCommand=null;feedback=null;const notice=result.effect==='started'?'Game started — press Spin to play.':result.effect==='award'?'Wheel spinning… Watch the desktop for the result.':result.effect==='bonus_trigger'?'Bonus round activated on screen.':`Game ${result.effect||'updated'}.`;message(notice);appendChat(notice);}
    else if(result.commandId){pendingCommand={id:result.commandId,label:proofClaim?'edit claimed prize video':action.replaceAll('-',' ')};message(`Waiting for game screen to execute ${pendingCommand.label}…`);appendChat(`Waiting for game screen to execute ${pendingCommand.label}…`);}
   else message('Command was not confirmed by the game screen.',true);
  }catch(e){message(e.message,true);appendChat(e.message);if(/authorization|expired|disconnected/i.test(e.message))forget();}
  finally{busy=false;if(action==='prize-accept'||action==='prize-decline')lockDecision(false);}
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
  if(!SpeechRecognition){voiceStatus.textContent='Voice recognition unavailable in this browser.';message(voiceStatus.textContent,true);return;}
  if(!window.isSecureContext){voiceStatus.textContent='Voice control needs HTTPS or localhost.';message(voiceStatus.textContent,true);return;}
  if(!active){voiceStatus.textContent='Connect your phone before enabling listening.';message(voiceStatus.textContent,true);return;}
  try{
   speech=new SpeechRecognition();speech.lang='en-US';speech.interimResults=false;speech.continuous=false;
   speech.onresult=event=>{const words=Array.from(event.results).filter(r=>r.isFinal).map(r=>r[0]?.transcript||'').join(' ').trim();if(words){voiceStatus.textContent='Heard: '+words;const parsed=window.LuckyCommands?.parse(words);if(parsed?.type==='stop-listening')toggleListening(false);else submitPhrase(words,true);}};
   speech.onerror=event=>{voiceStatus.textContent='Microphone: '+(event.error||'error');message(voiceStatus.textContent,true);if(['not-allowed','service-not-allowed','audio-capture'].includes(event.error))toggleListening(false);};
   speech.onend=()=>{if(listening&&document.visibilityState==='visible'){try{speech.start();}catch(_){toggleListening(false);}}};
   listening=true;speech.start();mic?.setAttribute('aria-pressed','true');mic?.classList.add('phone-voice-active');voiceStatus.textContent='Listening…';
  }catch(e){toggleListening(false);voiceStatus.textContent='Could not start microphone: '+e.message;message(voiceStatus.textContent,true);}
 }
 mic?.addEventListener('click',()=>toggleListening(!listening));
 document.addEventListener('visibilitychange',()=>{if(document.visibilityState==='hidden'&&listening)toggleListening(false);});
 async function movieControl(verb){
  if(movieBusy||!currentMovie||!active)return;movieBusy=true;
  const session=currentMovie.session;
  document.querySelectorAll('.phone-movie-buttons button').forEach(b=>b.disabled=true);
  try{
   await request('movie_command',{session,verb,nonce:crypto.randomUUID?.().replaceAll('-','')||Array.from(crypto.getRandomValues(new Uint8Array(16))).map(x=>x.toString(16).padStart(2,'0')).join('')});
   if(verb==='stop'){currentMovie=null;show('phone-dashboard');await loadDashboard();}
   else $('phone-movie-state').textContent=verb==='pause'?'Pausing desktop movie…':'Resuming desktop movie…';
  }catch(e){message(e.message,true);}finally{movieBusy=false;document.querySelectorAll('.phone-movie-buttons button').forEach(b=>b.disabled=false);}
 }

  function renderClaimHistory(){
   const list=$('phone-claimed-prizes'),empty=$('phone-claims-empty'),context=$('phone-claims-context');
   if(!list)return;
   const signature=JSON.stringify([claimHistorySession,claimedPrizes]);
   if(list.dataset.signature===signature)return;
   list.dataset.signature=signature;list.replaceChildren();
   if(empty)empty.hidden=claimedPrizes.length>0;
   if(context)context.textContent=claimedPrizes.length?'Accepted prizes in the selected game, oldest first. Edit or re-record on the host computer.':'No accepted prizes for this game yet.';
   const money=new Intl.NumberFormat('en-US',{style:'currency',currency:'USD'});
   for(const claim of claimedPrizes){
    if(!/^[a-f0-9]{32}$/.test(claim.id))continue;
    const row=document.createElement('li');row.className='phone-claim-item';
    const info=document.createElement('div');info.className='phone-claim-info';
    const name=document.createElement('strong');name.textContent=String(claim.name||'Claimed prize');
    const details=document.createElement('span');details.textContent=`${String(claim.tier||'').toUpperCase()} · ${money.format(Number(claim.cents||0)/100)}${claim.hasVideo?' · Video saved':' · No video yet'}`;
    info.append(name,details);
    const button=document.createElement('button');button.type='button';button.className='btn btn-outline';button.textContent='🎥 Edit / Re-record video';
    button.setAttribute('aria-label',`Edit or re-record video for ${name.textContent}`);
    button.onclick=()=>{closeMenu();void send('proof-edit-'+claim.id);};
    row.append(info,button);list.append(row);
   }
  }

 function setMenuTab(tab){
  for(const button of document.querySelectorAll('[data-phone-menu-tab]')){
   const selected=button.dataset.phoneMenuTab===tab;
   button.setAttribute('aria-selected',String(selected));button.tabIndex=selected?0:-1;
   const panel=$('phone-menu-panel-'+button.dataset.phoneMenuTab);if(panel)panel.hidden=!selected;
  }
 }
 const closeArrows=()=>{const dialog=$('phone-arrows-dialog');if(dialog?.open)dialog.close();};
 const closeMenu=()=>{const dialog=$('phone-quick-menu');if(dialog?.open)dialog.close();};
  const openMenu=(tab='controls')=>{const dialog=$('phone-quick-menu');if(active&&state&&dialog){closeArrows();syncNav();if(tab==='claims'&&!claimedPrizes.length)tab='controls';setMenuTab(tab);if(!dialog.open)dialog.showModal();}};
 const openArrows=()=>{const dialog=$('phone-arrows-dialog'),button=$('phone-arrows-toggle');if(!active||!state||button.disabled||!dialog||dialog.open)return;
  closeMenu();dialog.showModal();button.setAttribute('aria-expanded','true');button.classList.add('is-active');};
 $('phone-menu-close').onclick=closeMenu;
 $('phone-arrows-toggle').onclick=openArrows;
 $('phone-arrows-close').onclick=closeArrows;
 $('phone-arrows-dialog').addEventListener('close',()=>{$('phone-arrows-toggle').setAttribute('aria-expanded','false');$('phone-arrows-toggle').classList.remove('is-active');});
  document.querySelectorAll('[data-phone-menu-tab]').forEach(button=>button.addEventListener('click',()=>{if(!button.disabled)setMenuTab(button.dataset.phoneMenuTab);}));
 document.querySelectorAll('[data-phone-view]').forEach(button=>button.addEventListener('click',()=>{
  const target=button.dataset.phoneView;
   if(target==='more'){openMenu();return;}
   if(target==='prize'&&!mayGoPrize()&&claimedPrizes.length){openMenu('claims');return;}
  closeMenu();if(button.disabled)return;
  if(target==='home'){if(!mayGoHome()){message('Finish the active game or proof before returning home.',true);return;}
   dashboardRequested=true;show('phone-dashboard');void loadDashboard();return;}
  if(target==='camera'&&mayGoCamera()&&proofState.phoneCameraAllowed&&proofState.consent){window.LuckyPhoneCapture?.open();return;}
  if(target==='game'&&mayGoGame()||target==='prize'&&mayGoPrize()||target==='camera'&&mayGoCamera()){
   show('phone-controls');if(game)updateGame(game);updateProof(proofState);
  }
 }));
 $('phone-movie-play').onclick=()=>movieControl('play');
 $('phone-movie-pause').onclick=()=>movieControl('pause');
 $('phone-movie-stop').onclick=()=>movieControl('stop');
 $('phone-offer-speak').addEventListener('click',()=>{if(game?.offer?.status==='pending')speakOffer(game.offer);});
 $('phone-offer-play-video').addEventListener('click',()=>send('prize-video-play'));
 $('phone-proof-play-video').addEventListener('click',()=>send('prize-video-play'));
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
 $('phone-capture-open')?.addEventListener('click',()=>window.LuckyPhoneCapture?.open());
 $('phone-disconnect').addEventListener('click',async()=>{closeMenu();try{await request('disconnect');}catch(_){}forget();});
 try{const existing=JSON.parse(sessionStorage.getItem(storageKey)||'null');if(existing&&/^[a-f0-9]{32}$/.test(existing.id)&&/^[a-f0-9]{64}$/.test(existing.token))state=existing;}catch(_){}
 if(state){if(location.protocol!=='about:')history.replaceState(null,'','controller.php');show('phone-wait');status();}
 else if((/^[a-f0-9]{32}$/.test(params.get('invite')||'')&&/^[a-f0-9]{64}$/.test(params.get('key')||''))||(/^[a-f0-9]{32}$/.test(params.get('session')||'')&&/^[a-f0-9]{48}$/.test(params.get('viewer')||''))){show('phone-join');message('Scan complete. Enter your phone name to request approval.');}
 else {show('phone-join');$('phone-join-form').hidden=true;message('Scan the QR code displayed by the host to connect.',true);}
 setInterval(()=>{if(state)status();},600);
})();
