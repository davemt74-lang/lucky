/* HF8C0: private bearer invite or public directory; view only the mixed Program track. */
(() => {
 'use strict';
 const id=document.body.dataset.broadcastId||'',key=document.body.dataset.broadcastKey||'';
 const view=document.getElementById('director-view-video'),info=document.getElementById('director-view-status');
 let peer=null,alive=true;
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
  if(!/^[a-f0-9]{32}$/.test(id)||(key!==''&&!/^[a-f0-9]{48}$/.test(key)))throw Error('Open a valid Live View link from Camera Director.');
  if(!window.RTCPeerConnection)throw Error('Live View needs a WebRTC-capable browser.');
  await request('watch',{id,key});
  const viewer=Array.from(crypto.getRandomValues(new Uint8Array(16)),x=>x.toString(16).padStart(2,'0')).join('');
  peer=new RTCPeerConnection(ICE);
  peer.addTransceiver('video',{direction:'recvonly'});
  peer.ontrack=e=>{view.srcObject=e.streams[0]||new MediaStream([e.track]);void view.play().catch(()=>{});state('● LIVE · Camera Director Program');};
  peer.onconnectionstatechange=()=>{
   if(peer.connectionState==='failed'||peer.connectionState==='disconnected')state('Feed interrupted. Reload the Live View link to reconnect.',true);
   if(peer.connectionState==='connected')state('● LIVE · Camera Director Program');
  };
  await peer.setLocalDescription(await peer.createOffer());
  await gathered(peer);
  await request('offer',{id,key,viewer,sdp:peer.localDescription.sdp},'POST');
  let answered=false;
  for(let i=0;i<22&&alive;i++){
   const result=await request('poll',{id,key,viewer});
   if(result.answer){await peer.setRemoteDescription({type:'answer',sdp:result.answer});answered=true;break;}
   await new Promise(resolve=>setTimeout(resolve,1200));
  }
  if(!answered&&alive)throw Error('The Director has not answered. Keep the desktop proof and Program open, then reload.');
  // Poll only the authenticated publication status, so revocation blanks stale video immediately.
  while(alive){await new Promise(resolve=>setTimeout(resolve,3500));if(!alive)return;await request('watch',{id,key});}
 }
 function stop(){alive=false;try{peer?.close();}catch(_){}view.srcObject=null;}
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
 if(!id){
  const refresh=()=>void listPublic().catch(e=>state('Unable to load public broadcasts: '+e.message,true));
  document.getElementById('director-public-refresh')?.addEventListener('click',refresh);
  refresh();
  const refreshTimer=setInterval(refresh,12000);
  window.addEventListener('pagehide',()=>clearInterval(refreshTimer));
 }else{
  void connect().catch(e=>{stop();state('Live View unavailable: '+e.message,true);});
 }
})();
