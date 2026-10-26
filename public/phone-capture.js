(() => {
 'use strict';
 const $=id=>document.getElementById(id),dialog=$('phone-capture-dialog');if(!dialog)return;
 const MAX_BYTES=80*1024*1024,MAX_SECONDS=60,CHUNK_BYTES=512*1024;
 const allowedTypes={photo:new Set(['image/jpeg','image/png','image/webp']),video:new Set(['video/mp4','video/webm','video/quicktime']),audio:new Set(['audio/mpeg','audio/mp4','audio/ogg','audio/wav','audio/x-wav','audio/webm'])};
 let permission=null,mode='photo',stream=null,recorder=null,recorderTimer=null,selectedFile=null,selectedDurationMs=0,nonce='',previewUrl='',saving=false,generation=0,armed=false,multicamRecording=false;
 const fail=(message)=>{const el=$('phone-capture-status');if(el){el.textContent=message;el.classList.add('platform-error');}};
 const note=(message)=>{const el=$('phone-capture-status');if(el){el.textContent=message;el.classList.remove('platform-error');}};
 const permitted=()=>permission?.active===true&&permission.phoneCameraAllowed===true&&/^[a-f0-9]{32}$/.test(permission.sessionId)&&/^[a-f0-9]{32}$/.test(permission.claimId);
 const authorized=()=>permitted()&&permission.consent===true;
 const randomNonce=()=>crypto.randomUUID?.().replaceAll('-','')||Array.from(crypto.getRandomValues(new Uint8Array(16)),b=>b.toString(16).padStart(2,'0')).join('');
 function cleanupStream(){window.dispatchEvent(new Event('lucky:phone-capture-close'));armed=false;multicamRecording=false;if(recorder&&recorder.state!=='inactive'){recorder.onstop=null;recorder.ondataavailable=null;try{recorder.stop();}catch(_){}}
  recorder=null;clearTimeout(recorderTimer);recorderTimer=null;stream?.getTracks().forEach(track=>track.stop());stream=null;
  const live=$('phone-capture-live');if(live){live.pause();live.srcObject=null;live.hidden=true;}
 }
 function clearPreview(){if(previewUrl)URL.revokeObjectURL(previewUrl);previewUrl='';selectedFile=null;selectedDurationMs=0;nonce='';for(const id of ['phone-capture-photo','phone-capture-video','phone-capture-audio']){const el=$(id);if(!el)continue;el.hidden=true;if(el.tagName==='IMG')el.removeAttribute('src');else{el.pause();el.removeAttribute('src');el.load();}}}
 function buttons(){const recording=recorder?.state==='recording',hasStream=!!stream;
  const consent=$('phone-capture-consent');if(consent){consent.checked=!!permission?.consent;consent.disabled=!permitted()||saving;}
  $('phone-capture-start').hidden=!!selectedFile||hasStream;$('phone-capture-start').disabled=!authorized()||saving;
  $('phone-capture-start').textContent=mode==='photo'?'Start photo camera':mode==='video'?'Record video':'Record audio';
  $('phone-capture-arm').hidden=mode!=='video'||!!selectedFile||hasStream;
  $('phone-capture-arm').disabled=!authorized()||saving||permission?.phase!=='live';
  $('phone-capture-disarm').hidden=!armed;$('phone-capture-disarm').disabled=saving;
  $('phone-capture-live-start').hidden=mode!=='video'||!!selectedFile||hasStream;
  $('phone-capture-live-start').disabled=!authorized()||saving;
  $('phone-capture-live-stop').hidden=mode!=='video'||!hasStream||recording||armed;
  $('phone-capture-live-stop').disabled=saving;
  $('phone-capture-live-record').hidden=mode!=='video'||!hasStream||recording||armed;
  $('phone-capture-live-record').disabled=!authorized()||saving;
  $('phone-capture-snapshot').hidden=mode!=='photo'||!hasStream;
  $('phone-capture-stop').hidden=!recording;$('phone-capture-stop').disabled=saving;
  $('phone-capture-retake').hidden=!selectedFile;$('phone-capture-retake').disabled=saving;
  $('phone-capture-send').hidden=!selectedFile;$('phone-capture-send').disabled=!authorized()||saving;
  $('phone-capture-send').textContent=saving?'Uploading…':'↑ Send to Video Studio';
  document.querySelectorAll('[data-phone-capture-mode]').forEach(button=>{button.setAttribute('aria-pressed',String(button.dataset.phoneCaptureMode===mode));button.disabled=saving;});
  $('phone-capture-file').disabled=!authorized()||saving;
  $('phone-capture-placeholder').hidden=hasStream||!!selectedFile;
 }
 function release(){generation++;cleanupStream();clearPreview();buttons();}
 function close(){release();if(dialog.open)dialog.close();}
 function selectMode(value){if(!allowedTypes[value]||saving)return;release();mode=value;buttons();note(value==='photo'?'Tap Start photo camera, then Take photo.':value==='video'?'Tap Live preview to see the phone on the desktop without recording; choose Record when ready.':'Tap Record audio to capture up to 60 seconds.');}
 function mediaType(){if(mode==='photo')return'image/jpeg';const choices=mode==='video'?['video/webm;codecs=vp8,opus','video/webm','video/mp4']:['audio/webm;codecs=opus','audio/webm','audio/mp4','audio/ogg'];return choices.find(v=>window.MediaRecorder?.isTypeSupported?.(v))||'';}
 function showPreview(file,durationMs=0){cleanupStream();clearPreview();selectedFile=file;selectedDurationMs=durationMs;nonce=randomNonce();previewUrl=URL.createObjectURL(file);
  const el=$(mode==='photo'?'phone-capture-photo':mode==='video'?'phone-capture-video':'phone-capture-audio');el.src=previewUrl;el.hidden=false;
  if(mode!=='photo')el.onloadedmetadata=()=>{if(selectedFile===file&&Number.isFinite(el.duration))selectedDurationMs=Math.min(3600000,Math.max(selectedDurationMs,Math.round(el.duration*1000)));};
  note(`${mode} ready (${(file.size/1048576).toFixed(1)} MB). Send it to the current prize.`);buttons();}
 function beginMediaRecorder(stamp,coordinated=false){
  if(!authorized()||stamp!==generation||!stream)return;
  if(!window.MediaRecorder)throw Error('Recording is unavailable in this browser. Use the file picker instead.');
  const preferred=mediaType();recorder=new MediaRecorder(stream,preferred?{mimeType:preferred}:undefined);
  const parts=[];let bytes=0;const capturedMode=mode;let startedAt=0;
  recorder.ondataavailable=e=>{if(e.data?.size){parts.push(e.data);bytes+=e.data.size;if(bytes>=MAX_BYTES&&recorder?.state==='recording')stop();}};
  recorder.onerror=()=>{cleanupStream();buttons();fail('Recording failed. You can try again or choose an existing file.');};
  recorder.onstop=()=>{clearTimeout(recorderTimer);if(stamp!==generation||!authorized())return;
   const blob=new Blob(parts,{type:recorder?.mimeType||preferred||(capturedMode==='video'?'video/webm':'audio/webm')});
   if(!blob.size||blob.size>MAX_BYTES){cleanupStream();buttons();fail('Recording was empty or exceeded 80 MB. Please retry.');return;}
   const actual=blob.type.split(';')[0]||(capturedMode==='video'?'video/webm':'audio/webm');
   if(!allowedTypes[capturedMode].has(actual)){cleanupStream();buttons();fail('Unsupported recording type; please use the file picker.');return;}
   showPreview(new File([blob],`${capturedMode}-${Date.now()}.${actual.includes('mp4')?'mp4':'webm'}`,{type:actual}),Math.max(250,Math.round(performance.now()-startedAt)));
  };
  startedAt=performance.now();armed=false;multicamRecording=coordinated;
  recorder.start(700);recorderTimer=setTimeout(stop,MAX_SECONDS*1000);
  note(coordinated?'● Multicamera recording in progress with the desktop. The host will stop this take.':`● Recording ${mode} (60-second limit). Tap Stop when done.`);
  buttons();
 }
 async function start(arm=false,previewOnly=false){if(!authorized()||saving)return;
  if(arm&&(mode!=='video'||permission?.phase!=='live'))return fail('Arm a video camera before the desktop starts its next recording.');
  const stamp=++generation;cleanupStream();clearPreview();buttons();
  if(!navigator.mediaDevices?.getUserMedia){fail('Camera or microphone needs HTTPS and a compatible browser. Use the file picker below instead.');return;}
  try{
   const requested=mode==='audio'?{audio:true}:mode==='photo'?{video:{facingMode:'environment'}}:{video:{facingMode:'environment'},audio:true};
   try{stream=await navigator.mediaDevices.getUserMedia(requested);}catch(e){if(mode!=='video')throw e;stream=await navigator.mediaDevices.getUserMedia({video:{facingMode:'environment'}});note('Microphone unavailable. Recording silent video.');}
   if(stamp!==generation||!authorized()||!dialog.open){cleanupStream();return;}
   if(mode==='photo'){$('phone-capture-live').srcObject=stream;$('phone-capture-live').hidden=false;await $('phone-capture-live').play();note('Frame your prize, then tap Take photo.');}
   else{
    if(mode==='video'){$('phone-capture-live').srcObject=stream;$('phone-capture-live').hidden=false;await $('phone-capture-live').play();
     window.dispatchEvent(new CustomEvent('lucky:phone-capture-stream',{detail:{mode,stream,consent:true,session:permission.sessionId,claim:permission.claimId}}));}
    if(arm){armed=true;note('Camera armed. Waiting for the host countdown; recording starts when the desktop cameras start.');}
    else if(previewOnly)note('● Live preview starting on desktop. Record when ready, or Stop live preview to disconnect.');
    else beginMediaRecorder(stamp);
   }
  }catch(e){cleanupStream();fail(`Could not start ${mode}: ${e?.message||'Camera permission denied'}. Check site permissions or use the file picker.`);}
  finally{buttons();}
 }
 function snapshot(){if(mode!=='photo'||!authorized()||!stream)return;const stamp=generation;try{const video=$('phone-capture-live');const w=video.videoWidth,h=video.videoHeight;if(!w||!h)throw Error('Camera preview is not ready.');
  const canvas=document.createElement('canvas');canvas.width=w;canvas.height=h;canvas.getContext('2d').drawImage(video,0,0,w,h);
  canvas.toBlob(blob=>{if(stamp!==generation||!authorized())return;if(!blob)return fail('Could not capture photo.');if(blob.size>MAX_BYTES)return fail('Photo exceeds 80 MB.');showPreview(new File([blob],`photo-${Date.now()}.jpg`,{type:'image/jpeg'}));},'image/jpeg',.9);
 }catch(e){fail(e.message);}}
 function stop(){if(recorder?.state==='recording'){recorder.stop();note('Preparing recording…');}buttons();}
 async function upload(){if(!selectedFile||!authorized()||saving)return;const file=selectedFile,receipt=nonce,proof={...permission};let pair;
  try{pair=JSON.parse(sessionStorage.getItem('lucky-phone-controller-v5p')||'null');}catch(_){}
  if(!/^[a-f0-9]{32}$/.test(pair?.id||'')||!/^[a-f0-9]{64}$/.test(pair?.token||'')){fail('Phone pairing expired. Scan the QR code again.');return;}
  saving=true;buttons();note('Uploading to the private Video Studio…');
  try{
   const fields={id:pair.id,nonce:receipt,session:proof.sessionId,claim:proof.claimId,kind:mode,
    durationMs:String(selectedDurationMs||0),title:file.name.replace(/\.[^.]+$/,'').slice(0,120)||'Phone proof'};
   const form=extra=>{const data=new FormData();for(const [key,value] of Object.entries({...fields,...extra}))data.append(key,String(value));return data;};
   const send=async(data,description)=>{
    let response;
    try{response=await fetch('api/phone-media.php',{method:'POST',credentials:'omit',cache:'no-store',
     headers:{'X-Controller-Token':pair.token},body:data});}
    catch(_){throw Error(description+': connection dropped. Check the cloud HTTPS upload connection and retry.');}
    let result={};try{result=await response.json();}catch(_){}
    if(!response.ok||!result.ok)throw Error(result.error||
     (response.status===413?'Server upload limit rejected this piece of the recording.':description+' failed (HTTP '+response.status+').'));
    return result;
   };
   let result;
   // Send video in small pieces even for a short recording. Shared PHP hosting
   // commonly limits a single POST to 2–8 MB, below the 80 MB media limit.
   if(mode==='video'||file.size>CHUNK_BYTES){
    const total=Math.ceil(file.size/CHUNK_BYTES),shared={uploadAction:'chunk',bytes:file.size,total};
    for(let index=0;index<total;index++){
     if(!authorized()||permission.sessionId!==proof.sessionId||permission.claimId!==proof.claimId)
      throw Error('Desktop proof permission changed while sending the recording.');
     const part=form({...shared,index});part.append('chunk',file.slice(index*CHUNK_BYTES,(index+1)*CHUNK_BYTES),'part-'+index+'.bin');
     const ack=await send(part,'Chunk '+(index+1)+' of '+total);
     const next=Number(ack.upload?.next);
     if(!Number.isInteger(next)||next<index+1||next>total)throw Error('The server did not acknowledge the uploaded video piece.');
     note('Uploading video to Video Studio… '+Math.round(next/total*100)+'% ('+next+'/'+total+' pieces).');
     index=next-1; // A retry can resume where the host already has the pieces.
    }
    result=await send(form({uploadAction:'commit',bytes:file.size,total}),'Finalize video');
   }else{
    const data=form({uploadAction:'direct'});data.append('media',file,file.name);
    result=await send(data,'Send capture');
   }
   if(!authorized()||permission.sessionId!==proof.sessionId||permission.claimId!==proof.claimId){close();return;}
   note('✓ '+mode+' saved to Video Studio · '+(result.clip?.id||'Upload complete')+'.');cleanupStream();clearPreview();buttons();
  }catch(e){fail(e.message+' Your file is still here; tap Send to retry.');}finally{saving=false;buttons();}
 }
 function open(){if(!permitted()){fail('The host must enable phone camera for this prize first.');return;}
  if(!dialog.open)dialog.showModal();$('phone-capture-prize').textContent='Current prize proof · Photos, video and audio go to the host Video Studio.';
  note(authorized()?'Choose a mode, or upload an existing file.':'Confirm recording consent to activate the capture controls.');buttons();}
 window.addEventListener('lucky:phone-proof-status',event=>{const old=permission;permission=event.detail;
  if(!permitted()||old&&old.sessionId&&old.claimId&&(old.sessionId!==permission.sessionId||old.claimId!==permission.claimId)){if(dialog.open)close();else release();}
  else if(dialog.open){
   if(!authorized()){cleanupStream();if(!permission.consent)note('Confirm recording consent to activate camera and upload controls.');}
   if(armed&&authorized()&&permission.phase==='recording'){
    try{beginMediaRecorder(generation,true);}catch(e){cleanupStream();fail('Coordinated recording could not start: '+e.message);}
   }else if(multicamRecording&&recorder?.state==='recording'&&permission.phase!=='recording'){
    stop();
   }else if(armed&&permission.phase==='countdown')note('Host countdown started. Stay on this screen; recording will start with the desktop.');
   buttons();
  }
 });
 $('phone-capture-consent')?.addEventListener('change',event=>{if(!permitted())return;
  window.dispatchEvent(new CustomEvent('lucky:phone-proof-consent-request',{detail:{consent:event.target.checked}}));
  note('Waiting for the host to confirm your recording consent…');});
 document.querySelectorAll('[data-phone-capture-mode]').forEach(button=>button.addEventListener('click',()=>selectMode(button.dataset.phoneCaptureMode)));
 $('phone-capture-close').addEventListener('click',close);dialog.addEventListener('close',release);
 $('phone-capture-start').addEventListener('click',()=>start());$('phone-capture-arm').addEventListener('click',()=>start(true));
 $('phone-capture-live-start').addEventListener('click',()=>start(false,true));
 $('phone-capture-live-stop').addEventListener('click',()=>{release();note('Live phone preview stopped.');});
 $('phone-capture-live-record').addEventListener('click',()=>{if(stream&&!recorder&&authorized())try{beginMediaRecorder(generation);}catch(e){fail(e.message);}});
 $('phone-capture-disarm').addEventListener('click',()=>{release();note('Camera disarmed. No recording captured.');});
 $('phone-capture-snapshot').addEventListener('click',snapshot);$('phone-capture-stop').addEventListener('click',stop);
 $('phone-capture-retake').addEventListener('click',()=>{release();note('Ready to try again.');});$('phone-capture-send').addEventListener('click',upload);
 $('phone-capture-file').addEventListener('change',event=>{const file=event.target.files?.[0];event.target.value='';if(!file||!authorized())return;
  const kind=Object.keys(allowedTypes).find(k=>allowedTypes[k].has(file.type));if(!kind){fail('Unsupported media type. Choose JPEG, PNG, WebP, MP4, WebM, MOV, MP3, WAV, OGG or M4A.');return;}
  if(file.size<1||file.size>MAX_BYTES){fail('Choose a file smaller than 80 MB.');return;}mode=kind;release();showPreview(file);});
 window.addEventListener('pagehide',release);window.LuckyPhoneCapture={open,close};buttons();
})();
