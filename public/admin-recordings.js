(() => {
 'use strict';
 const $=id=>document.getElementById(id);if(!$('tab-recordings'))return;
 let sessions=[],selected='',details=null,busy=false;
 const random=()=>crypto.randomUUID?.().replaceAll('-','')||Array.from(crypto.getRandomValues(new Uint8Array(16)),b=>b.toString(16).padStart(2,'0')).join('');
 const el=(tag,label='',className='')=>{const x=document.createElement(tag);x.textContent=label;x.className=className;return x;};
 const note=(message,error=false)=>{const n=$('recording-admin-status');n.textContent=message;n.classList.toggle('platform-error',error);};
 const createDialog=$('recording-new-dialog');
 const openCreate=()=>{createDialog.showModal();$('recording-title').focus();};
 const closeCreate=()=>{if(!busy&&createDialog.open)createDialog.close();};
 $('recording-new-open').addEventListener('click',openCreate);
 $('recording-new-close').addEventListener('click',closeCreate);
 $('recording-new-cancel').addEventListener('click',closeCreate);
 createDialog.addEventListener('cancel',e=>{if(busy)e.preventDefault();});
 async function api(action,body=null,query={}){
  const url='api/recording-sessions.php?'+new URLSearchParams({action,...query});const headers={};
  if(body!==null){const me=await Lucky.getMe();headers['Content-Type']='application/json';headers['X-CSRF-Token']=me.csrf||'';}
  const response=await fetch(url,{method:body===null?'GET':'POST',credentials:'same-origin',headers,body:body===null?undefined:JSON.stringify(body),cache:'no-store'});
  const result=await response.json().catch(()=>({}));if(!response.ok||!result.ok)throw Error(result.error||`Request failed (${response.status})`);return result;
 }
 async function refresh(){
  const result=await api('list');sessions=result.sessions||[];paintList();
  if(selected&&sessions.some(s=>s.id===selected))await select(selected);else {selected='';details=null;$('recording-workspace').hidden=true;}
 }
 function paintList(){const root=$('recording-session-list');root.replaceChildren();const type=$('recording-filter-type').value;
  const search=$('recording-search').value.toLowerCase().trim(),status=$('recording-filter-status').value;let found=0;
  const ordered=[...sessions].sort((a,b)=>({active:0,draft:1,completed:2,archived:3}[a.status]??4)-({active:0,draft:1,completed:2,archived:3}[b.status]??4)||String(b.createdAt||'').localeCompare(String(a.createdAt||'')));
  for(const s of ordered){if(type&&s.type!==type)continue;if(status&&s.status!==status)continue;
   const keywords=[s.title,s.type,s.description,s.gameSessionId,s.profile?.location,s.profile?.eventDate,...(s.profile?.tags||[])].filter(Boolean).join(' ').toLowerCase();
   if(search&&!keywords.includes(search))continue;found++;
   const card=el('article','','recording-session-card');
   const choose=el('button','','recording-list-item');choose.type='button';
   choose.setAttribute('aria-pressed',String(selected===s.id));choose.dataset.recordingId=s.id;
   const top=el('span','','recording-card-top');top.append(el('strong',s.title,'recording-card-name'));
   const badge=el('span',s.status,'recording-card-status');badge.dataset.status=s.status;top.append(badge);choose.append(top);
   const category=({live_event:'Live event',production:'Production',general:'Custom',game:'Game'})[s.type]||s.type;
   const when=s.profile?.eventDate||s.createdAt?.slice(0,10)||'';
   choose.append(el('span',[category,when,s.profile?.location].filter(Boolean).join(' · '),'recording-card-meta'));
   if(s.description)choose.append(el('span',s.description,'recording-card-description'));
   const tags=(s.profile?.tags||[]).slice(0,3).map(t=>'#'+t).join(' ');
   if(tags)choose.append(el('span',tags,'recording-card-tags'));
   choose.onclick=()=>void run(async()=>select(s.id));card.append(choose);
   const actions=el('div','','recording-card-actions');
   const editor=el('a','✂ Edit timeline','btn btn-outline');editor.href='recording-editor.php?session='+encodeURIComponent(s.id);actions.append(editor);
   if(['active','draft'].includes(s.status)){const director=el('a','◉ Camera Director','btn btn-primary');director.href='director.php?recording='+encodeURIComponent(s.id);actions.prepend(director);}
   card.append(actions);root.append(card);
  }
  const active=sessions.filter(s=>s.status==='active').length;
  $('recording-library-count').textContent=`${found} of ${sessions.length} session${sessions.length===1?'':'s'} · ${active} active`;
  if(!found){const empty=el('div','','recording-library-empty');empty.append(el('p',sessions.length?'No matching recordings. Change your filters.':'Your recording library is empty. Create your first recording session.'));if(!sessions.length){const create=el('button','＋ New recording','btn btn-primary');create.type='button';create.onclick=openCreate;empty.append(create);}root.append(empty);}
 }
 async function select(id){
  selected=id;const [detail,permission,media]=await Promise.all([api('detail',null,{id}),api('permission_status',null,{id}),api('media_list',null,{id})]);
  if(selected!==id)return;details=detail;$('recording-workspace').hidden=false;
  const s=detail.session;$('recording-selected-title').textContent=s.title;
  let link=$('recording-open-editor');if(!link){link=el('a','✂ Edit multicamera timeline','btn btn-primary');link.id='recording-open-editor';
   link.style.display='inline-flex';link.style.marginBottom='12px';$('recording-selected-details').after(link);}
  link.href='recording-editor.php?session='+encodeURIComponent(s.id);
  let director=$('recording-open-director');if(!director){director=el('a','◉ Open Camera Director','btn btn-outline');director.id='recording-open-director';
   director.style.display='inline-flex';director.style.margin='0 0 12px 10px';link.after(director);}
  director.href='director.php?recording='+encodeURIComponent(s.id);
  director.hidden=s.status==='completed';
  const p=s.profile||{},capture=p.capture||{};
  const location=p.location?' · '+p.location:'';const tags=(p.tags||[]).map(t=>'#'+t).join(' ');
  $('recording-selected-details').textContent=`${s.type.replace('_',' ')} · ${s.status==='completed'?'COMPLETED · no longer available on phone controllers':s.status==='draft'?'DRAFT · activate below to allow approved phones':'ACTIVE · available to approved phones when capture is enabled'} · ${s.gameSessionId?'Game '+s.gameSessionId.slice(0,8):'Independent recording'}${location}${tags?' · '+tags:''} · ${(capture.multicameraEnabled?'Multi':'Single')}-camera (max ${capture.maxCameras||1}) · ${['video','photo','audio'].filter(k=>capture[k]).join('/')}`;
  $('recording-activate').hidden=s.status!=='draft';$('recording-finish').hidden=s.status!=='active';
  $('recording-phone-enabled').checked=permission.phoneEnabled;
  $('recording-phone-enabled').disabled=s.status==='archived'||s.status==='completed'||capture.phoneCameraEnabled===false;
  $('recording-phone-enabled').title=capture.phoneCameraEnabled===false?'Phone capture disabled in this profile. Create a new recording with updated settings.':
    s.status==='completed'?'Completed sessions no longer appear on the phone. Create a new recording for additional footage.':'';
  $('recording-take-start').disabled=s.status!=='active';
  const instructions=[p.brief,...(p.shotList||[]).map((shot,i)=>`${i+1}. ${shot}`)].filter(Boolean).join('\n');
  $('recording-take-name').placeholder=instructions?instructions.slice(0,100):'Take 1 / scene description';
  await refreshDevices(id);
  const takes=$('recording-takes');takes.replaceChildren();
  for(const t of detail.takes){const row=el('div',`Take ${t.take_index} · ${t.title} · ${t.status}`,'recording-take-row');
   if(t.status==='recording'){const stop=el('button','■ Stop take','btn btn-outline');stop.type='button';stop.onclick=()=>run(async()=>{await api('take_stop',{id,takeId:t.id});await refresh();});row.append(stop);}takes.append(row);}
  if(!detail.takes.length)takes.append(el('p','No takes. Start one before coordinating cameras.'));
  const mediaList=$('recording-media');mediaList.replaceChildren();
  for(const asset of media.media||[]){const row=el('article','','recording-media-card');
   const aligned=Number(asset.sync_confidence)>0?` · take offset ${Number(asset.start_offset_ms)>=0?'+':''}${(Number(asset.start_offset_ms)/1000).toFixed(2)}s (approx.)`:'';
   const label=el('div',`${asset.title} · ${asset.kind} · ${(asset.bytes/1048576).toFixed(1)} MB${aligned}`);row.append(label);
   const src='api/recording-media.php?'+new URLSearchParams({id:asset.id});
   if(asset.kind==='photo'){const img=el('img');img.alt=asset.title;img.loading='lazy';img.src=src;row.append(img);}
   else{const viewer=el(asset.kind==='audio'?'audio':'video');viewer.src=src;viewer.controls=true;viewer.preload='none';if(asset.kind==='video')viewer.playsInline=true;row.append(viewer);}
   mediaList.append(row);
  }
  if(!(media.media||[]).length)mediaList.append(el('p','No phone media uploaded yet.'));
  rootMark();
 }
 async function refreshDevices(id=selected){if(!id)return;
  const response=await api('camera_devices',null,{id});if(selected!==id)return;
  const root=$('recording-armed-devices');root.replaceChildren();
  for(const device of response.devices||[]){if(!device.armed)continue;
   const status=device.online?`${device.state} · connected`:'offline / awaiting reconnection';
   const item=el('div',`${device.name||'Paired phone'} · ${device.kind} · ${status}`,'recording-camera-device');
   item.dataset.online=String(device.online);root.append(item);
  }
  if(!root.children.length)root.append(el('p','No phones armed. Open Camera Studio on each approved phone, grant consent and tap Arm for host.'));
 }
 function rootMark(){document.querySelectorAll('.recording-list-item').forEach(button=>button.setAttribute('aria-pressed',String(button.dataset.recordingId===selected)));}
 async function run(fn){if(busy)return;busy=true;try{await fn();note('Recording session updated.');}catch(e){note(e.message,true);}finally{busy=false;}}
 function captureValues(){return {multicameraEnabled:$('recording-multicam').checked,phoneCameraEnabled:$('recording-phone').checked,
  maxCameras:Number($('recording-max').value),countdownSeconds:Number($('recording-countdown').value),
  video:$('recording-video').checked,photo:$('recording-photo').checked,audio:$('recording-audio').checked};}
 $('recording-type').onchange=()=>{const game=$('recording-type').value==='game';$('recording-game-wrap').hidden=!game;
  $('recording-game-policy-note').hidden=!game;document.querySelectorAll('#recording-create-capture input').forEach(input=>input.disabled=game);
 };
 $('recording-multicam').onchange=()=>{$('recording-max').disabled=!$('recording-multicam').checked;};
 $('recording-create').onsubmit=e=>{e.preventDefault();void run(async()=>{
  const body={type:$('recording-type').value,title:$('recording-title').value.trim(),description:$('recording-description').value.trim(),gameSessionId:$('recording-game-id').value.trim(),
   eventDate:$('recording-event-date').value,location:$('recording-location').value.trim(),tags:$('recording-tags').value.split(',').map(s=>s.trim()).filter(Boolean),
   brief:$('recording-brief').value.trim(),shotList:$('recording-shots').value.split('\n').map(s=>s.trim()).filter(Boolean),capture:captureValues(),nonce:random()};
  const result=await api('create',body);$('recording-create').reset();$('recording-type').onchange();$('recording-multicam').onchange();selected=result.session.id;await refresh();createDialog.close();
 });};
 $('recording-search').addEventListener('input',paintList);$('recording-filter-type').addEventListener('change',paintList);$('recording-filter-status').addEventListener('change',paintList);
 $('recording-refresh').onclick=()=>run(refresh);
 $('recording-refresh-devices').onclick=()=>void refreshDevices().catch(e=>note(e.message,true));
 $('recording-activate').onclick=()=>run(async()=>{await api('status',{id:selected,status:'active',revision:details.session.revision});await refresh();});
 $('recording-finish').onclick=()=>run(async()=>{await api('status',{id:selected,status:'completed',revision:details.session.revision});await api('phone_permission',{id:selected,enabled:false});await refresh();});
 $('recording-phone-enabled').onchange=e=>{const enabled=e.target.checked;void run(async()=>{await api('phone_permission',{id:selected,enabled});await select(selected);});};
 $('recording-take-start').onclick=()=>run(async()=>{const id=selected,seconds=details?.session?.profile?.capture?.countdownSeconds||0;
  for(let remaining=seconds;remaining>0;remaining--){note(`Take starts in ${remaining}…`);await new Promise(resolve=>setTimeout(resolve,1000));if(selected!==id||document.hidden)throw Error('Countdown cancelled because recording selection changed or page was hidden.');}
  await api('take_start',{id,title:$('recording-take-name').value.trim()||'Take',nonce:random()});$('recording-take-name').value='';await refresh();});
 document.querySelector('[data-tab="recordings"]')?.addEventListener('click',()=>void run(refresh));
 setInterval(()=>{if(selected&&!document.hidden&&!$('tab-recordings').hidden)void refreshDevices().catch(()=>{});},3000);
})();
