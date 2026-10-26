/* HF8A Camera Director: local program mix, cue, cut, fade and lossless source separation. */
(() => {
 'use strict';
 const root=document.getElementById('camera-director');if(!root)return;
 const $=id=>document.getElementById(id);
 const grid=$('camera-grid'),preview=$('director-preview'),program=$('director-program');
 const selector=$('director-preview-source'),previewCtx=preview.getContext('2d'),programCtx=program.getContext('2d');
 const WIDTH=1280,HEIGHT=720,MAX_BYTES=80*1024*1024;
 const state={preview:'',program:'',transition:null,recording:false,active:false};
 let recorder=null,parts=[],bytes=0,frame=0,lastOptions='',exportStream=null,download=null,exportStarted=0;
 const info=(message,error=false)=>{const node=$('director-status');node.textContent=message;node.classList.toggle('is-error',error);};
 function sources(){
  if(!grid)return [];
  return [...grid.querySelectorAll(':scope > .camera-source')].map(card=>{
   const id=card.dataset.phonePair?'phone:'+card.dataset.phonePair:card.dataset.cameraId?'camera:'+card.dataset.cameraId:'';
   if(!id)return null;
   return {id,label:card.querySelector('strong')?.textContent?.trim()||'Camera',video:card.querySelector('video')};
  }).filter(entry=>entry?.video);
 }
 function source(id){return sources().find(s=>s.id===id)||null;}
 function sync(){
  const list=sources(),fingerprint=JSON.stringify(list.map(s=>[s.id,s.label]));
  if(fingerprint===lastOptions)return;
  lastOptions=fingerprint;selector.replaceChildren();
  const prompt=new Option(list.length?'Choose a camera to preview':'Waiting for live camera sources','');selector.add(prompt);
  for(const entry of list)selector.add(new Option(entry.label,entry.id));
  if(!list.some(s=>s.id===state.preview))state.preview='';
  selector.value=state.preview;
  $('director-source-count').textContent=String(list.length);
  $('director-cut').disabled=!state.preview;
  $('director-fade').disabled=!state.preview;
  if(!list.some(s=>s.id===state.program)&&state.program){
   state.program='';state.transition=null;
   info('Program camera disconnected. Select and Take another source.',true);
  }
  labels();
 }
 function labels(){
  $('director-preview-label').textContent=source(state.preview)?.label||'No camera cued';
  $('director-program-label').textContent=source(state.program)?.label||'Program off';
  $('director-cut').disabled=!state.active||!source(state.preview);
  $('director-fade').disabled=!state.active||!source(state.preview);
  $('director-export-start').disabled=!state.program||state.recording;
 }
 function select(id){state.preview=source(id)?id:'';selector.value=state.preview;labels();return !!state.preview;}
 function take(kind='cut',at=performance.now()){
  if(!state.active||!source(state.preview))return false;
  if(kind!=='cut'&&kind!=='fade')return false;
  const previous=state.program;
  state.transition=kind==='fade'&&previous&&previous!==state.preview?
   {from:previous,to:state.preview,started:at,duration:450}:null;
  state.program=state.preview;
  info((kind==='fade'?'Fade':'Cut')+' to '+source(state.program).label+'. Program output updated.');
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
 function graphics(ctx){
  const caption=$('director-overlay-text').value.trim().slice(0,100);
  const sticker=$('director-overlay-sticker').value;
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
  renderSource(previewCtx,state.preview);
  renderSource(programCtx,state.program);
  if(state.transition){
   const t=state.transition,progress=Math.max(0,Math.min(1,(at-t.started)/t.duration));
   if(progress<1){
    slate(programCtx,'Transitioning…');
    const prev=source(t.from),next=source(t.to);
    if(prev)fit(programCtx,prev.video,1);
    if(next)fit(programCtx,next.video,progress);
   }else state.transition=null;
  }
  if(state.program)graphics(programCtx);
  frame=requestAnimationFrame(paint);
 }
 const supported=()=>{
  if(!program.captureStream||!window.MediaRecorder)throw Error('Program export requires a browser with canvas recording support.');
  return ['video/webm;codecs=vp9','video/webm;codecs=vp8','video/webm','video/mp4']
   .find(type=>MediaRecorder.isTypeSupported(type))||'';
 };
 function stopExport(){
  if(recorder?.state==='recording')recorder.stop();
  $('director-export-stop').disabled=true;
 }
 function startExport(){
  if(state.recording||!state.program||!state.active)return false;
  try{
   const mime=supported();parts=[];bytes=0;exportStream=program.captureStream(25);
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
    info('Program export ready ('+(blob.size/1048576).toFixed(1)+' MB). Download this video; audio mixing and publishing follow in later sections.');
   };
   recorded.start(1000);state.recording=true;
   $('director-export-start').disabled=true;$('director-export-stop').disabled=false;
   $('director-recording-badge').hidden=false;$('director-export-download').hidden=true;
   info('● Recording the Program output. Camera cuts, fades and graphics are included.');
   return true;
  }catch(e){state.recording=false;exportStream?.getTracks().forEach(track=>track.stop());exportStream=null;info(e.message,true);return false;}
 }
 function close(){state.active=false;state.preview='';state.program='';state.transition=null;stopExport();sync();labels();}
 selector.addEventListener('change',()=>select(selector.value));
 $('director-cut').addEventListener('click',()=>take());
 $('director-fade').addEventListener('click',()=>take('fade'));
 $('director-export-start').addEventListener('click',startExport);
 $('director-export-stop').addEventListener('click',stopExport);
 $('director-export-download').addEventListener('click',()=>{setTimeout(()=>{if(download){URL.revokeObjectURL(download);download=null;}},30000);});
 window.addEventListener('lucky:proof-open',e=>{state.active=!!e.detail?.session&&!!e.detail?.claim;sync();labels();});
 window.addEventListener('lucky:proof-close',close);
 window.addEventListener('lucky:cameras-refreshed',sync);
 const observer=new MutationObserver(sync);if(grid)observer.observe(grid,{subtree:true,childList:true,characterData:true});
 window.addEventListener('pagehide',()=>{close();observer.disconnect();cancelAnimationFrame(frame);if(download)URL.revokeObjectURL(download);});
 preview.width=program.width=WIDTH;preview.height=program.height=HEIGHT;
 sync();labels();frame=requestAnimationFrame(paint);
 window.LuckyCameraDirector={sources:()=>sources().map(({id,label})=>({id,label})),select,take,startExport,stopExport,
  state:()=>({preview:state.preview,program:state.program,recording:state.recording,transition:!!state.transition})};
})();
