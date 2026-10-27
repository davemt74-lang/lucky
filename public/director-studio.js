/* HF8C4: standalone, admin-owned Director bound to existing recording sessions. */
(() => {
 'use strict';
 const $=id=>document.getElementById(id);
 if(!$('director-studio-session'))return;
 const idOk=id=>/^[a-f0-9]{32}$/.test(id||'');
 const grid=$('camera-grid'),selector=$('director-studio-session'),devices=$('director-studio-camera');
 const connected=new Map();let sessions=[],active='',epoch=0,refreshTimer=null,busy=false,opening=false;
 const rememberedMemory=new Map();
 const rememberedKey=id=>'lucky-director-cameras:'+id;
 const remembered=()=>{let rows=rememberedMemory.get(active)||[];try{rows=JSON.parse(sessionStorage.getItem(rememberedKey(active))||JSON.stringify(rows));}catch(_){/* Opaque origins and private windows can block storage. */}return Array.isArray(rows)?rows.filter(x=>x&&typeof x.deviceId==='string'&&typeof x.name==='string').slice(0,4):[];};
 function keepRemembered(rows){if(!active)return;rememberedMemory.set(active,rows);try{sessionStorage.setItem(rememberedKey(active),JSON.stringify(rows));}catch(_){/* Still recoverable in this tab. */}}
 function saveRemembered(){if(!active)return;keepRemembered([...connected.values()].filter(s=>s.enumeratedId).map(s=>({deviceId:s.enumeratedId,name:s.label})));}
 const reconnect=document.createElement('button');reconnect.type='button';reconnect.className='btn btn-outline';reconnect.id='director-studio-reconnect';reconnect.hidden=true;reconnect.textContent='Reconnect previous cameras';
 $('director-studio-add-camera').after(reconnect);
 function reconnectHint(){reconnect.hidden=!active||!remembered().some(item=>![...connected.values()].some(s=>s.enumeratedId===item.deviceId));}
 let csrf='',take=null,takeBusy=false,startNonce='',startTitle='',reportedTake='',takeStartedAt=0;
 const status=(message,error=false)=>{const node=$('director-studio-status');node.textContent=message;node.classList.toggle('is-error',error);};
 const cameraStatus=(message,error=false)=>{const node=$('director-studio-device-status');node.textContent=message;node.classList.toggle('is-error',error);};
 const random=()=>crypto.randomUUID?.().replaceAll('-','')||
  Array.from(crypto.getRandomValues(new Uint8Array(16)),b=>b.toString(16).padStart(2,'0')).join('');
 function cameraIndicators(){
  for(const source of connected.values()){
   const healthy=source.stream.getVideoTracks().some(track=>track.readyState==='live');
   source.card.dataset.status=healthy?(take?'recording':'connected'):'offline';
   source.indicator.textContent=healthy?(take?'● REC':'● LIVE'):'○ OFFLINE';
  }
 }
 function takeButtons(){
  $('director-studio-take-start').disabled=!active||!!take||takeBusy;
  $('director-studio-take-stop').disabled=!active||!take||takeBusy;
 }
 const takeStatus=(message,error=false)=>{const node=$('director-studio-take-status');node.textContent=message;node.classList.toggle('is-error',error);};
 const parseStartedAt=value=>{
  if(!value)return 0;
  const normalized=String(value).trim().replace(' ','T');
  const timestamp=Date.parse(/[zZ]|[+-]\d\d:\d\d$/.test(normalized)?normalized:normalized+'Z');
  return Number.isFinite(timestamp)?timestamp:0;
 };
 function renderTakeClock(){
  const clock=$('director-studio-record-clock');if(!clock)return;
  clock.hidden=!take;
  if(!take)return;
  const elapsed=Math.max(0,Math.floor((Date.now()-(takeStartedAt||Date.now()))/1000));
  clock.textContent=`● REC ${String(Math.floor(elapsed/3600)).padStart(2,'0')}:${String(Math.floor(elapsed%3600/60)).padStart(2,'0')}:${String(elapsed%60).padStart(2,'0')}`;
 }
 async function request(action,query={},method='GET'){
  const opts={method,credentials:'same-origin',cache:'no-store'};
  const params=new URLSearchParams({action});
  if(method==='GET')for(const [key,value] of Object.entries(query))params.set(key,String(value));
  else{
   if(!csrf)csrf=(await Lucky.getMe()).csrf;
   opts.headers={'Content-Type':'application/json','X-CSRF-Token':csrf};opts.body=JSON.stringify(query);
  }
  const response=await fetch('api/recording-sessions.php?'+params,opts);
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
   const exists=id=>sessions.some(s=>s.id===id&&s.status!=='completed'&&s.status!=='archived');
   selector.value=exists(active)?active:exists(before)?before:exists(wanted)?wanted:'';
   if(active&&!exists(active)){closeSession();status('This recording session is no longer available for capture. Choose another session.',true);return;}
   if(!active&&!opening&&idOk(wanted)&&selector.value===wanted)void openSession();
   if(!sessions.length)status('No recording sessions yet. Create one under Admin → Recording Sessions.');
   else if(!active&&!opening&&!selector.value)status('Choose a recording session and click Open recording session.');
  }catch(e){status(e.message,true);}
 }
 function closeSession(){
  if(refreshTimer){clearInterval(refreshTimer);refreshTimer=null;}
  active='';epoch++;window.dispatchEvent(new Event('lucky:director-studio-close'));
  stopCameras();take=null;reportedTake='';takeBusy=false;startNonce='';startTitle='';takeButtons();
  takeStartedAt=0;renderTakeClock();
  reconnectHint();
 }
 async function syncSession(version){
  if(version!==epoch||!active)return;
  try{
   const data=await request('detail',{id:active});if(version!==epoch)return;
   const s=data.session||{};
   if(!idOk(s.id)){closeSession();status('The selected recording session is unavailable.',true);return;}
   if(s.status==='completed'||s.status==='archived'){
    closeSession();status('The recording was completed. Reopen an active recording to direct and broadcast.',true);return;
   }
   take=(data.takes||[]).find(t=>t.status==='recording')||null;
   if(take){takeStartedAt=parseStartedAt(take.started_at||take.startedAt)||takeStartedAt||Date.now();}
   else takeStartedAt=0;
   renderTakeClock();
   cameraIndicators();
   if(take){startNonce='';startTitle='';}
    if(take?.id!==reportedTake){
     if(reportedTake)window.dispatchEvent(new CustomEvent('lucky:director-take-stop',{detail:{recordingSession:active,takeId:reportedTake}}));
     if(take?.id)window.dispatchEvent(new CustomEvent('lucky:director-take-start',{detail:{recordingSession:active,takeId:take.id,clockOffsetMs:0}}));
     reportedTake=take?.id||'';
    }takeButtons();
   const devices=await request('camera_devices',{id:active}).catch(()=>({devices:[]}));
   if(version!==epoch)return;
   const armed=(devices.devices||[]).filter(d=>d.armed&&d.online);
   takeStatus(take?'● RECORDING · Take '+(take.take_index||take.index||'')+' · '+armed.length+' armed phone'+(armed.length===1?'':'s')+'. Stop the group take to upload and review originals.':
    'Ready to start a synchronized take · '+armed.length+' armed phone'+(armed.length===1?'':'s')+'. Open Camera Studio on each approved phone and tap Arm for host.');
   $('director-studio-media-status').textContent=(data.assets||[]).length+' recording assets · '+(data.takes||[]).length+' grouped takes · '+(data.sources||[]).length+' registered sources. Director clips refresh automatically.';
   void window.LuckyCameraDirector?.refreshClips?.();
  }catch(e){if(version===epoch)status('Recording session sync: '+e.message,true);}
 }
 async function openSession(){
  if(opening)return;
  const id=selector.value;
  if(!idOk(id)){status('Choose an existing recording session.',true);return;}
  const row=sessions.find(s=>s.id===id);if(!row){status('Refresh the recording list and choose a valid session.',true);return;}
  if(row.status==='completed'||row.status==='archived'){status('Completed or archived recordings are read-only. Choose a draft or active session.',true);return;}
  if(active===id){await syncSession(epoch);return;}
  opening=true;
  closeSession();active=id;const version=epoch;
  window.dispatchEvent(new CustomEvent('lucky:director-studio-open',{detail:{recordingSession:id}}));
  try{const url=new URL(location.href);url.searchParams.set('recording',id);history.replaceState(null,'',url);}catch(_){/* embedded test/doc context */}
  status('● Director connected to '+row.title+' · '+row.status+'. Preview is private until you explicitly publish Program.');
  reconnectHint();
  try{
   await syncSession(version);
   if(version===epoch&&active)refreshTimer=setInterval(()=>void syncSession(version),5000);
  }finally{opening=false;}
 }
 async function startTake(){
  if(!active||take||takeBusy)return;
  const id=active,version=epoch,title=$('director-studio-take-title').value.trim()||'Camera Director Take';
  if(title.length>120){takeStatus('Take name is limited to 120 characters.',true);return;}
  if(!startNonce){startNonce=random();startTitle=title;}takeBusy=true;takeButtons();
  try{
   const before=Date.now();
    const result=await request('take_start',{id,title:startTitle,nonce:startNonce},'POST');
    const after=Date.now();
   if(version!==epoch||active!==id)return;
   startNonce='';startTitle='';take=result.take;reportedTake=take.id;takeStartedAt=Date.now();renderTakeClock();
   cameraIndicators();
    window.dispatchEvent(new CustomEvent('lucky:director-take-start',{detail:{recordingSession:id,takeId:take.id,clockOffsetMs:Number(result.serverTimeMs||after)-Math.round((before+after)/2)}}));
    takeStatus('● TAKE STARTED · Desktop cameras and Program are now recording; phones observe host commands through polling.');
   await syncSession(version);
  }catch(e){if(version===epoch)takeStatus('Start take: '+e.message+'. Retrying will use the same request token.',true);}
  finally{if(version===epoch){takeBusy=false;takeButtons();}}
 }
 async function stopTake(){
  if(!active||!take||takeBusy)return;
  const id=active,version=epoch,takeId=take.id;takeBusy=true;takeButtons();
  try{
   await request('take_stop',{id,takeId},'POST');
   if(version!==epoch||active!==id)return;
   window.dispatchEvent(new CustomEvent('lucky:director-take-stop',{detail:{recordingSession:id,takeId}}));
    reportedTake='';take=null;takeStartedAt=0;renderTakeClock();cameraIndicators();takeStatus('■ TAKE STOPPED · Desktop and Program originals are queuing for upload; armed phones will finish theirs.');
   await syncSession(version);
  }catch(e){if(version===epoch)takeStatus('Stop take: '+e.message+'. You can retry safely.',true);}
  finally{if(version===epoch){takeBusy=false;takeButtons();}}
 }
 async function scanDevices(){
  if(!navigator.mediaDevices?.enumerateDevices)return;
  try{
   const list=(await navigator.mediaDevices.enumerateDevices()).filter(d=>d.kind==='videoinput');
   const before=devices.value;devices.replaceChildren(new Option(list.length?'Default camera':'No camera found',''));
   list.forEach((d,i)=>devices.add(new Option(d.label||'Camera '+(i+1),d.deviceId)));
   if(list.some(d=>d.deviceId===before))devices.value=before;
   // Device unplug events do not consistently end tracks in every browser.
   // Remove only cameras linked to a known enumerated device, never anonymous
   // browser-generated track IDs.
   const known=new Set(list.map(d=>d.deviceId));
   for(const [key,source] of connected){
    if(source.enumeratedId&&!known.has(source.enumeratedId))removeCamera(key,false);
   }
   cameraIndicators();
  }catch(_){/* Camera names require browser permission. */}
 }
 async function addCamera(desiredId='',desiredName=''){
  if(!active){cameraStatus('Open a recording session before adding cameras.',true);return;}
  if(connected.size>=4){cameraStatus('Four desktop sources are supported. Remove one before adding another.',true);return;}
  if(!navigator.mediaDevices?.getUserMedia){cameraStatus('Camera access requires HTTPS or localhost.',true);return;}
  if(busy)return;busy=true;$('director-studio-add-camera').disabled=true;
  const requestedSession=active,requestedEpoch=epoch;
  try{
   const requested=desiredId||devices.value;
   const stream=await navigator.mediaDevices.getUserMedia({video:requested?{deviceId:{exact:requested}}:true,audio:false});
   if(!active||active!==requestedSession||epoch!==requestedEpoch){stream.getTracks().forEach(track=>track.stop());return;}
   // Prefer the enumerated device the host explicitly selected. Browser track
   // settings may report a transient internal id (especially for virtual cameras).
   const track=stream.getVideoTracks()[0],key=requested||track?.getSettings?.().deviceId||track?.id||'';
   if(connected.has(key)){stream.getTracks().forEach(t=>t.stop());cameraStatus('This camera is already connected.');return;}
   // A stable device-scoped id lets saved scenes find this camera after reload.
   const id='cam_'+(key.replace(/[^A-Za-z0-9_-]/g,'').slice(0,58)||track.id.slice(0,58));
   const card=document.createElement('section');card.className='camera-source';card.dataset.cameraId=id;
   const label=document.createElement('strong');label.textContent=desiredName||track?.label||'Desktop camera '+(connected.size+1);
   const state=document.createElement('span');state.className='director-camera-state';state.setAttribute('role','status');state.textContent='● LIVE';
   const rename=document.createElement('input');rename.type='text';rename.className='director-camera-name';rename.maxLength=60;rename.setAttribute('aria-label','Camera name');rename.value=label.textContent;
   rename.addEventListener('change',()=>{const name=rename.value.trim().slice(0,60);if(!name){rename.value=label.textContent;return;}label.textContent=name;const source=connected.get(key);if(source)source.label=name;saveRemembered();window.dispatchEvent(new Event('lucky:cameras-refreshed'));});
   const video=document.createElement('video');video.autoplay=true;video.playsInline=true;video.muted=true;video.srcObject=stream;
   const remove=document.createElement('button');remove.className='btn btn-outline';remove.type='button';remove.textContent='Remove';remove.addEventListener('click',()=>removeCamera(key));
   card.append(label,state,video,rename,remove);grid.append(card);connected.set(key,{stream,card,id,label:label.textContent,indicator:state,enumeratedId:requested||''});
   track?.addEventListener('ended',()=>removeCamera(key,false),{once:true});
   // Some device/driver combinations leave play() pending indefinitely even
   // after getUserMedia succeeds. Preview playback must not hold the reconnect
   // workflow's busy lock or block capture/session controls.
   saveRemembered();void video.play().catch(()=>{});await scanDevices();cameraIndicators();window.dispatchEvent(new Event('lucky:cameras-refreshed'));reconnectHint();
   cameraStatus(connected.size+' desktop camera'+(connected.size===1?'':'s')+' connected. Phone uploads sync through the selected recording session.');
  }catch(e){cameraStatus('Camera not added: '+e.message,true);}
  finally{busy=false;$('director-studio-add-camera').disabled=false;}
 }
 function removeCamera(key,forget=true){
  const source=connected.get(key);if(!source)return;
  connected.delete(key);
  // Notify the recording bridge while tracks are still live so MediaRecorder
  // can finalize the outgoing take before the browser tears down the stream.
  window.dispatchEvent(new Event('lucky:cameras-refreshed'));
  source.stream.getTracks().forEach(track=>track.stop());source.card.remove();
  if(forget)keepRemembered(remembered().filter(item=>item.deviceId!==source.enumeratedId));
  reconnectHint();
  cameraStatus(connected.size+' desktop camera'+(connected.size===1?'':'s')+' connected.');
 }
 function stopCameras(){for(const key of [...connected.keys()])removeCamera(key,false);}
 $('director-studio-open').addEventListener('click',()=>void openSession());
 $('director-studio-refresh').addEventListener('click',()=>void refreshSessions());
 $('director-studio-add-camera').addEventListener('click',()=>void addCamera());
 reconnect.addEventListener('click',async()=>{
  if(!active||busy)return;
  const missing=remembered().filter(item=>![...connected.values()].some(source=>source.enumeratedId===item.deviceId));
  for(const item of missing){if(!active||connected.size>=4)break;await addCamera(item.deviceId,item.name);}
  reconnectHint();
 });
 $('director-studio-stop-cameras').addEventListener('click',stopCameras);
 navigator.mediaDevices?.addEventListener?.('devicechange',()=>void scanDevices());
 $('director-studio-take-start').addEventListener('click',()=>void startTake());
 $('director-studio-take-stop').addEventListener('click',()=>void stopTake());
 window.addEventListener('pagehide',()=>{closeSession();stopCameras();});
 setInterval(renderTakeClock,1000);
 takeButtons();void refreshSessions();void scanDevices();
 window.LuckyDirectorStudio={state:()=>({recordingSession:active,cameras:connected.size,activeTake:take?.id||null}),cameras:()=>[...connected.values()].map(({stream,id,label})=>({stream,id,label})),open:id=>{selector.value=id;return openSession();}};
})();
