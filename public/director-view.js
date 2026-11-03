/* HF8C3: public/private read-only Program video and mixed audio viewer. */
(() => {
 'use strict';
 let id=document.body.dataset.broadcastId||'',key=document.body.dataset.broadcastKey||'';
 const show=document.body.dataset.showId||'';
 const view=document.getElementById('director-view-video'),info=document.getElementById('director-view-status');
 let peer=null,alive=true,fallbackStream=null,epoch=0,showTimer=null,showQuery=0;
 const sound=document.getElementById('director-view-sound');
 const state=(message,error=false)=>{info.textContent=message;info.classList.toggle('is-error',error);};
 const ICE={iceServers:[{urls:'stun:stun.l.google.com:19302'}]};
 async function request(action,fields={},method='GET'){
  const params=new URLSearchParams({action});
  if(method==='GET')Object.entries(fields).forEach(([k,v])=>params.set(k,v));
  const response=await fetch('api/director-live.php?'+params.toString(),{
   method,cache:'no-store',credentials:'same-origin',
   ...(method==='POST'?{headers:{'Content-Type':'application/json'},body:JSON.stringify(fields)}:{})
  });
  const out=await response.json().catch(()=>({}));
  if(!response.ok||!out.ok)throw Error(out.error||'Live View request failed (HTTP '+response.status+').');
  return out;
 }
 function gathered(p){
  if(p.iceGatheringState==='complete')return Promise.resolve();
  return new Promise(resolve=>{
   const finish=()=>{clearTimeout(t);p.removeEventListener('icegatheringstatechange',check);resolve();};
   const check=()=>{if(p.iceGatheringState==='complete')finish();};
   const t=setTimeout(finish,7000);p.addEventListener('icegatheringstatechange',check);
  });
 }
 async function connect(){
  const liveId=id,liveKey=key,version=++epoch;
  if(!/^[a-f0-9]{32}$/.test(liveId)||(liveKey!==''&&!/^[a-f0-9]{48}$/.test(liveKey)))throw Error('Open a valid Live View link from Camera Director.');
  if(!window.RTCPeerConnection)throw Error('Live View needs a WebRTC-capable browser.');
  await request('watch',{id:liveId,key:liveKey});
  if(!alive||version!==epoch)return;
  const viewer=Array.from(crypto.getRandomValues(new Uint8Array(16)),x=>x.toString(16).padStart(2,'0')).join('');
  const connection=new RTCPeerConnection(ICE);peer=connection;
  connection.addTransceiver('video',{direction:'recvonly'});
  connection.addTransceiver('audio',{direction:'recvonly'});
  connection.ontrack=e=>{
   if(!alive||version!==epoch)return;
   if(e.streams?.[0]){view.srcObject=e.streams[0];}
   else{
    if(!fallbackStream)fallbackStream=new MediaStream();
    if(!fallbackStream.getTracks().some(track=>track.id===e.track.id))fallbackStream.addTrack(e.track);
    view.srcObject=fallbackStream;
   }
   void view.play().catch(()=>{});state('● LIVE · Camera Director Program');
  };
  connection.onconnectionstatechange=()=>{
   if(!alive||version!==epoch)return;
   if(connection.connectionState==='failed'){
    if(show){stopFeed();id='';state('Program interrupted. Reconnecting…');}
    else state('Feed interrupted. Reload the Live View link to reconnect.',true);
   }
   if(connection.connectionState==='disconnected')state(show?'Program interrupted. Waiting for connection…':'Feed interrupted. Reload the Live View link to reconnect.',true);
   if(connection.connectionState==='connected')state('● LIVE · Camera Director Program');
  };
  await connection.setLocalDescription(await connection.createOffer());
  await gathered(connection);
  if(!alive||version!==epoch)return;
  await request('offer',{id:liveId,key:liveKey,viewer,sdp:connection.localDescription.sdp},'POST');
  let answered=false;
  for(let i=0;i<22&&alive&&version===epoch;i++){
   const result=await request('poll',{id:liveId,key:liveKey,viewer});
   if(!alive||version!==epoch)return;
   if(result.answer){await connection.setRemoteDescription({type:'answer',sdp:result.answer});answered=true;break;}
   await new Promise(resolve=>setTimeout(resolve,1200));
  }
  if(!alive||version!==epoch)return;
  if(!answered)throw Error('The Director has not answered. Keep Program open, then reload.');
  // Poll only the authenticated publication status, so revocation blanks stale video immediately.
  while(alive&&version===epoch){await new Promise(resolve=>setTimeout(resolve,3500));
   if(!alive||version!==epoch)return;await request('watch',{id:liveId,key:liveKey});}
 }
 function stopFeed(){epoch++;try{peer?.close();}catch(_){}peer=null;if(view)view.srcObject=null;fallbackStream=null;}
 function stop(){alive=false;clearInterval(showTimer);stopFeed();}
 function launch(){
  const current=epoch+1;
  void connect().catch(e=>{
   if(!alive||current!==epoch)return;
   stopFeed();if(show)id='';
   state(show?'OFF AIR · Waiting for a public Program broadcast…':'Live View unavailable: '+e.message,!show);
  });
 }
 async function refreshShow(){
  if(!alive)return;
  const query=++showQuery;
  try{
   const result=await request('resolve_show',{show});
   if(!alive||query!==showQuery)return;
   const next=result.live&&/^[a-f0-9]{32}$/.test(result.broadcastId||'')?result.broadcastId:'';
   if(next===id)return;
   stopFeed();id=next;key='';
   if(next){state('Connecting to the live Program…');launch();}
   else state('OFF AIR · Waiting for a public Program broadcast…');
  }catch(e){
   if(!alive||query!==showQuery)return;
   stopFeed();id='';state('This show is unavailable: '+e.message,true);
  }
 }
 sound?.addEventListener('click',()=>{
  if(!view)return;
  view.muted=!view.muted;
  sound.textContent=view.muted?'🔇 Enable sound':'🔊 Mute sound';
  sound.setAttribute('aria-pressed',String(!view.muted));
  if(!view.muted)void view.play().catch(()=>{
   view.muted=true;sound.textContent='🔇 Enable sound';sound.setAttribute('aria-pressed','false');
   state('Tap Enable sound again if this browser blocks audio autoplay.',true);
  });
 });
 async function listPublic(){
  const root=document.getElementById('director-public-list');
  if(!root)return;
  const result=await request('public_list');
  root.replaceChildren();
  const items=Array.isArray(result.broadcasts)?result.broadcasts:[];
  for(const [index,item] of items.entries()){
   if(!/^[a-f0-9]{32}$/.test(item.id||''))continue;
   const link=document.createElement('a');
   const url=new URL('live-view.php',document.baseURI);url.searchParams.set('id',item.id);
   link.href=url.href;link.className='director-public-item';
   const label=document.createElement('strong');label.textContent='● LIVE · Broadcast '+(index+1);
   const hint=document.createElement('span');hint.textContent='Watch host-selected Program →';
   link.append(label,hint);root.appendChild(link);
  }
  state(root.children.length?'Live public broadcasts · Only the host selects the Program.':'No public broadcasts are live right now.');
 }
 window.addEventListener('pagehide',stop);
 if(!id&&!show){
  const refresh=()=>void listPublic().catch(e=>state('Unable to load public broadcasts: '+e.message,true));
  document.getElementById('director-public-refresh')?.addEventListener('click',refresh);
  refresh();
  const refreshTimer=setInterval(refresh,12000);
  window.addEventListener('pagehide',()=>clearInterval(refreshTimer));
 }else if(show){
  if(/^[a-f0-9]{32}$/.test(show)){
   void refreshShow();showTimer=setInterval(()=>void refreshShow(),4500);
  }else state('Invalid show URL.',true);
 }else{
  launch();
 }
 window.addEventListener('pagehide',stop);
})();
