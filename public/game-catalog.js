(() => {
 'use strict';
 const $=id=>document.getElementById(id), api=Lucky.api;
 const text=(tag,value='',cls='')=>{const e=document.createElement(tag);e.textContent=value;e.className=cls;return e;};
 let games=[],selected='',introRequest=0,tracksReady=null;
 const fields={title:'catalog-title',tagline:'catalog-tagline',description:'catalog-description',
  publisherName:'catalog-publisher',publisherWebsite:'catalog-publisher-website',
  category:'catalog-category',version:'catalog-version',ageRating:'catalog-age'};
 const artURL=(id,name)=>'api/index.php?'+new URLSearchParams({action:'game_art',id,file:name});
 const note=(msg,bad=false)=>{const e=$('catalog-save-status');e.textContent=msg;e.classList.toggle('platform-error',bad);};
 function showImages(game){
  for(const slot of ['cover','hero','logo','splash']){
   const img=$('catalog-'+slot+'-preview'),name=game.images?.[slot];
   img.hidden=!name;if(name)img.src=artURL(game.id,name);else img.removeAttribute('src');
  }
  $('catalog-splash-placeholder').hidden=!!game.images?.splash;
  const gallery=$('catalog-gallery');gallery.replaceChildren();
  for(const name of game.images?.gallery||[]){
   const img=document.createElement('img');img.alt='Screenshot from '+game.title;
   img.loading='lazy';img.src=artURL(game.id,name);gallery.append(img);
  }
 }
 const defaultSplash=game=>({intro:{title:game.title||'Game intro',subtitle:'Ready to play',trackId:'',volume:70,announcement:true},theme:{accent:'#f6cb74',background:'#0d1423'}});
 function splashValues(){return {intro:{title:$('intro-title').value.trim(),subtitle:$('intro-subtitle').value.trim(),trackId:$('intro-track').value,
  volume:Number($('intro-volume').value),announcement:$('intro-announcement').checked},
  theme:{accent:$('theme-accent').value,background:$('theme-background').value}};}
 function fillSplash(saved,game){const data=saved&&typeof saved==='object'?saved:defaultSplash(game);
  $('intro-title').value=data.intro?.title||game.title;$('intro-subtitle').value=data.intro?.subtitle||'';
  $('intro-volume').value=Number.isFinite(Number(data.intro?.volume))?data.intro.volume:70;
  $('intro-announcement').checked=data.intro?.announcement!==false;
  $('theme-accent').value=data.theme?.accent||'#f6cb74';$('theme-background').value=data.theme?.background||'#0d1423';
  Lucky.fillTracks('intro-track',data.intro?.trackId||'');
 }
 async function loadSplash(game){const requested=++introRequest;
  const preview=$('catalog-preview-splash');const installed=game.status==='ready';
  preview.hidden=!installed;preview.href='intro.php?game='+encodeURIComponent(game.id);
  $('catalog-splash-settings').disabled=false;
  // Preview immediately from persisted metadata; registered games use the authoritative game_settings row.
  fillSplash(game.splash,game);
  try{
   tracksReady=tracksReady||Lucky.loadTracks();await tracksReady;
   if(selected!==game.id||introRequest!==requested)return;
   let data=game.splash;
   if(installed){const response=await api('settings',{query:{game:game.id}});data={intro:response.settings.intro,theme:response.settings.theme};}
   if(selected!==game.id||introRequest!==requested)return;
   fillSplash(data,game);
  }catch(err){if(selected===game.id&&introRequest===requested)note('Unable to load this game’s splash settings: '+err.message,true);}
 }
 function edit(id){
  const game=games.find(item=>item.id===id);if(!game)return;
  selected=id;$('catalog-editor').hidden=false;
  $('catalog-id').value=id;$('catalog-editor-heading').textContent=game.title;
  $('catalog-execution-status').textContent=game.builtIn
   ?'Permanent built-in game · updates supported'
   :game.status==='ready'?'Installed playable package · '+game.version
   :'Draft listing · Activate a verified package before gameplay';
  for(const [key,control] of Object.entries(fields))$(control).value=game[key]??'';
  $('catalog-min').value=game.playersMin;
  $('catalog-max').value=game.playersMax;
  $('catalog-controller').value=game.controllerMode;
  $('catalog-controller').disabled=game.status==='ready';
  $('catalog-enabled').checked=!!game.enabled;
  showImages(game);paintList();note('');loadSplash(game);
 }
 function paintList(){
  const area=$('catalog-game-list');area.replaceChildren();
  for(const g of games){
   const button=text('button','','game-catalog-card');button.type='button';
   button.classList.toggle('selected',g.id===selected);
   const image=g.images?.cover;
   if(image){const img=document.createElement('img');img.src=artURL(g.id,image);img.alt='';img.loading='lazy';button.append(img);}
   const body=text('span','','game-catalog-card-info');
   body.append(text('strong',g.title),text('small',g.publisherName||'Publisher not set'),text('small',g.builtIn?'Built-in · protected':g.status==='ready'?'Installed':'Draft — not playable'));
   button.append(body);button.onclick=()=>edit(g.id);area.append(button);
  }
  if(!games.length)area.append(text('p','No games in the library yet.'));
 }
 async function reload(preferred){
  const result=await api('game_catalog');games=result.games||[];
  const publisherSelect=$('game-publisher-game');
  if(publisherSelect){
   const current=publisherSelect.value;
   publisherSelect.replaceChildren();
   for(const g of games){
    const option=document.createElement('option');
    option.value=g.id;option.textContent=g.title+' · '+g.id;
    publisherSelect.append(option);
   }
   if(games.some(g=>g.id===current))publisherSelect.value=current;
   else if(games.some(g=>g.id===selected))publisherSelect.value=selected;
  }
  const id=games.find(g=>g.id===preferred)?.id||games.find(g=>g.id===selected)?.id||games[0]?.id;
  paintList();if(id)edit(id);else $('catalog-editor').hidden=true;
 }
 $('catalog-editor').onsubmit=async ev=>{
  ev.preventDefault();if(!selected)return;
  const body={id:selected};
  for(const [key,control] of Object.entries(fields))body[key]=$(control).value;
  body.playersMin=Number($('catalog-min').value);body.playersMax=Number($('catalog-max').value);
  body.controllerMode=$('catalog-controller').value;body.enabled=$('catalog-enabled').checked;
   body.splash=splashValues();
   if(!body.splash.intro.title){note('Enter a splash title.',true);return;}
  try {note('Saving…');const game=games.find(g=>g.id===selected);
    // Existing game_settings data remains authoritative for playable games. Drafts keep settings in catalog metadata until installed.
    if(game?.status==='ready')await api('settings_save',{body:{game:selected,...body.splash}});
    await api('game_catalog_save',{body});await reload(selected);note('Game profile and splash settings saved.');}
  catch(e){note(e.message,true);}
 };
 document.querySelectorAll('[data-game-art]').forEach(input=>input.onchange=async()=>{
  const file=input.files?.[0],slot=input.dataset.gameArt;if(!file||!selected)return;
  if(file.size>6*1024*1024){note('Artwork must be 6MB or less.',true);input.value='';return;}
  const body=new FormData();body.append('id',selected);body.append('slot',slot);body.append('image',file);
  try{note('Uploading '+slot+' artwork…');await api('game_art_upload',{body});await reload(selected);note(slot+' image saved.');}
  catch(e){note(e.message,true);}finally{input.value='';}
 });
 $('catalog-new-game').onclick=async()=>{
  const requested=prompt('New game ID (lowercase letters, numbers and hyphens):');
  if(requested===null)return;
  const id=requested.trim().toLowerCase();
  if(!/^[a-z0-9][a-z0-9-]{1,62}$/.test(id)){note('Use 2–63 lowercase letters, numbers or hyphens.',true);return;}
  if(games.some(g=>g.id===id)){edit(id);return;}
  try{
   await api('game_catalog_save',{body:{id,title:id,enabled:false,controllerMode:'custom',playersMin:1,playersMax:8}});
   await reload(id);note('Draft game listing created. Install a game module before making it playable.');
  }catch(e){note(e.message,true);}
 };
 
 let packagePending=false;
 const packageNote=(message,bad=false)=>{
  const status=$('game-package-status');status.textContent=message;
  status.classList.toggle('platform-error',bad);
 };
 async function loadPackages(){
  const result=await api('game_packages');
  const rows=Array.isArray(result.packages)?result.packages:[];
  const issues=Array.isArray(result.issues)?result.issues:[];
  const warning=$('game-package-health');
  warning.hidden=issues.length===0;
  warning.textContent=issues.length?
    'Some game packages need attention: '+issues.map(i=>i.game).join(', ')+
    '. The affected game is disabled until you repair its installation. Other games remain available.':'';
  const area=$('game-package-list');area.replaceChildren();
  if(!rows.length){area.append(text('p','No game packages uploaded yet. Spin the Wheel is already bundled.'));return;}
  const active=result.active||{};
  const damaged=new Set(issues.filter(i=>i.reason==='invalid_activation').map(i=>i.game));
  for(const game of rows){
   const card=text('article','','game-update-release');
   const now=active[game.id]?.version||null;
   card.append(text('strong',game.title+' · '+game.id+' · v'+game.version));
   card.append(text('small',(game.phone?'Custom phone UI included':'No phone UI')+
    ' · '+(game.signatureVerified?'Publisher signature verified':'Locally uploaded game')));
   card.append(text('small',game.capabilities?.length
    ?'SDK capabilities: '+game.capabilities.join(', ')+' · Data schema v'+(game.dataVersion||1)
    :'SDK capabilities: none (sandboxed UI and basic inputs only)'));
   card.append(text('small','Imported '+game.installedAt+' · '+game.fileCount+' files · '+
    (game.builtIn?'Protected built-in game update':now===game.version?'Currently active':'Available for activation')));
   if(!game.builtIn&&damaged.has(game.id)){
    const repair=document.createElement('button');
    repair.className='btn btn-outline';repair.type='button';
    repair.textContent='Recover using v'+game.version;
    repair.onclick=async()=>{
     if(!confirm('This game\'s active-version record is damaged. Restore v'+game.version+
       ' from its locally staged package? The damaged record will be preserved and other games unaffected.'))return;
     repair.disabled=true;
     try{
      const out=await api('game_package_recover',{body:{game:game.id,version:game.version,confirm:true}});
      packageNote(out.recovery.game+' recovered to v'+out.recovery.version+'.');
      await loadPackages();await reload(game.id);
     }catch(e){packageNote(e.message,true);repair.disabled=false;}
    };
    card.append(repair);
   }
   if(!game.builtIn&&!damaged.has(game.id)){
    const activate=document.createElement('button');
    activate.className='btn btn-outline';activate.type='button';
    activate.textContent=now===game.version?'Active version':(now?'Switch / Roll back to this version':'Activate game version');
    activate.disabled=now===game.version;
    activate.onclick=async()=>{
     if(!confirm('Activate '+game.title+' v'+game.version+'? Requested SDK permissions: '+
      ((game.capabilities||[]).join(', ')||'none')+'. Existing sessions must be saved or ended.'))return;
     activate.disabled=true;
     try{
      const out=await api('game_package_activate',{body:{
       game:game.id,version:game.version,expectedCurrent:now,confirm:true
      }});
      packageNote(out.activation.game+' v'+out.activation.version+' activated. Existing versions are retained.');
      await loadPackages();await reload(game.id);
     }catch(e){packageNote(e.message,true);activate.disabled=false;}
    };
    card.append(activate);
   }
   area.append(card);
  }
 }
 $('game-package-refresh').onclick=()=>loadPackages().catch(e=>packageNote(e.message,true));
 $('game-package-upload').onsubmit=async event=>{
  event.preventDefault();if(packagePending)return;
  const form=event.currentTarget;const file=form.querySelector('[name=package]')?.files?.[0];
  if(!file){packageNote('Select a game ZIP file.',true);return;}
  if(file.size>40*1024*1024){packageNote('The game package exceeds 40 MB.',true);return;}
  packagePending=true;const button=form.querySelector('button[type=submit]');button.disabled=true;
  try{
   packageNote('Verifying package paths, manifest, file hashes and publisher signature…');
   const body=new FormData();body.append('package',file);
   const result=await api('game_package_upload',{body});
   packageNote(result.package.title+' v'+result.package.version+
    ' verified and staged. Gameplay was not replaced or activated.');
   form.reset();await loadPackages();
  }catch(e){packageNote(e.message,true);}
  finally{packagePending=false;button.disabled=false;}
 };
 loadPackages().catch(e=>packageNote('Unable to load packages: '+e.message,true));


 let publisherChannels=[];
 const publisherStatus=(message,bad=false)=>{
  const e=$('game-publisher-status');e.textContent=message;
  e.classList.toggle('platform-error',bad);
 };
 async function loadPublisherChannels(){
  const result=await api('game_publisher_channels');
  publisherChannels=result.channels||[];
  const area=$('game-publisher-list');area.replaceChildren();
  if(!publisherChannels.length){
   area.append(text('p','No game-specific publishers connected. Every game stays offline unless you explicitly check a configured channel.'));
   return;
  }
  for(const config of publisherChannels){
   const card=text('article','','game-update-release');
   card.append(text('strong',config.game+' · '+(config.publisher||'Invalid publisher configuration')));
   if(!config.connected){
    card.append(text('small','Trust configuration needs repair; game updates are disabled.'));
    area.append(card);continue;
   }
   card.append(text('small','HTTPS: '+config.url));
   card.append(text('small','Pinned key: '+config.keyFingerprint.slice(0,24)+'… · No automatic checks/install'));
   const controls=text('div','','platform-control-row');
   const check=text('button','Check game updates','btn btn-outline');check.type='button';
   check.onclick=async()=>{
    check.disabled=true;
    try{
     publisherStatus('Verifying signed releases for '+config.game+'…');
     const result=await api('game_publisher_check',{body:{game:config.game}});
     const releases=$('game-publisher-releases');releases.replaceChildren();
     releases.append(text('p',result.updates.releases.length+' compatible signed release(s) for '+config.game+'. No download performed.'));
     for(const release of result.updates.releases){
      const row=text('article','','game-update-release');
      row.append(text('strong',release.game+' · v'+release.version));
      row.append(text('small','Requires game SDK ≥ '+release.sdkMin+' · SHA-256: '+release.sha256));
      if(release.notes)row.append(text('p',release.notes));
      const stage=text('button','Download & stage verified ZIP','btn btn-outline');
      stage.type='button';stage.disabled=release.bytes>40*1024*1024||release.compatible===false;
      if(release.compatible===false)stage.textContent='Requires newer game SDK ('+release.sdkMin+')';
      else if(release.bytes>40*1024*1024)stage.textContent='Exceeds 40 MB package limit';
      stage.onclick=async()=>{
       if(!confirm('Download and stage '+release.game+' v'+release.version+' from '+config.publisher+'? No game is activated.'))return;
       stage.disabled=true;
       try{
        publisherStatus('Rechecking signature and downloading '+release.game+' v'+release.version+'…');
        const output=await api('game_update_stage',{body:{game:config.game,version:release.version}});
        publisherStatus(output.package.title+' v'+output.package.version+' staged and verified; activation is separate.');
        await loadPackages();
       }catch(e){publisherStatus(e.message,true);}finally{stage.disabled=false;}
      };
      row.append(stage);releases.append(row);
     }
     publisherStatus('Signature and SDK requirements verified for '+config.game+'.');
    }catch(e){publisherStatus(e.message,true);}
    finally{check.disabled=false;}
   };
   const revoke=text('button','Disconnect this publisher','btn btn-outline');revoke.type='button';
   revoke.onclick=async()=>{
    if(!confirm('Disconnect '+config.publisher+' from '+config.game+'? Local games and saved sessions will remain.'))return;
    revoke.disabled=true;
    try{
     await api('game_publisher_disconnect',{body:{game:config.game,confirm:true}});
     $('game-publisher-releases').replaceChildren();
     publisherStatus(config.game+' update trust disconnected. Installed games remain local.');
     await loadPublisherChannels();
    }catch(e){publisherStatus(e.message,true);revoke.disabled=false;}
   };
   controls.append(check,revoke);card.append(controls);area.append(card);
  }
 }
 $('game-publisher-form').onsubmit=async event=>{
  event.preventDefault();
  const game=$('game-publisher-game').value;
  const body={game,publisher:$('game-publisher-name').value.trim(),
   url:$('game-publisher-url').value.trim(),
   publicKey:$('game-publisher-key').value.trim(),
   token:$('game-publisher-token').value};
  if(!game){publisherStatus('Select a game from the catalog.',true);return;}
  try{
   publisherStatus('Saving game-specific publisher trust…');
   try{await api('game_publisher_connect',{body});}
   catch(e){
    if(!e.message.includes('Explicitly confirm')||
       !confirm('This will replace the previously trusted publisher identity, feed or signing key for '+game+'. Continue?'))throw e;
    await api('game_publisher_connect',{body:{...body,confirmReplace:true}});
   }
   $('game-publisher-key').value='';$('game-publisher-token').value='';
   await loadPublisherChannels();
   publisherStatus('Publisher connected to '+game+'. No updates were downloaded or activated.');
  }catch(e){publisherStatus(e.message,true);}
 };
 loadPublisherChannels().catch(e=>publisherStatus('Unable to read publisher trust: '+e.message,true));

 let connected=false;
 const updateMessage=(message,bad=false)=>{
  const node=$('game-update-message');node.textContent=message;node.classList.toggle('platform-error',bad);
 };
 async function loadUpdateConnection(){
  const result=await api('game_update_connection');const cfg=result.connection||{};
  connected=!!cfg.connected;
  $('game-update-connection-status').textContent=connected?'Connected · signed updates':'Not connected · offline';
  $('game-update-check').disabled=!connected;
  $('game-update-disconnect').disabled=!connected;
  $('game-update-url').value=cfg.url||'';
  if(connected)updateMessage('Verified-key fingerprint: '+cfg.keyFingerprint.slice(0,16)+'… The signing key and access token stay private.');
  else updateMessage('No cloud requests will be made unless you connect and click Check for updates.');
 }
 $('game-update-connect-form').onsubmit=async ev=>{
  ev.preventDefault();
  const body={url:$('game-update-url').value.trim(),
   publicKey:$('game-update-public-key').value.trim(),
   token:$('game-update-token').value.trim()};
  try{
   updateMessage('Saving trusted update connection…');
   await api('game_update_connect',{body});
   $('game-update-public-key').value='';$('game-update-token').value='';
   await loadUpdateConnection();
  }catch(e){updateMessage(e.message,true);}
 };
 $('game-update-disconnect').onclick=async()=>{
  if(!connected||!confirm('Disconnect from the cloud update feed? Local games remain available.'))return;
  try{await api('game_update_disconnect',{body:{}});$('game-update-releases').replaceChildren();await loadUpdateConnection();}
  catch(e){updateMessage(e.message,true);}
 };
 $('game-update-check').onclick=async()=>{
  const control=$('game-update-check');control.disabled=true;
  try{
   updateMessage('Checking publisher signature and release metadata…');
   const {updates}=await api('game_update_check',{body:{}});
   const list=$('game-update-releases');list.replaceChildren();
   const heading=text('p',updates.releases.length+' verified game release(s) · No download performed until you select a release.');
   list.append(heading);
   for(const release of updates.releases){
    const item=text('article','','game-update-release');
    item.append(text('strong',release.game+' · v'+release.version));
    if(release.notes)item.append(text('p',release.notes));
    item.append(text('small','SHA-256: '+release.sha256+' · '+Math.ceil(release.bytes/1024)+' KB'));
    const stage=document.createElement('button');stage.type='button';stage.className='btn btn-outline';
    stage.textContent='Download & stage verified ZIP';
    stage.disabled=release.bytes>40*1024*1024;
    if(stage.disabled)stage.textContent='Exceeds 40 MB package limit';
    stage.onclick=async()=>{
     if(!confirm('Download and stage '+release.game+' v'+release.version+' from the trusted publisher? No game will be activated.'))return;
     stage.disabled=true;
     try{
      updateMessage('Downloading and validating signed '+release.game+' v'+release.version+'…');
      const output=await api('game_update_stage',{body:{game:release.game,version:release.version}});
      await loadPackages();
      updateMessage(output.package.title+' v'+output.package.version+' downloaded and staged. No executable game code was activated.');
     }catch(e){updateMessage(e.message,true);}finally{stage.disabled=false;}
    };
    item.append(stage);
    list.append(item);
   }
   updateMessage('Publisher signature verified. Select a release to download and stage it. No game code will execute.');
  }catch(e){updateMessage(e.message,true);}
  finally{control.disabled=!connected;}
 };
 loadUpdateConnection().catch(e=>updateMessage('Update settings unavailable: '+e.message,true));

 reload().catch(e=>note('Unable to load game catalog: '+e.message,true));
})();
