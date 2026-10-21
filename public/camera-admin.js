/* Admin > Cameras: device-local global defaults shared by all game host pages. */
(() => {
 'use strict';
 const $=id=>document.getElementById(id),api=window.LuckyCameraProfiles;
 if(!$('global-camera-detect')||!api)return;
 const root=$('global-camera-list'),note=$('global-camera-status'),previews=new Map();
 let scanning=false,generation=0;
 function summary(message){note.textContent=message;}
 function stop(){generation++;for(const stream of previews.values())stream.getTracks().forEach(t=>t.stop());previews.clear();
  for(const card of root.querySelectorAll('.global-camera-card')){const video=card.querySelector('video'),button=card.querySelector('[data-preview]');if(video){video.pause();video.srcObject=null;}if(button){button.textContent='Preview';button.setAttribute('aria-pressed','false');}}
 }
 async function preview(deviceId,card){const button=card.querySelector('[data-preview]'),video=card.querySelector('video'),state=card.querySelector('[data-camera-status]');
  if(previews.has(deviceId)){previews.get(deviceId).getTracks().forEach(t=>t.stop());previews.delete(deviceId);video.pause();video.srcObject=null;button.textContent='Preview';button.setAttribute('aria-pressed','false');state.textContent='Preview off';return;}
  button.disabled=true;const epoch=generation;state.textContent='Opening private preview…';
  try{const stream=await navigator.mediaDevices.getUserMedia({video:{deviceId:{exact:deviceId}},audio:false});
   if(epoch!==generation){stream.getTracks().forEach(t=>t.stop());return;}
   previews.set(deviceId,stream);video.srcObject=stream;video.play().catch(()=>{});button.textContent='Stop preview';button.setAttribute('aria-pressed','true');state.textContent='Preview active · not recording';
  }catch(e){state.textContent='Preview unavailable';summary('Camera preview unavailable: '+e.message);}finally{button.disabled=false;}
 }
 function updateCount(){const total=root.querySelectorAll('[data-shared-camera]:checked').length;
  $('global-camera-count').textContent=total+' / '+api.LIMIT+' shared';
 }
 async function detect(){if(scanning)return;scanning=true;const button=$('global-camera-detect');button.disabled=true;stop();root.replaceChildren();const epoch=++generation;summary('Finding cameras on this computer…');
  try{
   if(!navigator.mediaDevices?.getUserMedia||!navigator.mediaDevices?.enumerateDevices)throw Error('Camera access requires HTTPS or localhost in a supported browser.');
   const probe=await navigator.mediaDevices.getUserMedia({video:true,audio:false});probe.getTracks().forEach(t=>t.stop());
   if(epoch!==generation)return;
   const cameras=(await navigator.mediaDevices.enumerateDevices()).filter(d=>d.kind==='videoinput'),chosen=new Set(api.shared()),aliases=api.getAliases();
   for(const [index,camera] of cameras.entries()){
    const deviceId=camera.deviceId;if(!deviceId){continue;}
    const card=document.createElement('article');card.className='global-camera-card';card.dataset.cameraId=deviceId;
    const top=document.createElement('div');top.className='global-camera-card-header';const label=document.createElement('label');label.className='global-camera-toggle';
    const enable=document.createElement('input');enable.type='checkbox';enable.dataset.sharedCamera=deviceId;enable.checked=chosen.has(deviceId);
    enable.onchange=()=>{const count=root.querySelectorAll('[data-shared-camera]:checked').length;if(count>api.LIMIT){enable.checked=false;summary(`Select up to ${api.LIMIT} shared recording cameras.`);}updateCount();};
    const title=document.createElement('strong');title.textContent=aliases[deviceId]||camera.label||`Camera ${index+1}`;label.append(enable,title);top.append(label);
    const nameLabel=document.createElement('label');nameLabel.className='global-camera-name';nameLabel.textContent='Display name';
    const nameInput=document.createElement('input');nameInput.type='text';nameInput.maxLength=120;nameInput.value=title.textContent;nameInput.setAttribute('aria-label','Rename '+title.textContent);
    nameInput.onchange=()=>{try{title.textContent=api.alias(deviceId,nameInput.value);nameInput.value=title.textContent;}catch(e){summary(e.message);}};nameLabel.append(nameInput);
    const video=document.createElement('video');video.muted=true;video.autoplay=true;video.playsInline=true;video.setAttribute('aria-label',title.textContent+' private camera preview');
    const state=document.createElement('p');state.dataset.cameraStatus='';state.className='live-camera-item-status';state.textContent='Preview off';
    const action=document.createElement('button');action.type='button';action.dataset.preview='';action.className='btn btn-outline';action.textContent='Preview';action.setAttribute('aria-pressed','false');action.onclick=()=>preview(deviceId,card);
    card.append(top,video,nameLabel,state,action);root.append(card);
   }
   const unavailable=[...chosen].filter(camera=>!cameras.some(d=>d.deviceId===camera));
   updateCount();summary(`${cameras.length} camera${cameras.length===1?'':'s'} detected. Select shared cameras and Save shared setup.`+(unavailable.length?` ${unavailable.length} previously configured camera${unavailable.length===1?' is':'s are'} disconnected.`:''));
  }catch(e){if(epoch===generation)summary('Could not detect cameras: '+e.message);}finally{scanning=false;button.disabled=false;}
 }
 function save(){try{
  if(!root.children.length)throw Error('Detect cameras before saving shared setup.');
  const selected=[...root.querySelectorAll('[data-shared-camera]:checked')].map(x=>x.dataset.sharedCamera);
  api.saveShared(selected);summary(`Saved ${selected.length} shared camera${selected.length===1?'':'s'} for all games in this host browser. Game screens can add their own cameras.`);updateCount();
 }catch(e){summary(e.message);}}
 $('global-camera-detect').addEventListener('click',detect);$('global-camera-save').addEventListener('click',save);
 $('global-camera-count').textContent=api.shared().length+' / '+api.LIMIT+' shared';
 summary(api.shared().length?`${api.shared().length} shared camera${api.shared().length===1?'':'s'} already configured. Detect cameras to preview or change them.`:'No shared cameras configured. Detect cameras to set your defaults.');
 document.addEventListener('visibilitychange',()=>{if(document.visibilityState==='hidden')stop();});window.addEventListener('pagehide',stop);
})();
