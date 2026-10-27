/* HF8C3: host-only Program video and mixed audio, public or private visibility. */
(() => {
 'use strict';
 const $=id=>document.getElementById(id);
 if(!$('director-publish'))return;
 const ICE={iceServers:[{urls:'stun:stun.l.google.com:19302'}]};
 let proof=null,active=null,stream=null,csrf='',timer=null,epoch=0,busy=false;
 const peers=new Map();
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
 function gathered(peer){
  if(peer.iceGatheringState==='complete')return Promise.resolve();
  return new Promise(resolve=>{
   const done=()=>{clearTimeout(timeout);peer.removeEventListener('icegatheringstatechange',check);resolve();};
   const check=()=>{if(peer.iceGatheringState==='complete')done();};
   const timeout=setTimeout(done,7000);
   peer.addEventListener('icegatheringstatechange',check);
  });
 }
 function clearPeers(){for(const peer of peers.values())try{peer.close();}catch(_){}peers.clear();}
 async function connect(offer,current,version){
  if(!/^[a-f0-9]{32}$/.test(offer.id)||!/^v=0\r?\n/.test(offer.offer||''))return;
  const peer=new RTCPeerConnection(ICE);peers.set(offer.id,peer);
  peer.onconnectionstatechange=()=>{if(['closed','failed','disconnected'].includes(peer.connectionState)){
   if(peers.get(offer.id)===peer)peers.delete(offer.id);
   peer.close();
  }};
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
  }catch(e){if(peers.get(offer.id)===peer)peers.delete(offer.id);peer.close();}
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
  if(!proof){status('Open a prize-proof session first.',true);return;}
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
   stream=mix;active={id:result.id,key:result.key,visibility};
   const url=new URL('live-view.php',document.baseURI);
   url.searchParams.set('id',result.id);
   if(visibility==='private')url.searchParams.set('key',result.key);
   $('director-live-url').value=url.href;$('director-open-live').href=url.href;
   $('director-live-link-label').textContent=visibility==='public'?'Public Live View link':'Private Live View invitation';
   status(visibility==='public'?'● PUBLIC · Program is on Live View for anyone to watch (8 viewers max).':'● PRIVATE · Program is live. Share this invitation only with intended viewers.');
   void poll(active.id,++epoch);
  }catch(e){mix.getTracks().forEach(t=>t.stop());status('Unable to publish: '+e.message,true);}
  finally{busy=false;refresh();}
 }
 async function stop(silent=false){
  const was=active;active=null;epoch++;clearTimeout(timer);timer=null;
  clearPeers();stream?.getTracks().forEach(track=>track.stop());stream=null;
  $('director-live-url').value='';$('director-open-live').removeAttribute('href');
  refresh();
  if(!silent)status('Program is OFF AIR. Existing Live View invitations have been revoked.');
  if(was){try{await request('stop',{id:was.id},'POST');}catch(e){if(!silent)status('Broadcast closed locally; server invitation expires automatically if unreachable: '+e.message,true);}}
 }
 $('director-broadcast-visibility').addEventListener('change',()=>{if(selectedVisibility()!=='public')$('director-publish-public-ack').checked=false;visibilityUi();});
 $('director-publish-consent').addEventListener('change',()=>{if(active&&!$('director-publish-consent').checked)void stop();});
 $('director-publish-public-ack').addEventListener('change',()=>{if(active&&active.visibility==='public'&&!$('director-publish-public-ack').checked)void stop();});
 $('director-publish').addEventListener('click',()=>void publish());
 $('director-unpublish').addEventListener('click',()=>void stop());
 window.addEventListener('lucky:proof-open',e=>{
  void stop(true);
  const d=e.detail||{};
  proof=/^[a-f0-9]{32}$/.test(d.session||'')&&/^[a-f0-9]{32}$/.test(d.claim||'')?{session:d.session,claim:d.claim}:null;
  $('director-publish-consent').checked=false;$('director-publish-public-ack').checked=false;visibilityUi();refresh();
 });
 window.addEventListener('lucky:director-studio-open',e=>{
  void stop(true);
  const id=String(e.detail?.recordingSession||'');
  proof=/^[a-f0-9]{32}$/.test(id)?{recordingSession:id}:null;
  $('director-publish-consent').checked=false;$('director-publish-public-ack').checked=false;visibilityUi();refresh();
 });
 window.addEventListener('lucky:director-studio-close',()=>{proof=null;void stop(true);refresh();});
 window.addEventListener('lucky:proof-close',()=>{proof=null;void stop(true);refresh();});
 window.addEventListener('lucky:director-program-ended',()=>{if(active){status('The Program camera was disconnected; Live View is ending.',true);void stop(true);}});
 window.addEventListener('pagehide',()=>{proof=null;void stop(true);});
 visibilityUi();refresh();
 window.LuckyDirectorPublisher={publish,stop,state:()=>({onAir:!!active,visibility:active?.visibility||null,viewerConnections:peers.size})};
})();
