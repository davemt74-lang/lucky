/* V5Q.3 video production editor: non-destructive segments, autosuggested cuts and private MP4 queue. */
(() => {
 'use strict';const $=id=>document.getElementById(id),L=window.Lucky;
 let source=[],clips=[],chosen=null,session='',playToken=0,previewMedia=null,previewTimer=0,dirty=false,mediaFilter='all',cameraSession='',cameraClaim='';
 const inform=(s,bad=false)=>{const n=$('editor-feedback');n.textContent=s;n.classList.toggle('platform-error',bad);};
 const secs=ms=>(Number(ms||0)/1000).toFixed(1),ms=s=>Math.round(Number(s||0)*1000);
 const fresh=()=>window.crypto?.randomUUID?.()||('seg-'+Math.random().toString(36).slice(2));
 const name=c=>`${c.kind.toUpperCase()} · ${c.angleIndex?`View ${c.angleIndex}: `:''}${c.angleLabel||c.sourceLabel||'Imported'} · ${c.prize||'Prize'}`;
 const fields=c=>({id:c.id,instanceId:c.instanceId,title:c.title,trimStartMs:c.trimStartMs,trimEndMs:c.trimEndMs,photoDurationMs:c.photoDurationMs,transition:c.transition||'fade'});
 const cloneSource=(c)=>({...c,instanceId:fresh()});
 const lengthMs=c=>c.kind==='photo'?Number(c.photoDurationMs)||4000:Math.max(500,(Number(c.trimEndMs)||Number(c.durationMs)||5000)-(Number(c.trimStartMs)||0));
 const clock=millis=>{const t=Math.max(0,Math.floor(millis/1000));return `${String(Math.floor(t/60)).padStart(2,'0')}:${String(t%60).padStart(2,'0')}`;};
 function addFromBin(id){const c=source.find(x=>x.id===id);if(!c)return;if(clips.length>=80)return inform('Maximum 80 segments in an edit.',true);const added=cloneSource(c);clips.push(added);dirty=true;render();select(added.instanceId);inform(`${c.title} added to the edit. Save to keep this change.`);}
 function renderBin(){const bin=$('editor-media-bin');if(!bin)return;bin.replaceChildren();const count=$('editor-bin-count');if(count)count.textContent=`${source.length} files`;
  const filtered=source.filter(x=>mediaFilter==='all'||x.kind===mediaFilter);
  if(!filtered.length){const p=document.createElement('p');p.className='studio-bin-help';p.textContent=source.length?'No clips of this type.':'No proof media in this game yet. Capture photos or video while claiming a prize.';bin.append(p);return;}
  for(const c of filtered){const card=document.createElement('article');card.className='studio-media-item';card.draggable=true;card.ondragstart=e=>{e.dataTransfer.setData('application/x-lucky-media-id',c.id);e.dataTransfer.effectAllowed='copy';};
   const icon=document.createElement('span');icon.className='studio-media-icon';icon.textContent=c.kind==='video'?'▣':c.kind==='photo'?'▧':'♫';
   const info=document.createElement('div');info.className='studio-media-info';const title=document.createElement('strong');title.textContent=c.title;const detail=document.createElement('small');detail.textContent=`${c.kind.toUpperCase()} · ${c.sourceLabel||'Uploaded'} · ${clock(lengthMs(c))}`;info.append(title,detail);
   const add=document.createElement('button');add.className='studio-media-add';add.type='button';add.textContent='+ Add to timeline';add.setAttribute('aria-label',`Add ${c.title} to timeline`);add.onclick=()=>addFromBin(c.id);card.append(icon,info,add);bin.append(card);}
 }
 function renderRuler(){const tickRoot=$('editor-ruler'),audio=$('editor-audio-lane');if(!tickRoot||!audio)return;tickRoot.replaceChildren();audio.replaceChildren();const total=clips.reduce((sum,c)=>sum+lengthMs(c),0),runtime=$('editor-runtime');if(runtime)runtime.textContent=`00:00 / ${clock(total)}`;
  let offset=0;const width=Math.max(500,clips.reduce((sum,c)=>sum+Math.max(95,Math.min(360,Math.round(lengthMs(c)/1000*16))),0));const ticks=Math.max(1,Math.ceil(total/30000));for(let i=0;i<=ticks;i++){const tick=document.createElement('span');tick.className='studio-ruler-tick';tick.style.width=`${width/(ticks+1)}px`;tick.textContent=clock(i*30000);tickRoot.append(tick);}
  clips.forEach(c=>{const cell=document.createElement('div');cell.className='studio-audio-cell'+(c.kind==='photo'?' no-audio':'');cell.style.width=`${Math.max(95,Math.min(360,Math.round(lengthMs(c)/1000*16)))}px`;cell.textContent=c.kind==='photo'?'—':(c.kind==='audio'?'♫ Audio clip':'▥ Camera audio');audio.append(cell);});
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
 function stop(){playToken++;clearTimeout(previewTimer);if(previewMedia){previewMedia.pause();previewMedia.src='';previewMedia=null;}$('editor-preview').replaceChildren();$('editor-caption').textContent='Preview stopped.';}
 function select(instanceId){chosen=clips.find(c=>c.instanceId===instanceId)||null;const c=chosen;$('editor-form').hidden=!c;$('editor-selected').textContent=c?name(c):'Select a clip to edit';
  if(c){$('editor-title').value=c.title;$('editor-source').value=c.sourceLabel||'Unspecified';$('editor-trim-start').value=secs(c.trimStartMs);$('editor-trim-end').value=secs(c.trimEndMs);$('editor-photo-seconds').value=secs(c.photoDurationMs);$('editor-photo-seconds').disabled=c.kind!=='photo';$('editor-transition').value=c.transition||'fade';$('editor-split').disabled=c.kind==='photo';}
  document.querySelectorAll('.editor-clip').forEach(el=>el.classList.toggle('is-selected',el.dataset.instanceId===instanceId));
 }
 function reorder(from,to){if(from===to||from<0||to<0||from>=clips.length||to>=clips.length)return;const [part]=clips.splice(from,1);clips.splice(to,0,part);dirty=true;render();}
 function render(){const root=$('editor-timeline');root.replaceChildren();$('editor-count').textContent=`${clips.length} segments`;renderRuler();
  if(!clips.length){root.textContent='Timeline empty. Add proof clips from the media bin or choose Auto cut.';select(null);return;}
  clips.forEach((c,i)=>{
   const row=document.createElement('article');row.className='editor-clip';row.dataset.instanceId=c.instanceId;row.dataset.kind=c.kind;row.style.width=`${Math.max(95,Math.min(360,Math.round(lengthMs(c)/1000*16)))}px`;row.draggable=true;
   row.ondragstart=e=>{e.dataTransfer.setData('text/plain',c.instanceId);e.dataTransfer.effectAllowed='move';};
   row.ondragover=e=>e.preventDefault();row.ondrop=e=>{e.preventDefault();const sourceId=e.dataTransfer.getData('application/x-lucky-media-id');if(sourceId){addFromBin(sourceId);return;}reorder(clips.findIndex(x=>x.instanceId===e.dataTransfer.getData('text/plain')),i);};
   const choose=document.createElement('button');choose.type='button';choose.className='editor-clip-select';choose.onclick=()=>select(c.instanceId);
   const info=document.createElement('span');info.className='mini-label';info.textContent=`${i+1} · ${c.kind.toUpperCase()} · ${clock(lengthMs(c))}`;
   const strong=document.createElement('strong');strong.textContent=c.title;choose.append(info,strong);
   const actions=document.createElement('div');actions.className='editor-clip-controls';
   for(const [text,delta] of [['↑',-1],['↓',1]]){const b=document.createElement('button');b.type='button';b.className='btn btn-outline';b.textContent=text;b.title=delta===-1?'Move earlier':'Move later';b.disabled=i+delta<0||i+delta>=clips.length;b.onclick=()=>reorder(i,i+delta);actions.append(b);}
   const view=document.createElement('button');view.type='button';view.className='btn btn-outline';view.textContent='Preview';view.onclick=()=>preview([c]);actions.append(view);
   row.append(choose,actions);root.append(row);
  });select(chosen?.instanceId||clips[0].instanceId);
 }
 async function load(){stop();window.LuckyCapture?.release();cameraClaim='';cameraSession='';if(!session){source=[];clips=[];renderBin();render();await updateCaptureClaims();return;}
  try {const [a,b]=await Promise.all([media('list'),api('project')]);source=a.clips||[];renderBin();const map=new Map(source.map(c=>[c.id,c]));
   const existing=b.project;clips=Array.isArray(existing)?existing.filter(c=>map.has(c.id)).map(c=>({...map.get(c.id),...c})):source.map(cloneSource);
   dirty=false;render();inform(`${clips.length} segments ready. Changes affect only this montage, not original recordings.`);await loadRenders();await updateCaptureClaims();
  }catch(e){inform(e.message,true);}
 }
 async function auto(){if(dirty&&!confirm('Replace the current timeline with a suggested arrangement?'))return;
  try{const r=await api('auto');const map=new Map(source.map(c=>[c.id,c]));clips=r.ids.map(id=>cloneSource(map.get(id))).filter(c=>c.id);dirty=true;render();inform('Suggested highlight order ready. Save or edit before rendering.');}
  catch(e){inform(e.message,true);}
 }
 async function save(){if(!session)return;try{await api('save_project',{edits:clips.map(fields)},true);dirty=false;inform('Your non-destructive timeline has been saved.');}
  catch(e){inform(e.message,true);}
 }
 function updateSelected(event){event.preventDefault();if(!chosen)return;
  const start=ms($('editor-trim-start').value),end=ms($('editor-trim-end').value),photo=ms($('editor-photo-seconds').value);
  if(!Number.isFinite(start)||!Number.isFinite(end)||start<0||end<0||(end!==0&&end<=start)||photo<1000||photo>20000||chosen.durationMs>0&&(start>=chosen.durationMs||end>chosen.durationMs))return inform('Trim boundaries or photo duration are invalid.',true);
  chosen.title=$('editor-title').value.trim();if(!chosen.title||chosen.title.length>120)return inform('Enter a clip title shorter than 120 characters.',true);
  chosen.trimStartMs=start;chosen.trimEndMs=end;chosen.photoDurationMs=photo;chosen.transition=$('editor-transition').value;dirty=true;render();inform('Clip edits applied. Save timeline to keep them.');
 }
 function split(){if(!chosen||chosen.kind==='photo')return;const pos=ms($('editor-trim-end').value);
  const end=chosen.trimEndMs||chosen.durationMs;
  if(!pos||pos<=chosen.trimStartMs||!end||pos>=end)return inform('Set Trim end to a point inside the clip, then Split.',true);
  if(clips.length>=80)return inform('Maximum of 80 segments per render.',true);
  const second={...chosen,instanceId:fresh(),trimStartMs:pos,trimEndMs:end,title:chosen.title+' · Part 2'};
  chosen.trimEndMs=pos;clips.splice(clips.findIndex(x=>x.instanceId===chosen.instanceId)+1,0,second);dirty=true;render();inform('Clip split into two editable parts. The original file is untouched.');
 }
 function remove(){if(!chosen)return;clips=clips.filter(c=>c.instanceId!==chosen.instanceId);chosen=null;dirty=true;render();inform('Removed from montage; original recording is retained.');}
 async function permanentDelete(){if(!chosen||!confirm('Permanently delete this ORIGINAL recording and all timeline uses?'))return;
  const id=chosen.id;try{await media('delete',{id},true);source=source.filter(c=>c.id!==id);clips=clips.filter(c=>c.id!==id);chosen=null;dirty=true;render();await save();inform('Original recording deleted.');}
  catch(e){inform(e.message,true);}
 }
 const wait=(delay,token)=>new Promise(resolve=>{previewTimer=setTimeout(()=>resolve(token===playToken),delay);});
 async function preview(sequence){stop();if(!sequence.length)return;const token=playToken,stage=$('editor-preview');
  for(let i=0;i<sequence.length&&token===playToken;i++){
   const c=sequence[i];stage.replaceChildren();$('editor-caption').textContent=`${i+1}/${sequence.length}: ${c.title} · ${c.sourceLabel||c.kind}`;
   try{
    if(c.kind==='photo'){const image=new Image();image.alt=c.title;stage.append(image);const loaded=new Promise((resolve,reject)=>{image.onload=resolve;image.onerror=()=>reject(Error('Photo cannot be loaded'));});image.src=c.src;await loaded;await wait(c.photoDurationMs||4000,token);}
    else {const el=document.createElement(c.kind==='audio'?'audio':'video');el.playsInline=true;el.controls=true;el.preload='metadata';stage.append(el);previewMedia=el;
     await new Promise((resolve,reject)=>{el.onloadedmetadata=resolve;el.onerror=()=>reject(Error('Cannot read recording'));el.src=c.src;});
     const start=(c.trimStartMs||0)/1000,end=c.trimEndMs?c.trimEndMs/1000:(Number.isFinite(el.duration)?el.duration:Infinity);
     if(start)el.currentTime=start;
     await el.play().catch(()=>{inform('Select Play in the video player to allow audio.',true);});
     await new Promise(resolve=>{const tick=()=>{if(token!==playToken||el.ended||el.currentTime>=end){el.pause();resolve();return;}previewTimer=setTimeout(tick,150);};tick();});previewMedia=null;
    }
   }catch(e){inform(`${c.title}: ${e.message}`,true);}
  }
  if(token===playToken){$('editor-caption').textContent='Montage preview complete.';inform('Preview complete. Export MP4 to create a single finished movie.');}
 }
 function exportProject(){if(!session||!clips.length)return inform('Nothing to export.',true);
  const project={version:2,session,exportedAt:new Date().toISOString(),clips:clips.map(c=>({...fields(c),claimId:c.claimId,kind:c.kind,source:c.sourceLabel,prize:c.prize,tier:c.tier,src:c.src}))};
  const url=URL.createObjectURL(new Blob([JSON.stringify(project,null,2)],{type:'application/json'}));const link=document.createElement('a');link.href=url;link.download='lucky-game-'+session.slice(0,8)+'.json';document.body.append(link);link.click();link.remove();setTimeout(()=>URL.revokeObjectURL(url),2000);inform('Edit project downloaded.');
 }
 async function queue(){if(!session||!clips.length)return inform('Add at least one clip before exporting.',true);
  const b=$('render-queue');b.disabled=true;
  try{if(dirty)await save();const r=await api('queue',{edits:clips.map(fields),profile:$('render-profile').value,soundtrackId:$('render-track').value,musicVolume:Number($('render-volume').value)},true);
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
  $('editor-play').onclick=()=>preview([...clips]);$('editor-stop').onclick=stop;$('editor-auto').onclick=auto;$('editor-save-project').onclick=save;$('editor-export').onclick=exportProject;
  $('editor-form').onsubmit=updateSelected;$('editor-split').onclick=split;$('editor-remove').onclick=remove;$('editor-delete').onclick=permanentDelete;
  $('render-queue').onclick=queue;$('render-refresh').onclick=loadRenders;
  window.addEventListener('lucky:media-uploaded',receiveCapturedClip);
  $('render-volume').oninput=()=>{$('render-volume-text').textContent=$('render-volume').value+'%';};
  document.querySelectorAll('[data-media-filter]').forEach(b=>b.onclick=()=>{mediaFilter=b.dataset.mediaFilter;document.querySelectorAll('[data-media-filter]').forEach(x=>{x.setAttribute('aria-pressed',String(x===b));x.classList.toggle('is-active',x===b);});renderBin();});
  const lane=$('editor-timeline');if(lane){lane.ondragover=e=>e.preventDefault();lane.addEventListener('drop',e=>{if(e.target.closest('.editor-clip'))return;e.preventDefault();const id=e.dataTransfer.getData('application/x-lucky-media-id');if(id)addFromBin(id);});}
  try{const sessions=await L.api('sessions');const s=$('editor-session');for(const item of sessions.sessions||[])s.add(new Option(`${item.game_id} · ${new Date(item.created_at).toLocaleString()} · ${item.status}`,item.id));
   const requested=new URL(location.href).searchParams.get('session');session=(sessions.sessions||[]).some(x=>x.id===requested)?requested:(sessions.sessions||[])[0]?.id||'';s.value=session;
   document.querySelector('.studio-exit a[href^="game.php"]:not(#editor-session-claims)')?.setAttribute('href','game.php?session='+encodeURIComponent(session));document.querySelector('#editor-session-claims')?.setAttribute('href',session?'claims.php?session='+encodeURIComponent(session):'game.php');
   s.onchange=()=>{if(dirty&&!confirm('Discard unsaved changes?')){s.value=session;return;}session=s.value;document.querySelector('.studio-exit a[href^="game.php"]:not(#editor-session-claims)')?.setAttribute('href','game.php?session='+encodeURIComponent(session));document.querySelector('#editor-session-claims')?.setAttribute('href',session?'claims.php?session='+encodeURIComponent(session):'game.php');load();};
   const tracks=await L.loadTracks();for(const track of tracks.filter(x=>x.kind==='upload'))$('render-track').add(new Option(track.title,track.id));
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
