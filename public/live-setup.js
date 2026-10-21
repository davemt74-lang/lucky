/* V5Q.25: one Detect Cameras action, then individual camera cards. Camera access is opt-in. */
(() => {
 'use strict';
 const $=id=>document.getElementById(id);
 if(!$('live-camera-detect'))return;
 const previews=new Map(),cameraPrefs=window.LuckyCameraProfiles,gameId='spin-wheel';
 let scanning=false,generation=0;
 const note=text=>{$('live-camera-status').textContent=text;};
 function off(){
  generation++;
  for(const stream of previews.values())stream.getTracks().forEach(t=>t.stop());
  previews.clear();
  for(const card of $('live-camera-list').querySelectorAll('.live-camera-item')){
   const video=card.querySelector('video'),button=card.querySelector('[data-preview-action]'),state=card.querySelector('[data-camera-state]');
   if(video){video.pause();video.srcObject=null;}
   if(button){button.textContent='Preview';button.setAttribute('aria-pressed','false');}
   if(state)state.textContent='Preview off';
  }
 }
 function saved(){return cameraPrefs?new Set(cameraPrefs.selected(gameId)):new Set();}
 function save(input){if(!cameraPrefs)return;try{cameraPrefs.setGameCamera(gameId,input.dataset.liveCamera,input.checked);note('Camera selection saved for Spin the Wheel. Shared defaults remain unchanged.');}catch(e){input.checked=!input.checked;note(e.message);}}
 async function previewCamera(deviceId,video,button,state,epoch){
  if(previews.has(deviceId)){
   previews.get(deviceId).getTracks().forEach(t=>t.stop());previews.delete(deviceId);
   video.pause();video.srcObject=null;button.textContent='Preview';button.setAttribute('aria-pressed','false');state.textContent='Preview off';return;
  }
  button.disabled=true;state.textContent='Opening preview…';
  try{
   const feed=await navigator.mediaDevices.getUserMedia({video:{deviceId:{exact:deviceId}},audio:false});
   if(epoch!==generation){feed.getTracks().forEach(t=>t.stop());return;}
   previews.set(deviceId,feed);video.srcObject=feed;
   video.play().catch(()=>{});
   button.textContent='Stop preview';button.setAttribute('aria-pressed','true');state.textContent='Live preview · not recording';
  }catch(e){state.textContent='Preview unavailable';note('Camera preview unavailable: '+e.message);}
  finally{button.disabled=false;}
 }
 async function detect(){
  if(scanning)return;
  scanning=true;const button=$('live-camera-detect');button.disabled=true;
  const root=$('live-camera-list');off();root.replaceChildren();
  const epoch=++generation;note('Checking connected cameras…');
  try{
   if(!navigator.mediaDevices?.enumerateDevices||!navigator.mediaDevices?.getUserMedia)throw Error('Camera detection requires HTTPS or localhost.');
   // Request permission explicitly, so the device enumeration can show real labels.
   const handshake=await navigator.mediaDevices.getUserMedia({video:true,audio:false});
   handshake.getTracks().forEach(t=>t.stop());
   if(epoch!==generation)return;
   const all=(await navigator.mediaDevices.enumerateDevices()).filter(d=>d.kind==='videoinput');
   const devices=all,keep=saved(),shared=new Set(cameraPrefs?.shared()||[]);
   // Match the visible initial choice to the proof editor even without an Admin profile.
   if(!keep.size&&devices[0]?.deviceId&&cameraPrefs){try{cameraPrefs.setGameCamera(gameId,devices[0].deviceId,true);keep.add(devices[0].deviceId);}catch(e){note(e.message);}}
   if(!devices.length){note('No cameras detected. Check your connections and try again.');return;}
   for(const [i,device] of devices.entries()){
    const deviceId=device.deviceId||'camera-'+i;
    const item=document.createElement('article');item.className='live-camera-item';item.dataset.cameraId=deviceId;
    const header=document.createElement('div');header.className='live-camera-item-head';
    const label=document.createElement('label');label.className='live-camera-item-label';
    const checkbox=document.createElement('input');checkbox.type='checkbox';checkbox.dataset.liveCamera=deviceId;
    checkbox.checked=keep.size?keep.has(deviceId):i===0;checkbox.onchange=()=>save(checkbox);
    const name=document.createElement('strong');name.textContent=cameraPrefs?.getAliases()?.[deviceId]||device.label||'Camera '+(i+1);
    label.append(checkbox,name);header.append(label);if(shared.has(deviceId)){const tag=document.createElement('span');tag.className='camera-shared-tag';tag.textContent='Shared default';header.append(tag);}
    const state=document.createElement('span');state.className='live-camera-item-status';state.dataset.cameraState='';state.textContent='Ready';
    const video=document.createElement('video');video.autoplay=true;video.muted=true;video.playsInline=true;
    video.setAttribute('aria-label',(device.label||'Camera '+(i+1))+' live preview');
    const action=document.createElement('button');action.type='button';action.className='btn btn-outline live-camera-preview-action';
    action.dataset.previewAction='';action.textContent='Preview';action.setAttribute('aria-pressed','false');
    action.onclick=()=>previewCamera(deviceId,video,action,state,generation);
    item.append(header,video,state,action);root.append(item);
   }
   note(devices.length+' camera'+(devices.length===1?'':'s')+' detected. Shared defaults are already selected. Check another camera to add it for this game (up to four).');
   // Start live previews after the user explicitly clicks Detect Cameras.
   for(const card of root.querySelectorAll('.live-camera-item')){
    if(epoch!==generation)break;
    if(previews.size<4)await previewCamera(card.dataset.cameraId,card.querySelector('video'),card.querySelector('[data-preview-action]'),card.querySelector('[data-camera-state]'),epoch);
   }
  }catch(e){if(epoch===generation)note('Could not detect cameras: '+e.message);}
  finally{scanning=false;button.disabled=false;}
 }
 $('live-camera-detect').addEventListener('click',detect);
 document.addEventListener('visibilitychange',()=>{if(document.visibilityState==='hidden')off();});
 window.addEventListener('pagehide',off);
})();
