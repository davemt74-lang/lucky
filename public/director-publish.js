/* HF8C3: host-only Program video and mixed audio, public or private visibility. */
(() => {
 'use strict';
 const $=id=>document.getElementById(id);
 if(!$('director-publish'))return;
 const ICE={iceServers:[{urls:'stun:stun.l.google.com:19302'}]};
 let proof=null,active=null,stream=null,csrf='',timer=null,epoch=0,busy=false;
 let showId='',viewerSessions=0,health='Idle',lastHeartbeat=0,relayConfigured=false,iceHealth='Idle';
 const peers=new Map();
 const stableShowUrl=()=>{
  if(!/^[a-f0-9]{32}$/.test(showId))return '';
  const url=new URL('live-view.php',document.baseURI);url.searchParams.set('show',showId);return url.href;
 };
 function selectedVisibility(){return $('director-broadcast-visibility').value==='public'?'public':'private';}
 function visibilityUi(){
  const isPublic=selectedVisibility()==='public';
  $('director-public-ack-label').hidden=!isPublic;
  $('director-visibility-explanation').textContent=isPublic?'Public: anyone can view this Program on the Live View page, without signing in. Up to 8 concurrent viewers.':'Private: only people with the invitation link can join. Up to 8 concurrent viewers.';
 }
 const status=(message,error=false)=>{const node=$('director-live-status');node.textContent=message;node.classList.toggle('is-error',error);};
 function refresh(){
  $('director-publish').disabled=busy||!!active||!proof;
  $('director-unpublish').disabled=!active;
  $('director-live-badge').textContent=active?'● ON AIR':'OFF AIR';
  $('director-live-badge').classList.toggle('is-live',!!active);
  $('director-live-link').hidden=!active;
  $('director-broadcast-visibility').disabled=busy||!!active;
  $('director-publish-public-ack').disabled=!!active;
  const permanent=stableShowUrl();
  if($('director-show-url'))$('director-show-url').value=permanent;
  if($('director-show-copy'))$('director-show-copy').disabled=!permanent;
  if($('director-show-open')){
   $('director-show-open').href=permanent||'#';
   $('director-show-open').setAttribute('aria-disabled',String(!permanent));
  }
  if($('director-live-viewers'))$('director-live-viewers').textContent=(active?viewerSessions:0)+' / 8';
  if($('director-live-health'))$('director-live-health').textContent=active?(Date.now()-lastHeartbeat>12000?'Checking…':health):'Idle';
  if($('director-live-relay'))$('director-live-relay').textContent=active?(active.ice?.relayEnabled?'TURN enabled':'Direct / STUN'):(relayConfigured?'TURN ready':'Direct / STUN');
  if($('director-live-ice'))$('director-live-ice').textContent=active?iceHealth:'Idle';
  const seconds=active?Math.max(0,Math.floor((Date.now()-active.startedMs)/1000)):0;
  if($('director-live-elapsed'))$('director-live-elapsed').textContent=String(Math.floor(seconds/60)).padStart(2,'0')+':'+String(seconds%60).padStart(2,'0');
 }
 async function request(action,data={},method='GET',auth=true){
  const params=new URLSearchParams({action});
  if(method==='GET')for(const [key,value] of Object.entries(data))params.set(key,value);
  const headers=method==='POST'?{'Content-Type':'application/json',...(auth?{'X-CSRF-Token':csrf}:{})}:{};
  const response=await fetch('api/director-live.php?'+params.toString(),{
   method,credentials:'same-origin',cache:'no-store',headers,
   ...(method==='POST'?{body:JSON.stringify(data)}:{})
  });
  const out=await response.json().catch(()=>({}));
  if(!response.ok||!out.ok)throw Error(out.error||'Program signaling failed (HTTP '+response.status+').');
  return out;
 }
 async function loadShowLink(recording){
  try{
   csrf=(await Lucky.getMe()).csrf;
   const result=await request('show_link',{recordingSession:recording},'POST');
   if(proof?.recordingSession!==recording)return;
   if(!/^[a-f0-9]{32}$/.test(result.showId||''))throw Error('The server returned an invalid show address.');
   showId=result.showId;refresh();
  }catch(e){
   if(proof?.recordingSession===recording)status('Permanent show URL unavailable: '+e.message,true);
  }
 }
 async function refreshTelemetry(){
  if(!active||!proof?.recordingSession)return;
  const recording=proof.recordingSession,broadcast=active.id;
  try{
   const result=await request('host_status',{recordingSession:recording});
   if(!active||active.id!==broadcast||proof?.recordingSession!==recording)return;
   viewerSessions=Math.min(8,Math.max(0,Number(result.viewerCount)||0));
   health=result.live&&result.visibility===active.visibility?'Healthy':'Reconnecting';
   if(result.ice?.iceServers)active.ice=result.ice;
   lastHeartbeat=Date.now();refresh();
  }catch(_){if(active?.id===broadcast){health='Checking…';refresh();}}
 }
 function gathered(peer){
  if(peer.iceGatheringState==='complete')return Promise.resolve();
  return new Promise(resolve=>{
   const done=()=>{clearTimeout(timeout);peer.removeEventListener('icegatheringstatechange',check);resolve();};
   const check=()=>{if(peer.iceGatheringState==='complete')done();};
   const timeout=setTimeout(done,7000);
   peer.addEventListener('icegatheringstatechange',check);
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
  if(!/^[a-f0-9]{32}$/.test(offer.id)||!/^v=0\r?\n/.test(offer.offer||''))return;
  const peer=new RTCPeerConnection(active?.ice?.iceServers?active.ice:ICE);peers.set(offer.id,peer);
  let disconnectTimer=null;
  const close=()=>{clearTimeout(disconnectTimer);if(peers.get(offer.id)===peer)peers.delete(offer.id);peer.close();reportIce();};
  peer.oniceconnectionstatechange=reportIce;
  peer.onconnectionstatechange=()=>{
   if(peer.connectionState==='failed'||peer.connectionState==='closed')close();
   else if(peer.connectionState==='disconnected'){
    clearTimeout(disconnectTimer);disconnectTimer=setTimeout(()=>{if(peer.connectionState==='disconnected')close();},8000);
   }else if(peer.connectionState==='connected'){clearTimeout(disconnectTimer);reportIce();}
  };reportIce();
  try{
   if(!stream||!active||active.id!==current||version!==epoch)throw Error('Publisher stopped.');
   // Both tracks represent the composited Program; no individual camera feed
   // or local microphone track is offered to viewers independently.
   for(const track of stream.getTracks())peer.addTrack(track,stream);
   await peer.setRemoteDescription({type:'offer',sdp:offer.offer});
   await peer.setLocalDescription(await peer.createAnswer());
   await gathered(peer);
   if(!active||active.id!==current||version!==epoch)throw Error('Publisher stopped.');
   await request('answer',{id:current,viewer:offer.id,sdp:peer.localDescription.sdp},'POST');
  }catch(e){close();}
 }
 async function poll(current,version){
  if(!active||active.id!==current||version!==epoch)return;
  try{
   const out=await request('host_list',{id:current});
   if(version!==epoch||!active)return;
   for(const offer of out.offers||[])if(!peers.has(offer.id))void connect(offer,current,version);
  }catch(e){
   if(version!==epoch)return;
   // A lost proof, expired consent, or revoked broadcast stops transmission immediately.
   status('Live View disconnected: '+e.message,true);
   void stop(true);return;
  }
  if(version===epoch&&active)timer=setTimeout(()=>void poll(current,version),1400);
 }
 async function publish(){
  if(busy||active)return;
  if(!proof){status('Open a recording session before publishing.',true);return;}
  if(!$('director-publish-consent').checked){status('Confirm participant/content broadcast permission before publishing.',true);return;}
  const visibility=selectedVisibility();
  if(visibility==='public'&&!$('director-publish-public-ack').checked){status('Confirm that anyone may view this public broadcast.',true);return;}
  if(!window.RTCPeerConnection){status('Live View requires a WebRTC-capable browser.',true);return;}
  const mix=window.LuckyCameraDirector?.programStream?.();
  if(!mix?.getVideoTracks().length){status('Take a live source to Program before publishing.',true);return;}
  busy=true;refresh();
  try{
   csrf=(await Lucky.getMe()).csrf;
   const result=await request('publish',{...proof,consent:true,visibility,public_ack:visibility==='public'&&$('director-publish-public-ack').checked},'POST');
   if(/^[a-f0-9]{32}$/.test(result.showId||''))showId=result.showId;
   stream=mix;active={id:result.id,key:result.key,visibility,startedMs:Date.now(),ice:result.ice||ICE};
   const url=new URL('live-view.php',document.baseURI);
   url.searchParams.set('id',result.id);
   if(visibility==='private')url.searchParams.set('key',result.key);
   const finalUrl=visibility==='public'?(stableShowUrl()||url.href):url.href;
   $('director-live-url').value=finalUrl;$('director-open-live').href=finalUrl;
   $('director-live-link-label').textContent=visibility==='public'?'Permanent public show URL':'Private Live View invitation';
   viewerSessions=0;health='Connecting';iceHealth='Waiting for viewers';lastHeartbeat=Date.now();
   status(visibility==='public'?'● PUBLIC · Program is on Live View for anyone to watch (8 viewers max).':'● PRIVATE · Program is live. Share this invitation only with intended viewers.');
   void poll(active.id,++epoch);void refreshTelemetry();
  }catch(e){mix.getTracks().forEach(t=>t.stop());status('Unable to publish: '+e.message,true);}
  finally{busy=false;refresh();}
 }
 async function stop(silent=false){
  const was=active;active=null;epoch++;clearTimeout(timer);timer=null;
  clearPeers();stream?.getTracks().forEach(track=>track.stop());stream=null;
  viewerSessions=0;health='Idle';iceHealth='Idle';lastHeartbeat=0;
  $('director-live-url').value='';$('director-open-live').removeAttribute('href');
  refresh();
  if(!silent)status('Program is OFF AIR. Existing Live View invitations have been revoked.');
  if(was){try{await request('stop',{id:was.id},'POST');}catch(e){if(!silent)status('Broadcast closed locally; server invitation expires automatically if unreachable: '+e.message,true);}}
 }
 $('director-turn-save')?.addEventListener('click',()=>void saveTurn());
 $('director-broadcast-visibility').addEventListener('change',()=>{if(selectedVisibility()!=='public')$('director-publish-public-ack').checked=false;visibilityUi();});
 $('director-publish-consent').addEventListener('change',()=>{if(active&&!$('director-publish-consent').checked)void stop();});
 $('director-publish-public-ack').addEventListener('change',()=>{if(active&&active.visibility==='public'&&!$('director-publish-public-ack').checked)void stop();});
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
 window.addEventListener('lucky:director-program-ended',()=>{if(active){status('The Program camera was disconnected; Live View is ending.',true);void stop(true);}});
 window.addEventListener('pagehide',()=>{proof=null;void stop(true);});
 visibilityUi();refresh();void loadTurn();
 setInterval(()=>{if(!document.hidden)refresh();},1000);
 setInterval(()=>{if(!document.hidden)void refreshTelemetry();},4500);
 window.LuckyDirectorPublisher={publish,stop,state:()=>({onAir:!!active,visibility:active?.visibility||null,
  viewerConnections:peers.size,showUrl:stableShowUrl(),streamHealth:health})};
})();
