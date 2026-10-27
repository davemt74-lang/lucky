/* V5Q.29E4D1: full-screen multicamera workspace with non-destructive edits. */
(() => {
 'use strict';
 const $=id=>document.getElementById(id), params=new URLSearchParams(location.search),sessionId=params.get('session')||'',requestedAsset=params.get('asset')||'';
 let assets=[],revision=0,timeline={offsets:[],switches:[]},session=null,selected='',previewOverride='',timeMs=0,playing=false,lastFrame=0,mediaId='',busy=false,dirty=false,initialized=false;
 let exportBusy=false,exportActive=false,exportTimer=null,exportClosed=false,mediaFilter='all';
 const MAX_MS=86400000, clamp=(n,a,b)=>Math.max(a,Math.min(b,n)),seconds=ms=>(ms/1000).toFixed(2);
 const clock=ms=>{const n=Math.max(0,Math.floor(ms/1000));return `${String(Math.floor(n/60)).padStart(2,'0')}:${String(n%60).padStart(2,'0')}.${Math.floor(ms%1000/100)}`;};
 const el=(tag,text='',className='')=>{const item=document.createElement(tag);item.textContent=text;item.className=className;return item;};
 const notice=(message,bad=false)=>{const item=$('mc-status');item.textContent=message;item.classList.toggle('platform-error',bad);};
 const asset=id=>assets.find(x=>x.id===id);
 const offset=a=>timeline.offsets.find(o=>o.assetId===a.id)?.offsetMs??a.initial_global_offset_ms;
 const length=a=>Math.max(500,Math.min(MAX_MS,a.duration_ms||(a.kind==='photo'?4000:5000)));
 const end=a=>offset(a)+length(a);
 const total=()=>clamp(Math.max(10000,...assets.map(a=>end(a)+2000)),10000,MAX_MS);
 const activeAt=(a,t)=>t>=offset(a)&&t<end(a);
 const canSwitch=a=>a&&['video','photo'].includes(a.kind);
 function openInspector(){const panel=$('mc-inspector');panel.hidden=false;$('mc-inspector-backdrop').hidden=false;
  $('mc-inspector-toggle').setAttribute('aria-expanded','true');inspect();
 }
 function closeInspector(){const panel=$('mc-inspector');panel.hidden=true;$('mc-inspector-backdrop').hidden=true;
  $('mc-inspector-toggle').setAttribute('aria-expanded','false');
 }
 function renderMedia(){const root=$('mc-media-list'),query=$('mc-media-search').value.trim().toLocaleLowerCase();root.replaceChildren();
  const filtered=assets.filter(a=>(mediaFilter==='all'||a.kind===mediaFilter)&&(!query||`${a.title} ${a.source_name||''} ${a.take_title||''}`.toLocaleLowerCase().includes(query)));
  $('mc-library-count').textContent=`${assets.length} clip${assets.length===1?'':'s'}`;
  for(const a of filtered){const button=el('button','','mc-media-item');button.type='button';button.dataset.mediaId=a.id;
   button.setAttribute('aria-pressed',String(a.id===selected));button.setAttribute('aria-label',`Select ${a.title} from ${a.source_name||'recording source'}`);
   button.append(el('span',a.kind==='video'?'▣':a.kind==='photo'?'▧':'♫','mc-media-icon'));
   const detail=el('span','','mc-media-details');detail.append(el('strong',a.title),el('small',`${a.source_name||'Unknown source'} · ${clock(length(a))}`));button.append(detail);
   button.onclick=()=>{selected=a.id;previewOverride=a.id;scrub(Math.max(0,offset(a)));inspect();openInspector();notice('Previewing '+a.title+'. Adjust alignment in the inspector.');};root.append(button);
  }
  if(!filtered.length)root.append(el('p',assets.length?'No media matches this filter.':'No media uploaded to this recording yet.','mc-media-empty'));
 }
 function markDirty(){dirty=true;$('mc-save').textContent='● Save alignment';$('mc-save').disabled=false;$('mc-export-start').disabled=true;}
 async function api(action,body=null){const headers={};if(body!==null){headers['Content-Type']='application/json';headers['X-CSRF-Token']=(await window.Lucky.getMe()).csrf||'';}
  const url='api/recording-timeline.php?'+new URLSearchParams({action,...(body===null?{id:sessionId}:{})});
  const response=await fetch(url,{method:body===null?'GET':'POST',credentials:'same-origin',headers,body:body===null?undefined:JSON.stringify(body),cache:'no-store'});
  const result=await response.json().catch(()=>({}));if(!response.ok||!result.ok)throw Error(result.error||`Server error (${response.status})`);return result;
 }
 function playSource(a){
  for(const node of [$('mc-video'),$('mc-audio')]){if(!node.paused)node.pause();}
  for(const id of ['mc-video','mc-photo','mc-audio']){$(id).hidden=true;}
  $('mc-empty').hidden=!!a;mediaId=a?.id||'';
  if(!a){$('mc-monitor-mark').textContent='NO CAMERA AVAILABLE';return;}
  const node=$(a.kind==='video'?'mc-video':a.kind==='photo'?'mc-photo':'mc-audio');
  node.src='api/recording-media.php?'+new URLSearchParams({id:a.id});node.hidden=false;
  $('mc-monitor-mark').textContent=a.source_name||a.title;
 }
 function displayedAt(){
  const available=assets.filter(a=>canSwitch(a)&&activeAt(a,timeMs));
  const inspectSource=asset(previewOverride);if(inspectSource&&activeAt(inspectSource,timeMs))return inspectSource;
  let wanted='';for(const entry of timeline.switches){if(entry.atMs>timeMs)break;wanted=entry.assetId;}
  return available.find(a=>a.id===wanted)||available[0]||assets.find(a=>a.kind==='audio'&&activeAt(a,timeMs))||null;
 }
 function syncPreview(){const now=displayedAt();if((now?.id||'')!==mediaId)playSource(now);
  if(now&&now.kind!=='photo'){
   const player=$(now.kind==='video'?'mc-video':'mc-audio');const local=clamp((timeMs-offset(now))/1000,0,Math.max(0,(player.duration||Infinity)-.05));
   if(Number.isFinite(local)&&player.readyState>0&&Math.abs(player.currentTime-local)>.35)try{player.currentTime=local;}catch(_){}
   if(playing&&player.paused)player.play().catch(()=>{});
   if(!playing&&!player.paused)player.pause();
  }
  document.querySelectorAll('[data-angle-id]').forEach(node=>node.setAttribute('aria-pressed',String(node.dataset.angleId===now?.id)));
 }
 function scrub(t){timeMs=clamp(Math.round(t),0,total());$('mc-playhead-time').textContent=clock(timeMs);$('mc-scrubber').max=String(total());$('mc-scrubber').value=String(timeMs);
  document.querySelectorAll('.mc-lane-playhead').forEach(x=>x.style.left=(timeMs/total()*100)+'%');syncPreview();renderAngles();}
 function renderAngles(){const container=$('mc-angles');container.replaceChildren();
  for(const a of assets.filter(a=>canSwitch(a)&&activeAt(a,timeMs))){const b=el('button',a.source_name||a.title,'mc-angle-btn');b.type='button';b.dataset.angleId=a.id;
   b.setAttribute('aria-pressed',String(displayedAt()?.id===a.id));b.onclick=()=>{selected=a.id;inspect();openInspector();switchTo(a.id);};container.append(b);}
  if(!container.children.length)container.append(el('p','No camera angle at this playhead. Scrub to footage.'));
 }
 function inspect(){const a=asset(selected);$('mc-inspector-fields').hidden=!a;
  $('mc-selected-title').textContent=a?a.title:'Click an angle clip to adjust its alignment.';
  if(!a)return;
  $('mc-source').textContent=a.source_name||'Unknown camera';$('mc-take').textContent=a.take_title?`Take ${a.take_index}: ${a.take_title}`:'Ungrouped media';
  $('mc-confidence').textContent=Number(a.sync_confidence)===2?'Director clock · timestamp aligned':Number(a.sync_confidence)===1?'Approximate · phone polling':'Unaligned / manually imported';
  $('mc-original').textContent=seconds(a.initial_global_offset_ms)+' s';$('mc-offset').value=seconds(offset(a));
  $('mc-select-angle').disabled=!canSwitch(a)||!activeAt(a,timeMs);
  document.querySelectorAll('.mc-clip').forEach(b=>b.setAttribute('aria-selected',String(b.dataset.assetId===selected)));
  document.querySelectorAll('[data-media-id]').forEach(b=>b.setAttribute('aria-pressed',String(b.dataset.mediaId===selected)));
 }
 function switchTo(id){const a=asset(id);if(!canSwitch(a)||!activeAt(a,timeMs))return notice('Move the playhead into this clip to cut to it.',true);
  previewOverride='';
  const atMs=Math.round(timeMs/100)*100;timeline.switches=timeline.switches.filter(s=>s.atMs!==atMs);
  timeline.switches.push({atMs,assetId:id});timeline.switches.sort((a,b)=>a.atMs-b.atMs);
  markDirty();renderCuts();syncPreview();renderAngles();notice('Angle cut staged at '+clock(atMs)+'. Save alignment to keep it.');
 }
 function renderCuts(){const root=$('mc-cuts-list');root.replaceChildren();for(const entry of timeline.switches){const a=asset(entry.assetId);if(!a)continue;
   const row=el('div','','mc-cut');const go=el('button',`${clock(entry.atMs)} → ${a.source_name||a.title}`,'btn btn-outline');go.type='button';go.onclick=()=>{scrub(entry.atMs);selected=a.id;inspect();};
   const del=el('button','✕','btn btn-outline');del.type='button';del.setAttribute('aria-label','Delete angle cut');del.onclick=()=>{timeline.switches=timeline.switches.filter(s=>s!==entry);markDirty();renderCuts();syncPreview();renderAngles();};row.append(go,del);root.append(row);
  }if(!root.children.length)root.append(el('p','No camera cuts yet. Move the playhead and choose an available angle.'));
 }
 function renderTimeline(){const width=clamp(Math.round(total()/25),1100,5500),duration=total();
  const ruler=$('mc-ruler');ruler.replaceChildren();ruler.style.width=(width-134)+'px';ruler.style.marginLeft='134px';
  for(let i=0;i<=10;i++){const tick=el('span',clock(duration*i/10));tick.style.left=(i*10)+'%';ruler.append(tick);}
  const lanes=$('mc-lanes');lanes.replaceChildren();lanes.style.width=width+'px';
  // A source can record several takes. Keep each take/source pair on its own
  // track while retaining the absolute session-clock offsets for alignment.
  const groups=new Map();for(const a of assets){const key=(a.take_id||'unassigned')+':'+(a.source_id||a.id);if(!groups.has(key))groups.set(key,[]);groups.get(key).push(a);}
  for(const group of groups.values()){
   const first=group[0],take=first.take_index?`Take ${first.take_index} · `:'Unassigned · ';
   const row=el('div','','mc-lane');const label=el('strong',take+(first.source_name||first.title),'mc-lane-name');row.append(label);
   const track=el('div','','mc-lane-track');const playhead=el('span','','mc-lane-playhead');playhead.style.left=clamp(timeMs/duration*100,0,100)+'%';track.append(playhead);
   for(const a of group){const b=el('button',a.title,'mc-clip');b.type='button';b.dataset.assetId=a.id;b.dataset.kind=a.kind;b.title=`${a.title} · ${seconds(offset(a))}s · ${seconds(length(a))}s`;
    b.style.left=clamp(offset(a)/duration*100,0,100)+'%';b.style.width=clamp(length(a)/duration*100,.22,100)+'%';b.setAttribute('aria-selected',String(a.id===selected));
    b.onclick=()=>{selected=a.id;previewOverride=a.id;inspect();openInspector();syncPreview();notice('Previewing '+a.title+'. Adjust its offset in the inspector.');};track.append(b);}
   row.append(track);lanes.append(row);
  }
  if(!assets.length)lanes.append(el('p','No phone uploads in this recording yet.'));
  renderCuts();inspect();renderAngles();scrub(timeMs);
 }
 function setOffset(value){const a=asset(selected),n=Number(value);if(!a||!Number.isFinite(n))return;
  const target=clamp(Math.round(n),-600000,MAX_MS);timeline.offsets=timeline.offsets.filter(o=>o.assetId!==a.id);
  if(target!==a.initial_global_offset_ms)timeline.offsets.push({assetId:a.id,offsetMs:target});
  markDirty();renderTimeline();notice('Adjusted '+a.title+' to '+seconds(target)+'s; original media unchanged.');
 }
 let lastAngleUpdate=0;
 function frame(now){if(!playing)return;const elapsed=clamp(now-lastFrame,0,250);lastFrame=now;
  if(timeMs+elapsed>=total()){playing=false;$('mc-play').textContent='▶ Play';scrub(total());return;}
  timeMs+=elapsed;$('mc-playhead-time').textContent=clock(timeMs);$('mc-scrubber').value=String(Math.round(timeMs));
  document.querySelectorAll('.mc-lane-playhead').forEach(x=>x.style.left=(timeMs/total()*100)+'%');syncPreview();
  if(now-lastAngleUpdate>350){lastAngleUpdate=now;renderAngles();inspect();}
  requestAnimationFrame(frame);
 }
 async function save(){if(busy)return;busy=true;$('mc-save').disabled=true;
  try{const result=await api('save',{id:sessionId,revision,timeline});revision=result.edit.revision;timeline=result.edit.timeline;dirty=false;
   $('mc-save').textContent='✓ Saved';$('mc-export-start').disabled=!assets.length||exportActive;notice(`Timeline saved · revision ${revision}. Original footage remains private and unchanged.`);
  }catch(e){notice(e.message,true);$('mc-save').disabled=false;}finally{busy=false;}
 }
 async function exportApi(action,body=null){
  const headers={};if(body!==null){headers['Content-Type']='application/json';headers['X-CSRF-Token']=(await window.Lucky.getMe()).csrf||'';}
  const url='api/recording-export.php?'+new URLSearchParams({action,...(body===null?{session:sessionId}:{})});
  const response=await fetch(url,{method:body===null?'GET':'POST',credentials:'same-origin',headers,cache:'no-store',body:body===null?undefined:JSON.stringify(body)});
  const data=await response.json().catch(()=>({}));if(!response.ok||!data.ok)throw Error(data.error||`Export request failed (${response.status})`);
  return data;
 }
 function exportNotice(message){$('mc-export-status').textContent=message;}
 function exportAudioOptions(){
  const select=$('mc-export-audio');
  for(const a of assets.filter(a=>a.kind==='audio')){
   const item=document.createElement('option');item.value=a.id;item.textContent=`Master: ${a.source_name||a.title} · ${a.title}`;select.append(item);
  }
 }
 function exportNonce(){
  if(!window.crypto?.getRandomValues)throw Error('A secure browser connection is required for video export.');
  const bytes=new Uint8Array(16);window.crypto.getRandomValues(bytes);return Array.from(bytes,b=>b.toString(16).padStart(2,'0')).join('');
 }
 function renderExports(items){
  const root=$('mc-export-list');root.replaceChildren();
  for(const job of items){
   const row=el('div','','mc-export-row');row.append(el('span',`${job.status.toUpperCase()} · rev ${job.revision} · ${clock(job.durationMs)} · ${new Date(job.createdAt).toLocaleString()}`));
   if(job.status==='ready'&&/^[a-f0-9]{32}$/.test(job.id)){
    const link=el('a','Download MP4');link.href='api/recording-export.php?action=download&id='+job.id;link.setAttribute('download','');row.append(link);
    const play=el('a','Play');play.href='api/recording-export.php?action=play&id='+job.id;play.target='_blank';play.rel='noopener';row.append(play);
   }
   if(job.status==='queued'){
    const cancel=el('button','Cancel queued export','btn btn-outline');cancel.type='button';cancel.onclick=async()=>{
     cancel.disabled=true;try{await exportApi('cancel',{id:job.id});await loadExports();}catch(e){exportNotice(e.message);}
    };row.append(cancel);
   }
   if(job.status==='failed'&&job.error)row.append(el('span',job.error,'platform-error'));
   root.append(row);
  }
  if(!items.length)root.append(el('p','No MP4 exports yet.'));
 }
 async function loadExports(){
  if(!initialized||exportClosed)return;
  try{
   const data=await exportApi('list');renderExports(data.exports||[]);
   const active=(data.exports||[]).some(job=>['queued','running'].includes(job.status));exportActive=active;
   $('mc-export-start').disabled=dirty||exportBusy||active||!assets.length;
   if(active)exportNotice('Export queued or processing. Your sources and saved timeline remain available.');
   else if(!exportBusy)exportNotice('Ready to render a private MP4 from your current saved edits.');
   if(exportTimer)clearTimeout(exportTimer);
   if(active&&!exportClosed)exportTimer=setTimeout(loadExports,3500);
  }catch(e){exportNotice(e.message);}
 }
 async function startExport(){
  if(exportBusy||exportActive||dirty||!assets.length)return;
  exportBusy=true;$('mc-export-start').disabled=true;
  try{
   const selection=$('mc-export-audio').value;
   const audio=selection==='follow'?null:selection==='silent'?'':selection;
   const result=await exportApi('queue',{session:sessionId,revision,masterAudio:audio,nonce:exportNonce()});
   exportNotice(`Export ${result.export.status} · ${result.export.id.slice(0,8)}. The server will render without changing original recordings.`);
   await loadExports();
  }catch(e){exportNotice(e.message);}
  finally{exportBusy=false;$('mc-export-start').disabled=dirty||exportActive||!assets.length;}
 }
 function download(){const report={type:'lucky-multicamera-edl-v1',sessionId,sessionType:session?.type,revision,timeline,media:assets.map(a=>({id:a.id,source:a.source_name,takeId:a.take_id,originalOffsetMs:a.initial_global_offset_ms,alignedOffsetMs:offset(a),durationMs:length(a)}))};
  const url=URL.createObjectURL(new Blob([JSON.stringify(report,null,2)],{type:'application/json'}));const link=document.createElement('a');link.href=url;link.download='multicamera-'+sessionId.slice(0,8)+'.json';link.click();setTimeout(()=>URL.revokeObjectURL(url),1500);
 }
 async function initialize(){if(!/^[a-f0-9]{32}$/.test(sessionId))return notice('Choose a recording from Admin → Recordings.',true);
  try{const r=await api('get');session=r.project.session;revision=r.project.revision;timeline=r.project.timeline||{offsets:[],switches:[]};assets=r.media||[];initialized=true;
   $('mc-session-name').textContent=session.title;$('mc-session-meta').textContent=`${session.type.replaceAll('_',' ')} · ${assets.length} sources · ${session.status}`;
   $('mc-play').disabled=!assets.length;$('mc-edl').disabled=!assets.length;$('mc-scrubber').disabled=!assets.length;
   $('mc-save').disabled=true;$('mc-export-start').disabled=!assets.length;exportAudioOptions();renderTimeline();renderMedia();
   const directorLink=$('mc-director-link');if(directorLink){directorLink.href='director.php?'+new URLSearchParams({recording:sessionId});directorLink.hidden=!['draft','active'].includes(session.status);}
   const requested=asset(requestedAsset);
   if(requested){selected=requested.id;previewOverride=requested.id;scrub(Math.max(0,offset(requested)));renderMedia();openInspector();notice('Opened '+requested.title+' from Camera Director. Align this original or cut to it.');}
   else notice(assets.length?'Ready. Select a clip, nudge alignment and cut between camera angles.':'No recorded media found in this recording.');
   void loadExports();
  }catch(e){notice(e.message,true);}
 }
 $('mc-offset').onchange=e=>setOffset(Number(e.target.value)*1000);
 $('mc-media-search').oninput=renderMedia;
 document.querySelectorAll('[data-media-filter]').forEach(button=>button.onclick=()=>{mediaFilter=button.dataset.mediaFilter;
  document.querySelectorAll('[data-media-filter]').forEach(b=>b.setAttribute('aria-pressed',String(b===button)));renderMedia();});
 $('mc-inspector-toggle').onclick=()=>{$('mc-inspector').hidden?openInspector():closeInspector();};
 $('mc-inspector-close').onclick=closeInspector;$('mc-inspector-backdrop').onclick=closeInspector;
 const exportDialog=$('mc-export-dialog');
 $('mc-export-open').onclick=()=>{exportDialog.showModal();void loadExports();};
 $('mc-export-close').onclick=()=>exportDialog.close();
 exportDialog.addEventListener('close',()=>{$('mc-export-open').focus();});
 document.querySelectorAll('[data-nudge]').forEach(b=>b.onclick=()=>{const a=asset(selected);if(a)setOffset(offset(a)+Number(b.dataset.nudge));});
 $('mc-reset').onclick=()=>{const a=asset(selected);if(a)setOffset(a.initial_global_offset_ms);};
 $('mc-select-angle').onclick=()=>switchTo(selected);
 $('mc-scrubber').oninput=e=>{previewOverride='';scrub(Number(e.target.value));inspect();};
 $('mc-play').onclick=()=>{if(!playing)previewOverride='';playing=!playing;$('mc-play').textContent=playing?'❚❚ Pause':'▶ Play';if(playing){lastFrame=performance.now();syncPreview();requestAnimationFrame(frame);}else syncPreview();};
 document.addEventListener('keydown',e=>{if(e.key==='Escape'&&!exportDialog.open&&!$('mc-inspector').hidden){closeInspector();return;}
  if(e.code!=='Space'||e.repeat||exportDialog.open||e.altKey||e.ctrlKey||e.metaKey)return;
  if(e.target.closest('input,textarea,select,button,a,[contenteditable="true"]')||$('mc-play').disabled)return;
  e.preventDefault();$('mc-play').click();});
 $('mc-save').onclick=save;$('mc-edl').onclick=download;$('mc-export-start').onclick=startExport;
 window.addEventListener('pagehide',()=>{playing=false;exportClosed=true;if(exportTimer)clearTimeout(exportTimer);for(const n of [$('mc-video'),$('mc-audio')])n.pause();});
 void initialize();
})();
