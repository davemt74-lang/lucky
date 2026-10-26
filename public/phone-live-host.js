/* HF7: Phone WebRTC feeds share the desktop prize-proof camera grid. */
(() => {
 'use strict';
 const grid=document.getElementById('camera-grid');if(!grid)return;
 const ICE={iceServers:[{urls:'stun:stun.l.google.com:19302'}]};
 const phones=new Map();
 let proof=null,epoch=0,timer=null;
 async function signal(action,data={},method='GET'){
  const params=new URLSearchParams({role:'host',action});
  if(method==='GET')for(const [key,value] of Object.entries(data))params.set(key,value);
  const me=method==='POST'?await Lucky.getMe():null;
  const r=await fetch('api/phone-live.php?'+params.toString(),{
   method,credentials:'same-origin',cache:'no-store',
   ...(method==='POST'?{headers:{'Content-Type':'application/json','X-CSRF-Token':me.csrf},body:JSON.stringify(data)}:{}),
  });
  let payload={};try{payload=await r.json();}catch(_){/* HTTP error below */}
  if(!r.ok||!payload.ok)throw Error(payload.error||'Live camera signaling failed (HTTP '+r.status+').');
  return payload;
 }
 function gathered(peer,timeout=7000){
  if(peer.iceGatheringState==='complete')return Promise.resolve();
  return new Promise(resolve=>{
   let done=false;
   const finish=()=>{if(done)return;done=true;clearTimeout(timerId);peer.removeEventListener('icegatheringstatechange',check);resolve();};
   const check=()=>{if(peer.iceGatheringState==='complete')finish();};
   const timerId=setTimeout(finish,timeout);
   peer.addEventListener('icegatheringstatechange',check);
  });
 }
 function remove(id){
  const item=phones.get(id);if(!item)return;
  phones.delete(id);
  try{item.peer.close();}catch(_){/* peer may already be closed */}
  item.video.srcObject=null;item.card.remove();
 }
 function stop(){
  epoch++;clearTimeout(timer);timer=null;proof=null;
  for(const id of [...phones.keys()])remove(id);
 }
 function cardFor(offer){
  const card=document.createElement('section');card.className='camera-source camera-source-phone';
  card.dataset.phonePair=offer.id;
  const video=document.createElement('video');video.autoplay=true;video.muted=true;video.playsInline=true;
  video.setAttribute('aria-label','Live phone camera preview');
  const title=document.createElement('label');title.className='camera-source-title';
  const name=document.createElement('strong');name.textContent=(offer.name||'Paired phone')+' · phone live';
  title.append(name);
  const info=document.createElement('p');info.className='mini-label';info.setAttribute('role','status');
  info.textContent='Connecting live phone camera…';
  const help=document.createElement('p');help.className='mini-label';
  help.textContent='Live preview only · record on your phone, then Send to Video Studio for the editable original.';
  card.append(video,title,info,help);grid.append(card);
  return {card,video,info};
 }
 async function connect(offer,context,version){
  const peer=new RTCPeerConnection(ICE),tile=cardFor(offer);
  const item={...tile,peer,nonce:offer.nonce};phones.set(offer.id,item);
  peer.ontrack=e=>{
   if(phones.get(offer.id)!==item)return;
   const incoming=e.streams[0]||new MediaStream([e.track]);
   tile.video.srcObject=incoming;
   void tile.video.play().catch(()=>{});
   tile.info.textContent='● LIVE · phone camera on desktop';
  };
  peer.onconnectionstatechange=()=>{
   if(phones.get(offer.id)!==item)return;
   if(peer.connectionState==='failed'||peer.connectionState==='disconnected')
    tile.info.textContent='Phone preview disconnected · reconnecting…';
   if(peer.connectionState==='connected')tile.info.textContent='● LIVE · phone camera on desktop';
  };
  try{
   await peer.setRemoteDescription({type:'offer',sdp:offer.offer});
   await peer.setLocalDescription(await peer.createAnswer());
   await gathered(peer);
   if(epoch!==version||phones.get(offer.id)!==item)return;
   await signal('answer',{session:context.session,claim:context.claim,id:offer.id,nonce:offer.nonce,sdp:peer.localDescription?.sdp||''},'POST');
  }catch(e){
   if(phones.get(offer.id)===item)remove(offer.id);
  }
 }
 async function poll(version){
  if(!proof||epoch!==version)return;
  try{
   const context=proof;
   const data=await signal('list',{session:context.session,claim:context.claim});
   if(epoch!==version)return;
   const available=new Set();
   for(const offer of data.phones||[]){
    if(!/^[a-f0-9]{32}$/.test(offer.id)||!/^[a-f0-9]{32}$/.test(offer.nonce))continue;
    available.add(offer.id);
    const current=phones.get(offer.id);
    if(current&&current.nonce===offer.nonce){if(!current.card.isConnected)grid.append(current.card);continue;}
    if(current)remove(offer.id);
    if(window.RTCPeerConnection)void connect(offer,context,version);
   }
   for(const id of [...phones.keys()])if(!available.has(id))remove(id);
  }catch(e){
   // Temporary host heartbeat/connection outages do not destroy the peer feed.
   if(epoch===version)for(const item of phones.values())if(!item.video.srcObject)
    item.info.textContent='Waiting for camera signal: '+e.message;
  }finally{
   if(proof&&epoch===version)timer=setTimeout(()=>void poll(version),1700);
  }
 }
 function start(detail){
  stop();if(!/^[a-f0-9]{32}$/.test(detail?.session||'')||!/^[a-f0-9]{32}$/.test(detail?.claim||''))return;
  proof={session:detail.session,claim:detail.claim};
  if(!window.RTCPeerConnection)return;
  void poll(epoch);
 }
 window.addEventListener('lucky:proof-open',e=>start(e.detail));
 window.addEventListener('lucky:proof-close',stop);
 window.addEventListener('lucky:cameras-refreshed',()=>{
  for(const item of phones.values())if(!item.card.isConnected)grid.append(item.card);
 });
 window.addEventListener('pagehide',stop);
})();
