/* HF8C3 Camera Director: Program video, graphics, and governed audio mixing. */
(() => {
 'use strict';
 const root=document.getElementById('camera-director');if(!root)return;
 const $=id=>document.getElementById(id);
 const grid=$('camera-grid'),preview=$('director-preview'),program=$('director-program');
 const selector=$('director-preview-source'),previewCtx=preview.getContext('2d'),programCtx=program.getContext('2d');
 const WIDTH=1280,HEIGHT=720,MAX_BYTES=80*1024*1024;
 const state={preview:'',program:'',transition:null,recording:false,active:false,programGraphics:{text:'',sticker:''},audioMuted:false,audioVolume:100};
 let recorder=null,parts=[],bytes=0,frame=0,lastOptions='',exportStream=null,download=null,exportStarted=0;
 const clips=new Map();let proof=null,clipVersion=0,lastClipLoad=0;
 let audioContext=null,audioOutput=null,hostMicrophone=null,lastAudioCheck=0;
 const audioChannels=new Map();
 const info=(message,error=false)=>{const node=$('director-status');node.textContent=message;node.classList.toggle('is-error',error);};
 function sources(){
  if(!grid)return [];
  const live=[...grid.querySelectorAll(':scope > .camera-source')].map(card=>{
   const id=card.dataset.phonePair?'phone:'+card.dataset.phonePair:card.dataset.cameraId?'camera:'+card.dataset.cameraId:'';
   if(!id)return null;
   return {id,label:card.querySelector('strong')?.textContent?.trim()||'Camera',video:card.querySelector('video')};
  }).filter(entry=>entry?.video);
  return live.concat([...clips.values()].map(({id,label,video})=>({id,label,video})));
 }
 function source(id){return sources().find(s=>s.id===id)||null;}
 const audioInfo=(message,error=false)=>{const label=$('director-audio-status');label.textContent=message;label.classList.toggle('is-error',error);};
 function openAudioMixer(){
  if(!audioContext){
   const Context=window.AudioContext||window.webkitAudioContext;
   if(!Context){audioInfo('This browser cannot mix audio. Video remains available.',true);return false;}
   try{audioContext=new Context();audioOutput=audioContext.createMediaStreamDestination();}
   catch(e){audioContext=null;audioOutput=null;audioInfo('Unable to initialize Program audio: '+e.message,true);return false;}
  }
  if(audioContext.state==='suspended')void audioContext.resume().catch(()=>audioInfo('Tap a Program audio control to enable sound.',true));
  return true;
 }
 function discardChannel(id){
  const channel=audioChannels.get(id);if(!channel)return;
  try{channel.input.disconnect();channel.gain.disconnect();}catch(_){/* already disconnected */}
  if(channel.clipVideo)channel.clipVideo.muted=true;
  audioChannels.delete(id);
 }
 function sourceAudio(id){
  const isDesktop=id.startsWith('camera:');
  if(isDesktop){
   if(!hostMicrophone?.getAudioTracks().some(t=>t.readyState==='live'))return null;
   return {key:'host-mic',stream:hostMicrophone};
  }
  const entry=source(id);if(!entry)return null;
  if(id.startsWith('phone:')){
   const stream=entry.video?.srcObject;
   return stream?.getAudioTracks?.().some(t=>t.readyState==='live')?{key:id,stream}:null;
  }
  if(id.startsWith('clip:'))return {key:id,clipVideo:entry.video};
  return null;
 }
 function ensureChannel(spec){
  if(!audioContext||!spec)return null;
  const current=audioChannels.get(spec.key);
  if(current&&current.stream===spec.stream&&current.clipVideo===spec.clipVideo)return current;
  if(current)discardChannel(spec.key);
  try{
   const input=spec.clipVideo?audioContext.createMediaElementSource(spec.clipVideo):
    audioContext.createMediaStreamSource(new MediaStream(spec.stream.getAudioTracks().filter(t=>t.readyState==='live')));
   const gain=audioContext.createGain();gain.gain.value=0;input.connect(gain);gain.connect(audioOutput);
   const channel={input,gain,stream:spec.stream,clipVideo:spec.clipVideo};audioChannels.set(spec.key,channel);
   // Only the mixed Program destination receives clip sound, never local speakers.
   if(spec.clipVideo)spec.clipVideo.muted=false;
   return channel;
  }catch(e){audioInfo('Audio source unavailable: '+e.message,true);return null;}
 }
 function routeAudio(duration=0){
  if(!audioContext)return;
  const spec=state.active&&state.program?sourceAudio(state.program):null;
  const channel=spec?ensureChannel(spec):null;
  const selectedKey=channel?spec.key:'';
  const at=audioContext.currentTime,seconds=Math.max(0,Math.min(duration,0.7));
  const level=state.audioMuted?0:state.audioVolume/100;
  for(const [id,item] of audioChannels){
   const target=id===selectedKey?level:0,param=item.gain.gain;
   param.cancelScheduledValues(at);param.setValueAtTime(param.value,at);
   if(seconds)param.linearRampToValueAtTime(target,at+seconds);else param.setValueAtTime(target,at);
  }
  if(!state.program)audioInfo('Take a source to Program to route its sound.');
  else if(state.audioMuted||state.audioVolume===0)audioInfo('Program audio muted. Live View and exports receive silence.');
  else if(!channel)audioInfo(state.program.startsWith('camera:')?
   'Desktop camera video is silent. Enable the host microphone to add audio.':'This source has no audio track; Program video remains live.');
  else audioInfo('● Program audio: '+(selectedKey==='host-mic'?'Host microphone':source(state.program)?.label||'Selected source')+' · '+state.audioVolume+'%');
 }
 function programStream(){
  if(!state.active||!state.program||!source(state.program))return null;
  if(openAudioMixer())routeAudio();
  const result=program.captureStream(25);
  // Clone the mixed track: stopping a broadcast/export must not stop the mixer.
  const track=audioOutput?.stream.getAudioTracks()[0];
  if(track?.readyState==='live')result.addTrack(track.clone());
  return result;
 }
 function stopHostMicrophone(){
  discardChannel('host-mic');hostMicrophone?.getTracks().forEach(t=>t.stop());hostMicrophone=null;
  $('director-host-mic').textContent='Enable host microphone';routeAudio();
 }
 async function toggleHostMicrophone(){
  if(hostMicrophone){stopHostMicrophone();return;}
  if(!state.active)return;
  if(!navigator.mediaDevices?.getUserMedia){audioInfo('Microphone permission needs HTTPS and a compatible browser.',true);return;}
  const button=$('director-host-mic');button.disabled=true;
  try{
   const stream=await navigator.mediaDevices.getUserMedia({video:false,audio:{echoCancellation:true,noiseSuppression:true,autoGainControl:true}});
   if(!state.active){stream.getTracks().forEach(t=>t.stop());return;}
   hostMicrophone=stream;button.textContent='Disable host microphone';
   if(openAudioMixer())routeAudio();
   stream.getAudioTracks().forEach(t=>t.addEventListener('ended',()=>{if(hostMicrophone===stream)stopHostMicrophone();},{once:true}));
  }catch(e){audioInfo('Host microphone not enabled: '+e.message,true);}
  finally{button.disabled=!state.active;}
 }
 function resetAudio(){
  for(const id of [...audioChannels.keys()])discardChannel(id);
  hostMicrophone?.getTracks().forEach(t=>t.stop());hostMicrophone=null;
  const old=audioContext;audioContext=null;audioOutput=null;if(old)void old.close().catch(()=>{});
  state.audioMuted=false;state.audioVolume=100;
  $('director-audio-mute').checked=false;$('director-audio-volume').value='100';$('director-audio-volume-label').textContent='100%';
  $('director-host-mic').textContent='Enable host microphone';$('director-host-mic').disabled=!state.active;
  audioInfo('Audio follows the selected Program source. No microphone is enabled.');
 }
 function discardClip(id){
  discardChannel(id);
  const clip=clips.get(id);if(!clip)return;
  clip.video.pause();clip.video.removeAttribute('src');clip.video.load();clips.delete(id);
 }
 function cueClip(id,reset=false){
  const clip=clips.get(id);if(!clip)return;
  if(reset||clip.video.currentTime<clip.start||clip.video.currentTime>=clip.end){
   try{clip.video.currentTime=clip.start;}catch(_){}
  }
  void clip.video.play().catch(()=>info('Select the clip again to allow playback in this browser.',true));
 }
 async function loadClips(force=false){
  const context=proof;if(!context||!state.active)return;
  if(!force&&Date.now()-lastClipLoad<4500)return;
  lastClipLoad=Date.now();const version=clipVersion;
  try{
   const r=await fetch('api/claim-media.php?action=list&session='+encodeURIComponent(context.session),{credentials:'same-origin',cache:'no-store'});
   const data=await r.json();if(!r.ok||!data.ok)throw Error(data.error||'Unable to list saved proof clips.');
   if(version!==clipVersion||!proof||proof.claim!==context.claim)return;
   const allowed=new Set();
   for(const c of (data.clips||[]).filter(c=>c.kind==='video'&&c.claimId===context.claim).slice(0,40)){
    if(!/^[a-f0-9]{32}$/.test(c.id||'')||c.src!=='api/claim-media.php?action=stream&id='+c.id)continue;
    const id='clip:'+c.id;allowed.add(id);
    const start=Math.max(0,Number(c.trimStartMs||0)/1000);
    const requestedEnd=Number(c.trimEndMs||0)/1000;
    const full=Number(c.durationMs||0)/1000;
    const end=requestedEnd>start?requestedEnd:full>start?full:Number.POSITIVE_INFINITY;
    let entry=clips.get(id);
    if(!entry){
     const video=document.createElement('video');video.preload='metadata';video.muted=true;video.playsInline=true;
     video.src=c.src;
     entry={id,label:'CLIP · '+String(c.title||'Untitled').slice(0,80),video,start,end};clips.set(id,entry);
     video.addEventListener('timeupdate',()=>{
      const current=clips.get(id);if(!current||video.paused)return;
      if(video.currentTime>=current.end-.04||video.ended){
       try{video.currentTime=current.start;}catch(_){}void video.play().catch(()=>{});
      }
     });
     video.addEventListener('ended',()=>cueClip(id,true));
    }else{entry.start=start;entry.end=end;entry.label='CLIP · '+String(c.title||'Untitled').slice(0,80);}
   }
   for(const id of [...clips.keys()])if(!allowed.has(id))discardClip(id);
   $('director-clip-count').textContent=String(clips.size);
   lastOptions='';sync();
  }catch(e){if(version===clipVersion&&$('director-clip-status'))$('director-clip-status').textContent='Saved clip list unavailable: '+e.message;}
 }
 function sync(){
  const list=sources(),fingerprint=JSON.stringify(list.map(s=>[s.id,s.label]));
  if(fingerprint===lastOptions)return;
  lastOptions=fingerprint;selector.replaceChildren();
  const prompt=new Option(list.length?'Choose a camera to preview':'Waiting for live camera sources','');selector.add(prompt);
  for(const entry of list)selector.add(new Option(entry.label,entry.id));
  if(!list.some(s=>s.id===state.preview))state.preview='';
  selector.value=state.preview;
  $('director-source-count').textContent=String(list.length);
  if(!list.some(s=>s.id===state.program)&&state.program){
   state.program='';state.transition=null;
   routeAudio();
   info('Program camera disconnected. Select and Take another source.',true);
   window.dispatchEvent(new Event('lucky:director-program-ended'));
  }
  labels();window.dispatchEvent(new Event('lucky:director-sources-updated'));
 }
 function labels(){
  $('director-preview-label').textContent=source(state.preview)?.label||'No camera cued';
  $('director-program-label').textContent=source(state.program)?.label||'Program off';
  $('director-cut').disabled=!state.active||!source(state.preview);
  $('director-fade').disabled=!state.active||!source(state.preview);
  $('director-wipe').disabled=!state.active||!source(state.preview);
  $('director-dip').disabled=!state.active||!source(state.preview);
  $('director-export-start').disabled=!state.program||state.recording;
 }
 function select(id){
  const previous=state.preview;state.preview=source(id)?id:'';selector.value=state.preview;
  if(previous.startsWith('clip:')&&previous!==state.program&&previous!==state.preview)clips.get(previous)?.video.pause();
  if(state.preview.startsWith('clip:'))cueClip(state.preview);
  labels();return !!state.preview;
 }
 function take(kind='cut',at=performance.now()){
  if(!state.active||!source(state.preview))return false;
  if(!['cut','fade','wipe','dip'].includes(kind))return false;
  const previous=state.program;
  if(state.preview.startsWith('clip:'))cueClip(state.preview,true);
  state.transition=kind!=='cut'&&previous&&previous!==state.preview?
   {kind,from:previous,to:state.preview,started:at,duration:kind==='fade'?450:650}:null;
  state.program=state.preview;
  state.programGraphics=readGraphics();
  if(openAudioMixer())routeAudio(kind==='cut'?0:kind==='fade'?.45:.65);
  info(({fade:'Fade',wipe:'Wipe',dip:'Dip to black',cut:'Cut'})[kind]+' to '+source(state.program).label+'. Program output updated.');
  labels();return true;
 }
 function fit(ctx,video,alpha=1){
  if(!video||video.hidden||video.readyState<2||!video.videoWidth||!video.videoHeight)return false;
  const iw=video.videoWidth,ih=video.videoHeight,scale=Math.max(WIDTH/iw,HEIGHT/ih);
  const w=iw*scale,h=ih*scale;ctx.save();ctx.globalAlpha=alpha;
  try{ctx.drawImage(video,(WIDTH-w)/2,(HEIGHT-h)/2,w,h);return true;}
  catch(_){return false;}finally{ctx.restore();}
 }
 function slate(ctx,message){
  ctx.fillStyle='#081323';ctx.fillRect(0,0,WIDTH,HEIGHT);
  ctx.fillStyle='#a7bad6';ctx.textAlign='center';ctx.font='34px system-ui, sans-serif';
  ctx.fillText(message,WIDTH/2,HEIGHT/2);ctx.textAlign='left';
 }
 function renderSource(ctx,id){
  slate(ctx,id?'Waiting for '+(source(id)?.label||'camera'):'Select a source');
  const item=source(id);if(item)fit(ctx,item.video);
 }
 function readGraphics(){return {text:$('director-overlay-text').value.trim().slice(0,100),sticker:$('director-overlay-sticker').value};}
 function graphics(ctx,overlays){
  const caption=overlays.text||'';
  const sticker=overlays.sticker||'';
  if(caption){
   ctx.save();ctx.font='bold 48px system-ui, sans-serif';
   const width=Math.min(WIDTH-120,ctx.measureText(caption).width+64);
   ctx.fillStyle='rgba(4,12,25,.78)';ctx.fillRect(45,HEIGHT-136,width,87);
   ctx.fillStyle='#fff';ctx.textBaseline='middle';ctx.fillText(caption,70,HEIGHT-92,WIDTH-170);ctx.restore();
  }
  if(sticker){ctx.save();ctx.font='110px system-ui, sans-serif';ctx.textAlign='right';ctx.fillText(sticker,WIDTH-55,145);ctx.restore();}
 }
 function paint(at){
  if(!root.isConnected)return;
  // A paired phone can reconnect with a replacement WebRTC MediaStream while
  // remaining the same selected Program tile. Refresh that audio route safely.
  if(audioContext&&at-lastAudioCheck>1100){
   lastAudioCheck=at;
   const id=state.program;
   if(id?.startsWith('phone:')){
    const incoming=sourceAudio(id),current=audioChannels.get(id);
    if((incoming?.stream||null)!==(current?.stream||null))routeAudio();
   }
  }
  renderSource(previewCtx,state.preview);
  if(state.preview)graphics(previewCtx,readGraphics());
  renderSource(programCtx,state.program);
  if(state.transition){
   const t=state.transition,progress=Math.max(0,Math.min(1,(at-t.started)/t.duration));
   if(progress<1){
    slate(programCtx,'Transitioning…');
    const prev=source(t.from),next=source(t.to);
    if(t.kind==='dip'){
     if(progress<.5){if(prev)fit(programCtx,prev.video);programCtx.fillStyle='rgba(0,0,0,'+(progress*2)+')';programCtx.fillRect(0,0,WIDTH,HEIGHT);}
     else{if(next)fit(programCtx,next.video);programCtx.fillStyle='rgba(0,0,0,'+((1-progress)*2)+')';programCtx.fillRect(0,0,WIDTH,HEIGHT);}
    }else{
     if(prev)fit(programCtx,prev.video);
     if(next){if(t.kind==='wipe'){programCtx.save();programCtx.beginPath();programCtx.rect(0,0,WIDTH*progress,HEIGHT);programCtx.clip();fit(programCtx,next.video);programCtx.restore();}
      else fit(programCtx,next.video,progress);}
    }
   }else{state.transition=null;if(t.from.startsWith('clip:')&&t.from!==state.preview)clips.get(t.from)?.video.pause();}
  }
  if(state.program)graphics(programCtx,state.programGraphics);
  frame=requestAnimationFrame(paint);
 }
 const supported=()=>{
  if(!program.captureStream||!window.MediaRecorder)throw Error('Program export requires a browser with canvas recording support.');
  return ['video/webm;codecs=vp9,opus','video/webm;codecs=vp8,opus','video/webm','video/mp4']
   .find(type=>MediaRecorder.isTypeSupported(type))||'';
 };
 function stopExport(){
  if(recorder?.state==='recording')recorder.stop();
  $('director-export-stop').disabled=true;
 }
 function startExport(){
  if(state.recording||!state.program||!state.active)return false;
  try{
   const mime=supported();parts=[];bytes=0;exportStream=programStream();
   if(!exportStream)throw Error('Take a camera to Program before recording.');
   recorder=new MediaRecorder(exportStream,mime?{mimeType:mime}:undefined);
   const recorded=recorder;exportStarted=Date.now();
   recorded.ondataavailable=e=>{if(e.data?.size){parts.push(e.data);bytes+=e.data.size;if(bytes>=MAX_BYTES)stopExport();}};
   recorded.onerror=()=>{info('Program recording failed; choose Record again.',true);stopExport();};
   recorded.onstop=()=>{
    state.recording=false;exportStream?.getTracks().forEach(track=>track.stop());exportStream=null;
    $('director-export-start').disabled=!state.program;
    $('director-export-stop').disabled=true;
    $('director-recording-badge').hidden=true;
    const blob=new Blob(parts,{type:recorded.mimeType||mime||'video/webm'});parts=[];
    if(!blob.size){info('No Program video was captured.',true);return;}
    if(download)URL.revokeObjectURL(download);
    download=URL.createObjectURL(blob);
    const anchor=$('director-export-download');anchor.href=download;
    anchor.download='lucky-program-'+new Date(exportStarted).toISOString().replace(/[:.]/g,'-')+(blob.type.includes('mp4')?'.mp4':'.webm');
    anchor.hidden=false;
    info('Program export ready ('+(blob.size/1048576).toFixed(1)+' MB). The selected Program audio is included when available.');
   };
   recorded.start(1000);state.recording=true;
   $('director-export-start').disabled=true;$('director-export-stop').disabled=false;
   $('director-recording-badge').hidden=false;$('director-export-download').hidden=true;
   info('● Recording Program video, graphics and mixed audio. Camera transitions are included.');
   return true;
  }catch(e){state.recording=false;exportStream?.getTracks().forEach(track=>track.stop());exportStream=null;info(e.message,true);return false;}
 }
 function close(){
  state.active=false;state.preview='';state.program='';state.transition=null;state.programGraphics={text:'',sticker:''};proof=null;clipVersion++;
  $('director-host-mic').disabled=true;
  for(const id of [...clips.keys()])discardClip(id);
  $('director-clip-count').textContent='0';lastOptions='';stopExport();resetAudio();sync();labels();
 }
 selector.addEventListener('change',()=>select(selector.value));
 $('director-cut').addEventListener('click',()=>take());
 $('director-fade').addEventListener('click',()=>take('fade'));
 $('director-wipe').addEventListener('click',()=>take('wipe'));
 $('director-dip').addEventListener('click',()=>take('dip'));
 $('director-refresh-clips').addEventListener('click',()=>void loadClips(true));
 $('director-export-start').addEventListener('click',startExport);
 $('director-export-stop').addEventListener('click',stopExport);
 $('director-audio-volume').addEventListener('input',()=>{
  state.audioVolume=Math.max(0,Math.min(100,Number($('director-audio-volume').value)||0));
  $('director-audio-volume-label').textContent=state.audioVolume+'%';
  if(openAudioMixer())routeAudio(.07);
 });
 $('director-audio-mute').addEventListener('change',()=>{
  state.audioMuted=$('director-audio-mute').checked;
  if(openAudioMixer())routeAudio(.05);
 });
 $('director-host-mic').addEventListener('click',()=>void toggleHostMicrophone());
 $('director-export-download').addEventListener('click',()=>{setTimeout(()=>{if(download){URL.revokeObjectURL(download);download=null;}},30000);});
 window.addEventListener('lucky:proof-open',e=>{
  close();state.active=!!e.detail?.session&&!!e.detail?.claim;
  if(state.active)proof={session:e.detail.session,claim:e.detail.claim};
  $('director-host-mic').disabled=!state.active;
  sync();labels();void loadClips(true);
 });
 window.addEventListener('lucky:proof-close',close);
 window.addEventListener('lucky:proof-review',()=>void loadClips());
 window.addEventListener('lucky:cameras-refreshed',sync);
 const observer=new MutationObserver(sync);if(grid)observer.observe(grid,{subtree:true,childList:true,characterData:true});
 window.addEventListener('pagehide',()=>{close();observer.disconnect();cancelAnimationFrame(frame);if(download)URL.revokeObjectURL(download);});
 preview.width=program.width=WIDTH;preview.height=program.height=HEIGHT;
 sync();labels();frame=requestAnimationFrame(paint);
 window.LuckyCameraDirector={sources:()=>sources().map(({id,label})=>({id,label})),select,take,startExport,stopExport,programStream,
  audioState:()=>({muted:state.audioMuted,volume:state.audioVolume,microphone:!!hostMicrophone,
   source:state.program,activeChannel:state.program.startsWith('camera:')?'host-mic':state.program,
   channels:[...audioChannels.keys()],hasAudioTrack:!!audioOutput?.stream.getAudioTracks().length}),
  state:()=>({preview:state.preview,program:state.program,recording:state.recording,transition:!!state.transition,programGraphics:{...state.programGraphics}})};
})();
