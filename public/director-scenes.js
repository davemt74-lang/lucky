/* HF8C2: host-only reusable scenes; Cue never publishes until Take. */
(() => {
 'use strict';
 const $=id=>document.getElementById(id);
 if(!$('director-scene-list'))return;
 let proof=null,scenes=[],epoch=0,busy=false,csrf='';
 const scope=()=>proof?.recordingSession?'recording session':'proof';
 const status=(message,error=false)=>{const n=$('director-scene-status');n.textContent=message;n.classList.toggle('is-error',error);};
 const available=id=>!!window.LuckyCameraDirector?.sources?.().some(s=>s.id===id);
 const selected=()=>window.LuckyCameraDirector?.state?.().preview||'';
 const buttons=()=>{$('director-scene-save').disabled=!proof||busy||!selected();$('director-scene-refresh').disabled=!proof||busy;$('director-scenes-count').textContent=scenes.length+'/16 scenes';};
 async function request(action,data={},method='GET'){
  const qs=new URLSearchParams({action});
  if(method==='GET')for(const [k,v] of Object.entries(data))qs.set(k,v);
  const opts={method,cache:'no-store',credentials:'same-origin'};
  if(method==='POST'){
   if(!csrf)csrf=(await Lucky.getMe()).csrf;
   opts.headers={'Content-Type':'application/json','X-CSRF-Token':csrf};
   opts.body=JSON.stringify(data);
  }
  const response=await fetch('api/director-scenes.php?'+qs,opts),out=await response.json().catch(()=>({}));
  if(!response.ok||!out.ok)throw Error(out.error||'Scene request failed ('+response.status+').');
  return out;
 }
 function cue(scene){
  if(!proof||!available(scene.source)){status('Scene source offline or outside the active '+scope()+'.',true);return false;}
  if(!window.LuckyCameraDirector.select(scene.source))return false;
  $('director-overlay-text').value=scene.title;
  $('director-overlay-sticker').value=scene.sticker;
  $('director-scene-name').value=scene.name;
  $('director-scene-transition').value=scene.transition;
  status('Cued '+scene.name+' in Preview; Program has not changed.');buttons();return true;
 }
 function take(scene){if(!cue(scene))return false;const ok=!!window.LuckyCameraDirector.take(scene.transition);if(ok)status('● Took '+scene.name+' to Program.');return ok;}
 async function remove(scene,button){
  if(button.dataset.confirm!=='yes'){button.dataset.confirm='yes';button.textContent='Confirm remove';status('Click Confirm remove again to delete '+scene.name+'.');return;}
  if(busy||!proof)return;busy=true;buttons();const context=proof,version=epoch;
  try{
   await request('remove',{...context,id:scene.id},'POST');
   if(version!==epoch)return;scenes=scenes.filter(s=>s.id!==scene.id);render();status('Scene removed.');
  }catch(e){if(version===epoch)status(e.message,true);}
  finally{busy=false;buttons();}
 }
 function render(){
  const root=$('director-scene-list');root.replaceChildren();
  if(!proof){buttons();return;}
  if(!scenes.length){const p=document.createElement('p');p.className='mini-label';p.textContent='No scenes yet. Cue a source, add a title or sticker, and save it.';root.append(p);}
  for(const scene of scenes){
   const ready=available(scene.source);
   const card=document.createElement('div');card.className='director-scene-item';
   const detail=document.createElement('div');detail.className='director-scene-detail';
   const name=document.createElement('strong');name.textContent=scene.name;
   const summary=document.createElement('span');summary.textContent=(ready?'READY':'OFFLINE')+' · '+scene.source+' · '+scene.transition;
   detail.append(name,summary);
   if(scene.title||scene.sticker){const line=document.createElement('small');line.textContent=[scene.title,scene.sticker].filter(Boolean).join(' · ');detail.append(line);}
   const actions=document.createElement('div');actions.className='director-scene-actions';
   const make=(label,click,disabled=false)=>{const btn=document.createElement('button');btn.type='button';btn.className='btn btn-outline';btn.textContent=label;btn.disabled=disabled;btn.addEventListener('click',click);actions.append(btn);return btn;};
   make('CUE',()=>cue(scene),!ready);
   make('TAKE',()=>take(scene),!ready);
   const removeBtn=make('Remove',()=>void remove(scene,removeBtn));
   card.append(detail,actions);root.append(card);
  }
  buttons();
 }
 async function load(){
  if(!proof)return;const context=proof,version=epoch;
  try{
   const data=await request('list',context);
   if(version!==epoch)return;scenes=Array.isArray(data.scenes)?data.scenes.slice(0,16):[];render();status('Loaded '+scenes.length+' saved scenes.');
  }catch(e){if(version===epoch)status('Scene list unavailable: '+e.message,true);}
 }
 async function save(){
  if(busy||!proof)return;
  const name=$('director-scene-name').value.trim(),source=selected();
  if(!available(source)){status('Cue an available camera or clip first.',true);return;}
  if(!name||name.length>60){status('Enter a scene name (1–60 characters).',true);return;}
  busy=true;buttons();const context=proof,version=epoch;
  try{
   await request('save',{...context,name,source,transition:$('director-scene-transition').value,
    title:$('director-overlay-text').value.trim().slice(0,100),sticker:$('director-overlay-sticker').value},'POST');
   if(version!==epoch)return;
   await load();if(version===epoch)status('Saved '+name+' to this '+scope()+'.');
  }catch(e){if(version===epoch)status('Save failed: '+e.message,true);}
  finally{busy=false;buttons();}
 }
 function close(){
  proof=null;epoch++;scenes=[];csrf='';busy=false;
  $('director-scene-name').value='';$('director-scene-transition').value='cut';
  render();status('Open a recording session to load production scenes.');
 }
 $('director-scene-save').addEventListener('click',()=>void save());
 $('director-scene-refresh').addEventListener('click',()=>void load());
 $('director-preview-source').addEventListener('change',buttons);
 window.addEventListener('lucky:director-sources-updated',render);
 window.addEventListener('lucky:proof-open',e=>{
  close();const d=e.detail||{};
  proof=/^[a-f0-9]{32}$/.test(d.session||'')&&/^[a-f0-9]{32}$/.test(d.claim||'')?{session:d.session,claim:d.claim}:null;
  render();void load();
 });
 window.addEventListener('lucky:director-studio-open',e=>{
  close();const id=String(e.detail?.recordingSession||'');
  proof=/^[a-f0-9]{32}$/.test(id)?{recordingSession:id}:null;
  render();void load();
 });
 window.addEventListener('lucky:director-studio-close',close);
 window.addEventListener('lucky:proof-close',close);
 window.addEventListener('pagehide',close);
 buttons();
 window.LuckyDirectorScenes={state:()=>({active:!!proof,count:scenes.length}),
  cue:id=>{const s=scenes.find(s=>s.id===id);return s?cue(s):false;},
  take:id=>{const s=scenes.find(s=>s.id===id);return s?take(s):false;}};
})();
