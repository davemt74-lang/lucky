/* HF8C5: privately subscribe to armed recording-session phone previews. */
(() => {
 'use strict';
 const grid=document.getElementById('camera-grid'),status=document.getElementById('director-studio-phone-status');
 if(!grid||!status)return;
 const ICE={iceServers:[{urls:'stun:stun.l.google.com:19302'}]};
 const phones=new Map();let recording='',epoch=0,timer=null,csrf='';
 function feedback(message,bad=false){status.textContent=message;status.classList.toggle('is-error',bad);}
 async function signal(action,data={},method='GET'){
  const args=new URLSearchParams({role:'host',action});
  if(method==='GET')for(const [key,value] of Object.entries(data))args.set(key,value);
  const opts={method,credentials:'same-origin',cache:'no-store'};
  if(method==='POST'){
   if(!csrf)csrf=(await Lucky.getMe()).csrf;
   opts.headers={'Content-Type':'application/json','X-CSRF-Token':csrf};opts.body=JSON.stringify(data);
  }
  const response=await fetch('api/recording-live.php?'+args,opts);
  const body=await response.json().catch(()=>({}));
  if(!response.ok||!body.ok)throw Error(body.error||'Recording live connection failed ('+response.status+').');
  return body;
 }
 function gathered(peer){if(peer.iceGatheringState==='complete')return Promise.resolve();
  return new Promise(resolve=>{
   const done=()=>{clearTimeout(timeout);peer.removeEventListener('icegatheringstatechange',check);resolve();};
   const check=()=>{if(peer.iceGatheringState==='complete')done();};
   const timeout=setTimeout(done,7000);peer.addEventListener('icegatheringstatechange',check);
  });
 }
 function remove(id){
  const item=phones.get(id);if(!item)return;phones.delete(id);
  try{item.peer.close();}catch(_){}item.video.srcObject=null;item.card.remove();
  window.dispatchEvent(new Event('lucky:cameras-refreshed'));
 }
 function stop(){epoch++;clearTimeout(timer);timer=null;recording='';csrf='';
  for(const id of [...phones.keys()])remove(id);feedback('No phone previews connected. Arm a video camera on the paired phone to make it available.');
 }
 function tile(offer){
  const card=document.createElement('section');card.className='camera-source camera-source-phone';card.dataset.phonePair=offer.id;
  const video=document.createElement('video');video.autoplay=true;video.muted=true;video.playsInline=true;
  video.setAttribute('aria-label','Recording-session live phone camera');
  const name=document.createElement('strong');name.textContent=offer.name||'Paired phone';
  const info=document.createElement('p');info.className='mini-label';info.setAttribute('role','status');info.textContent='Connecting…';
  card.append(name,video,info);grid.append(card);window.dispatchEvent(new Event('lucky:cameras-refreshed'));
  return {card,video,info};
 }
 async function connect(offer,session,version){
  const peer=new RTCPeerConnection(ICE),view=tile(offer),item={...view,peer,nonce:offer.nonce};
  phones.set(offer.id,item);
  peer.ontrack=e=>{
   if(phones.get(offer.id)!==item)return;
   item.video.srcObject=e.streams[0]||new MediaStream([e.track]);
   void item.video.play().catch(()=>{});item.info.textContent='● LIVE · private recording camera';
   window.dispatchEvent(new Event('lucky:cameras-refreshed'));
  };
  peer.onconnectionstatechange=()=>{
   if(phones.get(offer.id)!==item)return;
   if(peer.connectionState==='connected')item.info.textContent='● LIVE · private recording camera';
   if(['failed','disconnected'].includes(peer.connectionState))item.info.textContent='Connection interrupted · waiting for phone retry';
  };
  try{
   await peer.setRemoteDescription({type:'offer',sdp:offer.offer});
   await peer.setLocalDescription(await peer.createAnswer());await gathered(peer);
   if(epoch!==version||phones.get(offer.id)!==item)return;
   await signal('answer',{session,id:offer.id,nonce:offer.nonce,sdp:peer.localDescription?.sdp||''},'POST');
  }catch(e){if(phones.get(offer.id)===item)remove(offer.id);}
 }
 async function poll(version){
  if(!recording||epoch!==version)return;
  try{
   const session=recording,body=await signal('list',{session});if(epoch!==version)return;
   const seen=new Set();
   for(const offer of body.phones||[]){
    if(!/^[a-f0-9]{32}$/.test(offer.id||'')||!/^[a-f0-9]{32}$/.test(offer.nonce||''))continue;
    seen.add(offer.id);const active=phones.get(offer.id);
    if(active&&active.nonce===offer.nonce)continue;
    if(active)remove(offer.id);
    if(window.RTCPeerConnection)void connect(offer,session,version);
   }
   for(const id of [...phones.keys()])if(!seen.has(id))remove(id);
   feedback(phones.size+' paired phone preview'+(phones.size===1?'':'s')+' connected to this recording. Only Program may be published.');
  }catch(e){if(epoch===version)feedback('Phone preview sync: '+e.message,true);}
  finally{if(recording&&epoch===version)timer=setTimeout(()=>void poll(version),1700);}
 }
 window.addEventListener('lucky:director-studio-open',e=>{
  stop();const session=String(e.detail?.recordingSession||'');
  if(!/^[a-f0-9]{32}$/.test(session))return;
  recording=session;if(!window.RTCPeerConnection){feedback('Browser does not support WebRTC phone previews.',true);return;}
  void poll(epoch);
 });
 window.addEventListener('lucky:director-studio-close',stop);
 window.addEventListener('pagehide',stop);
 window.LuckyRecordingLiveHost={state:()=>({recording,cameras:phones.size,phones:[...phones.keys()]})};
})();
