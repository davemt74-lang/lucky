/* Lucky Prize Proof: opt-in, browser-native USB/built-in multi-camera capture. */
(() => {
 'use strict';
 const $=id=>document.getElementById(id);
 let session='',claim='',prize='Prize',feeds=new Map(),recordings=new Map(),audioStream=null,audioRecorder=null,recordTimer=null;
 let uploading=0,closing=false,previewGeneration=0,proofProfile=[],proofPlan=[],sequenceAbort=null,sequencePromise=null,cameraAuthorized=false;
 const drafts=new Map();let draftSerial=0,recordAttempt=0,priming=null;
 // A user-initiated Start Game click obtains camera permission before the first prize.
 // Release the probe immediately so hardware remains available for live proof feeds.
 async function prime(){
  if(!navigator.mediaDevices?.getUserMedia)return false;
  try{const stream=await navigator.mediaDevices.getUserMedia({video:true,audio:false});stream.getTracks().forEach(t=>t.stop());
   cameraAuthorized=true;try{sessionStorage.setItem('lucky-cameras-authorized','1');}catch(_){}
   return true;
  }catch(e){status('Camera access can be enabled when proof opens: '+e.message,true);return false;}
 }
 function showDrafts(){
  const pane=$('capture-review'),list=$('capture-review-list'),controls=$('capture-recording-tools');if(!pane||!list)return;
  list.replaceChildren();pane.hidden=drafts.size===0;if(controls)controls.hidden=drafts.size>0;
  for(const [id,clip] of drafts){const tile=document.createElement('div');tile.className='review-tile';tile.dataset.draft=id;
   const title=document.createElement('strong');title.textContent=clip.angleLabel||clip.sourceLabel||'Recorded video';
   const player=document.createElement('video');player.controls=true;player.playsInline=true;player.preload='metadata';player.src=clip.url;
   const actions=document.createElement('div');actions.className='capture-actions';
   for(const [label,fn] of [['▶ Play',()=>{player.currentTime=0;return player.play();}],['✓ Approve',()=>approveDraft(id)],['↻ Re-record',()=>rerecordDraft(id)],['🗑 Delete',()=>deleteDraft(id)]]){
    const button=document.createElement('button');button.type='button';button.className='btn btn-outline';button.textContent=label;button.onclick=()=>Promise.resolve().then(fn).catch(e=>status(e.message,true));actions.append(button);
   }
   tile.append(title,player,actions);list.append(tile);
  }
  window.dispatchEvent(new CustomEvent('lucky:proof-review',{detail:{session,claim,reviewCount:drafts.size}}));
 }
 function stage(blob,filename,durationMs,sourceLabel,angleLabel='',angleIndex=0){
  if(closing||!blob?.size)return;const id='video-'+(++draftSerial);drafts.set(id,{blob,filename,durationMs,sourceLabel,angleLabel,angleIndex,url:URL.createObjectURL(blob),session,claim});
  showDrafts();status('Video recorded. Play it, then Approve to add it to this prize.');
 }
 function deleteDraft(id){const clip=drafts.get(id);if(!clip)return;URL.revokeObjectURL(clip.url);drafts.delete(id);showDrafts();status('Recording discarded locally; no upload was made.');}
 async function approveDraft(id){const clip=drafts.get(id);if(!clip)throw Error('No recording to approve.');if(clip.session!==session||clip.claim!==claim)throw Error('Prize changed; recording not uploaded.');
  await upload(clip.blob,clip.filename,'video',clip.durationMs,clip.sourceLabel,clip.angleLabel,clip.angleIndex);
  deleteDraft(id);status('Approved video saved to this prize.');
 }
 async function rerecordDraft(id){const clip=drafts.get(id);if(!clip)return;const cameraId=[...feeds.entries()].find(([_,f])=>f.label===clip.sourceLabel)?.[0]||selected()[0];
  deleteDraft(id);if(!cameraId)throw Error('Select the camera to record again.');await record([cameraId]);
 }
 function clearDrafts(){for(const id of [...drafts.keys()])deleteDraft(id);}
 async function phoneAction(action){
  if(!$('win-dialog')?.open||!claim)return false;
  switch(action){
   case 'proof-record':if(drafts.size)return false;await record();return true;
   case 'proof-stop':{const wasVideo=recordings.size>0||!!sequencePromise;await stopRecording();if(wasVideo){for(let i=0;i<40&&drafts.size===0;i++)await new Promise(r=>setTimeout(r,50));}return true;}
   case 'proof-play':{const player=$('capture-review-list')?.querySelector('video');if(!player)return false;player.currentTime=0;await player.play();return true;}
   case 'proof-approve':{const id=drafts.keys().next().value;if(!id)return false;await approveDraft(id);return true;}
   case 'proof-rerecord':{const id=drafts.keys().next().value;if(!id)return false;await rerecordDraft(id);return true;}
   case 'proof-delete':{const id=drafts.keys().next().value;if(!id)return false;deleteDraft(id);return true;}
   default:return false;
  }
 }
 const savedNames=()=>{try{return JSON.parse(localStorage.getItem('lucky-camera-aliases')||'{}')||{};}catch(_){return {};}};
 const MAX_CAMERAS=4;
 const status=(message,bad=false)=>{const p=$('capture-feedback');if(p){p.textContent=message;p.classList.toggle('is-error',bad);}};
 const badge=()=>{const n=$('capture-live-status');if(n)n.textContent=`${feeds.size} camera${feeds.size===1?'':'s'} connected${recordings.size||audioRecorder?' · recording':''}`;};
 const contextReady=()=>{if(!/^[a-f0-9]{32}$/.test(session)||!/^[a-f0-9]{32}$/.test(claim))throw Error('Select a claimed prize before enabling its cameras.');};
 const permitted=()=>{contextReady();if(!$('capture-consent')?.checked)throw Error('Check recording consent first.');};
 const capabilities=()=>{if(!navigator.mediaDevices?.getUserMedia)throw Error('Camera requires a supported browser and HTTPS. You can still upload files.');};
 const selected=()=>[...document.querySelectorAll('[data-camera-select]:checked')].map(x=>x.dataset.cameraSelect);
 function getCameraName(id){const card=[...document.querySelectorAll('.camera-source')].find(c=>c.dataset.cameraId===id);return clean(card?.querySelector('[data-camera-name]')?.value||card?.querySelector('strong')?.textContent||'Camera');}
 function drawProofPlan(){const el=$('proof-angle-plan');if(!el)return;const cameras=selected().map(id=>({id,label:getCameraName(id)}));
  proofPlan=cameras.length&&cameras.length<=MAX_CAMERAS?LuckyProofSequence.build(cameras,proofProfile):[];el.replaceChildren();
  for(const angle of proofPlan){const chip=document.createElement('div');chip.className='proof-angle-chip';chip.textContent=`${angle.index}. ${angle.angleLabel} · ${angle.cameraName} · ${angle.seconds}s`;el.append(chip);}
  if($('proof-active-angle')&&!sequencePromise)$('proof-active-angle').textContent=`${proofPlan.length} active proof view${proofPlan.length===1?'':'s'}`;
  if($('proof-next-angle')&&!sequencePromise)$('proof-next-angle').textContent=proofPlan.length?'Next: '+proofPlan[0].angleLabel+' ('+proofPlan[0].cameraName+')':'Next: choose your cameras';
  if($('proof-countdown')&&!sequencePromise)$('proof-countdown').textContent='—';
 }
 function proofAnnounce(state){const a=$('proof-active-angle'),n=$('proof-next-angle'),c=$('proof-countdown');
  if(!a||!n||!c)return;
  a.textContent=state.phase==='countdown'?`Starting ${state.now.angleLabel}`:state.phase==='recording'?`● Recording ${state.now.angleLabel}`:`✓ Saved ${state.now.angleLabel}`;
  n.textContent=state.next?`Next camera: ${state.next.cameraName} · ${state.next.angleLabel}`:'Next camera: final view';
  c.textContent=state.phase==='recording'?`${state.remaining}s`:state.phase==='saved'?'✓':String(state.remaining);
  a.dataset.phase=state.phase;
 }
 const clean=name=>String(name||'Camera').replace(/[\x00-\x1f\x7f]/g,'').slice(0,120);
 const mimeFor=kind=>{if(typeof MediaRecorder==='undefined')return '';const formats=kind==='video'?['video/webm;codecs=vp8,opus','video/webm;codecs=vp9,opus','video/webm','video/mp4']:['audio/webm;codecs=opus','audio/webm','audio/mp4'];return formats.find(x=>MediaRecorder.isTypeSupported(x))||'';};
 const extension=type=>type.includes('mp4')?'.mp4':'.webm';
 function stopFeed(id){const f=feeds.get(id);if(!f)return;f.stream.getTracks().forEach(t=>t.stop());f.video.srcObject=null;feeds.delete(id);badge();}
 async function stopAll(){if(sequenceAbort){sequenceAbort.abort();try{await sequencePromise;}catch(_){}}if(recordings.size||audioRecorder)await stopRecording();for(const id of [...feeds.keys()])stopFeed(id);$('camera-grid')?.replaceChildren();clearDrafts();badge();}
 function checkLive(){if(closing)throw Error('Reopen the capture studio to continue.');}
 async function refresh(grant=false){
  checkLive();capabilities();contextReady();
  // Browsers often hide camera names until permission is granted through a user gesture.
  if(grant){const probe=await navigator.mediaDevices.getUserMedia({video:true,audio:false});probe.getTracks().forEach(t=>t.stop());}
  const items=(await navigator.mediaDevices.enumerateDevices()).filter(d=>d.kind==='videoinput');
  const grid=$('camera-grid');const was=new Set(selected());const connectedIds=new Set(items.map((d,i)=>d.deviceId||`unlabeled-${i}`));for(const id of [...feeds.keys()])if(!connectedIds.has(id))stopFeed(id);grid.replaceChildren();
  if(!items.length){status('No cameras detected. Plug in a USB camera, allow camera access, then Refresh.',true);return;}
  items.forEach((d,i)=>{
   const id=d.deviceId||`unlabeled-${i}`,label=clean(savedNames()[id]||d.label||`Camera ${i+1}`);
   const card=document.createElement('section');card.className='camera-source';card.dataset.cameraId=id;
   const top=document.createElement('label');top.className='camera-source-title';
   const input=document.createElement('input');input.type='checkbox';input.dataset.cameraSelect=id;input.checked=was.has(id)||(!was.size&&i<MAX_CAMERAS);
   const name=document.createElement('strong');name.textContent=label;top.append(input,name);
   input.onchange=drawProofPlan;
   const cameraName=document.createElement('input');cameraName.type='text';cameraName.maxLength=120;cameraName.value=label;cameraName.dataset.cameraName=id;cameraName.setAttribute('aria-label',`Name camera ${i+1}`);
   cameraName.onchange=()=>{const updated=clean(cameraName.value)||`Camera ${i+1}`;cameraName.value=updated;name.textContent=updated;
    const aliases=savedNames();aliases[id]=updated;try{localStorage.setItem('lucky-camera-aliases',JSON.stringify(aliases));}catch(_){/* Preferences optional */}
    const feed=feeds.get(id);if(feed)feed.label=updated;drawProofPlan();};
   const video=document.createElement('video');video.autoplay=true;video.muted=true;video.playsInline=true;video.setAttribute('aria-label',label+' preview');video.hidden=true;
   const info=document.createElement('p');info.className='mini-label';info.textContent='Not previewing';
   const actions=document.createElement('div');actions.className='camera-source-actions';
   const preview=document.createElement('button');preview.type='button';preview.className='btn btn-outline';preview.textContent='Preview';
   preview.onclick=()=>openCamera(id,label,video,info).catch(e=>{info.textContent=e.message;status(`${label}: ${e.message}`,true);});
   const photo=document.createElement('button');photo.type='button';photo.className='btn btn-outline';photo.textContent='Photo';
   photo.onclick=async()=>{try{permitted();await openCamera(id,label,video,info);await snap(id);}catch(e){status(e.message,true);}};
   actions.append(preview,photo);card.append(video,top,cameraName,info,actions);grid.append(card);
   if(feeds.has(id)){const f=feeds.get(id);f.video=video;f.info=info;video.srcObject=f.stream;video.hidden=false;info.textContent='Live · '+label;video.play().catch(()=>{});}
  });
  badge();drawProofPlan();
  // Enable is an explicit user gesture. Open checked feeds immediately to show live video,
  // rather than forcing another tap on every camera tile. Consent is required for capture,
  // not for viewing locally after the browser grants camera permission.
  const errors=[];if(grant){for(const id of selected().slice(0,MAX_CAMERAS)){
    const card=[...grid.querySelectorAll('.camera-source')].find(c=>c.dataset.cameraId===id);
    if(card)try{await openCamera(id,getCameraName(id),card.querySelector('video'),card.querySelector('.mini-label'));}catch(e){errors.push(`${getCameraName(id)}: ${e.message}`);}
  }}
  const hint=$('camera-ready-message');if(hint)hint.textContent=feeds.size?`${feeds.size} live camera preview${feeds.size===1?'':'s'} · select a view to capture`:'Cameras detected; click Preview to show live feeds.';
  status(errors.length?`Some cameras could not open: ${errors.join('; ')}`:`${items.length} camera input${items.length===1?'':'s'} found · ${feeds.size} live.`,!!errors.length);
 }
 async function openCamera(id,label,video,info){
  checkLive();capabilities();contextReady();if(feeds.has(id))return feeds.get(id);
  if(feeds.size>=MAX_CAMERAS)throw Error(`Maximum ${MAX_CAMERAS} simultaneous preview feeds. Turn off another camera first.`);
  const stream=await navigator.mediaDevices.getUserMedia({video:{deviceId:{exact:id},width:{ideal:1280},height:{ideal:720}},audio:false});
  const feed={id,label,stream,video,info};feeds.set(id,feed);video.srcObject=stream;video.hidden=false;
  try{await video.play();}catch(_){/* Some browsers need a tap to play. */}
  info.textContent='Live · '+label;badge();return feed;
 }
 async function ensureSelected(){
  const ids=selected();if(!ids.length)throw Error('Select at least one camera.');if(ids.length>MAX_CAMERAS)throw Error(`Select ${MAX_CAMERAS} cameras or fewer.`);
  let ok=0,errors=[];for(const id of ids){const card=[...document.querySelectorAll('.camera-source')].find(c=>c.dataset.cameraId===id);
   const name=getCameraName(id);try{await openCamera(id,name,card.querySelector('video'),card.querySelector('.mini-label'));ok++;}catch(e){errors.push(`${name}: ${e.message}`);}}
  if(!ok)throw Error(errors.join(' · ')||'No selected camera is available.');if(errors.length)status('Some cameras could not be opened: '+errors.join(' · '),true);
  return ids.filter(id=>feeds.has(id));
 }
 async function upload(blob,filename,kind,durationMs,sourceLabel='',angleLabel='',angleIndex=0){
  checkLive();permitted();if(!blob?.size)return;if(blob.size>80*1024*1024)throw Error('Each clip must be 80 MB or less.');uploading++;
  try{
   status(`Uploading ${sourceLabel||kind} clip…`);const me=await Lucky.getMe();const form=new FormData();
   form.append('session',session);form.append('claim',claim);form.append('media',blob,filename);form.append('kind',kind);
   form.append('durationMs',String(Math.max(0,Math.round(durationMs||0))));form.append('sourceLabel',clean(sourceLabel));form.append('angleLabel',clean(angleLabel).slice(0,80));form.append('angleIndex',String(angleIndex));form.append('title',`${prize} · ${angleLabel||sourceLabel||kind} reaction`.slice(0,120));
   const r=await fetch('api/claim-media.php?action=upload',{method:'POST',credentials:'same-origin',headers:{'X-CSRF-Token':me.csrf},body:form});
   const data=await r.json();if(!r.ok||!data.ok)throw Error(data.error||'Upload failed');
   status(`Saved ${sourceLabel||kind} to the game timeline.`);await refreshClips();window.dispatchEvent(new CustomEvent('lucky:media-uploaded',{detail:{session,claim,clip:data.clip}}));return data.clip;
  }finally{uploading--;}
 }
 async function snap(id){const f=feeds.get(id);if(!f)throw Error('Preview the camera first.');const v=f.video,c=$('capture-photo-canvas');
  if(!v.videoWidth)throw Error('Camera is not ready to capture.');c.width=v.videoWidth;c.height=v.videoHeight;c.getContext('2d').drawImage(v,0,0,c.width,c.height);
  const blob=await new Promise((resolve,reject)=>c.toBlob(b=>b?resolve(b):reject(Error('Unable to capture photo.')),'image/jpeg',0.88));
  await upload(blob,`camera-${Date.now()}.jpg`,'photo',0,f.label);
 }
 async function photos(){try{permitted();const ids=await ensureSelected();for(const id of ids){try{await snap(id);}catch(e){status(`${feeds.get(id)?.label||'Camera'}: ${e.message}`,true);}}}catch(e){status(e.message,true);}}
 async function captureAudio(){try{permitted();capabilities();if(typeof MediaRecorder==='undefined')throw Error('Audio recording unavailable; upload an audio file.');if(audioRecorder||recordings.size)return;
  audioStream=await navigator.mediaDevices.getUserMedia({video:false,audio:true});const mime=mimeFor('audio');const chunks=[];const r=mime?new MediaRecorder(audioStream,{mimeType:mime}):new MediaRecorder(audioStream);
  audioRecorder=r;const start=Date.now();r.ondataavailable=e=>{if(e.data.size)chunks.push(e.data);};
  r.onstop=async()=>{const blob=new Blob(chunks,{type:r.mimeType||mime||'audio/webm'});audioStream?.getTracks().forEach(t=>t.stop());audioStream=null;audioRecorder=null;badge();if(blob.size)try{await upload(blob,`reaction${extension(blob.type)}`,'audio',Date.now()-start,'Microphone');}catch(e){status(e.message,true);}};
  r.start(250);$('capture-stop').hidden=false;badge();status('Recording microphone. Press Stop to save.');
 }catch(e){status(e.message,true);}}
 async function captureRotation(){
  try{
   permitted();capabilities();if(typeof MediaRecorder==='undefined')throw Error('Video recording is not available in this browser.');
   if(sequencePromise||recordings.size||audioRecorder)throw Error('Finish the current recording first.');
   const ids=selected();if(!ids.length||ids.length>MAX_CAMERAS)throw Error('Select 1–4 active cameras.');
   const cameras=ids.map(id=>({id,label:getCameraName(id)}));
   const plan=LuckyProofSequence.build(cameras,proofProfile);
   // One physical camera opened and recorded at a time. Avoid simultaneous USB bandwidth overload.
   sequenceAbort=new AbortController();const signal=sequenceAbort.signal;
   $('capture-stop').hidden=false;$('proof-rotate').disabled=true;$('capture-video').disabled=true;
   $('capture-photo').disabled=true;status(`Starting ${plan.length} sequential proof views. Five-second countdown before each angle.`);
   const runner=LuckyProofSequence.run(plan,{
    signal,announce:proofAnnounce,
    record:async angle=>{
     const card=[...document.querySelectorAll('.camera-source')].find(c=>c.dataset.cameraId===angle.id);
     if(!card)throw Error(`Camera ${angle.cameraName} disconnected.`);
     // Free other sources so USB cameras that cannot run concurrently still work.
     for(const other of [...feeds.keys()])if(other!==angle.id)stopFeed(other);
     const f=await openCamera(angle.id,angle.cameraName,card.querySelector('video'),card.querySelector('.mini-label'));
     if(signal.aborted)throw Error('Capture stopped.');
     const mime=mimeFor('video'),chunks=[];
     const recorder=mime?new MediaRecorder(f.stream,{mimeType:mime}):new MediaRecorder(f.stream);
     const started=Date.now();
     const completed=new Promise((resolve,reject)=>{
      recorder.ondataavailable=e=>{if(e.data?.size)chunks.push(e.data);};
      recorder.onerror=()=>reject(Error('Camera recording failed for '+angle.cameraName));
      recorder.onstop=()=>resolve(new Blob(chunks,{type:recorder.mimeType||mime||'video/webm'}));
     });
     let timer=null;const cancel=()=>{clearInterval(timer);if(recorder.state==='recording')recorder.stop();};
     try{
      recorder.start(250);recordings.set(angle.id,recorder);badge();
      timer=setInterval(()=>{const remaining=Math.max(0,Math.ceil((angle.seconds*1000-(Date.now()-started))/1000));proofAnnounce({phase:'recording',now:angle,next:plan[angle.index]||null,remaining});},250);
      await Promise.race([LuckyProofSequence.wait(angle.seconds*1000,signal),completed.then(()=>{throw Error('Camera stopped early: '+angle.cameraName);})]);
      cancel();const blob=await completed;
      if(!blob.size)throw Error('No footage recorded from '+angle.cameraName);
      if(!closing)stage(blob,`proof-angle-${angle.index}-${Date.now()}${extension(blob.type)}`,Date.now()-started,angle.cameraName,angle.angleLabel,angle.index);
     }finally{clearInterval(timer);signal.removeEventListener('abort',cancel);cancel();recordings.delete(angle.id);stopFeed(angle.id);badge();}
    }
   });
   sequencePromise=runner;
   const count=await runner;
   status(`${count} proof angle clips saved in the active-game timeline.`);
  }catch(e){status(e.message,e.message!=='Capture stopped.');}
  finally{sequenceAbort=null;sequencePromise=null;$('capture-stop').hidden=true;$('proof-rotate').disabled=false;$('capture-video').disabled=false;$('capture-photo').disabled=false;drawProofPlan();}
 }
 async function record(onlyIds=null){try{permitted();if(typeof MediaRecorder==='undefined')throw Error('Video recording unavailable; upload a clip instead.');if(recordings.size||audioRecorder)return;
  const ids=onlyIds||await ensureSelected();if(onlyIds)await ensureSelected();const mime=mimeFor('video');let failures=[];
  for(const id of ids){const feed=feeds.get(id),chunks=[];try{
   // One video stream per camera. Audio can be added as a separate reaction clip, avoiding doubled microphones.
   const r=mime?new MediaRecorder(feed.stream,{mimeType:mime}):new MediaRecorder(feed.stream);
   const start=Date.now();r.ondataavailable=e=>{if(e.data?.size)chunks.push(e.data);};
   r.onstop=async()=>{recordings.delete(id);badge();const blob=new Blob(chunks,{type:r.mimeType||mime||'video/webm'});
    if(blob.size)stage(blob,`camera-${Date.now()}${extension(blob.type)}`,Date.now()-start,feed.label);
   };r.start(250);recordings.set(id,r);
  }catch(e){failures.push(`${feed.label}: ${e.message}`);}}
  if(!recordings.size)throw Error('Could not record any selected cameras. '+failures.join('; '));
  $('capture-stop').hidden=false;badge();status(`Recording ${recordings.size} camera${recordings.size===1?'':'s'} simultaneously, with separate timeline clips.${failures.length?' Unavailable: '+failures.join('; '):''}`);
  clearTimeout(recordTimer);recordTimer=setTimeout(()=>stopRecording(),30000);
 }catch(e){status(e.message,true);}}
 async function stopRecording(){if(sequenceAbort){sequenceAbort.abort();return;}clearTimeout(recordTimer);for(const recorder of recordings.values())if(recorder.state==='recording')recorder.stop();
  if(audioRecorder?.state==='recording')audioRecorder.stop();$('capture-stop').hidden=true;badge();}
 async function refreshClips(){if(!session||!claim)return;try{const r=await fetch('api/claim-media.php?action=list&session='+encodeURIComponent(session),{credentials:'same-origin'});const data=await r.json();if(!r.ok||!data.ok)throw Error(data.error||'Could not list media');
  const list=$('capture-clip-list');list.replaceChildren();const clips=(data.clips||[]).filter(c=>c.claimId===claim);
  const title=document.createElement('strong');title.textContent=`${clips.length} captured clip${clips.length===1?'':'s'} for this prize`;list.append(title);
  for(const clip of clips){const row=document.createElement('div');row.className='claim-captured';row.textContent=`✓ ${clip.title} (${clip.kind})${clip.sourceLabel?' · '+clip.sourceLabel:''}`;list.append(row);}
 }catch(e){status(e.message,true);}}
 function show(s,c,n,angles=[]){clearDrafts();closing=false;session=s||'';claim=c||'';prize=n||'Prize';proofProfile=Array.isArray(angles)?angles:[];drawProofPlan();$('capture-prize-label').textContent=prize;$('capture-open-editor').href='editor.php?session='+encodeURIComponent(session);
  $('capture-consent').checked=false;status(session&&claim?'Live preview ready. Recording requires consent.':'Choose a prize first.');refreshClips();
  if(session&&claim&&navigator.mediaDevices?.enumerateDevices)refresh(false).then(async()=>{
   let wasAuthorized=cameraAuthorized;try{wasAuthorized=wasAuthorized||sessionStorage.getItem('lucky-cameras-authorized')==='1';}catch(_){}
   if(wasAuthorized){
    for(const id of selected().slice(0,MAX_CAMERAS)){const card=[...document.querySelectorAll('.camera-source')].find(c=>c.dataset.cameraId===id);if(card)try{await openCamera(id,getCameraName(id),card.querySelector('video'),card.querySelector('.mini-label'));}catch(e){status(e.message,true);}}
   }
  }).catch(e=>status(e.message,true));}
 function setup(){const dialog=$('win-dialog');if(!dialog)return;
  $('camera-enable').onclick=()=>refresh(true).catch(e=>status(e.message,true));$('camera-refresh').onclick=()=>refresh().catch(e=>status(e.message,true));
  $('camera-off').onclick=()=>stopAll().catch(e=>status(e.message,true));$('capture-photo').onclick=photos;$('capture-video').onclick=()=>record();
  $('capture-audio').onclick=captureAudio;$('proof-rotate').onclick=captureRotation;$('capture-stop').onclick=stopRecording;
  $('capture-file').onchange=async e=>{try{permitted();for(const file of [...e.target.files]){
    const kind=file.type.startsWith('image/')?'photo':file.type.startsWith('video/')?'video':file.type.startsWith('audio/')?'audio':null;
    if(!kind){status(`Unsupported file ${file.name}`,true);continue;}try{await upload(file,file.name,kind,0,'Imported file');}catch(err){status(err.message,true);}
  }}catch(err){status(err.message,true);}finally{e.target.value='';}};
  if(dialog.tagName==='DIALOG')dialog.addEventListener('close',()=>{closing=true;stopAll().catch(()=>{});});
  window.addEventListener('pagehide',()=>{closing=true;stopAll().catch(()=>{});});
  navigator.mediaDevices?.addEventListener?.('devicechange',()=>{if((dialog.open||dialog.dataset.inlineCapture==='true')&&!recordings.size&&!sequencePromise)refresh().catch(()=>{});});
 }
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',setup);else setup();
 window.LuckyCapture={show,prime,phoneAction,reviewCount:()=>drafts.size,hasClaim:()=>!!claim&&!closing,release:stopAll,isBusy:()=>!!sequencePromise||recordings.size>0||!!audioRecorder||uploading>0};
})();
