/* V5Q32C: viewer-initiated, in-memory Live View invitation for the game/TV screen.
 * Never retrieve or persist a Director private invitation through public game state. */
(() => {
 'use strict';
 if(document.body.dataset.page!=='display'&&!document.getElementById('start-game'))return;
 const $=id=>document.getElementById(id);
 const hex32=/^[a-f0-9]{32}$/,hex48=/^[a-f0-9]{48}$/;
 const ICE={iceServers:[{urls:'stun:stun.l.google.com:19302'}]};
 let current=null,epoch=0,maximized=false;

 function element(tag,id,classes,text){
  const el=document.createElement(tag);
  if(id)el.id=id;if(classes)el.className=classes;if(text)el.textContent=text;
  return el;
 }
 const launch=element('button','lucky-program-connect','btn btn-outline lucky-program-launch','📡 Connect Program');
 launch.type='button';document.body.append(launch);
 const dialog=element('dialog','lucky-program-dialog','lucky-program-dialog');
 const form=element('form',null,'lucky-program-form');form.method='dialog';
 const heading=element('h2',null,'','Connect Live Program');
 const explanation=element('p',null,'',
  'In Camera Director, publish Program with permission and copy its Live View link. Paste that invitation here to watch on this screen.');
 const label=element('label',null,'','Authorized Live View link');
 const field=element('input','lucky-program-invitation');field.type='text';field.autocomplete='off';field.spellcheck=false;
 field.placeholder='Paste live-view.php?id=…&key=…';field.setAttribute('aria-label','Authorized Live View invitation');
 label.append(field);
 const formStatus=element('p','lucky-program-form-status','mini-label');formStatus.setAttribute('role','status');
 const actions=element('div',null,'lucky-program-actions');
 const join=element('button','lucky-program-join','btn btn-primary','Connect feed');join.type='submit';join.value='join';
 const cancel=element('button','lucky-program-cancel','btn btn-outline','Cancel');cancel.type='button';
 cancel.addEventListener('click',()=>dialog.close());actions.append(join,cancel);
 form.append(heading,explanation,label,formStatus,actions);dialog.append(form);document.body.append(dialog);

 const surface=element('section','lucky-program-surface','lucky-program-surface');surface.hidden=true;
 surface.setAttribute('aria-label','Authorized Live Program video');
 const toolbar=element('div',null,'lucky-program-toolbar');
 const title=element('strong',null,'','● PROGRAM');
 const full=element('button','lucky-program-size','btn btn-outline','Expand');full.type='button';
 const stopButton=element('button','lucky-program-stop','btn btn-outline','Disconnect');stopButton.type='button';
 toolbar.append(title,full,stopButton);
 const video=element('video','lucky-program-video');video.autoplay=true;video.muted=true;
 video.playsInline=true;video.setAttribute('aria-label','Camera Director Program video');
 const footer=element('div',null,'lucky-program-footer');
 const info=element('p','lucky-program-status','mini-label','Waiting for Program…');info.setAttribute('role','status');
 const sound=element('button','lucky-program-sound','btn btn-outline','🔇 Enable sound');sound.type='button';
 sound.setAttribute('aria-pressed','false');footer.append(info,sound);
 surface.append(toolbar,video,footer);document.body.append(surface);

 const status=(message,error=false)=>{info.textContent=message;info.classList.toggle('is-error',error);};
 function urlParts(raw){
  let url;
  try{url=new URL(raw.trim(),document.baseURI);}catch(_){throw Error('Paste the Live View invitation from Camera Director.');}
  const expected=new URL('live-view.php',document.baseURI);
  if(url.origin!==expected.origin||url.pathname!==expected.pathname||url.username||url.password||url.hash)
   throw Error('The invitation must be a Live View link from this Lucky site.');
  const id=url.searchParams.get('id')||'',key=url.searchParams.get('key')||'';
  if(!hex32.test(id)||(key!==''&&!hex48.test(key)))throw Error('The Live View invitation is invalid.');
  // The server validates private invitations. A public broadcast needs no key.
  return {id,key};
 }
 async function request(action,values={},method='GET'){
  const query=new URLSearchParams({action});
  if(method==='GET')for(const [name,value] of Object.entries(values))query.set(name,value);
  const response=await fetch('api/director-live.php?'+query.toString(),{
   method,cache:'no-store',credentials:'same-origin',
   ...(method==='POST'?{headers:{'Content-Type':'application/json'},body:JSON.stringify(values)}:{})
  });
  const data=await response.json().catch(()=>({}));
  if(!response.ok||!data.ok)throw Error(data.error||'Live Program connection failed.');
  return data;
 }
 function waitForIce(peer){
  if(peer.iceGatheringState==='complete')return Promise.resolve();
  return new Promise(resolve=>{
   const done=()=>{clearTimeout(timer);peer.removeEventListener('icegatheringstatechange',check);resolve();};
   const check=()=>{if(peer.iceGatheringState==='complete')done();};
   const timer=setTimeout(done,7000);peer.addEventListener('icegatheringstatechange',check);
  });
 }
 function closeFeed(message='Disconnected from Program.'){
  ++epoch;const old=current;current=null;
  if(old){old.running=false;try{old.peer?.close();}catch(_){}}
  video.pause();video.srcObject=null;video.muted=true;document.body.classList.remove('lucky-program-on');
  surface.hidden=true;surface.classList.remove('is-maximized');maximized=false;full.textContent='Expand';
  sound.textContent='🔇 Enable sound';sound.setAttribute('aria-pressed','false');
  launch.textContent='📡 Connect Program';launch.disabled=false;
  status(message);
 }
 function stateForConnection(connection){return current===connection&&connection.running;}
 async function connect(parts){
  closeFeed();
  const connection={...parts,peer:null,running:true,version:++epoch};current=connection;
  surface.hidden=false;document.body.classList.add('lucky-program-on');
  launch.textContent='● Program connecting…';launch.disabled=true;
  status('Waiting for Camera Director Program…');
  try{
   if(!window.RTCPeerConnection)throw Error('This browser cannot receive WebRTC Program video.');
   await request('watch',{id:connection.id,key:connection.key},'POST');
   if(!stateForConnection(connection))return;
   const viewer=Array.from(crypto.getRandomValues(new Uint8Array(16)),x=>x.toString(16).padStart(2,'0')).join('');
   const peer=new RTCPeerConnection(ICE);connection.peer=peer;
   peer.addTransceiver('video',{direction:'recvonly'});
   peer.addTransceiver('audio',{direction:'recvonly'});
   let fallback=null;
   peer.ontrack=event=>{
    if(!stateForConnection(connection))return;
    if(event.streams?.[0])video.srcObject=event.streams[0];
    else{
     if(!fallback)fallback=new MediaStream();
     if(!fallback.getTracks().some(track=>track.id===event.track.id))fallback.addTrack(event.track);
     video.srcObject=fallback;
    }
    void video.play().catch(()=>{});status('● LIVE · Director controls the Program camera');
   };
   peer.onconnectionstatechange=()=>{
    if(!stateForConnection(connection))return;
    if(['failed','disconnected'].includes(peer.connectionState))closeFeed('Program connection interrupted. Reconnect with a new invitation.');
    else if(peer.connectionState==='connected')status('● LIVE · Director controls the Program camera');
   };
   await peer.setLocalDescription(await peer.createOffer());
   await waitForIce(peer);
   if(!stateForConnection(connection))return;
   await request('offer',{id:connection.id,key:connection.key,viewer,sdp:peer.localDescription.sdp},'POST');
   let answered=false;
   for(let i=0;i<22&&stateForConnection(connection);i++){
    const result=await request('poll',{id:connection.id,key:connection.key,viewer},'POST');
    if(result.answer){await peer.setRemoteDescription({type:'answer',sdp:result.answer});answered=true;break;}
    await new Promise(resolve=>setTimeout(resolve,1200));
   }
   if(!stateForConnection(connection))return;
   if(!answered)throw Error('Director did not answer. Keep Program published and try again.');
   launch.textContent='● Program connected';launch.disabled=false;
   // Keep the admitted viewer active; watch only authorizes initial admission.
   while(stateForConnection(connection)){
    await new Promise(resolve=>setTimeout(resolve,3500));
    if(!stateForConnection(connection))break;
    await request('poll',{id:connection.id,key:connection.key,viewer},'POST');
   }
  }catch(error){if(stateForConnection(connection))closeFeed('Live Program unavailable: '+error.message);}
 }
 launch.addEventListener('click',()=>{formStatus.textContent='';field.value='';dialog.showModal();field.focus();});
 form.addEventListener('submit',event=>{
  event.preventDefault();
  try{
   const parts=urlParts(field.value);field.value='';dialog.close();void connect(parts);
  }catch(error){formStatus.textContent=error.message;field.value='';}
 });
 stopButton.addEventListener('click',()=>closeFeed());
 full.addEventListener('click',()=>{maximized=!maximized;surface.classList.toggle('is-maximized',maximized);
  full.textContent=maximized?'Shrink':'Expand';});
 sound.addEventListener('click',()=>{
  video.muted=!video.muted;
  sound.textContent=video.muted?'🔇 Enable sound':'🔊 Mute sound';
  sound.setAttribute('aria-pressed',String(!video.muted));
  if(!video.muted)void video.play().catch(()=>{
   video.muted=true;sound.textContent='🔇 Enable sound';sound.setAttribute('aria-pressed','false');
   status('This browser blocked automatic sound. Tap Enable sound again.');
  });
 });
 window.addEventListener('pagehide',()=>closeFeed());
 // A deliberately narrow test/status surface; invitation keys are never exported.
 window.LuckyProgramDisplay=Object.freeze({connected:()=>Boolean(current),disconnect:closeFeed});
})();
