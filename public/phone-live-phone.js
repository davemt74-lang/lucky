/* HF7: HTTPS WebRTC signaling for the phone's live prize-proof preview. */
(() => {
 'use strict';
 const dialog=document.getElementById('phone-capture-dialog');if(!dialog)return;
 const anchor=document.getElementById('phone-capture-status');
 const indicator=document.createElement('p');indicator.id='phone-live-indicator';
 indicator.className='mini-label';indicator.setAttribute('role','status');indicator.textContent='Live desktop preview: off';
 anchor?.after(indicator);
 const ICE={iceServers:[{urls:'stun:stun.l.google.com:19302'}]};
 const random=()=>crypto.randomUUID?.().replaceAll('-','')||
  Array.from(crypto.getRandomValues(new Uint8Array(16)),n=>n.toString(16).padStart(2,'0')).join('');
 let active=null,revision=0;
 const state=(msg,alert=false)=>{indicator.textContent=msg;indicator.classList.toggle('platform-error',alert);};
 function pair(){
  let p=null;try{p=JSON.parse(sessionStorage.getItem('lucky-phone-controller-v5p')||'null');}catch(_){}
  if(!/^[a-f0-9]{32}$/.test(p?.id||'')||!/^[a-f0-9]{64}$/.test(p?.token||''))
   throw Error('Phone pairing expired. Scan the QR code again.');
  return p;
 }
 async function signal(connection,action,data={},method='POST',keepalive=false){
  const params=new URLSearchParams({role:'phone',action});
  if(method==='GET')for(const [k,v] of Object.entries(data))params.set(k,String(v));
  const result=await fetch('api/phone-live.php?'+params.toString(),{
   method,credentials:'omit',cache:'no-store',keepalive,
   headers:{'X-Controller-Token':connection.pair.token,...(method==='POST'?{'Content-Type':'application/json'}:{})},
   ...(method==='POST'?{body:JSON.stringify({...data,id:connection.pair.id})}:{}),
  });
  let payload={};try{payload=await result.json();}catch(_){}
  if(!result.ok||!payload.ok)throw Error(payload.error||'Live camera signaling failed (HTTP '+result.status+').');
  return payload;
 }
 function gathered(peer,timeout=7000){
  if(peer.iceGatheringState==='complete')return Promise.resolve();
  return new Promise(resolve=>{
   let done=false;
   const finish=()=>{if(done)return;done=true;clearTimeout(timer);peer.removeEventListener('icegatheringstatechange',check);resolve();};
   const check=()=>{if(peer.iceGatheringState==='complete')finish();};
   const timer=setTimeout(finish,timeout);
   peer.addEventListener('icegatheringstatechange',check);
  });
 }
 function stop(silent=false){
  const current=active;active=null;revision++;
  if(current){
   clearTimeout(current.timer);try{current.peer.close();}catch(_){}
   if(current.offered)void signal(current,'end',{nonce:current.nonce},'POST',true).catch(()=>{});
  }
  if(!silent)state('Live desktop preview: off');
 }
 async function start(detail){
  stop(true);
  if(detail?.mode!=='video'||!detail?.stream?.getVideoTracks?.().length||!detail.consent)return;
  if(!window.RTCPeerConnection){state('Live preview needs a WebRTC-capable browser.',true);return;}
  let pairing;
  try{pairing=pair();}catch(e){state(e.message,true);return;}
  const token=revision,nonce=random();
  const peer=new RTCPeerConnection(ICE);
  const connection={peer,nonce,pair:pairing,session:detail.session,claim:detail.claim,offered:false,timer:null};
  active=connection;state('Connecting live phone camera to desktop…');
  peer.onconnectionstatechange=()=>{
   if(active!==connection)return;
   if(peer.connectionState==='connected')state('● LIVE on desktop · camera streaming');
   else if(peer.connectionState==='failed'||peer.connectionState==='closed')
    state('Desktop live preview disconnected. Reopen the camera to retry.',true);
  };
  try{
   for(const track of detail.stream.getTracks())peer.addTrack(track,detail.stream);
   await peer.setLocalDescription(await peer.createOffer());
   await gathered(peer);
   if(active!==connection||revision!==token)return;
   await signal(connection,'offer',{session:connection.session,claim:connection.claim,nonce,sdp:peer.localDescription?.sdp||''});
   connection.offered=true;
   if(active!==connection||revision!==token){void signal(connection,'end',{nonce},'POST',true).catch(()=>{});return;}
   async function heartbeat(){
    if(active!==connection)return;
    try{
     const answer=await signal(connection,'poll',{id:pairing.id,session:connection.session,claim:connection.claim,nonce},'GET');
     if(answer.answer&&!peer.currentRemoteDescription)
      await peer.setRemoteDescription({type:'answer',sdp:answer.answer});
     if(active===connection)connection.timer=setTimeout(heartbeat,1800);
    }catch(e){
     if(active===connection){state('Live preview paused: '+e.message,true);stop(true);}
    }
   }
   await heartbeat();
  }catch(e){if(active===connection){state('Live preview unavailable: '+e.message,true);stop(true);}}
 }
 window.addEventListener('lucky:phone-capture-stream',e=>{void start(e.detail);});
 window.addEventListener('lucky:phone-capture-close',()=>stop());
 window.addEventListener('pagehide',()=>stop(true));
})();
