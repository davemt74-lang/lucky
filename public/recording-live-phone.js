/* HF8C5: optional, arm-gated WebRTC preview for universal recording sessions. */
(() => {
 'use strict';
 const anchor=document.getElementById('session-camera-arm-status');if(!anchor)return;
 const badge=document.createElement('p');badge.id='session-camera-director-status';badge.className='mini-label';
 badge.setAttribute('role','status');badge.textContent='Director live preview: off';anchor.after(badge);
 const ICE={iceServers:[{urls:'stun:stun.l.google.com:19302'}]};
 const random=()=>crypto.randomUUID?.().replaceAll('-','')||
  Array.from(crypto.getRandomValues(new Uint8Array(16)),x=>x.toString(16).padStart(2,'0')).join('');
 let current=null,desired=null,version=0,retryCount=0,retryTimer=null;
 const status=(s,bad=false)=>{badge.textContent=s;badge.classList.toggle('platform-error',bad);};
 function paired(){
  let value=null;try{value=JSON.parse(sessionStorage.getItem('lucky-phone-controller-v5p')||'null');}catch(_){}
  if(!/^[a-f0-9]{32}$/.test(value?.id||'')||!/^[a-f0-9]{64}$/.test(value?.token||''))
   throw Error('Approved phone pairing required. Return to your phone controller.');
  return value;
 }
 async function signal(item,action,data={},method='POST',keepalive=false){
  const args=new URLSearchParams({role:'phone',action});
  if(method==='GET')for(const [name,value] of Object.entries(data))args.set(name,String(value));
  const response=await fetch('api/recording-live.php?'+args,{
   method,credentials:'omit',cache:'no-store',keepalive,
   headers:{'X-Controller-Token':item.pair.token,...(method==='POST'?{'Content-Type':'application/json'}:{})},
   ...(method==='POST'?{body:JSON.stringify({...data,id:item.pair.id})}:{})
  });
  const body=await response.json().catch(()=>({}));
  if(!response.ok||!body.ok)throw Error(body.error||'Live camera signal failed ('+response.status+').');
  return body;
 }
 function gathered(peer){if(peer.iceGatheringState==='complete')return Promise.resolve();
  return new Promise(resolve=>{
   const done=()=>{clearTimeout(timeout);peer.removeEventListener('icegatheringstatechange',check);resolve();};
   const check=()=>{if(peer.iceGatheringState==='complete')done();};
   const timeout=setTimeout(done,7000);peer.addEventListener('icegatheringstatechange',check);
  });
 }
 function valid(detail){return detail?.consent&&detail?.armed&&/^[a-f0-9]{32}$/.test(detail.session||'')&&
  detail.stream?.getVideoTracks?.().some(t=>t.readyState==='live');}
 function closeCurrent(){
  const item=current;current=null;version++;
  if(item){clearTimeout(item.timer);try{item.peer.close();}catch(_){}
   if(item.offered)void signal(item,'end',{nonce:item.nonce},'POST',true).catch(()=>{});
  }
 }
 function stop(){desired=null;clearTimeout(retryTimer);retryTimer=null;retryCount=0;closeCurrent();status('Director live preview: off');}
 function retry(item,reason){
  if(current!==item||!valid(desired))return;
  closeCurrent();clearTimeout(retryTimer);
  const wait=Math.min(10000,1500*Math.pow(2,Math.min(retryCount++,3)));
  status('Director preview reconnecting in '+Math.ceil(wait/1000)+'s · '+reason,true);
  retryTimer=setTimeout(()=>{retryTimer=null;if(valid(desired))void start(desired);},wait);
 }
 async function start(detail){
  closeCurrent();if(!valid(detail))return;
  if(!window.RTCPeerConnection){status('Director preview requires WebRTC.',true);return;}
  let pair;try{pair=paired();}catch(e){status(e.message,true);return;}
  const token=version,peer=new RTCPeerConnection(ICE);
  const item={pair,peer,nonce:random(),session:detail.session,offered:false,timer:null};current=item;
  status('Connecting privately to Camera Director…');
  peer.onconnectionstatechange=()=>{
   if(current!==item)return;
   if(peer.connectionState==='connected'){retryCount=0;status('● LIVE · private Camera Director preview');}
   else if(['failed','disconnected'].includes(peer.connectionState))retry(item,'connection interrupted');
  };
  try{
   for(const track of detail.stream.getTracks())peer.addTrack(track,detail.stream);
   await peer.setLocalDescription(await peer.createOffer());await gathered(peer);
   if(current!==item||version!==token)return;
   await signal(item,'offer',{session:item.session,nonce:item.nonce,sdp:peer.localDescription?.sdp||''});
   item.offered=true;
   if(current!==item||version!==token)return;
   async function heartbeat(){
    if(current!==item)return;
    try{
     const answer=await signal(item,'poll',{id:pair.id,session:item.session,nonce:item.nonce},'GET');
     if(answer.answer&&!peer.currentRemoteDescription)
      await peer.setRemoteDescription({type:'answer',sdp:answer.answer});
     if(current===item)item.timer=setTimeout(heartbeat,1800);
    }catch(e){if(current===item)retry(item,e.message);}
   }
   await heartbeat();
  }catch(e){if(current===item)retry(item,e.message);}
 }
 window.addEventListener('lucky:recording-camera-live',e=>{
  const next=e.detail||{};
  if(!valid(next)){stop();return;}
  desired=next;retryCount=0;clearTimeout(retryTimer);retryTimer=null;void start(next);
 });
 window.addEventListener('pagehide',stop);
 window.LuckyRecordingPhonePreview={state:()=>({sharing:!!current,connected:current?.peer.connectionState==='connected'})};
})();
