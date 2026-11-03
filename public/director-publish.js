/* HF8C3: host-only Program video and mixed audio, public or private visibility. */
(() => {
 'use strict';
 const $=id=>document.getElementById(id);
 if(!$('director-publish'))return;
 const ICE={iceServers:[{urls:'stun:stun.l.google.com:19302'}]};
 let proof=null,active=null,stream=null,csrf='',timer=null,epoch=0,pending=null;
 // The server replaces the previous broadcast for a session when publishing.
 // Serialize creation and stale-result cleanup, not just response handling, so
 // a cancelled request cannot arrive late and replace a newer publication.
 let publicationQueue=Promise.resolve();
 const stopLabel=$('director-unpublish').textContent;
 let showId='',viewerSessions=0,health='Idle',lastHeartbeat=0,relayConfigured=false,iceHealth='Idle',sharingError='';
 const peers=new Map();
 function shareUrl(raw){
  if(window.LuckyInstallationUrls)return window.LuckyInstallationUrls.share(raw);
  if(document.querySelector?.('meta[name="lucky-deployment"]')?.content==='homeserver')throw Error('HomeServer sharing is unavailable. Reload this page.');
  return new URL(raw,document.baseURI);
 }
 const localUrl=raw=>window.LuckyInstallationUrls?window.LuckyInstallationUrls.localShare(raw):new URL(raw,document.baseURI);
 const stableShowUrl=()=>{
  sharingError='';
  if(!/^[a-f0-9]{32}$/.test(showId))return '';
  try{const url=shareUrl('live-view.php');url.searchParams.set('show',showId);return url.href;}
  catch(e){sharingError=e.message;return '';}
 };
 function selectedVisibility(){return $('director-broadcast-visibility').value==='public'?'public':'private';}
 function visibilityUi(){
  const isPublic=selectedVisibility()==='public';
  $('director-public-ack-label').hidden=!isPublic;
  $('director-visibility-explanation').textContent=isPublic?'Public: anyone can view this Program on the Live View page, without signing in. Up to 8 concurrent viewers.':'Private: only people with the invitation link can join. Up to 8 concurrent viewers.';
 }
 const status=(message,error=false)=>{const node=$('director-live-status');node.textContent=message;node.classList.toggle('is-error',error);};
 const live=(id,version)=>!!active&&active.id===id&&epoch===version;
 const current=attempt=>pending===attempt&&epoch===attempt.version&&proof===attempt.owner&&
  $('director-publish-consent').checked&&selectedVisibility()===attempt.visibility&&
  (attempt.visibility!=='public'||$('director-publish-public-ack').checked);
 function requireLiveProgram(media){
  if(typeof media?.getTracks!=='function'||typeof media?.getVideoTracks!=='function')
   throw Error('Take a live source to Program before publishing.');
  const tracks=media.getTracks();
  if(!media.getVideoTracks().some(track=>track?.kind==='video'&&track.readyState==='live'&&tracks.includes(track)))
   throw Error('Take a live source to Program before publishing.');
 }
 function stopTracks(media){
  // Each Program stream owns cloned output tracks, never the source camera or
  // mixer track. One bad/ended track must not prevent the rest from closing.
  const tracks=new Set();
  for(const method of ['getTracks','getVideoTracks','getAudioTracks']){
   try{for(const track of media?.[method]?.()||[])tracks.add(track);}catch(_){}
  }
  for(const track of tracks)try{track.stop();}catch(_){}
 }
 function refresh(){
  const busy=!!pending;
  $('director-publish').disabled=busy||!!active||!proof;
  $('director-unpublish').disabled=!active&&!pending;
  $('director-unpublish').textContent=pending?'■ Cancel publishing':stopLabel;
  $('director-live-badge').textContent=active?'● ON AIR':'OFF AIR';
  $('director-live-badge').classList.toggle('is-live',!!active);
  $('director-live-link').hidden=!active;
  $('director-broadcast-visibility').disabled=busy||!!active;
  $('director-publish-public-ack').disabled=!!active;
  const permanent=stableShowUrl();
  if(sharingError)status(sharingError,true);
  if(active){
   try{
    const url=shareUrl('live-view.php');url.searchParams.set('id',active.id);
    if(active.visibility==='private')url.searchParams.set('key',active.key);
    const shared=active.visibility==='public'?(permanent||url.href):url.href;
    $('director-live-url').value=shared;$('director-open-live').href=localUrl(shared).href;
   }catch(e){
    // A stale network lease hides links, not the owned Program stream or the
    // host's local signaling loop. A renewed lease rebuilds these addresses.
    $('director-live-url').value='';$('director-open-live').removeAttribute('href');status(e.message,true);
   }
  }
  if($('director-show-url'))$('director-show-url').value=permanent;
  if($('director-show-copy'))$('director-show-copy').disabled=!permanent;
  if($('director-show-open')){
   $('director-show-open').href=permanent?localUrl(permanent).href:'#';
   $('director-show-open').setAttribute('aria-disabled',String(!permanent));
  }
  if($('director-live-viewers'))$('director-live-viewers').textContent=(active?viewerSessions:0)+' / 8';
  if($('director-live-health'))$('director-live-health').textContent=active?(Date.now()-lastHeartbeat>12000?'Checking…':health):'Idle';
  if($('director-live-relay'))$('director-live-relay').textContent=active?(active.ice?.relayEnabled?'TURN enabled':'Direct / STUN'):(relayConfigured?'TURN ready':'Direct / STUN');
  if($('director-live-ice'))$('director-live-ice').textContent=active?iceHealth:'Idle';
  const seconds=active?Math.max(0,Math.floor((Date.now()-active.startedMs)/1000)):0;
  if($('director-live-elapsed'))$('director-live-elapsed').textContent=String(Math.floor(seconds/60)).padStart(2,'0')+':'+String(seconds%60).padStart(2,'0');
 }
 async function request(action,data={},method='GET',auth=true,token=csrf){
  const params=new URLSearchParams({action});
  if(method==='GET')for(const [key,value] of Object.entries(data))params.set(key,value);
  const headers=method==='POST'?{'Content-Type':'application/json',...(auth?{'X-CSRF-Token':token}:{})}:{};
  const response=await fetch('api/director-live.php?'+params.toString(),{
   method,credentials:'same-origin',cache:'no-store',headers,...(action==='stop'?{keepalive:true}:{}),
   ...(method==='POST'?{body:JSON.stringify(data)}:{})
  });
  const out=await response.json().catch(()=>({}));
  if(!response.ok||!out.ok)throw Error(out.error||'Program signaling failed (HTTP '+response.status+').');
  return out;
 }
 async function loadShowLink(recording){
  const owner=proof;
  try{
   const auth=await Lucky.getMe();
   if(proof!==owner||owner?.recordingSession!==recording)return;
   csrf=auth.csrf;
   const result=await request('show_link',{recordingSession:recording},'POST',true,auth.csrf);
   if(proof!==owner)return;
   if(!/^[a-f0-9]{32}$/.test(result.showId||''))throw Error('The server returned an invalid show address.');
   showId=result.showId;refresh();
  }catch(e){
   if(proof===owner)status('Permanent show URL unavailable: '+e.message,true);
  }
 }
 async function refreshTelemetry(){
  if(!active||!proof?.recordingSession)return;
  const recording=proof.recordingSession,broadcast=active.id,version=epoch;
  try{
   const result=await request('host_status',{recordingSession:recording});
   if(!live(broadcast,version)||proof?.recordingSession!==recording)return;
   viewerSessions=Math.min(8,Math.max(0,Number(result.viewerCount)||0));
   health=result.live&&result.visibility===active.visibility?'Healthy':'Reconnecting';
   if(result.ice?.iceServers)active.ice=result.ice;
   lastHeartbeat=Date.now();refresh();
  }catch(_){if(live(broadcast,version)){health='Checking…';refresh();}}
 }
 function gathered(peer,signal){
  if(peer.iceGatheringState==='complete'||signal.aborted)return Promise.resolve();
  return new Promise(resolve=>{
   const done=()=>{clearTimeout(timeout);peer.removeEventListener('icegatheringstatechange',check);signal.removeEventListener('abort',done);resolve();};
   const check=()=>{if(peer.iceGatheringState==='complete')done();};
   const timeout=setTimeout(done,7000);
   peer.addEventListener('icegatheringstatechange',check);
   signal.addEventListener('abort',done,{once:true});
  });
 }
 function clearPeers(){for(const peer of peers.values())try{peer.close();}catch(_){}peers.clear();iceHealth='Idle';}
 function reportIce(){
  const states=[...peers.values()].map(p=>p.iceConnectionState||p.connectionState||'new');
  const connected=states.filter(s=>s==='connected'||s==='completed').length;
  const checking=states.filter(s=>s==='checking'||s==='new'||s==='connecting').length;
  iceHealth=states.length?connected+' connected · '+checking+' negotiating':'No viewer peers';refresh();
 }
 async function loadTurn(){
  // Legacy proof and embedded browser views may not render the admin relay panel.
  if(!$('director-turn-urls'))return;
  try{
   const s=await request('turn_config');relayConfigured=!!s.enabled;
   $('director-turn-urls').value=(s.urls||[]).join('\n');$('director-turn-ttl').value=s.ttl||21600;
   $('director-turn-enabled').checked=!!s.enabled;
   $('director-turn-status').textContent=s.configured?(s.enabled?'TURN relay enabled.':'TURN saved but disabled.'):'TURN is optional; STUN/direct connection is active.';
   refresh();
  }catch(e){$('director-turn-status').textContent='Relay settings unavailable: '+e.message;}
 }
 async function saveTurn(){
  const button=$('director-turn-save');if(!button)return;button.disabled=true;
  try{
   csrf=(await Lucky.getMe()).csrf;
   const settings=await request('turn_config',{urls:$('director-turn-urls').value,secret:$('director-turn-secret').value,
    ttl:Number($('director-turn-ttl').value),enabled:$('director-turn-enabled').checked},'POST');
   $('director-turn-secret').value='';relayConfigured=!!settings.enabled;
   $('director-turn-status').textContent=settings.enabled?'TURN relay saved. New peers will receive relay credentials.':'TURN relay disabled. Direct/STUN remains available.';
   refresh();
  }catch(e){$('director-turn-status').textContent='Unable to save TURN relay: '+e.message;}
  finally{button.disabled=false;}
 }
 async function connect(offer,current,version){
  if(!live(current,version)||!stream)return;
  if(!/^[a-f0-9]{32}$/.test(offer.id)||!/^v=0\r?\n/.test(offer.offer||''))return;
  const publication=active,signal=publication.controller.signal;
  let peer=null,disconnectTimer=null,closed=false;
  const close=()=>{
   if(closed)return;closed=true;clearTimeout(disconnectTimer);
   signal.removeEventListener('abort',close);
   if(peers.get(offer.id)===peer)peers.delete(offer.id);
   if(peer){peer.onconnectionstatechange=null;peer.oniceconnectionstatechange=null;
    try{peer.close();}catch(_){}}
   reportIce();
  };
  const ensureCurrent=()=>{if(!live(current,version)||closed)throw Error('Publisher stopped.');};
  try{
   peer=new RTCPeerConnection(publication.ice?.iceServers?publication.ice:ICE);peers.set(offer.id,peer);
   signal.addEventListener('abort',close,{once:true});
   peer.oniceconnectionstatechange=reportIce;
   peer.onconnectionstatechange=()=>{
    if(peer.connectionState==='failed'||peer.connectionState==='closed')close();
    else if(peer.connectionState==='disconnected'){
     clearTimeout(disconnectTimer);disconnectTimer=setTimeout(()=>{if(peer.connectionState==='disconnected')close();},8000);
    }else if(peer.connectionState==='connected'){clearTimeout(disconnectTimer);reportIce();}
   };reportIce();
   ensureCurrent();
   // Both tracks represent the composited Program; no individual camera feed
   // or local microphone track is offered to viewers independently.
   for(const track of stream.getTracks())peer.addTrack(track,stream);
   await peer.setRemoteDescription({type:'offer',sdp:offer.offer});
   ensureCurrent();
   const answer=await peer.createAnswer();ensureCurrent();
   await peer.setLocalDescription(answer);ensureCurrent();
   await gathered(peer,signal);ensureCurrent();
   await request('answer',{id:current,viewer:offer.id,sdp:peer.localDescription.sdp},'POST',true,publication.csrf);
   ensureCurrent();
  }catch(e){close();}
 }
 async function poll(current,version){
  if(!live(current,version))return;
  try{
   const out=await request('host_list',{id:current});
   if(!live(current,version))return;
   for(const offer of out.offers||[])if(!peers.has(offer.id))void connect(offer,current,version);
  }catch(e){
   if(!live(current,version))return;
   // A lost proof, expired consent, or revoked broadcast stops transmission immediately.
   status('Live View disconnected: '+e.message,true);
   void stop(true);return;
  }
  if(live(current,version))timer=setTimeout(()=>void poll(current,version),1400);
 }
 async function publish(){
  if(pending||active)return;
  if(!proof){status('Open a recording session before publishing.',true);return;}
  if(!$('director-publish-consent').checked){status('Confirm participant/content broadcast permission before publishing.',true);return;}
  const visibility=selectedVisibility();
  if(visibility==='public'&&!$('director-publish-public-ack').checked){status('Confirm that anyone may view this public broadcast.',true);return;}
  if(!window.RTCPeerConnection){status('Live View requires a WebRTC-capable browser.',true);return;}
  try{shareUrl('live-view.php');}catch(e){status(e.message,true);return;}
  const attempt={version:++epoch,owner:proof,scope:{...proof},visibility,
   publicAck:visibility==='public'&&$('director-publish-public-ack').checked,stream:null,csrf:''};
  pending=attempt;refresh();
  try{
   // Program acquisition is currently synchronous, but treat its result as an
   // asynchronous resource: cancellation must also dispose of a late stream.
   attempt.stream=await window.LuckyCameraDirector?.programStream?.();
   if(!current(attempt))return;
   requireLiveProgram(attempt.stream);
   const auth=await Lucky.getMe();if(!current(attempt))return;
   attempt.csrf=auth.csrf;csrf=auth.csrf;
   const create=async()=>{
    if(!current(attempt))return;
    requireLiveProgram(attempt.stream);
    const result=await request('publish',{...attempt.scope,consent:true,visibility,public_ack:attempt.publicAck},'POST',true,attempt.csrf);
    let adopted=false;
    try{
     if(!current(attempt))return;
     requireLiveProgram(attempt.stream);
     if(!/^[a-f0-9]{32}$/.test(result.id||'')||
        (visibility==='private'&&!/^[a-f0-9]{48}$/.test(result.key||'')))
      throw Error('The server returned an invalid Live View invitation.');
     if(/^[a-f0-9]{32}$/.test(result.showId||''))showId=result.showId;
     const url=shareUrl('live-view.php');
     url.searchParams.set('id',result.id);
     if(visibility==='private')url.searchParams.set('key',result.key);
     const finalUrl=visibility==='public'?(stableShowUrl()||url.href):url.href;
     $('director-live-url').value=finalUrl;$('director-open-live').href=localUrl(finalUrl).href;
     $('director-live-link-label').textContent=visibility==='public'?'Permanent public show URL':'Private Live View invitation';
     active={id:result.id,key:result.key,visibility,startedMs:Date.now(),ice:result.ice||ICE,
      csrf:attempt.csrf,controller:new AbortController()};
     stream=attempt.stream;attempt.stream=null;pending=null;adopted=true;
     viewerSessions=0;health='Connecting';iceHealth='Waiting for viewers';lastHeartbeat=Date.now();
     status(visibility==='public'?'● PUBLIC · Program is on Live View for anyone to watch (8 viewers max).':'● PRIVATE · Program is live. Share this invitation only with intended viewers.');
     refresh();void poll(active.id,attempt.version);void refreshTelemetry();
    }finally{
     if(!adopted&&/^[a-f0-9]{32}$/.test(result.id||'')&&active?.id!==result.id){
      // Revoke only the abandoned creation, never whatever is now on air.
      // Keep this cleanup inside the queue before a later publish can start.
      try{await request('stop',{id:result.id},'POST',true,attempt.csrf);}catch(_){/* No local media; server liveness expires without a heartbeat. */}
     }
    }
   };
   const operation=publicationQueue.then(create,create);
   publicationQueue=operation.catch(()=>{});
   await operation;
  }catch(e){if(current(attempt))status('Unable to publish: '+e.message,true);}
  finally{
   stopTracks(attempt.stream);attempt.stream=null;
   if(pending===attempt)pending=null;
   refresh();
  }
 }
 async function stop(silent=false){
  const was=active,cancelled=pending;active=null;pending=null;const version=++epoch;
  clearTimeout(timer);timer=null;was?.controller.abort();
  clearPeers();stopTracks(stream);stream=null;
  stopTracks(cancelled?.stream);if(cancelled)cancelled.stream=null;
  viewerSessions=0;health='Idle';iceHealth='Idle';lastHeartbeat=0;
  $('director-live-url').value='';$('director-open-live').removeAttribute('href');
  refresh();
  if(!silent)status(cancelled?'Program is OFF AIR. Pending publication cancelled.':
   was?'Program is OFF AIR. Closing the Live View invitation…':'Program is OFF AIR.');
  if(was){try{
   await request('stop',{id:was.id},'POST',true,was.csrf);
   if(!silent&&epoch===version)status('Program is OFF AIR. Existing Live View invitations have been revoked.');
  }catch(e){if(!silent&&epoch===version)status('Broadcast closed locally; server invitation expires automatically if unreachable: '+e.message,true);}}
 }
 $('director-turn-save')?.addEventListener('click',()=>void saveTurn());
 $('director-broadcast-visibility').addEventListener('change',()=>{
  if((pending||active)&&selectedVisibility()!==(pending||active).visibility)void stop();
  if(selectedVisibility()!=='public')$('director-publish-public-ack').checked=false;visibilityUi();
 });
 $('director-publish-consent').addEventListener('change',()=>{if((active||pending)&&!$('director-publish-consent').checked)void stop();});
 $('director-publish-public-ack').addEventListener('change',()=>{if((active||pending)?.visibility==='public'&&!$('director-publish-public-ack').checked)void stop();});
 $('director-publish').addEventListener('click',()=>void publish());
 $('director-unpublish').addEventListener('click',()=>void stop());
 $('director-show-copy')?.addEventListener('click',async()=>{
  const url=stableShowUrl();if(!url)return;
  try{await navigator.clipboard.writeText(url);status('Permanent show URL copied. It stays the same across broadcasts.');}
  catch(_){$('director-show-url').select();status('Select and copy the permanent URL from the field.');}
 });
 window.addEventListener('lucky:proof-open',e=>{
  void stop(true);
  const d=e.detail||{};
  proof=/^[a-f0-9]{32}$/.test(d.session||'')&&/^[a-f0-9]{32}$/.test(d.claim||'')?{session:d.session,claim:d.claim}:null;
  showId='';refresh();
  $('director-publish-consent').checked=false;$('director-publish-public-ack').checked=false;visibilityUi();refresh();
 });
 window.addEventListener('lucky:director-studio-open',e=>{
  void stop(true);
  const id=String(e.detail?.recordingSession||'');
  proof=/^[a-f0-9]{32}$/.test(id)?{recordingSession:id}:null;
  showId='';refresh();if(proof)void loadShowLink(id);
  $('director-publish-consent').checked=false;$('director-publish-public-ack').checked=false;visibilityUi();refresh();
 });
 window.addEventListener('lucky:director-studio-close',()=>{proof=null;showId='';void stop(true);refresh();});
 window.addEventListener('lucky:proof-close',()=>{proof=null;showId='';void stop(true);refresh();});
 window.addEventListener('lucky:director-program-ended',()=>{if(active||pending){status('The Program camera was disconnected; Live View is ending.',true);void stop(true);}});
 window.addEventListener('pagehide',()=>{proof=null;void stop(true);});
 window.addEventListener('lucky:installation-urls',refresh);
 visibilityUi();refresh();void loadTurn();
 setInterval(()=>{if(!document.hidden)refresh();},1000);
 setInterval(()=>{if(!document.hidden)void refreshTelemetry();},4500);
 window.LuckyDirectorPublisher={publish,stop,state:()=>({onAir:!!active,publishing:!!pending,visibility:active?.visibility||null,
  viewerConnections:peers.size,showUrl:stableShowUrl(),streamHealth:health})};
})();
