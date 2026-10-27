/* HF8C4: standalone, admin-owned Director bound to existing recording sessions. */
(() => {
 'use strict';
 const $=id=>document.getElementById(id);
 if(!$('director-studio-session'))return;
 const idOk=id=>/^[a-f0-9]{32}$/.test(id||'');
 const grid=$('camera-grid'),selector=$('director-studio-session'),devices=$('director-studio-camera');
 const connected=new Map();let sessions=[],active='',epoch=0,refreshTimer=null,busy=false;
 const status=(message,error=false)=>{const node=$('director-studio-status');node.textContent=message;node.classList.toggle('is-error',error);};
 const cameraStatus=(message,error=false)=>{const node=$('director-studio-device-status');node.textContent=message;node.classList.toggle('is-error',error);};
 async function request(action,query={}){
  const response=await fetch('api/recording-sessions.php?'+new URLSearchParams({action,...query}),{credentials:'same-origin',cache:'no-store'});
  const data=await response.json().catch(()=>({}));if(!response.ok||!data.ok)throw Error(data.error||'Recording sessions are unavailable.');
  return data;
 }
 async function refreshSessions(){
  if(busy)return;
  const before=selector.value;
  try{
   const data=await request('list');sessions=(data.sessions||[]).filter(row=>idOk(row.id));
   selector.replaceChildren(new Option(sessions.length?'Choose a recording session':'No recording sessions yet',''));
   for(const session of sessions)selector.add(new Option(session.title+' · '+session.type+' · '+session.status,session.id));
   const wanted=new URL(location.href).searchParams.get('recording');
   selector.value=sessions.some(s=>s.id===before)?before:sessions.some(s=>s.id===active)?active:sessions.some(s=>s.id===wanted)?wanted:'';
   if(!active&&idOk(wanted)&&selector.value===wanted)void openSession();
   if(!sessions.length)status('No recording sessions yet. Create one under Admin → Recording Sessions.');
   else if(!active)status('Choose a recording session and click Open recording session.');
  }catch(e){status(e.message,true);}
 }
 function closeSession(){
  if(refreshTimer){clearInterval(refreshTimer);refreshTimer=null;}
  active='';epoch++;window.dispatchEvent(new Event('lucky:director-studio-close'));
  stopCameras();
 }
 async function syncSession(version){
  if(version!==epoch||!active)return;
  try{
   const data=await request('detail',{id:active});if(version!==epoch)return;
   const s=data.session||{};
   if(!idOk(s.id)){closeSession();status('The selected recording session is unavailable.',true);return;}
   if(s.status==='completed'){
    closeSession();status('The recording was completed. Reopen an active recording to direct and broadcast.',true);return;
   }
   $('director-studio-media-status').textContent=(data.assets||[]).length+' recording assets · '+(data.takes||[]).length+' grouped takes · '+(data.sources||[]).length+' registered sources. Director clips refresh automatically.';
   void window.LuckyCameraDirector?.refreshClips?.();
  }catch(e){if(version===epoch)status('Recording session sync: '+e.message,true);}
 }
 async function openSession(){
  const id=selector.value;
  if(!idOk(id)){status('Choose an existing recording session.',true);return;}
  const row=sessions.find(s=>s.id===id);if(!row){status('Refresh the recording list and choose a valid session.',true);return;}
  if(row.status==='completed'){status('Completed recordings are read-only. Choose a draft or active session.',true);return;}
  closeSession();active=id;const version=epoch;
  window.dispatchEvent(new CustomEvent('lucky:director-studio-open',{detail:{recordingSession:id}}));
  try{const url=new URL(location.href);url.searchParams.set('recording',id);history.replaceState(null,'',url);}catch(_){/* embedded test/doc context */}
  status('● Director connected to '+row.title+' · '+row.status+'. Preview is private until you explicitly publish Program.');
  await syncSession(version);
  if(version===epoch)refreshTimer=setInterval(()=>void syncSession(version),5000);
 }
 async function scanDevices(){
  if(!navigator.mediaDevices?.enumerateDevices)return;
  try{
   const list=(await navigator.mediaDevices.enumerateDevices()).filter(d=>d.kind==='videoinput');
   const before=devices.value;devices.replaceChildren(new Option(list.length?'Default camera':'No camera found',''));
   list.forEach((d,i)=>devices.add(new Option(d.label||'Camera '+(i+1),d.deviceId)));
   if(list.some(d=>d.deviceId===before))devices.value=before;
  }catch(_){/* Camera names require browser permission. */}
 }
 async function addCamera(){
  if(!active){cameraStatus('Open a recording session before adding cameras.',true);return;}
  if(connected.size>=4){cameraStatus('Four desktop sources are supported. Remove one before adding another.',true);return;}
  if(!navigator.mediaDevices?.getUserMedia){cameraStatus('Camera access requires HTTPS or localhost.',true);return;}
  if(busy)return;busy=true;$('director-studio-add-camera').disabled=true;
  const requestedSession=active,requestedEpoch=epoch;
  try{
   const requested=devices.value;
   const stream=await navigator.mediaDevices.getUserMedia({video:requested?{deviceId:{exact:requested}}:true,audio:false});
   if(!active||active!==requestedSession||epoch!==requestedEpoch){stream.getTracks().forEach(track=>track.stop());return;}
   const track=stream.getVideoTracks()[0],key=track?.getSettings?.().deviceId||requested||track?.id||'';
   if(connected.has(key)){stream.getTracks().forEach(t=>t.stop());cameraStatus('This camera is already connected.');return;}
   // A stable device-scoped id lets saved scenes find this camera after reload.
   const id='cam_'+(key.replace(/[^A-Za-z0-9_-]/g,'').slice(0,58)||track.id.slice(0,58));
   const card=document.createElement('section');card.className='camera-source';card.dataset.cameraId=id;
   const label=document.createElement('strong');label.textContent=track?.label||'Desktop camera '+(connected.size+1);
   const video=document.createElement('video');video.autoplay=true;video.playsInline=true;video.muted=true;video.srcObject=stream;
   const remove=document.createElement('button');remove.className='btn btn-outline';remove.type='button';remove.textContent='Remove';remove.addEventListener('click',()=>removeCamera(key));
   card.append(label,video,remove);grid.append(card);connected.set(key,{stream,card});
   track?.addEventListener('ended',()=>removeCamera(key),{once:true});
   await video.play().catch(()=>{});await scanDevices();window.dispatchEvent(new Event('lucky:cameras-refreshed'));
   cameraStatus(connected.size+' desktop camera'+(connected.size===1?'':'s')+' connected. Phone uploads sync through the selected recording session.');
  }catch(e){cameraStatus('Camera not added: '+e.message,true);}
  finally{busy=false;$('director-studio-add-camera').disabled=false;}
 }
 function removeCamera(key){
  const source=connected.get(key);if(!source)return;
  connected.delete(key);source.stream.getTracks().forEach(track=>track.stop());source.card.remove();
  window.dispatchEvent(new Event('lucky:cameras-refreshed'));
  cameraStatus(connected.size+' desktop camera'+(connected.size===1?'':'s')+' connected.');
 }
 function stopCameras(){for(const key of [...connected.keys()])removeCamera(key);}
 $('director-studio-open').addEventListener('click',()=>void openSession());
 $('director-studio-refresh').addEventListener('click',()=>void refreshSessions());
 $('director-studio-add-camera').addEventListener('click',()=>void addCamera());
 $('director-studio-stop-cameras').addEventListener('click',stopCameras);
 window.addEventListener('pagehide',()=>{closeSession();stopCameras();});
 void refreshSessions();void scanDevices();
 window.LuckyDirectorStudio={state:()=>({recordingSession:active,cameras:connected.size}),open:id=>{selector.value=id;return openSession();}};
})();
