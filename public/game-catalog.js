(() => {
 'use strict';
 const $=id=>document.getElementById(id), api=Lucky.api;
 const text=(tag,value='',cls='')=>{const e=document.createElement(tag);e.textContent=value;e.className=cls;return e;};
 let games=[],selected='';
 const fields={title:'catalog-title',tagline:'catalog-tagline',description:'catalog-description',
  publisherName:'catalog-publisher',publisherWebsite:'catalog-publisher-website',
  category:'catalog-category',version:'catalog-version',ageRating:'catalog-age'};
 const artURL=(id,name)=>'api/index.php?'+new URLSearchParams({action:'game_art',id,file:name});
 const note=(msg,bad=false)=>{const e=$('catalog-save-status');e.textContent=msg;e.classList.toggle('platform-error',bad);};
 function showImages(game){
  for(const slot of ['cover','hero','logo']){
   const img=$('catalog-'+slot+'-preview'),name=game.images?.[slot];
   img.hidden=!name;if(name)img.src=artURL(game.id,name);else img.removeAttribute('src');
  }
  const gallery=$('catalog-gallery');gallery.replaceChildren();
  for(const name of game.images?.gallery||[]){
   const img=document.createElement('img');img.alt='Screenshot from '+game.title;
   img.loading='lazy';img.src=artURL(game.id,name);gallery.append(img);
  }
 }
 function edit(id){
  const game=games.find(item=>item.id===id);if(!game)return;
  selected=id;$('catalog-editor').hidden=false;
  $('catalog-id').value=id;$('catalog-editor-heading').textContent=game.title;
  $('catalog-execution-status').textContent=game.status==='ready'
   ?'Built-in game · protected from deletion · updates supported · '+game.source
   :'Draft listing · Game module not installed; cannot be launched';
  for(const [key,control] of Object.entries(fields))$(control).value=game[key]??'';
  $('catalog-min').value=game.playersMin;
  $('catalog-max').value=game.playersMax;
  $('catalog-controller').value=game.controllerMode;
  $('catalog-controller').disabled=game.status==='ready';
  $('catalog-enabled').checked=!!game.enabled;
  showImages(game);paintList();note('');
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
  const id=games.find(g=>g.id===preferred)?.id||games.find(g=>g.id===selected)?.id||games[0]?.id;
  paintList();if(id)edit(id);else $('catalog-editor').hidden=true;
 }
 $('catalog-editor').onsubmit=async ev=>{
  ev.preventDefault();if(!selected)return;
  const body={id:selected};
  for(const [key,control] of Object.entries(fields))body[key]=$(control).value;
  body.playersMin=Number($('catalog-min').value);body.playersMax=Number($('catalog-max').value);
  body.controllerMode=$('catalog-controller').value;body.enabled=$('catalog-enabled').checked;
  try {note('Saving…');await api('game_catalog_save',{body});await reload(selected);note('Game profile saved to local catalog.');}
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
   const heading=text('p',updates.releases.length+' verified game release(s) · No download or installation performed.');
   list.append(heading);
   for(const release of updates.releases){
    const item=text('article','','game-update-release');
    item.append(text('strong',release.game+' · v'+release.version));
    if(release.notes)item.append(text('p',release.notes));
    item.append(text('small','SHA-256: '+release.sha256+' · '+Math.ceil(release.bytes/1024)+' KB'));
    list.append(item);
   }
   updateMessage('Publisher signature verified. The HomeServer has not installed or executed anything.');
  }catch(e){updateMessage(e.message,true);}
  finally{control.disabled=!connected;}
 };
 loadUpdateConnection().catch(e=>updateMessage('Update settings unavailable: '+e.message,true));

 reload().catch(e=>note('Unable to load game catalog: '+e.message,true));
})();
