(() => {
 'use strict';
 const $=id=>document.getElementById(id),KEY='lucky-phone-controller-v5p',MAX_BYTES=80*1024*1024,MAX_SECONDS=180;
 const valid={video:new Set(['video/webm','video/mp4','video/quicktime']),photo:new Set(['image/jpeg','image/png','image/webp']),audio:new Set(['audio/webm','audio/mpeg','audio/mp4','audio/ogg','audio/wav','audio/x-wav'])};
 let pair=null,sessions=[],mode='video',facing='environment',stream=null,recorder=null,parts=[],bytes=0,clock=null,started=0,generation=0,media=null,mediaNonce='',previewUrl='',duration=0,uploading=false,loading=false;
 const random=()=>crypto.randomUUID?.().replaceAll('-','')||Array.from(crypto.getRandomValues(new Uint8Array(16)),b=>b.toString(16).padStart(2,'0')).join('');
 const note=(message,bad=false)=>{const e=$('session-camera-message');e.textContent=message;e.classList.toggle('platform-error',bad);};
 const session=()=>sessions.find(s=>s.id===$('session-camera-session').value);
 const approved=()=>!!pair&&!!session()&&$('session-camera-consent').checked;
 const recording=()=>recorder?.state==='recording';
 const previewNode=()=>$(mode==='video'?'session-camera-review-video':mode==='photo'?'session-camera-review-photo':'session-camera-review-audio');
 function stopStream(){if(recorder&&recorder.state!=='inactive'){recorder.onstop=null;recorder.ondataavailable=null;try{recorder.stop();}catch(_){}}
  recorder=null;clearInterval(clock);clock=null;stream?.getTracks().forEach(t=>t.stop());stream=null;
  const live=$('session-camera-live');live.pause();live.srcObject=null;live.hidden=true;$('session-camera-live-dot').hidden=true;
 }
 function clearPreview(){if(previewUrl)URL.revokeObjectURL(previewUrl);previewUrl='';media=null;mediaNonce='';duration=0;
  for(const id of ['session-camera-review-video','session-camera-review-photo','session-camera-review-audio']){
   const n=$(id);n.hidden=true;if(n.tagName!=='IMG'){n.pause();n.removeAttribute('src');n.load();}else n.removeAttribute('src');
  }
 }
 function release(){generation++;stopStream();clearPreview();render();}
 function render(){const active=!!session(),live=!!stream,rec=recording(),saved=!!media;
  $('session-camera-open').hidden=!active||live||saved;$('session-camera-open').disabled=loading||uploading||!$('session-camera-consent').checked;
  $('session-camera-record').hidden=!live||rec||mode==='photo';$('session-camera-photo').hidden=!live||mode!=='photo';
  $('session-camera-record').disabled=!$('session-camera-consent').checked;$('session-camera-photo').disabled=!$('session-camera-consent').checked;
  $('session-camera-stop').hidden=!rec;$('session-camera-flip').hidden=!live||rec||mode==='audio';
  $('session-camera-retake').hidden=!saved;$('session-camera-send').hidden=!saved;$('session-camera-send').disabled=!approved()||uploading;
  $('session-camera-send').textContent=uploading?'Uploading…':'↑ Send to recording group';
  $('session-camera-empty').hidden=live||saved;$('session-camera-session').disabled=loading||uploading||rec;
  $('session-camera-file').disabled=!active||uploading||rec;$('session-camera-consent').disabled=uploading;
  $('session-camera-stage').dataset.mode=mode;
  document.querySelectorAll('[data-session-mode]').forEach(b=>{b.setAttribute('aria-pressed',String(b.dataset.sessionMode===mode));b.disabled=uploading||rec;});
 }
 async function call(action,body){const headers={'X-Controller-Token':pair?.token||''};let payload;
  if(body instanceof FormData)payload=body;else{headers['Content-Type']='application/json';payload=JSON.stringify({id:pair?.id,...body});}
  const response=await fetch('api/recording-phone.php?action='+encodeURIComponent(action),{method:'POST',headers,body:payload,credentials:'omit',cache:'no-store'});
  const data=await response.json().catch(()=>({}));if(!response.ok||!data.ok)throw Error(data.error||`Server error (${response.status})`);return data;
 }
 async function refresh(silent=false){if(loading||uploading)return;loading=true;const existing=$('session-camera-session').value;
  try{if(!pair)throw Error('No approved phone pairing. Return to the controller and scan the desktop QR code.');
   const result=await call('list',{});sessions=result.sessions||[];
   const select=$('session-camera-session');select.replaceChildren();
   for(const s of sessions)select.add(new Option(`${s.title} · ${s.type.replaceAll('_',' ')} · ${s.clips} clips`,s.id));
   if(!sessions.length)select.add(new Option('No active sessions enabled for phones',''));
   select.value=sessions.some(s=>s.id===existing)?existing:sessions[0]?.id||'';
   if(existing&&existing!==select.value)release();
   $('session-camera-badge').textContent=`${sessions.length} available session${sessions.length===1?'':'s'}`;
   if(!silent)note(sessions.length?'Choose Video, Photo, or Audio; then open your camera.':'Ask the host to activate a recording and enable approved phone capture in Admin → Recordings.');
  }catch(e){sessions=[];$('session-camera-session').replaceChildren(new Option('Phone access unavailable',''));$('session-camera-badge').textContent='Disconnected';
   release();note(e.message,true);
  }finally{loading=false;render();}
 }
 function type(){if(mode==='photo')return'';
  const choices=mode==='video'?['video/webm;codecs=vp8,opus','video/webm','video/mp4']:['audio/webm;codecs=opus','audio/webm','audio/mp4','audio/ogg'];
  return choices.find(t=>window.MediaRecorder?.isTypeSupported?.(t))||'';
 }
 function show(file,ms=0){stopStream();clearPreview();media=file;duration=ms;mediaNonce=random();previewUrl=URL.createObjectURL(file);
  const viewer=previewNode();viewer.src=previewUrl;viewer.hidden=false;
  if(mode!=='photo')viewer.onloadedmetadata=()=>{if(media===file&&Number.isFinite(viewer.duration)&&viewer.duration>0)duration=Math.min(3600000,Math.max(duration,Math.round(viewer.duration*1000)));};
  note(`Ready to send ${(file.size/1048576).toFixed(1)} MB to ${session()?.title||'your group'}.`);render();
 }
 async function open(){if(!session()||uploading)return;if(!$('session-camera-consent').checked){note('Confirm recording consent before opening the camera.',true);return;}
  const mark=++generation;stopStream();clearPreview();render();
  if(!navigator.mediaDevices?.getUserMedia){note('A secure HTTPS connection and camera-compatible browser are required. You can still select an existing file.',true);return;}
  try{
   const constraints=mode==='audio'?{audio:true}:{video:{facingMode:{ideal:facing}},audio:mode==='video'};
   try{stream=await navigator.mediaDevices.getUserMedia(constraints);}catch(e){if(mode!=='video')throw e;stream=await navigator.mediaDevices.getUserMedia({video:{facingMode:{ideal:facing}},audio:false});note('Microphone unavailable; recording video without sound.');}
   if(generation!==mark||!session()){stopStream();return;}
   if(mode!=='audio'){$('session-camera-live').srcObject=stream;$('session-camera-live').hidden=false;await $('session-camera-live').play();}
   if(mode==='audio')note('Microphone ready. Tap Record to start audio.');else note('Camera ready. Frame your shot and choose Capture or Record.');
  }catch(e){stopStream();note(`Camera permission unavailable: ${e.message}. You can use the file picker.`,true);}finally{render();}
 }
 function start(){if(!stream||recording()||mode==='photo')return;
  if(!window.MediaRecorder){note('This browser cannot record. Choose an existing file.',true);return;}
  const mark=generation,preferred=type();parts=[];bytes=0;
  try{recorder=new MediaRecorder(stream,preferred?{mimeType:preferred}:undefined);
   recorder.ondataavailable=e=>{if(e.data?.size){parts.push(e.data);bytes+=e.data.size;if(bytes>=MAX_BYTES)stop();}};
   recorder.onerror=()=>{release();note('Recording failed. Please retry.',true);};
   recorder.onstop=()=>{clearInterval(clock);clock=null;if(mark!==generation)return;
    const mime=(recorder?.mimeType||preferred||(mode==='audio'?'audio/webm':'video/webm')).split(';')[0];
    const blob=new Blob(parts,{type:mime});const elapsed=Math.round(performance.now()-started);
    if(!blob.size||blob.size>MAX_BYTES){release();note('The recording was empty or exceeded 80 MB.',true);return;}
    show(new File([blob],`recording-${Date.now()}.${mime.includes('mp4')?'mp4':'webm'}`,{type:mime}),elapsed);
   };
   started=performance.now();recorder.start(700);$('session-camera-clock').textContent='00:00';$('session-camera-live-dot').hidden=false;
   clock=setInterval(()=>{const seconds=Math.floor((performance.now()-started)/1000);
    $('session-camera-clock').textContent=String(Math.floor(seconds/60)).padStart(2,'0')+':'+String(seconds%60).padStart(2,'0');
    if(seconds>=MAX_SECONDS)stop();},250);note('● Recording. Stop when finished (3-minute / 80 MB limit).');
  }catch(e){release();note('Unable to record: '+e.message,true);}render();
 }
 function stop(){if(recording()){recorder.stop();note('Preparing recording for review…');}render();}
 function photo(){if(!stream||mode!=='photo')return;const video=$('session-camera-live'),mark=generation;
  if(!video.videoWidth||!video.videoHeight){note('Camera is not ready.',true);return;}
  const canvas=document.createElement('canvas');canvas.width=video.videoWidth;canvas.height=video.videoHeight;canvas.getContext('2d').drawImage(video,0,0);
  canvas.toBlob(blob=>{if(mark!==generation)return;if(!blob)return note('Unable to capture photo.',true);
   show(new File([blob],`photo-${Date.now()}.jpg`,{type:'image/jpeg'}));},'image/jpeg',.9);
 }
 async function send(){if(!approved()||!media||uploading)return;uploading=true;render();const group=session(),file=media,receipt=mediaNonce;
  try{const data=new FormData();for(const [key,value] of Object.entries({id:pair.id,nonce:receipt,consent:'yes',session:group.id,kind:mode,
    takeId:group.takeId||'',durationMs:String(duration),title:file.name.replace(/\.[^.]+$/,'').slice(0,120)||'Phone recording'}))data.append(key,value);
   data.append('media',file,file.name);const result=await call('upload',data);
   clearPreview();render();note(`✓ Saved ${result.asset.kind} to ${group.title}. It is available in Admin → Recordings.`);
   await refresh(true);
  }catch(e){note(e.message+' Your file remains here; tap Send again to retry.',true);}finally{uploading=false;render();}
 }
 $('session-camera-session').onchange=()=>{release();note(`Selected ${session()?.title||'another recording'}.`);};
 $('session-camera-consent').onchange=()=>{if(!$('session-camera-consent').checked){release();note('Recording consent withdrawn. Camera and microphone stopped.');}else render();};$('session-camera-refresh').onclick=()=>refresh();
 document.querySelectorAll('[data-session-mode]').forEach(b=>b.onclick=()=>{if(recording()||uploading)return;release();mode=b.dataset.sessionMode;render();note(`Ready for ${mode} capture.`);});
 $('session-camera-open').onclick=open;$('session-camera-flip').onclick=()=>{facing=facing==='environment'?'user':'environment';void open();};
 $('session-camera-record').onclick=start;$('session-camera-stop').onclick=stop;$('session-camera-photo').onclick=photo;
 $('session-camera-retake').onclick=()=>{release();note('Previous capture discarded; you can start again.');};
 $('session-camera-send').onclick=send;$('session-camera-file').onchange=e=>{const f=e.target.files?.[0];e.target.value='';if(!f||!session())return;
  const kind=Object.keys(valid).find(k=>valid[k].has(f.type));if(!kind||f.size<1||f.size>MAX_BYTES){note('File must be a supported photo, video, or audio under 80 MB.',true);return;}
  release();mode=kind;show(f);};
 try{const p=JSON.parse(sessionStorage.getItem(KEY)||'null');if(/^[a-f0-9]{32}$/.test(p?.id||'')&&/^[a-f0-9]{64}$/.test(p?.token||''))pair=p;}catch(_){}
 window.addEventListener('pagehide',release);document.addEventListener('visibilitychange',()=>{if(document.hidden&&stream&&!recording())stopStream();});
 render();void refresh();setInterval(()=>{if(!document.hidden)void refresh(true);},5000);
})();
