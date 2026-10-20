/* V5Q.3 video production editor: non-destructive segments, autosuggested cuts and private MP4 queue. */
(() => {
 'use strict';const $=id=>document.getElementById(id),L=window.Lucky;
 let source=[],clips=[],chosen=null,session='',playToken=0,previewMedia=null,previewTimer=0,dirty=false,mediaFilter='all',cameraSession='',cameraClaim='';
 let inspectorOpen=false,clipMenuFor=null,recorder=null,recordStream=null,recordBlob=null,recordUrl='',recordTarget=null,overlayPlayers=[];
 let layers={titles:[],background:null},selectedTitle='',availableSongs=[];
 const inform=(s,bad=false)=>{const n=$('editor-feedback');n.textContent=s;n.classList.toggle('platform-error',bad);};
 const secs=ms=>(Number(ms||0)/1000).toFixed(1),ms=s=>Math.round(Number(s||0)*1000);
 const fresh=()=>window.crypto?.randomUUID?.()||('seg-'+Math.random().toString(36).slice(2));
 const looks={none:'none',vivid:'contrast(1.16) saturate(1.35)',warm:'sepia(.18) saturate(1.16) contrast(1.06)',mono:'grayscale(1) contrast(1.12)',cinematic:'contrast(1.22) saturate(.78) brightness(.91)',vintage:'sepia(.48) saturate(.76) contrast(.92)'};
 const templateLooks={clean:'none',highlight:'vivid',classic:'mono',film:'cinematic',retro:'vintage'};
 const applyLook=(c,look)=>{if(!c||!Object.hasOwn(looks,look))return false;c.lookPreset=look;dirty=true;render();select(c.instanceId);return true;};
 const overlayClip=(c,txt,position,size)=>{if(!c||typeof txt!=='string'||txt.length>120||/[\u0000-\u001f]/.test(txt)||!['top','center','bottom'].includes(position)||!['small','medium','large'].includes(size))return false;c.overlayText=txt;c.overlayPosition=position;c.overlaySize=size;dirty=true;render();select(c.instanceId);return true;};
 function currentLookControls(c){$('editor-look').value=Object.hasOwn(looks,c?.lookPreset)?c.lookPreset:'none';$('editor-overlay-text').value=c?.overlayText||'';$('editor-overlay-position').value=c?.overlayPosition||'bottom';$('editor-overlay-size').value=c?.overlaySize||'medium';}
 function decoratePreview(stage,c){const media=stage.querySelector('video,img');if(media)media.style.filter=looks[c.lookPreset]||'none';if(c.overlayText){const node=document.createElement('span');node.className='studio-monitor-caption';node.dataset.position=c.overlayPosition||'bottom';node.dataset.size=c.overlaySize||'medium';node.textContent=c.overlayText;stage.append(node);}}

 const name=c=>`${c.kind.toUpperCase()} · ${c.angleIndex?`View ${c.angleIndex}: `:''}${c.angleLabel||c.sourceLabel||'Imported'} · ${c.prize||'Prize'}`;
 const fields=c=>({id:c.id,instanceId:c.instanceId,title:c.title,trimStartMs:c.trimStartMs,trimEndMs:c.trimEndMs,photoDurationMs:c.photoDurationMs,transition:c.transition||'fade',audioMode:c.audioMode||'normal',detachedOffsetMs:c.detachedOffsetMs||0,voiceoverId:c.voiceoverId||'',voiceoverOffsetMs:c.voiceoverOffsetMs||0,voiceoverMuted:c.voiceoverMuted===true,lookPreset:c.lookPreset||'none',overlayText:c.overlayText||'',overlayPosition:c.overlayPosition||'bottom',overlaySize:c.overlaySize||'medium'});
 const cloneSource=(c)=>({...c,instanceId:fresh(),audioMode:c.audioMode||'normal',detachedOffsetMs:Number(c.detachedOffsetMs||0),voiceoverId:c.voiceoverId||'',voiceoverOffsetMs:Number(c.voiceoverOffsetMs||0),voiceoverMuted:c.voiceoverMuted===true,lookPreset:c.lookPreset||'none',overlayText:c.overlayText||'',overlayPosition:c.overlayPosition||'bottom',overlaySize:c.overlaySize||'medium'});
 const lengthMs=c=>c.kind==='photo'?Number(c.photoDurationMs)||4000:Math.max(500,(Number(c.trimEndMs)||Number(c.durationMs)||5000)-(Number(c.trimStartMs)||0));
 const clock=millis=>{const t=Math.max(0,Math.floor(millis/1000));return `${String(Math.floor(t/60)).padStart(2,'0')}:${String(t%60).padStart(2,'0')}`;};
 function addFromBin(id){const c=source.find(x=>x.id===id);if(!c)return;if(clips.length>=80)return inform('Maximum 80 segments in an edit.',true);const added=cloneSource(c);clips.push(added);dirty=true;render();select(added.instanceId);inform(`${c.title} added to the edit. Save to keep this change.`);}
 function renderBin(){const bin=$('editor-media-bin');if(!bin)return;bin.replaceChildren();const count=$('editor-bin-count');if(count)count.textContent=`${source.length} files`;
  const filtered=source.filter(x=>mediaFilter==='all'||x.kind===mediaFilter);
  if(!filtered.length){const p=document.createElement('p');p.className='studio-bin-help';p.textContent=source.length?'No clips of this type.':'No proof media in this game yet. Capture photos or video while claiming a prize.';bin.append(p);return;}
  for(const c of filtered){const card=document.createElement('article');card.className='studio-media-item';card.draggable=true;
   card.ondragstart=e=>{e.dataTransfer.setData('application/x-lucky-media-id',c.id);e.dataTransfer.effectAllowed='copy';};
   let thumb;
   if(c.kind==='video'){thumb=document.createElement('video');thumb.muted=true;thumb.playsInline=true;thumb.preload='metadata';thumb.src=c.src;
    thumb.addEventListener('loadedmetadata',()=>{if(Number.isFinite(thumb.duration)&&thumb.duration>.4)try{thumb.currentTime=Math.min(.15,thumb.duration/2);}catch(_){}},{once:true});
   }else if(c.kind==='photo'){thumb=document.createElement('img');thumb.src=c.src;thumb.alt='';thumb.loading='lazy';}
   else{thumb=document.createElement('span');thumb.textContent='♫';}
   thumb.className='studio-media-preview';
   const info=document.createElement('div');info.className='studio-media-info';const title=document.createElement('strong');title.textContent=c.title;title.title=c.title;
   const detail=document.createElement('small');detail.textContent=`${c.kind.toUpperCase()} · ${clock(lengthMs(c))}`;info.append(title,detail);
   const add=document.createElement('button');add.className='studio-media-add';add.type='button';add.textContent='+';
   add.setAttribute('aria-label',`Add ${c.title} to timeline`);add.title='Add to timeline';add.onclick=()=>addFromBin(c.id);
   card.append(thumb,info,add);bin.append(card);
  }
 }
 function timelineMs(){return clips.reduce((sum,c)=>sum+lengthMs(c),0);}
 function timelineWidth(){return clips.reduce((sum,c)=>sum+visualWidth(c),0);}
 // Project a global time into the exact V1 clip width, including minimum-size clips.
 function timeToX(ms){
  let elapsed=0,x=0;
  for(const clip of clips){
   const duration=lengthMs(clip),width=visualWidth(clip);
   if(ms<=elapsed+duration)return x+Math.max(0,Math.min(1,(ms-elapsed)/duration))*width;
   elapsed+=duration;x+=width;
  }
  return x;
 }
 function xToTime(x){
  let elapsed=0,px=0;
  for(const clip of clips){
   const width=visualWidth(clip),dur=lengthMs(clip);
   if(x<=px+width)return elapsed+Math.round(Math.max(0,Math.min(1,(x-px)/width))*dur/100)*100;
   elapsed+=dur;px+=width;
  }
  return elapsed;
 }
 function selectTitle(id){
  selectedTitle=id;const title=layers.titles.find(t=>t.id===id);if(!title)return;
  $('timeline-title-text').value=title.text;$('timeline-title-start').value=secs(title.startMs);
  $('timeline-title-duration').value=secs(title.durationMs);$('timeline-title-position').value=title.position;
  $('timeline-title-size').value=title.size;
  document.querySelectorAll('.studio-title-block').forEach(el=>el.classList.toggle('is-selected',el.dataset.titleId===id));
 }
 function commitTitleForm(){
  const title=layers.titles.find(t=>t.id===selectedTitle);if(!title)return;
  const text=$('timeline-title-text').value.trim(),total=timelineMs();
  const start=ms($('timeline-title-start').value),duration=ms($('timeline-title-duration').value);
  if(!text||text.length>120||start<0||duration<300||start+duration>total)
   return inform('Title needs text and a time window contained in the video.',true);
  Object.assign(title,{text,startMs:start,durationMs:duration,
   position:$('timeline-title-position').value,size:$('timeline-title-size').value});
  dirty=true;render();selectTitle(title.id);
 }
 function beginLayerDrag(e,which,item,edge='move'){
  if(e.button!==0||!e.isPrimary)return;
  e.preventDefault();e.stopPropagation();
  const node=e.currentTarget;
  const baseline={start:item.startMs,end:item.endMs,duration:item.durationMs};
  const originX=e.clientX,originTime=xToTime(node.getBoundingClientRect().left-
   node.closest('.studio-layer-track').getBoundingClientRect().left);
  const total=timelineMs();
  let changed=false;
  try{node.setPointerCapture(e.pointerId);}catch(_){}
  const move=ev=>{
   if(ev.pointerId!==e.pointerId)return;
   const relative=originTime+xToTime(Math.max(0,ev.clientX-originX+timeToX(originTime)))-originTime;
   // xToTime is evaluated against the global ruler so short clips stay aligned.
   const delta=relative-originTime;
   if(which==='title'){
    if(edge==='end'){
     item.durationMs=Math.max(300,Math.min(total-baseline.start,baseline.duration+delta));
    }else item.startMs=Math.max(0,Math.min(total-baseline.duration,baseline.start+delta));
   }else if(edge==='end'){
    item.endMs=Math.max(baseline.start+300,Math.min(total,baseline.end+delta));
   }else{
    const len=baseline.end-baseline.start;
    item.startMs=Math.max(0,Math.min(total-len,baseline.start+delta));
    item.endMs=item.startMs+len;
   }
   changed=changed||item.startMs!==baseline.start||item.endMs!==baseline.end||item.durationMs!==baseline.duration;
   node.style.left=timeToX(item.startMs)+'px';
   node.style.width=Math.max(14,timeToX(which==='title'?item.startMs+item.durationMs:item.endMs)-timeToX(item.startMs))+'px';
  };
  const finish=ev=>{if(ev?.pointerId!==undefined&&ev.pointerId!==e.pointerId)return;
   node.removeEventListener('pointermove',move);node.removeEventListener('pointerup',finish);
   node.removeEventListener('pointercancel',cancel);
   if(node.hasPointerCapture?.(e.pointerId))node.releasePointerCapture(e.pointerId);
   if(changed){dirty=true;render();if(which==='title')selectTitle(item.id);}
  };
  const cancel=ev=>{Object.assign(item,baseline);changed=false;finish(ev);};
  node.addEventListener('pointermove',move);node.addEventListener('pointerup',finish);node.addEventListener('pointercancel',cancel);
 }
 function renderLayerLanes(){
  const titleLane=$('editor-titles-lane'),musicLane=$('editor-background-lane');
  if(!titleLane||!musicLane)return;
  titleLane.replaceChildren();musicLane.replaceChildren();
  const width=Math.max(200,timelineWidth());
  titleLane.style.width=width+'px';musicLane.style.width=width+'px';
  for(const title of layers.titles){
   const block=document.createElement('button');block.type='button';block.className='studio-layer-block studio-title-block';
   block.dataset.titleId=title.id;block.setAttribute('aria-label','Move text overlay '+title.text);
   block.textContent=title.text;block.style.left=timeToX(title.startMs)+'px';
   block.style.width=Math.max(14,timeToX(title.startMs+title.durationMs)-timeToX(title.startMs))+'px';
   block.onclick=()=>selectTitle(title.id);
   block.onpointerdown=e=>beginLayerDrag(e,'title',title);
   const edge=document.createElement('span');edge.className='studio-layer-edge';edge.title='Drag to change title end';edge.onpointerdown=e=>{e.stopPropagation();beginLayerDrag(e,'title',title,'end');};
   block.append(edge);titleLane.append(block);
  }
  if(layers.background){
   const music=layers.background,selected=availableSongs.find(x=>x.id===music.trackId);
   const block=document.createElement('button');block.type='button';block.className='studio-layer-block studio-background-block';
   block.textContent='♫ '+(selected?.title||'Soundtrack');block.title='Drag to move background audio';
   block.style.left=timeToX(music.startMs)+'px';
   block.style.width=Math.max(14,timeToX(music.endMs)-timeToX(music.startMs))+'px';
   block.onclick=()=>{$('studio-background-section').open=true;};
   block.onpointerdown=e=>beginLayerDrag(e,'background',music);
   const edge=document.createElement('span');edge.className='studio-layer-edge';edge.title='Drag to change music end';edge.onpointerdown=e=>{e.stopPropagation();beginLayerDrag(e,'background',music,'end');};
   block.append(edge);musicLane.append(block);
   $('timeline-music-select').value=music.trackId;
   $('timeline-music-start').value=secs(music.startMs);$('timeline-music-end').value=secs(music.endMs);
   $('timeline-music-offset').value=secs(music.offsetMs);$('timeline-music-volume').value=music.volume;
   $('timeline-music-volume-value').textContent=music.volume+'%';
  }
 }
 function renderRuler(){const tickRoot=$('editor-ruler'),audio=$('editor-audio-lane');if(!tickRoot||!audio)return;tickRoot.replaceChildren();audio.replaceChildren();const total=clips.reduce((sum,c)=>sum+lengthMs(c),0),runtime=$('editor-runtime');if(runtime)runtime.textContent=`00:00 / ${clock(total)}`;
  let offset=0;const width=Math.max(500,clips.reduce((sum,c)=>sum+Math.max(95,Math.min(360,Math.round(lengthMs(c)/1000*16))),0));const ticks=Math.max(1,Math.ceil(total/30000));for(let i=0;i<=ticks;i++){const tick=document.createElement('span');tick.className='studio-ruler-tick';tick.style.width=`${width/(ticks+1)}px`;tick.textContent=clock(i*30000);tickRoot.append(tick);}
  clips.forEach(c=>{
   const cell=document.createElement('button');cell.type='button';
   cell.className='studio-audio-cell'+(c.kind==='photo'?' no-audio':'')+
    ((c.audioMode==='muted')?' is-muted':'')+(c.audioMode==='detached'?' is-detached':'')+
    (c.voiceoverId?' is-voice':'');
   cell.style.width=`${Math.max(95,Math.min(360,Math.round(lengthMs(c)/1000*16)))}px`;
   cell.textContent=c.voiceoverId?(c.voiceoverMuted?'🔇 Voice muted':'🎙 Voiceover attached'):
    c.audioMode==='detached'?'↔ Detached camera audio':
    c.audioMode==='muted'?'🔇 Muted':c.kind==='photo'?'—':(c.kind==='audio'?'♫ Audio':'▥ Camera audio');
   cell.title='Click to edit audio for '+c.title;cell.onclick=()=>select(c.instanceId,true);audio.append(cell);
  });
 }

 async function api(action,data={},post=false){
  const opts={credentials:'same-origin',cache:'no-store'};let url='api/montage.php?action='+encodeURIComponent(action);
  if(post){const me=await L.getMe();opts.method='POST';opts.headers={'Content-Type':'application/json','X-CSRF-Token':me.csrf};opts.body=JSON.stringify({...data,session});}
  else {url+='&session='+encodeURIComponent(data.session||session);}
  const res=await fetch(url,opts),body=await res.json();if(!res.ok||!body.ok)throw Error(body.error||'Video request failed.');return body;
 }
 async function media(action,data={},post=false){const opts={credentials:'same-origin',cache:'no-store'};let url='api/claim-media.php?action='+encodeURIComponent(action);
  if(post){const me=await L.getMe();opts.method='POST';opts.headers={'Content-Type':'application/json','X-CSRF-Token':me.csrf};opts.body=JSON.stringify({...data,session});}
  else url+='&session='+encodeURIComponent(session);
  const res=await fetch(url,opts),body=await res.json();if(!res.ok||!body.ok)throw Error(body.error||'Clip operation failed.');return body;
 }
 function stop(){playToken++;clearTimeout(previewTimer);for(const item of overlayPlayers){item.pause();item.removeAttribute('src');item.load?.();}overlayPlayers=[];if(previewMedia){previewMedia.pause();previewMedia.removeAttribute('src');previewMedia=null;}$('editor-preview').replaceChildren();$('editor-caption').textContent='Preview stopped.';}
 function select(instanceId,openPanel=false){chosen=clips.find(c=>c.instanceId===instanceId)||null;const c=chosen;$('editor-form').hidden=!c;$('editor-selected').textContent=c?name(c):'Select a clip to edit';
  if(c){$('editor-title').value=c.title;$('editor-source').value=c.sourceLabel||'Unspecified';$('editor-trim-start').value=secs(c.trimStartMs);$('editor-trim-end').value=secs(c.trimEndMs);$('editor-photo-seconds').value=secs(c.photoDurationMs);$('editor-photo-seconds').disabled=c.kind!=='photo';$('editor-transition').value=c.transition||'fade';$('editor-split').disabled=c.kind==='photo';
   $('editor-audio-mode').value=c.audioMode||'normal';$('editor-audio-mode').disabled=c.kind==='photo';
   $('editor-audio-mode').querySelector('option[value="detached"]').disabled=c.kind!=='video';
   $('editor-detached-offset').value=secs(c.detachedOffsetMs||0);$('editor-detached-offset').disabled=c.audioMode!=='detached';
   $('editor-voice-offset').value=secs(c.voiceoverOffsetMs||0);$('editor-voice-offset').disabled=!c.voiceoverId;
   $('editor-voice-mute').checked=c.voiceoverMuted===true;$('editor-voice-mute').disabled=!c.voiceoverId;
   $('editor-record-audio').disabled=c.kind==='audio'||!c.claimId;
  }
  document.querySelectorAll('.editor-clip').forEach(el=>el.classList.toggle('is-selected',el.dataset.instanceId===instanceId));
  currentLookControls(c);
   if(openPanel)setInspector(true);
 }
 function setInspector(open){
  inspectorOpen=!!open;
  const panel=$('clip-inspector'),back=$('editor-inspector-backdrop'),toggle=$('editor-open-inspector');
  panel.classList.toggle('is-open',inspectorOpen);panel.inert=!inspectorOpen;
  panel.setAttribute('aria-hidden',String(!inspectorOpen));back.hidden=!inspectorOpen;
  toggle.setAttribute('aria-expanded',String(inspectorOpen));
  if(inspectorOpen){if(!chosen&&clips.length)select(clips[0].instanceId);$('editor-close-inspector').focus();}
  else toggle.focus();
 }
 function closeContextMenu(){
  const el=$('clip-context-menu');el.hidden=true;el.replaceChildren();clipMenuFor=null;
 }
 function showContextMenu(clip,x,y){
  closeContextMenu();select(clip.instanceId);clipMenuFor=clip.instanceId;
  const menu=$('clip-context-menu');menu.hidden=false;
  const item=(label,fn,disabled=false,danger=false)=>{
   const b=document.createElement('button');b.type='button';b.setAttribute('role','menuitem');
   b.textContent=label;b.disabled=disabled;b.className=danger?'is-danger':'';
   b.onclick=()=>{closeContextMenu();Promise.resolve().then(fn).catch(e=>inform(e.message,true));};
   menu.append(b);
  };
  item('▶ Preview clip',()=>preview([clip]));
  item('☷ Open clip inspector',()=>setInspector(true));
  item(clip.audioMode==='muted'?'♫ Unmute audio':'🔇 Mute audio',()=>{
   clip.audioMode=clip.audioMode==='muted'?'normal':'muted';dirty=true;render();
   inform((clip.audioMode==='muted'?'Muted':'Unmuted')+' original audio. Save the edit to retain this.');
  },clip.kind==='photo');
  item(clip.audioMode==='detached'?'↩ Reattach audio':'↔ Detach audio to A1',()=>{
   clip.audioMode=clip.audioMode==='detached'?'normal':'detached';dirty=true;render();
   inform(clip.audioMode==='detached'?'Audio detached into the editable A1 lane.':'Original audio reattached.');
  },clip.kind!=='video');
  item('● Record audio',()=>openAudioRecorder(clip),clip.kind==='audio'||!clip.claimId);
  if(clip.voiceoverId)item(clip.voiceoverMuted?'🎙 Unmute voiceover':'🔇 Mute voiceover',()=>{
   clip.voiceoverMuted=!clip.voiceoverMuted;dirty=true;render();
  });
  item('✂ Split clip',()=>{select(clip.instanceId,true);inform('Set an Out point in the inspector, then select Split.');},clip.kind==='photo');
  item('↺ Reset trim',()=>{clip.trimStartMs=0;clip.trimEndMs=0;clip.photoDurationMs=4000;dirty=true;render();});
  item('🗑 Delete clip from timeline',()=>{select(clip.instanceId);remove();},false,true);
  const width=menu.offsetWidth||220,height=menu.offsetHeight||330;
  menu.style.left=Math.max(6,Math.min(x,innerWidth-width-8))+'px';
  menu.style.top=Math.max(6,Math.min(y,innerHeight-height-8))+'px';
  menu.querySelector('button')?.focus();
 }
 const timelineScale=16; // pixels per second, shared by timeline clips and ruler.
 const visualWidth=c=>Math.max(95,Math.min(360,Math.round(lengthMs(c)/1000*timelineScale)));
 // Pointer movement must not trigger HTML5 clip reordering or the page's touch scroll.
 // Each clip's trim uses a stable pixels-per-second scale, independent of clipped width.
 function changeTrim(clip,side,deltaMs){
  const photo=clip.kind==='photo',full=photo?20000:Math.round(Number(clip.durationMs||0));
  if(!photo&&full<500)return false;
  const originalStart=Number(clip.trimStartMs||0);
  const originalEnd=photo?Number(clip.photoDurationMs||4000):(Number(clip.trimEndMs)||full);
  const snap=Math.round(deltaMs/100)*100;
  if(photo){
   if(side==='start')return false;
   const next=Math.max(1000,Math.min(20000,originalEnd+snap));
   if(next===originalEnd)return false;
   clip.photoDurationMs=next;return true;
  }
  if(side==='start'){
   const next=Math.max(0,Math.min(originalEnd-500,originalStart+snap));
   if(next===originalStart)return false;
   clip.trimStartMs=next;return true;
  }
  const next=Math.max(originalStart+500,Math.min(full,originalEnd+snap));
  if(next===originalEnd)return false;
  clip.trimEndMs=next>=full?0:next;return true;
 }
 function trimFeedback(clip,row,info){
  const full=clip.kind==='photo'?clip.photoDurationMs:clip.durationMs;
  row.style.width=visualWidth(clip)+'px';
  info.textContent='TRIM '+clock(lengthMs(clip))+' · In '+secs(clip.trimStartMs)+'s · Out '+secs(clip.kind==='photo'?clip.photoDurationMs:(clip.trimEndMs||full))+'s';
  if(chosen?.instanceId===clip.instanceId){
   $('editor-trim-start').value=secs(clip.trimStartMs);
   $('editor-trim-end').value=secs(clip.trimEndMs);
   $('editor-photo-seconds').value=secs(clip.photoDurationMs);
  }
 }
 function commitTrim(clip,message){
  dirty=true;render();select(clip.instanceId);inform(message||'Trim changed: '+clip.title+'. Save edit to retain it.');
 }
 function beginTrim(event,clip,side,row,info){
  if(event.button!==0||!event.isPrimary)return;
  event.preventDefault();event.stopPropagation();closeContextMenu();
  const handle=event.currentTarget,original={
   start:Number(clip.trimStartMs||0),end:Number(clip.trimEndMs||0),
   photo:Number(clip.photoDurationMs||4000)
  };
  const x0=event.clientX,oldDraggable=row.draggable;row.draggable=false;
  row.classList.add('is-trimming');
  let changed=false,finished=false;
  try{handle.setPointerCapture(event.pointerId);}catch(_){}
  const move=e=>{
   if(e.pointerId!==event.pointerId)return;
   const desired=Math.round((e.clientX-x0)/timelineScale*1000/100)*100;
   // Derive from original trim each time: no additive drift or jitter.
   clip.trimStartMs=original.start;clip.trimEndMs=original.end;clip.photoDurationMs=original.photo;
   changeTrim(clip,side,desired);
   changed=clip.trimStartMs!==original.start||clip.trimEndMs!==original.end||clip.photoDurationMs!==original.photo;
   trimFeedback(clip,row,info);
  };
  const cleanup=()=>{
   handle.removeEventListener('pointermove',move);
   handle.removeEventListener('pointerup',finish);
   handle.removeEventListener('pointercancel',cancel);
   handle.removeEventListener('lostpointercapture',finish);
   if(handle.hasPointerCapture?.(event.pointerId))handle.releasePointerCapture(event.pointerId);
   row.draggable=oldDraggable;row.classList.remove('is-trimming');
  };
  const finish=e=>{
   if(finished||e?.pointerId!==undefined&&e.pointerId!==event.pointerId)return;
   finished=true;cleanup();
   if(changed)commitTrim(clip);else render();
  };
  const cancel=e=>{
   if(finished||e?.pointerId!==undefined&&e.pointerId!==event.pointerId)return;
   clip.trimStartMs=original.start;clip.trimEndMs=original.end;clip.photoDurationMs=original.photo;
   changed=false;finish(e);
  };
  handle.addEventListener('pointermove',move);
  handle.addEventListener('pointerup',finish);
  handle.addEventListener('pointercancel',cancel);
  handle.addEventListener('lostpointercapture',finish);
 }
 function keyboardTrim(e,clip,side){
  if(!['ArrowLeft','ArrowRight'].includes(e.key))return;
  e.preventDefault();e.stopPropagation();
  const delta=(e.key==='ArrowRight'?1:-1)*(e.shiftKey?1000:100);
  if(changeTrim(clip,side,delta))commitTrim(clip);
 }

 function reorder(from,to){if(from===to||from<0||to<0||from>=clips.length||to>=clips.length)return;const [part]=clips.splice(from,1);clips.splice(to,0,part);dirty=true;render();}
 function render(){const root=$('editor-timeline');root.replaceChildren();$('editor-count').textContent=`${clips.length} segments`;renderRuler();renderLayerLanes();
  if(!clips.length){root.textContent='Timeline empty. Add clips from the media bin.';select(null);return;}
  clips.forEach((c,i)=>{
   const row=document.createElement('article');row.className='editor-clip'+(c.audioMode==='muted'?' is-muted':'')+(c.audioMode==='detached'?' is-detached':'');row.dataset.instanceId=c.instanceId;row.dataset.kind=c.kind;row.style.width=visualWidth(c)+'px';row.draggable=false;
   row.ondragstart=e=>{if(e.target!==row)e.stopPropagation();else e.preventDefault();};
   row.ondragover=e=>e.preventDefault();row.ondrop=e=>{e.preventDefault();const sourceId=e.dataTransfer.getData('application/x-lucky-media-id');if(sourceId){addFromBin(sourceId);return;}reorder(clips.findIndex(x=>x.instanceId===e.dataTransfer.getData('text/plain')),i);};
   const choose=document.createElement('button');choose.type='button';choose.className='editor-clip-select';choose.onclick=()=>select(c.instanceId,true);
   const info=document.createElement('span');info.className='mini-label';info.textContent=`${i+1} · ${c.kind.toUpperCase()} · ${clock(lengthMs(c))}`;
   const strong=document.createElement('strong');strong.textContent=c.title;choose.append(info,strong);
   const actions=document.createElement('div');actions.className='editor-clip-controls';
   const dragGrip=document.createElement('button');dragGrip.type='button';dragGrip.draggable=true;dragGrip.className='editor-clip-grip';dragGrip.textContent='⠿';dragGrip.title='Drag to reorder clip';dragGrip.setAttribute('aria-label','Drag to reorder '+c.title);
   dragGrip.ondragstart=e=>{e.dataTransfer.setData('text/plain',c.instanceId);e.dataTransfer.effectAllowed='move';};
   actions.append(dragGrip);
   for(const [text,delta] of [['↑',-1],['↓',1]]){const b=document.createElement('button');b.type='button';b.className='btn btn-outline';b.textContent=text;b.title=delta===-1?'Move earlier':'Move later';b.disabled=i+delta<0||i+delta>=clips.length;b.onclick=()=>reorder(i,i+delta);actions.append(b);}
   const view=document.createElement('button');view.type='button';view.className='btn btn-outline';view.textContent='Preview';view.onclick=()=>preview([c]);actions.append(view);
   const more=document.createElement('button');more.type='button';more.className='btn btn-outline editor-clip-more';
   more.textContent='⋯';more.title='Clip actions';more.setAttribute('aria-label','Actions for '+c.title);
   more.onclick=e=>{e.stopPropagation();showContextMenu(c,more.getBoundingClientRect().left,more.getBoundingClientRect().bottom+5);};
   actions.append(more);
   row.addEventListener('contextmenu',e=>{e.preventDefault();showContextMenu(c,e.clientX,e.clientY);});
   for(const side of ['start','end']){
    const handle=document.createElement('button');handle.type='button';handle.className='editor-trim-handle '+side;
    handle.setAttribute('aria-label',side==='start'?'Trim start of '+c.title:'Trim end of '+c.title);
    handle.title=side==='start'?'Drag to set In point · Arrow keys ±0.1s':'Drag to set Out point · Arrow keys ±0.1s';
    handle.textContent=side==='start'?'‹':'›';handle.disabled=(c.kind==='photo'&&side==='start')||(c.kind!=='photo'&&!(c.durationMs>0));
    handle.onpointerdown=e=>beginTrim(e,c,side,row,info);
    handle.onkeydown=e=>keyboardTrim(e,c,side);
    handle.onclick=e=>e.stopPropagation();
    row.append(handle);
   }
   row.append(choose,actions);root.append(row);
  });select(chosen?.instanceId||clips[0].instanceId);
 }
 async function load(){stop();setInspector(false);window.LuckyCapture?.release();cameraClaim='';cameraSession='';if(!session){source=[];clips=[];renderBin();render();await updateCaptureClaims();return;}
  try {const [a,b]=await Promise.all([media('list'),api('project')]);source=a.clips||[];renderBin();layers=b.layers&&Array.isArray(b.layers.titles)?b.layers:{titles:[],background:null};selectedTitle='';const map=new Map(source.map(c=>[c.id,c]));
   const existing=b.project;clips=Array.isArray(existing)?existing.filter(c=>map.has(c.id)).map(c=>({...map.get(c.id),...c})):source.map(cloneSource);
   dirty=false;render();inform(`${clips.length} segments ready. Changes affect only this montage, not original recordings.`);await loadRenders();await updateCaptureClaims();
  }catch(e){inform(e.message,true);}
 }
 async function auto(){if(dirty&&!confirm('Replace the current timeline with a suggested arrangement?'))return;
  try{const r=await api('auto');const map=new Map(source.map(c=>[c.id,c]));clips=r.ids.map(id=>cloneSource(map.get(id))).filter(c=>c.id);dirty=true;render();inform('Suggested highlight order ready. Save or edit before rendering.');}
  catch(e){inform(e.message,true);}
 }
 async function save(){if(!session)return;try{await api('save_project',{edits:clips.map(fields),layers},true);dirty=false;inform('Your non-destructive timeline has been saved.');}
  catch(e){inform(e.message,true);}
 }
 function updateSelected(event){event.preventDefault();if(!chosen)return;
  const start=ms($('editor-trim-start').value),end=ms($('editor-trim-end').value),photo=ms($('editor-photo-seconds').value);
  if(!Number.isFinite(start)||!Number.isFinite(end)||start<0||end<0||(end!==0&&end<=start)||photo<1000||photo>20000||chosen.durationMs>0&&(start>=chosen.durationMs||end>chosen.durationMs))return inform('Trim boundaries or photo duration are invalid.',true);
  chosen.title=$('editor-title').value.trim();if(!chosen.title||chosen.title.length>120)return inform('Enter a clip title shorter than 120 characters.',true);
  chosen.trimStartMs=start;chosen.trimEndMs=end;chosen.photoDurationMs=photo;chosen.transition=$('editor-transition').value;
  chosen.audioMode=$('editor-audio-mode').value;chosen.detachedOffsetMs=ms($('editor-detached-offset').value);
  chosen.voiceoverOffsetMs=ms($('editor-voice-offset').value);chosen.voiceoverMuted=$('editor-voice-mute').checked;
  if([chosen.detachedOffsetMs,chosen.voiceoverOffsetMs].some(n=>!Number.isFinite(n)||n<0||n>120000))return inform('Audio offsets must be between 0 and 120 seconds.',true);
  dirty=true;render();inform('Clip edits applied. Save timeline to keep them.');
 }
 function split(){if(!chosen||chosen.kind==='photo')return;const pos=ms($('editor-trim-end').value);
  const end=chosen.trimEndMs||chosen.durationMs;
  if(!pos||pos<=chosen.trimStartMs||!end||pos>=end)return inform('Set Trim end to a point inside the clip, then Split.',true);
  if(clips.length>=80)return inform('Maximum of 80 segments per render.',true);
  const second={...chosen,instanceId:fresh(),trimStartMs:pos,trimEndMs:end,title:chosen.title+' · Part 2'};
  chosen.trimEndMs=pos;clips.splice(clips.findIndex(x=>x.instanceId===chosen.instanceId)+1,0,second);dirty=true;render();inform('Clip split into two editable parts. The original file is untouched.');
 }
 function remove(){if(!chosen)return;const target=chosen;clips=clips.filter(c=>c.instanceId!==target.instanceId);chosen=null;dirty=true;closeContextMenu();render();inform('Removed '+target.title+' from this edit. The original recording and revisions remain intact.');}
 async function permanentDelete(){if(!chosen||!confirm('Permanently delete this ORIGINAL recording and all timeline uses?'))return;
  const id=chosen.id;try{await media('delete',{id},true);source=source.filter(c=>c.id!==id);clips=clips.filter(c=>c.id!==id);chosen=null;dirty=true;render();await save();inform('Original recording deleted.');}
  catch(e){inform(e.message,true);}
 }
 const wait=(delay,token)=>new Promise(resolve=>{previewTimer=setTimeout(()=>resolve(token===playToken),delay);});
 async function preview(sequence){stop();if(!sequence.length)return;const token=playToken,stage=$('editor-preview');
  const bg=layers.background,libraryTrack=availableSongs.find(x=>x.id===bg?.trackId);
  let music=null;
  if(bg&&libraryTrack){music=new Audio(libraryTrack.src);music.preload='auto';music.volume=bg.volume/100;music.loop=true;overlayPlayers.push(music);}
  const updateMusic=position=>{
   if(!music||!bg)return;
   const enabled=position>=bg.startMs&&position<bg.endMs;
   if(!enabled){music.pause();return;}
   const target=bg.offsetMs/1000+(position-bg.startMs)/1000;
   if(music.readyState>0&&Math.abs(music.currentTime-target)>0.35)try{music.currentTime=target;}catch(_){}
   if(music.paused)music.play().catch(()=>{});
  };
  const showTitles=(position)=>{
   stage.querySelectorAll('.studio-timeline-preview-title').forEach(n=>n.remove());
   for(const title of layers.titles){
    if(position<title.startMs||position>=title.startMs+title.durationMs)continue;
    const span=document.createElement('span');span.className='studio-monitor-caption studio-timeline-preview-title';
    span.textContent=title.text;span.dataset.position=title.position;span.dataset.size=title.size;
    stage.append(span);
   }
  };
  for(let i=0;i<sequence.length&&token===playToken;i++){
   const c=sequence[i];stage.replaceChildren();$('editor-caption').textContent=`${i+1}/${sequence.length}: ${c.title} · ${c.sourceLabel||c.kind}`;
   const index=clips.findIndex(x=>x.instanceId===c.instanceId);
   const startGlobal=clips.slice(0,Math.max(0,index)).reduce((sum,x)=>sum+lengthMs(x),0);
   showTitles(startGlobal);updateMusic(startGlobal);
   try{
    if(c.kind==='photo'){const image=new Image();image.alt=c.title;stage.append(image);const loaded=new Promise((resolve,reject)=>{image.onload=resolve;image.onerror=()=>reject(Error('Photo cannot be loaded'));});image.src=c.src;await loaded;decoratePreview(stage,c);
     const startTime=performance.now();const clockTitles=setInterval(()=>{const t=startGlobal+performance.now()-startTime;showTitles(t);updateMusic(t);},150);
     try{await wait(c.photoDurationMs||4000,token);}finally{clearInterval(clockTitles);}}
    else {const el=document.createElement(c.kind==='audio'?'audio':'video');el.playsInline=true;el.controls=true;el.preload='metadata';stage.append(el);previewMedia=el;
     await new Promise((resolve,reject)=>{el.onloadedmetadata=resolve;el.onerror=()=>reject(Error('Cannot read recording'));el.src=c.src;});
     decoratePreview(stage,c);
      const start=(c.trimStartMs||0)/1000,end=c.trimEndMs?c.trimEndMs/1000:(Number.isFinite(el.duration)?el.duration:Infinity);
     if(start)el.currentTime=start;
     const mode=c.audioMode||'normal';
     el.muted=mode!=='normal';
     const tracks=[];
     if(mode==='detached'&&c.kind==='video')
      tracks.push({src:c.src,offset:Number(c.detachedOffsetMs||0)/1000,startAt:start});
     if(c.voiceoverId&&!c.voiceoverMuted)
      tracks.push({src:'api/claim-media.php?action=stream&id='+encodeURIComponent(c.voiceoverId),
       offset:Number(c.voiceoverOffsetMs||0)/1000,startAt:0});
     for(const track of tracks){
      const audio=new Audio(track.src);audio.preload='auto';overlayPlayers.push(audio);
      const sync=()=>{
       const elapsed=Math.max(0,el.currentTime-start)-track.offset;
       if(elapsed<0||el.paused||el.ended){audio.pause();return;}
       const desired=track.startAt+elapsed;
       if(audio.readyState>0&&Math.abs((audio.currentTime||0)-desired)>0.3){
        try{audio.currentTime=desired;}catch(_){}
       }
       if(audio.paused)audio.play().catch(()=>{});
      };
      el.addEventListener('timeupdate',sync);el.addEventListener('playing',sync);
      el.addEventListener('pause',()=>audio.pause());
     }
     el.addEventListener('timeupdate',()=>{const absolute=startGlobal+Math.max(0,el.currentTime-start)*1000;showTitles(absolute);updateMusic(absolute);});
     await el.play().catch(()=>{inform('Select Play in the video player to allow audio.',true);});
     await new Promise(resolve=>{const tick=()=>{if(token!==playToken||el.ended||el.currentTime>=end){el.pause();resolve();return;}previewTimer=setTimeout(tick,150);};tick();});previewMedia=null;
    }
   }catch(e){inform(`${c.title}: ${e.message}`,true);}
  }
  if(music)music.pause();
  if(token===playToken){$('editor-caption').textContent='Montage preview complete.';inform('Preview complete. Export MP4 to create a single finished movie.');}
 }
 function exportProject(){if(!session||!clips.length)return inform('Nothing to export.',true);
  const project={version:3,session,exportedAt:new Date().toISOString(),layers,clips:clips.map(c=>({...fields(c),claimId:c.claimId,kind:c.kind,source:c.sourceLabel,prize:c.prize,tier:c.tier,src:c.src}))};
  const url=URL.createObjectURL(new Blob([JSON.stringify(project,null,2)],{type:'application/json'}));const link=document.createElement('a');link.href=url;link.download='lucky-game-'+session.slice(0,8)+'.json';document.body.append(link);link.click();link.remove();setTimeout(()=>URL.revokeObjectURL(url),2000);inform('Edit project downloaded.');
 }
 async function queue(){if(!session||!clips.length)return inform('Add at least one clip before exporting.',true);
  const b=$('render-queue');b.disabled=true;
  try{if(dirty)await save();const r=await api('queue',{edits:clips.map(fields),layers,profile:$('render-profile').value,soundtrackId:layers.background?.trackId||'',musicVolume:layers.background?.volume||0},true);
   inform(`Video queued (${r.metrics.clipCount} clips). Run the server's PHP CLI renderer to process it.`);await loadRenders();}
  catch(e){inform(e.message,true);}finally{b.disabled=false;}
 }
 async function loadRenders(){if(!session)return;try{const response=await api('list');const root=$('render-jobs');root.replaceChildren();
   for(const job of response.renders){const row=document.createElement('div');row.className='render-job';const info=document.createElement('span');info.textContent=`${new Date(job.createdAt).toLocaleString()} · ${job.profile} · ${job.status}`;row.append(info);
    if(job.play){const watch=document.createElement('button');watch.type='button';watch.className='btn btn-outline';watch.textContent='Watch MP4';watch.onclick=()=>{stop();const v=document.createElement('video');v.controls=true;v.playsInline=true;v.src=job.play;v.preload='metadata';$('editor-preview').replaceChildren(v);$('editor-caption').textContent='Rendered movie preview';v.play().catch(()=>inform('Tap the video play button to watch.',true));};row.append(watch);}
    if(job.download){const a=document.createElement('a');a.href=job.download;a.className='btn btn-primary';a.textContent='Download MP4';row.append(a);}
    if(job.error){const e=document.createElement('span');e.className='platform-error';e.textContent=job.error;row.append(e);}
    if(job.status==='queued'){const cancel=document.createElement('button');cancel.type='button';cancel.className='btn btn-outline';cancel.textContent='Cancel';cancel.onclick=async()=>{try{await api('cancel',{id:job.id},true);await loadRenders();}catch(e){inform(e.message,true);}};row.append(cancel);}
    root.append(row);
   }
   if(!response.renders.length)root.textContent='No exports yet.';
  }catch(e){inform(e.message,true);}
 }

 function audioStatus(text){$('editor-audio-status').textContent=text;}
 function disposeRecordedAudio(){
  if(recorder&&recorder.state!=='inactive')recorder.stop();
  recordStream?.getTracks().forEach(t=>t.stop());recordStream=null;recorder=null;
  if(recordUrl){URL.revokeObjectURL(recordUrl);recordUrl='';}
  recordBlob=null;$('editor-audio-preview').hidden=true;
  $('editor-audio-preview').removeAttribute('src');
  $('editor-audio-save').disabled=true;$('editor-audio-stop').disabled=true;
  $('editor-audio-start').disabled=false;
 }
 function openAudioRecorder(clip){
  if(!clip||clip.kind==='audio'||!clip.claimId)return inform('Select a claimed prize video or photo to record audio.',true);
  if(!navigator.mediaDevices?.getUserMedia||typeof MediaRecorder==='undefined')
   return inform('Audio recording requires a browser with microphone access on HTTPS or localhost.',true);
  disposeRecordedAudio();recordTarget={instanceId:clip.instanceId,claimId:clip.claimId,session};
  audioStatus('Press Start recording, speak into the microphone, then Stop. Review and Save to attach audio to this clip.');
  $('editor-audio-dialog').showModal();
 }
 async function startAudioRecording(){
  try{
   recordStream=await navigator.mediaDevices.getUserMedia({audio:true,video:false});
   const codecs=['audio/webm;codecs=opus','audio/webm','audio/mp4'];
   const mime=codecs.find(v=>MediaRecorder.isTypeSupported?.(v))||'';
   recorder=new MediaRecorder(recordStream,mime?{mimeType:mime}:{});
   const chunks=[];const recording=recorder;const type=mime||recording.mimeType||'audio/webm';
   recording.ondataavailable=e=>{if(e.data?.size)chunks.push(e.data);};
   recording.onerror=()=>audioStatus('Microphone recording failed. Check browser permissions.');
   recording.onstop=()=>{
    recordStream?.getTracks().forEach(t=>t.stop());recordStream=null;
    recordBlob=new Blob(chunks,{type});$('editor-audio-start').disabled=false;
    $('editor-audio-stop').disabled=true;
    if(!recordBlob.size){audioStatus('Recording was empty. Try again.');return;}
    recordUrl=URL.createObjectURL(recordBlob);
    const player=$('editor-audio-preview');player.src=recordUrl;player.hidden=false;
    $('editor-audio-save').disabled=false;
    audioStatus('Recording ready. Listen back and select Save to attach it to this clip.');
   };
   recording.start(250);audioStatus('● Recording audio…');
   $('editor-audio-start').disabled=true;$('editor-audio-stop').disabled=false;
  }catch(e){recordStream?.getTracks().forEach(t=>t.stop());recordStream=null;audioStatus('Microphone unavailable: '+e.message);}
 }
 async function saveRecordedAudio(){
  if(!recordBlob||!recordTarget)return;
  if(session!==recordTarget.session)return audioStatus('Game changed. Discard this recording and try again.');
  const target=clips.find(c=>c.instanceId===recordTarget.instanceId);
  if(!target||target.claimId!==recordTarget.claimId)return audioStatus('Selected prize changed. Recording not attached.');
  const button=$('editor-audio-save');button.disabled=true;
  audioStatus('Saving audio to this prize…');
  try{
   const me=await L.getMe();const form=new FormData();
   form.append('session',session);form.append('claim',recordTarget.claimId);form.append('kind','audio');
   const extension=recordBlob.type.startsWith('audio/mp4')?'m4a':'webm';
   form.append('media',recordBlob,'voiceover.'+extension);
   form.append('sourceLabel','Studio microphone');
   form.append('title','Voiceover · '+target.title);
   form.append('durationMs',String(Math.round(($('editor-audio-preview').duration||0)*1000)||0));
   const res=await fetch('api/claim-media.php?action=upload',{
    method:'POST',credentials:'same-origin',headers:{'X-CSRF-Token':me.csrf},body:form
   });
   const body=await res.json();if(!res.ok||!body.ok)throw Error(body.error||'Audio upload failed.');
   if(!body.clip||body.clip.kind!=='audio'||body.clip.claimId!==target.claimId)
    throw Error('Server returned an unexpected audio clip.');
   target.voiceoverId=body.clip.id;target.voiceoverMuted=false;target.voiceoverOffsetMs=0;
   const listing=await media('list');source=listing.clips||[];renderBin();
   dirty=true;render();audioStatus('Audio attached. Save the edit to retain this voiceover.');
   $('editor-audio-dialog').close();disposeRecordedAudio();
   inform('Microphone audio attached to '+target.title+'. Save the edit to keep the change.');
  }catch(e){audioStatus('Audio save failed: '+e.message);button.disabled=false;}
 }
 async function updateCaptureClaims(){
  const picker=$('studio-proof-claim');if(!picker)return;
  picker.replaceChildren(new Option('Select a claimed prize',''));
  $('camera-enable').disabled=true;
  window.LuckyCapture?.show('','','Prize',[]);
  if(!session)return;
  try{
   const data=await L.api('session',{query:{id:session}});
   const claims=data.session?.claims||[];
   for(const c of claims)picker.add(new Option(`${c.name} · ${c.tier.toUpperCase()}`,c.id));
   const apply=()=>{
    if(window.LuckyCapture?.isBusy?.()){inform('Stop recording before switching prize claims.',true);picker.value=cameraClaim;return;}
    cameraClaim=picker.value;cameraSession=session;
    const claim=data.session.claims.find(c=>c.id===cameraClaim);
    const profile=claim&&data.session.pools?.[claim.tier]?.prizes?.find(p=>p.id===claim.prizeId);
    window.LuckyCapture?.show(session,cameraClaim,claim?.name||'Prize',profile?.proofAngles||[]);
    $('camera-enable').disabled=!claim;
    $('camera-ready-message').textContent=claim?`Ready to preview cameras for ${claim.name}.`:'Select a claimed prize to enable live camera previews.';
   };
   picker.onchange=apply;
   $('camera-ready-message').textContent=claims.length?'Select a claimed prize to capture footage.':'This game has no claims yet; prize proof is attached to existing claims.';
  }catch(e){inform('Cannot load claimed prizes for camera capture: '+e.message,true);}
 }
 async function receiveCapturedClip(e){
  const detail=e.detail;if(!detail||detail.session!==session)return;
  try{const response=await media('list');const current=response.clips||[],known=new Set(source.map(c=>c.id));
   const additions=current.filter(c=>!known.has(c.id));source=current;renderBin();
   // The server already committed a claim-aware timeline update atomically with the upload.
   // Load the persisted positions and replacements; never duplicate a clip on the client.
   if(additions.length){
    if(dirty)inform('New proof footage saved in the server timeline. Save or discard your unsaved edits before reloading.',true);
    else{
     const project=await api('project');const map=new Map(source.map(c=>[c.id,c]));
     clips=Array.isArray(project.project)?project.project.filter(c=>map.has(c.id)).map(c=>({...map.get(c.id),...c})):source.map(cloneSource);
     render();inform(`${additions.length} proof clip${additions.length===1?'':'s'} synchronized in its claimed-prize timeline position.`);
    }
   }
  }catch(err){inform('Clip saved; refresh media bin to import it: '+err.message,true);}
 }
 async function init(){
  $('timeline-add-title').onclick=()=>{
   const total=timelineMs(),text=$('timeline-title-text').value.trim();
   if(!total||!text||text.length>120)return inform('Add video footage and enter a title first.',true);
   const start=ms($('timeline-title-start').value);
   const duration=ms($('timeline-title-duration').value);
   if(!Number.isFinite(start)||!Number.isFinite(duration)||start<0||start>=total||duration<300||start+duration>total)
    return inform('Title start/duration must fit within the movie.',true);
   if(layers.titles.length>=24)return inform('Maximum 24 text overlays.',true);
   const item={id:fresh(),text,startMs:start,durationMs:duration,
    position:$('timeline-title-position').value,size:$('timeline-title-size').value};
   layers.titles.push(item);dirty=true;render();selectTitle(item.id);inform('Text added to T1. Save edit to retain it.');
  };
  $('timeline-delete-title').onclick=()=>{
   if(!selectedTitle)return inform('Click a T1 title first.',true);
   layers.titles=layers.titles.filter(x=>x.id!==selectedTitle);selectedTitle='';
   dirty=true;render();inform('Removed T1 title without changing original footage.');
  };
  for(const id of ['timeline-title-text','timeline-title-start','timeline-title-duration','timeline-title-position','timeline-title-size']){
   $(id).addEventListener('change',commitTitleForm);
  }
  $('timeline-apply-music').onclick=()=>{
   const id=$('timeline-music-select').value,total=timelineMs();
   if(!total||!availableSongs.some(t=>t.id===id))return inform('Choose an uploaded audio-library song and add video first.',true);
   const start=ms($('timeline-music-start').value),end=ms($('timeline-music-end').value)||total,
    offset=ms($('timeline-music-offset').value),volume=Number($('timeline-music-volume').value);
   if([start,end,offset,volume].some(x=>!Number.isFinite(x))||start<0||end>total||end<=start||offset<0||offset>1200000)
    return inform('The soundtrack start/end must fit within this movie.',true);
   layers.background={trackId:id,startMs:start,endMs:end,offsetMs:offset,volume};
   $('render-track').value=id;$('render-volume').value=String(volume);
   dirty=true;render();inform('B1 background audio attached. Save edit to retain it.');
  };
  $('timeline-clear-music').onclick=()=>{layers.background=null;dirty=true;render();inform('Removed B1 soundtrack without deleting audio-library file.');};
  $('timeline-music-volume').oninput=()=>{$('timeline-music-volume-value').textContent=$('timeline-music-volume').value+'%';};
  $('render-track').addEventListener('change',()=>{
   $('timeline-music-select').value=$('render-track').value;
   if($('render-track').value&&timelineMs()){
    const total=timelineMs();
    layers.background={trackId:$('render-track').value,startMs:0,endMs:total,offsetMs:0,volume:Number($('render-volume').value)};
    dirty=true;render();
   }
  });
  $('editor-play').onclick=()=>preview([...clips]);$('editor-stop').onclick=stop;$('editor-save-project').onclick=save;$('editor-export').onclick=exportProject;
  $('editor-apply-look').onclick=()=>{if(!applyLook(chosen,$('editor-look').value))inform('Select a timeline clip first.',true);};
  $('editor-apply-overlay').onclick=()=>{if(!overlayClip(chosen,$('editor-overlay-text').value,$('editor-overlay-position').value,$('editor-overlay-size').value))inform('Select a clip and enter a title of at most 120 characters.',true);};
  $('editor-clear-overlay').onclick=()=>{if(!chosen)return inform('Select a timeline clip first.',true);overlayClip(chosen,'','bottom','medium');};
  $('editor-apply-template').onclick=()=>{if(!applyLook(chosen,templateLooks[$('editor-template').value]))inform('Select a timeline clip first.',true);};
  $('editor-template-all').onclick=()=>{const look=templateLooks[$('editor-template').value];if(!clips.length||!look)return inform('Add a clip to apply a template.',true);clips.forEach(c=>c.lookPreset=look);dirty=true;render();inform('Starter template applied to all clips. Save edit to retain it.');};
  $('editor-open-inspector').onclick=()=>setInspector(!inspectorOpen);
  $('editor-close-inspector').onclick=()=>setInspector(false);
  $('editor-inspector-backdrop').onclick=()=>setInspector(false);
  $('editor-audio-mode').onchange=()=>{if(chosen){$('editor-detached-offset').disabled=$('editor-audio-mode').value!=='detached';}};
  $('editor-record-audio').onclick=()=>{if(chosen)openAudioRecorder(chosen);};
  $('editor-audio-start').onclick=startAudioRecording;
  $('editor-audio-stop').onclick=()=>{if(recorder?.state==='recording')recorder.stop();};
  $('editor-audio-save').onclick=saveRecordedAudio;
  $('editor-audio-discard').onclick=()=>{disposeRecordedAudio();$('editor-audio-dialog').close();};
  $('editor-audio-close').onclick=()=>{disposeRecordedAudio();$('editor-audio-dialog').close();};
  $('editor-audio-dialog').addEventListener('cancel',()=>disposeRecordedAudio());
  document.addEventListener('keydown',e=>{if(e.key==='Escape'){closeContextMenu();if(inspectorOpen)setInspector(false);}});
  document.addEventListener('pointerdown',e=>{if(!e.target.closest('#clip-context-menu')&&!e.target.closest('.editor-clip-more'))closeContextMenu();});
  const exportDialog=$('render-dialog');
  $('open-render-dialog').onclick=()=>{if(!exportDialog.open)exportDialog.showModal();loadRenders();};
  $('close-render-dialog').onclick=()=>exportDialog.close();
  exportDialog.addEventListener('click',e=>{if(e.target===exportDialog)exportDialog.close();});
  $('editor-fullscreen').onclick=()=>{
   const player=$('editor-preview');
   if(document.fullscreenElement)document.exitFullscreen?.().catch(()=>{});
   else player.requestFullscreen?.().catch(()=>inform('Fullscreen preview is unavailable in this browser.',true));
  };
  $('editor-form').onsubmit=updateSelected;$('editor-split').onclick=split;$('editor-remove').onclick=remove;$('editor-delete').onclick=permanentDelete;
  $('render-queue').onclick=queue;$('render-refresh').onclick=loadRenders;
  window.addEventListener('lucky:media-uploaded',receiveCapturedClip);
  $('render-volume').oninput=()=>{$('render-volume-text').textContent=$('render-volume').value+'%';};
  document.querySelectorAll('[data-media-filter]').forEach(b=>b.onclick=()=>{mediaFilter=b.dataset.mediaFilter;document.querySelectorAll('[data-media-filter]').forEach(x=>{x.setAttribute('aria-pressed',String(x===b));x.classList.toggle('is-active',x===b);});renderBin();});
  const lane=$('editor-timeline');if(lane){lane.ondragover=e=>e.preventDefault();lane.addEventListener('drop',e=>{if(e.target.closest('.editor-clip'))return;e.preventDefault();const id=e.dataTransfer.getData('application/x-lucky-media-id');if(id)addFromBin(id);});}
  try{const sessions=await L.api('sessions');const s=$('editor-session');for(const item of sessions.sessions||[])s.add(new Option(`${item.game_id} · ${new Date(item.created_at).toLocaleString()} · ${item.status}`,item.id));
   const requested=new URL(location.href).searchParams.get('session');session=(sessions.sessions||[]).some(x=>x.id===requested)?requested:(sessions.sessions||[])[0]?.id||'';s.value=session;
   $('editor-back-game').href='game.php?session='+encodeURIComponent(session);
   s.onchange=()=>{if(dirty&&!confirm('Discard unsaved changes?')){s.value=session;return;}session=s.value;$('editor-back-game').href='game.php?session='+encodeURIComponent(session);load();};
   const tracks=await L.loadTracks();availableSongs=tracks.filter(x=>x.kind==='upload');for(const track of availableSongs){$('render-track').add(new Option(track.title,track.id));$('timeline-music-select').add(new Option(track.title,track.id));}
   await load();
  }catch(e){inform(e.message,true);}
 }
 window.addEventListener('beforeunload',event=>{if(!dirty)return;event.preventDefault();event.returnValue='';});
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
 // The same approved phone D-pad navigates the editor when this page is the host screen.
 // Do not interpret D-pad input as audio playback, spin, or montage destruction.
 window.LuckyHostRemote=async command=>{
   if(['up','down','left','right','select','back','done','menu'].includes(command)){
     return window.LuckyConsole?.emit(command==='done'?'back':command)??false;
   }
   return false;
 };
 window.LuckyEditor={preview,stop};
})();
