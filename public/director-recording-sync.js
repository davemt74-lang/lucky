/* HF8C7: Director originals follow the host group take; pending blobs survive reload. */
(() => {
 'use strict';
 const studio=document.getElementById('director-studio-take-start');
 if(!studio)return;
 const MAX_BYTES=75*1024*1024,MAX_MS=150000;
 const pendingMemory=new Map(),cameras=new Map();
 let current=null,flushing=false,programTimer=null,ownedProgramTake='',lastError='';
 const status=document.createElement('p');
 status.id='director-sync-status';status.className='mini-label director-sync-status';
 status.setAttribute('role','status');status.textContent='Director originals are saved privately to the selected recording session.';
 document.getElementById('director-studio-take-status')?.after(status);
 const message=s=>{status.textContent=s;};
 const recovery=document.createElement('section');recovery.id='director-sync-recovery';recovery.className='director-sync-recovery';
 recovery.setAttribute('aria-label','Recording recovery');
 const health=document.createElement('p');health.id='director-sync-health';health.setAttribute('role','status');
 const retry=document.createElement('button');retry.type='button';retry.id='director-sync-retry';retry.className='btn btn-outline';retry.textContent='Retry pending uploads';
 recovery.append(health,retry);status.after(recovery);
 async function refreshHealth(){
  const pending=(await list()).length,connected=window.LuckyDirectorStudio?.cameras?.().length||0;
  const recording=cameras.size,network=navigator.onLine===false?'Offline':'Online';
  health.textContent=`${network} · ${connected} camera${connected===1?'':'s'} connected · ${recording} recording · ${pending} upload${pending===1?'':'s'} pending`+(lastError?' · '+lastError:'');
  retry.disabled=flushing||pending===0||navigator.onLine===false;
 }
 const random=()=>crypto.randomUUID?.().replaceAll('-','')||Array.from(crypto.getRandomValues(new Uint8Array(16)),b=>b.toString(16).padStart(2,'0')).join('');
 const dbPromise=typeof indexedDB==='undefined'?Promise.resolve(null):new Promise(resolve=>{
  try{
   const req=indexedDB.open('lucky-director-originals',1);
   req.onupgradeneeded=()=>{if(!req.result.objectStoreNames.contains('pending'))req.result.createObjectStore('pending',{keyPath:'nonce'});};
   req.onsuccess=()=>resolve(req.result);req.onerror=()=>resolve(null);req.onblocked=()=>resolve(null);
  }catch(_){resolve(null);}
 });
 async function persist(record){
  const db=await dbPromise;
  if(!db){pendingMemory.set(record.nonce,record);message('Original queued in this tab only. Keep this tab open until upload succeeds.');return;}
  try{await new Promise((resolve,reject)=>{
   const tx=db.transaction('pending','readwrite');tx.objectStore('pending').put(record);
   tx.oncomplete=resolve;tx.onerror=()=>reject(tx.error);tx.onabort=()=>reject(tx.error);
  });}catch(_){pendingMemory.set(record.nonce,record);message('Local storage unavailable; keep this tab open until upload finishes.');}
  void refreshHealth();
 }
 async function list(){
  const db=await dbPromise,rows=[...pendingMemory.values()];
  if(!db)return rows;
  try{return [...rows,...await new Promise((resolve,reject)=>{
   const tx=db.transaction('pending','readonly'),req=tx.objectStore('pending').getAll();
   req.onsuccess=()=>resolve(req.result||[]);req.onerror=()=>reject(req.error);
  })].filter((item,i,all)=>all.findIndex(x=>x.nonce===item.nonce)===i);}
  catch(_){return rows;}
 }
 async function drop(nonce){
  pendingMemory.delete(nonce);const db=await dbPromise;if(!db)return;
  try{await new Promise((resolve,reject)=>{
   const tx=db.transaction('pending','readwrite');tx.objectStore('pending').delete(nonce);
   tx.oncomplete=resolve;tx.onerror=()=>reject(tx.error);tx.onabort=()=>reject(tx.error);
  });}catch(_){/* Retry will be idempotent after next page load. */}
 }
 async function flush(){
  if(flushing||navigator.onLine===false){void refreshHealth();return;}flushing=true;void refreshHealth();
  try{
   const items=await list();if(!items.length)return;
   const csrf=(await window.Lucky.getMe()).csrf;
   for(const item of items){
    if(navigator.onLine===false)break;
    const body=new FormData();
    for(const k of ['session','takeId','nonce','sourceKey','sourceName','recordedStartMs','recordedEndMs','clockOffsetMs','durationMs'])body.append(k,String(item[k]));
    body.append('media',item.blob,item.sourceKey==='program'?'director-program.webm':'director-camera.webm');
    try{
     const response=await fetch('api/recording-host.php',{method:'POST',headers:{'X-CSRF-Token':csrf},credentials:'same-origin',body});
     const data=await response.json().catch(()=>({}));
     if(!response.ok||!data.ok)throw Error(data.error||'Upload failed ('+response.status+').');
     await drop(item.nonce);
     lastError='';
     window.dispatchEvent(new Event('lucky:director-uploads-updated'));
     void window.LuckyDirectorWorkspace?.refreshTimeline?.();
     void window.LuckyCameraDirector?.refreshClips?.();
     message('Director original saved to this recording session. '+(await list()).length+' pending.');
    }catch(e){lastError='Upload retry needed';message('Director originals remain queued: '+e.message+'. Upload retries automatically.');break;}
   }
  }catch(e){lastError='Upload retry needed';message('Waiting to upload originals: '+e.message);}
  finally{flushing=false;void refreshHealth();}
 }
 function mark(camera,on){
  const card=document.querySelectorAll('.camera-source');
  for(const node of card)if(node.dataset.cameraId===camera){
   let badge=node.querySelector('.director-source-rec');
   if(on&&!badge){badge=document.createElement('span');badge.className='director-source-rec';badge.textContent='● REC';node.append(badge);}
   if(!on)badge?.remove();
  }
  void refreshHealth();
 }
 async function queue(blob,{session,takeId,sourceKey,sourceName,startMs,endMs,clockOffsetMs}){
  if(!blob?.size)return;
  if(blob.size>80*1024*1024){message('Director original exceeds 80 MB. Shorten the take or lower camera resolution.');return;}
  const record={nonce:random(),session,takeId,sourceKey,sourceName,blob,
   recordedStartMs:Math.round(startMs+clockOffsetMs),
   recordedEndMs:Math.round(endMs+clockOffsetMs),
   clockOffsetMs:Math.round(clockOffsetMs),
   durationMs:Math.max(0,Math.round(endMs-startMs))};
  await persist(record);message('Director original queued for private upload ('+(blob.size/1048576).toFixed(1)+' MB).');void refreshHealth();
  void flush();
 }
 function preferredType(){
  if(!window.MediaRecorder)return '';
  return ['video/webm;codecs=vp9','video/webm;codecs=vp8','video/webm','video/mp4']
   .find(type=>MediaRecorder.isTypeSupported?.(type))||'';
 }
 function startCamera(source,take){
  if(!current||current.takeId!==take.takeId||cameras.has(source.id))return;
  if(!source.stream?.getVideoTracks?.().some(track=>track.readyState==='live'))return;
  if(!window.MediaRecorder){message('This browser cannot record desktop camera originals.');return;}
  let recorder;
  try{const mime=preferredType();recorder=new MediaRecorder(source.stream,mime?{mimeType:mime}:undefined);}
  catch(e){message('Camera '+source.label+' cannot record: '+e.message);return;}
  const started=Date.now(),pieces=[];let total=0;
  const entry={recorder,takeId:take.takeId};cameras.set(source.id,entry);mark(source.id,true);
  recorder.ondataavailable=e=>{if(e.data?.size){pieces.push(e.data);total+=e.data.size;if(total>=MAX_BYTES&&recorder.state==='recording')recorder.stop();}};
  recorder.onerror=()=>message('Camera '+source.label+' encountered a capture error. Saved segments will retry.');
  recorder.onstop=()=>{
   clearTimeout(entry.timer);
   if(cameras.get(source.id)===entry){cameras.delete(source.id);mark(source.id,false);}
   const ended=Date.now(),blob=new Blob(pieces,{type:recorder.mimeType||'video/webm'});
   void queue(blob,{...take,sourceKey:source.id,sourceName:source.label||'Desktop Camera',startMs:started,endMs:ended});
   // A previous take may finish while a new one starts. Resume the currently
   // authorized take, never the old one, and preserve the old clip's metadata.
   if(current){const connected=window.LuckyDirectorStudio?.cameras?.().find(c=>c.id===source.id);
    if(connected)startCamera(connected,current);}
  };
  try{recorder.start(1000);entry.timer=setTimeout(()=>{if(recorder.state==='recording')recorder.stop();},MAX_MS);}
  catch(e){cameras.delete(source.id);mark(source.id,false);message('Camera recording failed: '+e.message);}
 }
 function startProgram(take){
  if(!current||current.takeId!==take.takeId)return;
  if(window.LuckyCameraDirector?.state?.().recording)return;
  const started=window.LuckyCameraDirector?.startExport?.({...take,sourceKey:'program',sourceName:'Program Output'});
  if(started){
   ownedProgramTake=take.takeId;
   clearTimeout(programTimer);programTimer=setTimeout(()=>window.LuckyCameraDirector?.stopExport?.(),MAX_MS);
  }else{
   message('Desktop cameras are recording. Select and Take a source to Program to include the edited output.');
  }
 }
 function stopCurrent(){
  const prior=current;current=null;
  clearTimeout(programTimer);programTimer=null;
  for(const entry of cameras.values())if(entry.recorder.state==='recording')entry.recorder.stop();
  // Never stop a manually started Program export that this take did not own.
  if(prior&&ownedProgramTake===prior.takeId)window.LuckyCameraDirector?.stopExport?.();
  ownedProgramTake='';
  if(prior)message('Take stopped. Completed originals are queued for secure upload.');
  void flush();
 }
 window.addEventListener('lucky:director-take-start',event=>{
  const detail=event.detail||{};
  if(!/^[a-f0-9]{32}$/.test(detail.recordingSession||'')||!/^[a-f0-9]{32}$/.test(detail.takeId||''))return;
  if(current?.takeId===detail.takeId)return;
  stopCurrent();
  current={session:detail.recordingSession,takeId:detail.takeId,clockOffsetMs:Number(detail.clockOffsetMs)||0};
  for(const source of window.LuckyDirectorStudio?.cameras?.()||[])startCamera(source,current);
  startProgram(current);
 });
 window.addEventListener('lucky:director-take-stop',event=>{
  if(current?.takeId===event.detail?.takeId)stopCurrent();
 });
 window.addEventListener('lucky:director-studio-close',stopCurrent);
 window.addEventListener('lucky:cameras-refreshed',()=>{
  void refreshHealth();
  if(!current)return;
  const sources=window.LuckyDirectorStudio?.cameras?.()||[];
  for(const [id,entry] of cameras)if(!sources.some(source=>source.id===id)&&entry.recorder.state==='recording')entry.recorder.stop();
  for(const source of sources)startCamera(source,current);
 });
 window.addEventListener('lucky:director-program-captured',event=>{
  const d=event.detail||{};
  if(!/^[a-f0-9]{32}$/.test(d.session||'')||!/^[a-f0-9]{32}$/.test(d.takeId||''))return;
  if(ownedProgramTake===d.takeId)ownedProgramTake='';
  void queue(d.blob,{...d,startMs:d.startMs,endMs:d.endMs});
  if(current)queueMicrotask(()=>startProgram(current));
 });
 retry.addEventListener('click',()=>void flush());
 window.addEventListener('online',()=>void flush());
 window.addEventListener('offline',()=>void refreshHealth());
 window.addEventListener('pageshow',()=>void flush());
 setInterval(()=>{void flush();void refreshHealth();},15000);
 window.LuckyDirectorRecovery={refresh:refreshHealth,retry:flush};
 void refreshHealth();
 void flush();
})();
