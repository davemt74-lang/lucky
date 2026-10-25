(() => {
 'use strict';
 const $=id=>document.getElementById(id);if(!$('tab-recordings'))return;
 let sessions=[],selected='',details=null,busy=false;
 const random=()=>crypto.randomUUID?.().replaceAll('-','')||Array.from(crypto.getRandomValues(new Uint8Array(16)),b=>b.toString(16).padStart(2,'0')).join('');
 const el=(tag,label='',className='')=>{const x=document.createElement(tag);x.textContent=label;x.className=className;return x;};
 const note=(message,error=false)=>{const n=$('recording-admin-status');n.textContent=message;n.classList.toggle('platform-error',error);};
 async function api(action,body=null,query={}){
  const url='api/recording-sessions.php?'+new URLSearchParams({action,...query});const headers={};
  if(body!==null){const me=await Lucky.getMe();headers['Content-Type']='application/json';headers['X-CSRF-Token']=me.csrf||'';}
  const response=await fetch(url,{method:body===null?'GET':'POST',credentials:'same-origin',headers,body:body===null?undefined:JSON.stringify(body),cache:'no-store'});
  const result=await response.json().catch(()=>({}));if(!response.ok||!result.ok)throw Error(result.error||`Request failed (${response.status})`);return result;
 }
 async function refresh(){
  const result=await api('list');sessions=result.sessions||[];const root=$('recording-session-list');root.replaceChildren();
  for(const s of sessions){const btn=el('button',`${s.title} · ${s.type.replace('_',' ')} · ${s.status}`,'recording-list-item');btn.type='button';btn.setAttribute('aria-pressed',String(selected===s.id));btn.onclick=()=>select(s.id);root.append(btn);}
  if(!sessions.length)root.append(el('p','No recording groups yet. Create one above.'));
  if(selected&&sessions.some(s=>s.id===selected))await select(selected);else {selected='';details=null;$('recording-workspace').hidden=true;}
 }
 async function select(id){
  selected=id;const [detail,permission,media]=await Promise.all([api('detail',null,{id}),api('permission_status',null,{id}),api('media_list',null,{id})]);
  if(selected!==id)return;details=detail;$('recording-workspace').hidden=false;
  const s=detail.session;$('recording-selected-title').textContent=s.title;
  $('recording-selected-details').textContent=`${s.type.replace('_',' ')} · ${s.status} · ${s.gameSessionId?'Game '+s.gameSessionId.slice(0,8):'Independent recording'}`;
  $('recording-activate').hidden=s.status!=='draft';$('recording-finish').hidden=s.status!=='active';
  $('recording-phone-enabled').checked=permission.phoneEnabled;
  $('recording-phone-enabled').disabled=s.status==='archived'||s.status==='completed';
  $('recording-take-start').disabled=s.status!=='active';
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
 function rootMark(){document.querySelectorAll('.recording-list-item').forEach((button,i)=>button.setAttribute('aria-pressed',String(sessions[i]?.id===selected)));}
 async function run(fn){if(busy)return;busy=true;try{await fn();note('Recording session updated.');}catch(e){note(e.message,true);}finally{busy=false;}}
 $('recording-type').onchange=()=>{$('recording-game-wrap').hidden=$('recording-type').value!=='game';};
 $('recording-create').onsubmit=e=>{e.preventDefault();void run(async()=>{
  const body={type:$('recording-type').value,title:$('recording-title').value.trim(),description:$('recording-description').value.trim(),gameSessionId:$('recording-game-id').value.trim(),nonce:random()};
  const result=await api('create',body);$('recording-create').reset();$('recording-game-wrap').hidden=true;selected=result.session.id;await refresh();
 });};
 $('recording-refresh').onclick=()=>run(refresh);
 $('recording-refresh-devices').onclick=()=>void refreshDevices().catch(e=>note(e.message,true));
 $('recording-activate').onclick=()=>run(async()=>{await api('status',{id:selected,status:'active',revision:details.session.revision});await refresh();});
 $('recording-finish').onclick=()=>run(async()=>{await api('status',{id:selected,status:'completed',revision:details.session.revision});await api('phone_permission',{id:selected,enabled:false});await refresh();});
 $('recording-phone-enabled').onchange=e=>{const enabled=e.target.checked;void run(async()=>{await api('phone_permission',{id:selected,enabled});await select(selected);});};
 $('recording-take-start').onclick=()=>run(async()=>{await api('take_start',{id:selected,title:$('recording-take-name').value.trim()||'Take',nonce:random()});$('recording-take-name').value='';await refresh();});
 document.querySelector('[data-tab="recordings"]')?.addEventListener('click',()=>void run(refresh));
 setInterval(()=>{if(selected&&!document.hidden&&!$('tab-recordings').hidden)void refreshDevices().catch(()=>{});},3000);
})();
