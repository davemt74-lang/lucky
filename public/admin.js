(() => {
 'use strict';const L=Lucky,$=L.$;let settings=null,activeTier='tier1',history=[],sessions=[],readyGames=[],packages=[],packageId='',selectedPackage='',packageDirty=false,packagesAvailable=false;
 const amount=s=>{const n=Number(s);if(!Number.isFinite(n)||n<0||n>10000000)throw Error('Invalid dollar amount.');return Math.round(n*100);};
 const cents=c=>(Number(c||0)/100).toFixed(2);
 const elem=(tag,txt='',cls='')=>{const e=document.createElement(tag);if(txt)e.textContent=txt;if(cls)e.className=cls;return e;};
 const status=(message,bad=false)=>L.note('admin-status',message,bad);
 let saveInFlight=null,lastSaveError='';
 function selectTier(tier){activeTier=tier;document.querySelectorAll('[data-tier]').forEach(b=>b.classList.toggle('selected',b.dataset.tier===tier));renderTier();}
 function setBudget(){settings.config.pools[activeTier].budgetCents=amount($('tier-budget').value);}
 function imagePreview(rootId,url,kind){
  const root=$(rootId);root.replaceChildren();
  if(!url){root.append(elem('span',kind==='photo'?'No photo uploaded':'No video uploaded','prize-edit-media-empty'));return;}
  if(kind==='photo'){
   const img=elem('img');img.alt='Uploaded prize photo';img.src=url;img.loading='lazy';img.onerror=()=>{root.replaceChildren(elem('span','Photo could not load','prize-edit-media-empty'));};root.append(img);
  }else{
   // No video URL text field: only clips uploaded and attached to this prize are editable.
   const video=elem('video');video.src=url;video.controls=true;video.preload='metadata';video.playsInline=true;root.append(video);
  }
 }
 function isUploadedVideo(raw){
  try{const url=new URL(raw,document.baseURI);return url.pathname.endsWith('/api/index.php')&&
    url.searchParams.get('action')==='prize_video'&&/^[a-f0-9]{32}$/.test(url.searchParams.get('id')||'');
  }catch(_){return false;}
 }
 function renderTier(){
  const pool=settings.config.pools[activeTier],list=$('prize-items');list.replaceChildren();$('tier-budget').value=cents(pool.budgetCents);
  for(const p of pool.prizes){
   const card=elem('article','','prize-catalog-card');card.dataset.prizeId=p.id;
   const photo=elem('div','','prize-catalog-art');if(p.image){const img=elem('img');img.src=p.image;img.alt='';img.loading='lazy';photo.append(img);}else photo.append(elem('span','✦','prize-catalog-placeholder'));
   const info=elem('div','','prize-catalog-content');
   const heading=elem('div','','prize-catalog-heading');heading.append(elem('h3',p.name),elem('strong','$'+cents(p.cents),'prize-catalog-value'));
   const description=elem('p',String(p.description||'No description yet. Add a custom announcement for this prize.').replace(/\s+/g,' ').slice(0,170),'prize-catalog-excerpt');
   const badges=elem('div','','prize-catalog-metadata');
   badges.append(elem('span',p.image?'📷 Photo attached':'📷 No photo','prize-catalog-chip'));
   badges.append(elem('span',isUploadedVideo(p.videoUrl)?'🎞 Video attached':'🎞 No video','prize-catalog-chip'));
   badges.append(elem('span',`${(p.proofAngles||[]).length} proof angles`,'prize-catalog-chip'));
   const edit=elem('button','Edit prize →','btn btn-outline prize-catalog-edit');edit.type='button';edit.setAttribute('aria-label','Edit prize '+p.name);edit.onclick=()=>openPrizeEditor(p);
   info.append(heading,description,badges);card.append(photo,info,edit);list.append(card);
  }
  if(!pool.prizes.length)list.append(elem('p','No prizes in this tier. Add one to get started.','prize-gallery-empty'));
 }
 function openPrizeEditor(prize=null){
  const dialog=$('prize-editor-modal'),pool=settings.config.pools[activeTier];
  const creating=!prize;
  const draft=prize?structuredClone(prize):{id:'p'+(crypto.randomUUID?.().replace(/-/g,'')||Math.random().toString(36).slice(2)+Date.now()),name:'',cents:0,url:'',videoUrl:'',image:'',description:'',proofAngles:[]};
  if(!isUploadedVideo(draft.videoUrl))draft.videoUrl='';
  $('prize-editor-heading').textContent=creating?'Create prize':'Edit prize';
  $('prize-edit-name').value=draft.name;$('prize-edit-value').value=cents(draft.cents);
  $('prize-edit-link').value=draft.url||'';$('prize-edit-description').value=draft.description||'';
  $('prize-edit-delete').hidden=creating;$('prize-edit-status').textContent='';
  const paintMedia=()=>{imagePreview('prize-edit-photo-preview',draft.image,'photo');imagePreview('prize-edit-video-preview',draft.videoUrl,'video');};paintMedia();
  let working=false;
  const uploadFile=async(kind,file)=>{if(!file||working)return;working=true;$('prize-edit-save').disabled=true;
   try{const data=new FormData();data.append(kind==='photo'?'image':'video',file);
    const response=await L.api(kind==='photo'?'image_upload':'prize_video_upload',{body:data});
    draft[kind==='photo'?'image':'videoUrl']=new URL(response.path,document.baseURI).href;paintMedia();
    $('prize-edit-status').textContent=(kind==='photo'?'Photo':'Video')+' uploaded. Save the prize to attach it.';
   }catch(e){$('prize-edit-status').textContent=e.message;}finally{working=false;$('prize-edit-save').disabled=false;$(kind==='photo'?'prize-edit-photo-upload':'prize-edit-video-upload').value='';}
  };
  $('prize-edit-photo-upload').onchange=e=>void uploadFile('photo',e.target.files?.[0]);
  $('prize-edit-video-upload').onchange=e=>void uploadFile('video',e.target.files?.[0]);
  $('prize-edit-photo-remove').onclick=()=>{draft.image='';paintMedia();};
  $('prize-edit-video-remove').onclick=()=>{draft.videoUrl='';paintMedia();};
  const tags=$('prize-edit-tags');tags.replaceChildren(elem('span','INSERT SMART TAG','mini-label'));
  for(const name of ['prize_name','prize_value','prize_tier']){
   const tag=elem('button','{'+name+'}','btn btn-outline');tag.type='button';tag.onclick=()=>{
    const field=$('prize-edit-description'),token='{'+name+'}',pos=field.selectionStart;
    field.setRangeText(token,pos,field.selectionEnd,'end');field.focus();
   };tags.append(tag);
  }
  const paintAngles=()=>{const root=$('prize-edit-angles');root.replaceChildren();
   (draft.proofAngles||[]).forEach((angle,i)=>{const row=elem('div','','prize-edit-angle');row.append(elem('span',`View ${i+1}`,'mini-label'));
    const label=elem('input');label.value=angle.label||'';label.maxLength=80;label.setAttribute('aria-label',`Camera ${i+1} angle label`);label.oninput=()=>angle.label=label.value;
    const duration=elem('input');duration.type='number';duration.min='3';duration.max='30';duration.value=angle.seconds||8;duration.setAttribute('aria-label',`Camera ${i+1} recording seconds`);duration.onchange=()=>angle.seconds=Number(duration.value);
    const remove=elem('button','✕','btn btn-outline');remove.type='button';remove.setAttribute('aria-label',`Remove angle ${i+1}`);remove.onclick=()=>{draft.proofAngles.splice(i,1);paintAngles();};
    row.append(label,duration,remove);root.append(row);
   });
   if(!draft.proofAngles?.length)root.append(elem('p','No proof angles configured. You can match cameras when they are connected.','prize-edit-hint'));
  };paintAngles();
  $('prize-edit-detect-cameras').onclick=async()=>{try{
   if(!navigator.mediaDevices?.enumerateDevices)throw Error('Camera detection requires HTTPS and a supported browser.');
   if(navigator.mediaDevices.getUserMedia){const stream=await navigator.mediaDevices.getUserMedia({video:true,audio:false});stream.getTracks().forEach(t=>t.stop());}
   const devices=(await navigator.mediaDevices.enumerateDevices()).filter(d=>d.kind==='videoinput').slice(0,4);
   const previous=draft.proofAngles||[];draft.proofAngles=devices.map((d,i)=>({label:previous[i]?.label||d.label?.slice(0,80)||`View ${i+1}`,seconds:previous[i]?.seconds||8}));paintAngles();
  }catch(e){$('prize-edit-status').textContent=e.message;}};
  const close=()=>{if(!working&&!saveInFlight)dialog.close();};$('prize-editor-close').onclick=close;$('prize-edit-cancel').onclick=close;
  $('prize-edit-delete').onclick=async()=>{if(working||creating)return;
   if(!(await packageDialog({title:'Delete prize?',message:'Delete “'+draft.name+'” from this tier? Save package changes to finalize.',action:'Delete prize'})))return;
   pool.prizes=pool.prizes.filter(x=>x.id!==prize.id);markPackageDirty();renderTier();dialog.close();
  };
  $('prize-editor-form').onsubmit=async e=>{e.preventDefault();if(working||saveInFlight)return;
   try{
    draft.name=$('prize-edit-name').value.trim();if(!draft.name||draft.name.length>80)throw Error('Enter a prize name (1–80 characters).');
    draft.cents=amount($('prize-edit-value').value);draft.url=$('prize-edit-link').value.trim();draft.description=$('prize-edit-description').value.trim();
    if(draft.url&&!/^https?:\/\//i.test(draft.url))throw Error('Prize URL must start with https:// or http://.');
    for(const angle of draft.proofAngles||[]){if(!Number.isInteger(Number(angle.seconds))||angle.seconds<3||angle.seconds>30)throw Error('Proof angles must record between 3 and 30 seconds.');}
    if(creating){if(pool.prizes.length>=24)throw Error('Limit: 24 prizes per tier.');pool.prizes.push(draft);prize=draft;}
    else Object.assign(prize,draft);
    markPackageDirty();renderTier();
    $('prize-edit-status').textContent='Saving prize in the selected package…';
    const saved=await save();if(saved)dialog.close();else $('prize-edit-status').textContent=(lastSaveError||'Could not save the prize. Please try again.')+' Changes have not been saved.';
   }catch(err){$('prize-edit-status').textContent=err.message;}
  };
  dialog.showModal();$('prize-edit-name').focus();
 }
 // A single themed, accessible dialog for package creation and confirmations.
 function packageDialog(options){
  const dialog=$('prize-package-modal'),form=$('prize-package-modal-form');
  const creating=options.type==='create';
  $('prize-package-modal-title').textContent=options.title||'Confirm package change';
  $('prize-package-modal-description').textContent=options.message||'';
  $('prize-package-modal-fields').hidden=!creating;
  $('prize-package-modal-submit').textContent=options.action||(creating?'Create package':'Confirm');
  $('prize-package-modal-name').value=options.name||'';
  $('prize-package-modal-budget').value='';
  const error=$('prize-package-modal-error');error.hidden=true;error.textContent='';
  return new Promise(resolve=>{
   let settled=false;
   const done=value=>{if(settled)return;settled=true;dialog.close();resolve(value);};
   form.onsubmit=e=>{
    e.preventDefault();
    if(!creating){done(true);return;}
    try{
     const name=$('prize-package-modal-name').value.trim();
     if(!name||name.length>100)throw Error('Enter a package name of 1–100 characters.');
     const raw=$('prize-package-modal-budget').value.trim();
     const budgetCents=raw===''?null:amount(raw);
     const source=packageFor(packageId);
     if(budgetCents!==null&&source?.config){
      const total=Object.values(source.config.pools).reduce((n,t)=>n+(t.budgetCents||0),0);
      if(total>budgetCents)throw Error('The package budget must cover the combined budget of all cloned tiers.');
     }
     done({name,budgetCents});
    }catch(err){error.hidden=false;error.textContent=err.message;}
   };
   $('prize-package-modal-cancel').onclick=()=>done(null);
   dialog.oncancel=e=>{e.preventDefault();done(null);};
   dialog.onclose=()=>{if(!settled){settled=true;resolve(null);}};
   dialog.showModal();
   (creating?$('prize-package-modal-name'):$('prize-package-modal-submit')).focus();
  });
 }
 function packageFor(id){return packages.find(p=>p.id===id);}
 function markPackageDirty(){if(packagesAvailable)packageDirty=true;}
 function drawPackageTabs(){
  const root=$('prize-package-tabs');root.replaceChildren();
  for(const p of packages){
   const button=elem('button',p.name+(p.publicationStatus==='draft'?' · Draft':''),'btn btn-outline');button.type='button';button.role='tab';button.dataset.packageId=p.id;
   button.classList.toggle('selected',p.id===packageId);
   button.classList.toggle('package-live-choice',p.id===selectedPackage);
   button.classList.toggle('package-disabled',!p.enabled);
   button.classList.toggle('package-draft',p.publicationStatus==='draft');
   button.setAttribute('aria-selected',String(p.id===packageId));
   button.onclick=()=>switchPackage(p.id);root.append(button);
  }
  const item=packageFor(packageId);
  const publication=item?.publicationStatus||'published';
  $('package-publication-status').textContent=publication==='draft'?'Draft — not available to players':'Published — available if enabled';
  $('package-publication-status').dataset.status=publication;
  $('package-publish').textContent=publication==='draft'?'Publish package':'Move to Draft';
  $('package-publish').disabled=!packagesAvailable||(publication==='published'&&packageId===selectedPackage);
  $('package-selection-status').textContent=!packagesAvailable?'Run upgrade.php to enable package tabs.':
   packageId===selectedPackage?'✓ Selected for the next game':publication==='draft'?'Draft · publish to make selectable':'Editing package · not selected for the next game';
  $('package-activate').disabled=!packagesAvailable||packageId===selectedPackage||!item?.enabled||publication!=='published';
  $('package-remove').disabled=!packagesAvailable||packageId===selectedPackage;
  if($('prize-package-summary-name'))$('prize-package-summary-name').textContent=item?.name||'Prize package';
  if($('prize-package-summary-state'))$('prize-package-summary-state').textContent=publication==='draft'?'Draft':item?.enabled?'Published & enabled':'Disabled';
  if($('prize-package-summary-description'))$('prize-package-summary-description').textContent=packageId===selectedPackage?'Default for the next game · Edit tier prizes below':'Available for player selection when published and enabled';
 }
 function switchPackage(id,force=false){
  if(!force&&packageDirty){
   void packageDialog({title:'Discard unsaved changes?',message:'Switching tabs will discard edits you have not saved.',action:'Discard changes'}).then(ok=>{if(ok)switchPackage(id,true);});return;
  }
  const p=packageFor(id);if(!p)return;
  packageId=id;packageDirty=false;activeTier='tier1';settings.config=structuredClone(p.config);
  $('package-name').value=p.name;$('package-image').value=p.image||'';$('package-enabled').checked=!!p.enabled;
  $('package-player-choice').checked=!!p.playerSelectable;
  $('package-budget').value=p.budgetCents==null?'':cents(p.budgetCents);
  for(const [k,v] of Object.entries(settings.config.odds))if($('odds-'+k))$('odds-'+k).value=v;
  selectTier('tier1');drawPackageTabs();
 }
 async function reloadPackages(preferred=''){
  const res=await L.api('prize_packages');
  if(!Array.isArray(res.packages))throw Error('Run upgrade.php to enable prize packages.');
  packagesAvailable=true;packages=res.packages;selectedPackage=res.selected;
  switchPackage(packageFor(preferred)?preferred:(packageFor(packageId)?packageId:selectedPackage),true);
 }
 async function loadSettings(){
  const r=await L.api('settings',{query:{game:'spin-wheel'}});settings=r.settings;
  try{await reloadPackages();}catch(e){
   packagesAvailable=false;
   packages=[{id:'legacy',name:'Original Prizes',config:structuredClone(settings.config),enabled:true,playerSelectable:true,publicationStatus:'published',version:1}];
   selectedPackage='legacy';switchPackage('legacy',true);
   status('Prize packages need setup. Click + New package to initialize them automatically, or run upgrade.php.',true);
  }
 }
 // Coalesce repeated save submissions and show the API's exact rejection inside the prize dialog.
 function save(){
  if(saveInFlight)return saveInFlight;
  const buttons=['save-prizes','package-settings-save','prize-edit-save'];
  for(const id of buttons){const button=$(id);if(button)button.disabled=true;}
  const operation=(async()=>{lastSaveError='';
   try{
  setBudget();for(const k of Object.keys(settings.config.odds))settings.config.odds[k]=Number($('odds-'+k).value);
  if(!packagesAvailable){
   const r=await L.api('settings_save',{body:{game:'spin-wheel',...settings}});settings=r.settings;renderTier();
   status('Legacy prize settings saved. Run upgrade.php for packages.');return true;
  }
  const budgetRaw=$('package-budget').value.trim();
  const packageBudget=budgetRaw===''?null:amount(budgetRaw);
  if(packageBudget!==null){
   const combined=Object.values(settings.config.pools).reduce((sum,pool)=>sum+pool.budgetCents,0);
   if(combined>packageBudget)throw Error('Combined tier budgets exceed the package budget. Increase the limit or reduce the tier budgets.');
  }
  for(const [tier,pool] of Object.entries(settings.config.pools)){
   const total=pool.prizes.reduce((sum,prize)=>sum+prize.cents,0);
   if(total>pool.budgetCents)throw Error(tier+' prize values total $'+cents(total)+' but the tier budget is $'+cents(pool.budgetCents)+'. Increase the tier budget or lower the prize values.');
  }
  const p=packageFor(packageId);if(!p)throw Error('Select a prize package.');
  const response=await L.api('package_save',{body:{id:p.id,version:p.version,name:$('package-name').value.trim(),
   config:settings.config,enabled:$('package-enabled').checked,playerSelectable:$('package-player-choice').checked,
   budgetCents:packageBudget,image:$('package-image').value.trim()}});
  Object.assign(p,response.package);settings.config=structuredClone(p.config);packageDirty=false;
  drawPackageTabs();renderTier();status('Prize package saved locally. Current sessions remain unchanged.');return true;
    }catch(e){lastSaveError=e instanceof Error?e.message:String(e);status(lastSaveError,true);return false;}
  })();
  saveInFlight=operation;
  return operation.finally(()=>{saveInFlight=null;for(const id of buttons){const button=$(id);if(button)button.disabled=false;}});
 }
 let creatingPackage=false;
 $('add-package').onclick=async()=>{
  if(creatingPackage)return;
  if(packageDirty&&!(await packageDialog({title:'Discard unsaved changes?',message:'The new package clones the last saved version. Unsaved edits will not carry over.',action:'Continue'})))return;
  const draft=await packageDialog({type:'create',title:'Create prize package',message:'Create a separate Draft package with the selected package’s saved tiers and prizes. Overall budget is optional.',name:'Prize Package '+(packages.length+1)});
  if(!draft)return;
  creatingPackage=true;$('add-package').disabled=true;
  try{
   const body={name:draft.name,budgetCents:draft.budgetCents};
   if(packageId!=='legacy')body.cloneFrom=packageId;
   const output=await L.api('package_create',{body});
   await reloadPackages(output.package.id);
   status('Draft package created. Edit its tiers and Save, then Publish.');
  }catch(e){status('Could not create prize package: '+e.message,true);}
  finally{creatingPackage=false;$('add-package').disabled=false;}
 };
 $('package-publish').onclick=async()=>{
  const p=packageFor(packageId);if(!p||!packagesAvailable)return;
  if(packageDirty&&!(await save()))return;
  const publish=(p.publicationStatus==='draft');
  if(!publish&&p.id===selectedPackage)return status('Select a different published package before moving this one to Draft.',true);
  if(!publish&&!(await packageDialog({title:'Move to Draft?',message:'This package will no longer be available to players. Existing sessions are unaffected.',action:'Move to Draft'})))return;
  try{
   const response=await L.api('package_publish',{body:{id:p.id,version:p.version,published:publish}});
   Object.assign(p,response.package);drawPackageTabs();
   status(publish?'Package Published. Enable and select it to use for the next game.':'Package moved to Draft. Existing game sessions are unchanged.');
  }catch(e){status(e.message,true);}
 };
 const packageUpload=$('package-image-upload');
 if(packageUpload)packageUpload.onchange=async()=>{if(!packageUpload.files?.[0])return;
  try{const form=new FormData();form.append('image',packageUpload.files[0]);const r=await L.api('image_upload',{body:form});
   $('package-image').value=new URL(r.path,document.baseURI).href;markPackageDirty();status('Package cover uploaded. Save package changes to attach it.');
  }catch(e){status(e.message,true);}finally{packageUpload.value='';}
 };
 $('package-activate').onclick=async()=>{
  if(packageDirty&&!(await save()))return;
  try{await L.api('package_select',{body:{id:packageId}});selectedPackage=packageId;drawPackageTabs();
   status('This prize package will be used for the next game.');}catch(e){status(e.message,true);}
 };
 $('package-remove').onclick=async()=>{
  const p=packageFor(packageId);if(!p||packageId===selectedPackage)return;
  if(!(await packageDialog({title:'Delete prize package?',message:'Delete “'+p.name+'”? This cannot be undone. Game history remains unchanged.',action:'Delete package'})))return;
  try{await L.api('package_delete',{body:{id:packageId}});await reloadPackages(selectedPackage);status('Package deleted.');}
  catch(e){status(e.message,true);}
 };
 for(const id of ['package-name','package-image','package-budget','package-enabled','package-player-choice'])$(id).addEventListener('change',markPackageDirty);
 $('tab-prizes').addEventListener('change',e=>{
  if(!e.target.closest('.prize-package-config')&&!e.target.closest('.prize-package-tabs'))markPackageDirty();
 });
 $('tier-budget').onchange=()=>{try{setBudget();markPackageDirty();}catch(e){status(e.message,true);}};
 document.querySelectorAll('[data-tier]').forEach(b=>b.onclick=()=>{try{setBudget();selectTier(b.dataset.tier);}catch(e){status(e.message,true);}});
 $('add-prize-button').onclick=()=>openPrizeEditor();
 $('auto-split').onclick=()=>{try{setBudget();const pool=settings.config.pools[activeTier],claimed=new Set((window.__lastSession?.session?.claims||[]).map(c=>c.prizeId));
  const fixed=pool.prizes.filter(p=>claimed.has(p.id)).reduce((n,p)=>n+p.cents,0),available=pool.prizes.filter(p=>!claimed.has(p.id));
  if(!available.length)throw Error('There are no unclaimed prizes to split.');const remaining=pool.budgetCents-fixed;
  if(remaining<0)throw Error('Claimed prizes exceed the tier budget.');const each=Math.floor(remaining/available.length);let extra=remaining%available.length;
  for(const p of available)p.cents=each+(extra-->0?1:0);
  renderTier();status('Unclaimed prizes auto-split. Save to publish.');
 }catch(e){status(e.message,true);}};
 $('open-package-settings').onclick=()=>$('package-settings-modal').showModal();
 $('package-settings-close').onclick=()=>$('package-settings-modal').close();
 $('package-settings-cancel').onclick=()=>$('package-settings-modal').close();
 $('package-settings-save').onclick=async()=>{if(await save())$('package-settings-modal').close();};
 $('save-prizes').onclick=save;
 async function refreshMusic(){const tracks=await L.loadTracks();const list=$('music-items');list.replaceChildren();
  for(const t of tracks){const row=elem('div','','platform-claim');const title=elem('strong',t.title),desc=elem('span',t.kind==='upload'?'Uploaded audio':'Hosted URL'),remove=elem('button','Remove','btn btn-outline');
   remove.onclick=async()=>{if(!confirm('Remove '+t.title+'?'))return;try{await L.api('track_remove',{body:{id:t.id}});await refreshMusic();status('Track removed.');}catch(e){status(e.message,true);}};
   row.append(title,desc,remove);list.append(row);
  }
 }
 $('music-upload').onsubmit=async e=>{e.preventDefault();const f=e.currentTarget,button=f.querySelector('button');button.disabled=true;
  try{const form=new FormData(f);await L.api('upload',{body:form});f.reset();await refreshMusic();status('Uploaded song saved to the shared server library.');}
  catch(err){status(err.message,true);}finally{button.disabled=false;}};
 $('music-add-url').onsubmit=async e=>{e.preventDefault();const f=e.currentTarget;try{await L.api('track_add',{body:{title:f.elements.title.value.trim(),url:f.elements.url.value.trim()}});
  f.reset();await refreshMusic();status('Hosted track saved.');}catch(err){status(err.message,true);}};
 async function refreshHistory(){const r=await L.api('history');history=r.history;const list=$('history-items');list.replaceChildren();
  for(const item of history){const row=elem('div','','platform-claim');row.append(elem('strong',(item.game||'Game')+' · '+item.id.slice(0,8)+' · '+item.claims+' claims'),
    elem('span',`${L.money(item.awardedCents)} awarded · ${item.spins} spins · ${item.endedAt||'Ended'}`));list.append(row);}
  if(!history.length)list.append(elem('p','No completed sessions yet.'));
 }
 $('refresh-history').onclick=()=>refreshHistory().catch(e=>status(e.message,true));

 function makeSessionButton(label,kind,item){
  const b=elem('button',label,'btn btn-outline');b.type='button';b.dataset.sessionAction=kind;b.dataset.sessionId=item.id;
  b.onclick=async()=>{
   if(b.disabled)return;
   if(['end','finish_saved'].includes(kind)&&!confirm('Finish this session permanently? Finished sessions are archived and cannot be resumed.'))return;
   b.disabled=true;
   try{
    await L.api(kind,{body:{id:item.id,idempotencyKey:L.idempotency()}});
    status('Session '+(kind==='save_session'?'saved':kind==='resume_saved'?'resumed':'finished')+'.');
    await refreshSessions();if(['end','finish_saved'].includes(kind))await refreshHistory();
   }catch(e){status(e.message,true);}
   finally{b.disabled=false;}
  };
  return b;
 }
 function renderSessions(){
  const container=$('admin-session-list'),filter=$('session-game-filter').value;
  container.replaceChildren();
  const rows=sessions.filter(x=>!filter||x.game===filter);
  if(!rows.length){container.append(elem('p','No sessions for this game yet. Select a ready game and choose Start game.'));return;}
  for(const session of rows){
   const game=readyGames.find(x=>x.id===session.game);
   const card=elem('article','','admin-session-card');
   const head=elem('header'),title=elem('strong',(game?.name||session.game)+' · '+session.id.slice(0,8));
   const state=elem('span',session.status==='ended'?'Finished':session.status,'admin-session-status');state.dataset.status=session.status;
   head.append(title,state);
   const meta=elem('div',session.spins+' spins · '+session.claims+' claims · '+L.money(session.awardedCents)+' awarded · '+session.createdAt,'admin-session-metadata');
   const actions=elem('div','','admin-session-actions');
   if(['active','paused'].includes(session.status)){
    const movie=elem('button','▸ Export current clips','btn btn-primary admin-session-export');
    movie.type='button';movie.dataset.sessionId=session.id;
    movie.setAttribute('aria-label','Export current clips into movie for session '+session.id);
    movie.onclick=async()=>{
     if(!confirm('Queue an MP4 movie from the current saved timeline (or automatically select captured clips if no edit exists)? Only Admin can export.'))return;
     movie.disabled=true;
     try{
      const result=await window.LuckyVideoGallery.queueCurrent(session.id);
      status('Movie queued: '+result.metrics.clipCount+' clips. View progress under Video gallery.');
      document.querySelector('[data-tab="videos"]')?.click();
      await window.LuckyVideoGallery.refresh();
     }catch(e){status(e.message,true);}finally{movie.disabled=false;}
    };
    actions.append(movie);
    actions.append(makeSessionButton('Save session' ,'save_session',session),makeSessionButton('Finish game','end',session));
   }
   if(session.status==='saved'){
    actions.append(makeSessionButton('Resume saved','resume_saved',session),makeSessionButton('Finish game','finish_saved',session));
   }
   if(game?.path&&session.status!=='ended'){
    const link=elem('a','Open game ↗','btn btn-outline');
    link.href=game.path+(game.path.includes('?')?'&':'?')+'session='+encodeURIComponent(session.id);
    actions.append(link);
   }
   const remove=elem('button','Delete session','btn btn-outline admin-session-delete');
   remove.type='button';remove.dataset.sessionAction='delete_session';remove.dataset.sessionId=session.id;
   remove.setAttribute('aria-label','Delete session '+session.id);
   remove.onclick=async()=>{
    const request='DELETE '+session.id;
    const answer=prompt('Permanently delete '+session.game+' session '+session.id+'? This removes its claims, proof recordings, video exports, and saved edits. This cannot be undone.\\n\\nType '+request+' to confirm:');
    if(answer!==request)return;
    remove.disabled=true;
    try{
     const response=await L.api('delete_session',{body:{id:session.id,expectedVersion:session.version,confirmation:answer}});
     await Promise.all([refreshSessions(),refreshHistory()]);
     status('Session deleted permanently.'+(response.cleanupWarnings?' '+response.cleanupWarnings+' media files require server cleanup.':''));
    }catch(e){status(e.message,true);}finally{remove.disabled=false;}
   };
   actions.append(remove);
   card.append(head,meta,actions);container.append(card);
  }
 }
 async function refreshSessions(){
  const results=await Promise.all([L.api('manifest'),L.api('sessions')]);
  readyGames=(results[0].games||[]).filter(x=>x.status==='ready'&&typeof x.id==='string'&&x.path);
  sessions=results[1].sessions||[];
  const select=$('session-game-filter'),old=select.value||'spin-wheel';
  select.replaceChildren(new Option('All games',''));
  const ids=new Set([...readyGames.map(x=>x.id),...sessions.map(x=>x.game)]);
  for(const id of ids){const game=readyGames.find(x=>x.id===id);select.add(new Option(game?.name||id,id));}
  select.value=ids.has(old)?old:'';
  updateStartButton();renderSessions();
 }
 function updateStartButton(){
  const id=$('session-game-filter').value;
  $('start-selected-game').disabled=!readyGames.some(x=>x.id===id);
 }
 $('session-game-filter').onchange=()=>{updateStartButton();renderSessions();};
 $('refresh-sessions').onclick=()=>refreshSessions().catch(e=>status(e.message,true));
 $('start-selected-game').onclick=async()=>{
  const game=$('session-game-filter').value;
  if(!readyGames.some(x=>x.id===game))return status('Choose a ready game first.',true);
  const button=$('start-selected-game');button.disabled=true;
  try{
   await L.api('start',{body:{game}});
   await refreshSessions();status('New '+game+' session started.');
  }catch(e){status(e.message,true);}finally{updateStartButton();}
 };

 $('export-history').onclick=()=>{const rows=[['Game','Session','Started','Ended','Spins','Claims','Awarded USD'],...history.map(h=>[h.game,h.id,h.createdAt,h.endedAt,h.spins,h.claims,cents(h.awardedCents)])];
  const csv=rows.map(row=>row.map(value=>{const s=String(value??'');return '"'+(/^[=+@\-]/.test(s)?"'":'')+s.replaceAll('"','""')+'"';}).join(',')).join('\r\n');
  const a=elem('a');a.href=URL.createObjectURL(new Blob([csv],{type:'text/csv'}));a.download='lucky-session-history.csv';a.click();setTimeout(()=>URL.revokeObjectURL(a.href),2000);};
 $('import-legacy').onclick=async()=>{try{const raw=JSON.parse(localStorage.getItem('lucky-spin-v3')||'null');if(!raw?.pools)throw Error('No prior V5 prize pools found in this browser.');
  if(!confirm('Replace server prize definitions with this browser\'s saved tier lists? Current server game claims will NOT be overwritten.'))return;
  const pools={};for(const tier of ['tier1','tier2','bonus']){const p=raw.pools[tier];pools[tier]={budgetCents:p.budgetCents,prizes:p.prizes.map(x=>({id:x.id,name:x.name,cents:x.cents,url:x.url||'',videoUrl:x.videoUrl||'',image:(x.image||'').startsWith('data:')?'':x.image||'',proofAngles:x.proofAngles||[]}))};}
  settings.config.pools=pools;
  if(raw.odds)for(const k of Object.keys(settings.config.odds))if(Number.isFinite(raw.odds[k]))settings.config.odds[k]=raw.odds[k];
  await L.api('settings_save',{body:{game:'spin-wheel',...settings}});
  if((raw.claims||[]).length){await L.api('import_legacy',{body:{sourceGameId:raw.gameId||'legacy-'+raw.createdAt,legacy:{pools,odds:settings.config.odds,claims:raw.claims}}});}
  await loadSettings();await refreshHistory();status(`Imported browser prize pools and ${(raw.claims||[]).length} archived claims.`);
 }catch(e){status(e.message,true);}};
 // Account logout is handled by the shared CSRF-protected user dropdown.
 document.querySelectorAll('[data-tab]').forEach(b=>b.onclick=()=>{const tab=b.dataset.tab;
   document.querySelectorAll('[data-tab]').forEach(item=>{item.classList.toggle('selected',item===b);if(item===b)item.setAttribute('aria-current','page');else item.removeAttribute('aria-current');});
   document.querySelectorAll('.platform-admin-panel').forEach(el=>el.hidden=el.id!=='tab-'+tab);
   document.querySelector('.admin-console')?.classList.remove('nav-open');
   $('admin-nav-toggle')?.setAttribute('aria-expanded','false');
   if(tab==='videos')window.LuckyVideoGallery?.refresh().catch(e=>status(e.message,true));
 });
 $('admin-nav-toggle').onclick=()=>{
  const open=document.querySelector('.admin-console').classList.toggle('nav-open');
  $('admin-nav-toggle').setAttribute('aria-expanded',String(open));
 };
 const initialTab=new URLSearchParams(location.search).get('tab');if(initialTab==='intro')document.querySelector('[data-tab=games]')?.click();if(['games','cameras','prizes','music','sessions','history','videos','migration'].includes(initialTab))document.querySelector(`[data-tab="${initialTab}"]`)?.click();
 try{const legacy=JSON.parse(localStorage.getItem('lucky-spin-v3')||'null');$('legacy-status').textContent=legacy?.pools?'Legacy prize pools found. You can import their definitions.':'No V5 game configuration found in this browser.';}catch(_){$('legacy-status').textContent='No readable V5 game data found.';}
 Promise.all([loadSettings(),refreshMusic(),refreshHistory(),refreshSessions(),L.api('last')]).then(async ([_,__,___,____,last])=>{window.__lastSession=last.data;status('Admin settings loaded.');}).catch(e=>status(e.message,true));
})();
