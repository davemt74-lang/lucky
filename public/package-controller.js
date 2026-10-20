(() => {
 'use strict';
 const $=id=>document.getElementById(id),query=new URLSearchParams(location.search);
 const key='lucky-package-controller-v1';
 let pair=null,game=null,busy=false,polling=false,ended=false,pending=null;
 const pendingKey=key+'-pending';
 const status=message=>$('package-phone-status').textContent=message;
 const valid=(value,pattern)=>typeof value==='string'&&pattern.test(value);
 const hex32=/^[a-f0-9]{32}$/,hex48=/^[a-f0-9]{48}$/;
 try{const cached=JSON.parse(sessionStorage.getItem(key)||'null');
  if(cached&&valid(cached.session,hex32)&&valid(cached.viewer,hex48)&&
    valid(cached.id,hex32)&&valid(cached.token,/^[a-f0-9]{64}$/))pair=cached;
 }catch(_){}
 if(pair&&query.has('session')&&(pair.session!==query.get('session')||pair.viewer!==query.get('viewer'))){
  pair=null;sessionStorage.removeItem(key);
 }
 const session=query.get('session')||pair?.session;
 const viewer=query.get('viewer')||pair?.viewer;
 if(!valid(session,hex32)||!valid(viewer,hex48)){status('Scan the current game QR code to connect.');$('package-phone-join-form').querySelector('button').disabled=true;return;}
 try{
  const saved=JSON.parse(sessionStorage.getItem(pendingKey)||'null');
  if(pair&&saved&&saved.id===pair.id&&saved.session===session&&saved.viewer===viewer
    &&valid(saved.nonce,/^[a-zA-Z0-9_-]{12,80}$/)
    &&['input','pause','resume','end'].includes(saved.verb)
    &&Number.isSafeInteger(saved.expectedVersion)&&saved.expectedVersion>0)
   pending=saved;
  else sessionStorage.removeItem(pendingKey);
 }catch(_){sessionStorage.removeItem(pendingKey);}
 function clearPending(){
  pending=null;sessionStorage.removeItem(pendingKey);updateButtons();
 }
 function updateButtons(){
  $('package-phone-retry').hidden=!pending||ended;
  $('package-phone-discard').hidden=!pending||ended;
  $('package-phone-pause').disabled=!!pending||busy||ended;
  $('package-phone-end').disabled=!!pending||busy||ended;
 }

 async function request(action,body={},authorized=true){
  const headers={'Content-Type':'application/json'};
  if(authorized){if(!pair)throw Error('Phone pairing required.');headers['X-Controller-Token']=pair.token;body={id:pair.id,...body};}
  const response=await fetch('api/controller.php?action='+encodeURIComponent(action),{
    method:'POST',headers,body:JSON.stringify(body),cache:'no-store',credentials:'same-origin'
  });
  const data=await response.json();if(!response.ok||!data.ok)throw Error(data.error||'Controller request failed.');
  return data;
 }
 function show(id){
  for(const section of ['join','wait','play','finished'])$('package-phone-'+section).hidden=section!==id;
 }
 function closeGame(){
  const iframe=$('package-phone-frame');
  iframe.hidden=true;
  iframe.removeAttribute('src');
  delete iframe.dataset.source;
  // Stop the old game's timers/audio when its persisted session ends or
  // the owner's approval is revoked. A hidden frame alone keeps running.
  try{iframe.contentWindow?.location.replace('about:blank');}catch(_){}
 }
 function refreshGame(view){
  if(ended||!view)return;
  game=view;
  if(view.status==='ended'||view.status==='saved'){
   ended=true;clearPending();closeGame();show('finished');
   status('Game ended · controller disconnected from gameplay.');return;
  }
  const playing=view.status==='active';
  show('play');
  $('package-phone-pause').textContent=playing?'Pause':'Resume';
  const iframe=$('package-phone-frame');
  iframe.hidden=false;
  const url='package-view.php?'+new URLSearchParams({
    game:view.game,version:view.packageVersion,session,viewer,role:'phone'
  });
  if(iframe.dataset.source!==url){iframe.dataset.source=url;iframe.src=url;}
  iframe.contentWindow?.postMessage({type:'lucky:state',state:view},'*');
  status(pending?'Previous action may have reached the game. Retry it with the same request ID or discard it.':
    playing?'Connected · game-specific controls active':'Game paused');
  updateButtons();
 }
 async function poll(){
  if(polling||busy||!pair||ended)return;polling=true;
  try{
   const reply=await request('package_status',{session,viewer});
   if(reply.status==='pending'){show('wait');status('Waiting for host approval…');}
   else if(reply.status==='paired')refreshGame(reply.game);
  }catch(e){
   status(e.message);
   if(/expired|disconnected|revoked|authorization|invalid/i.test(e.message)){
    clearPending();closeGame();sessionStorage.removeItem(key);pair=null;game=null;show('join');
   }else show('wait');
  }finally{polling=false;}
 }
 $('package-phone-join-form').onsubmit=async event=>{
  event.preventDefault();
  const name=$('package-phone-name').value.trim();
  if(!name)return;
  busy=true;
  try{
   const out=await request('claim_display',{session,viewer,name},false);
   pair={id:out.id,token:out.controllerToken,session,viewer};
   sessionStorage.setItem(key,JSON.stringify(pair));
   history.replaceState(null,'','package-controller.php');
   show('wait');status('Waiting for the host to approve your phone.');
  }catch(e){status(e.message);}
  finally{busy=false;}
 };
 async function action(verb,payload={},confirmEnd=false,retry=false){
  if(!pair||busy||ended||!game||(!retry&&pending))return;
  if(retry&&!pending)return;
  busy=true;
  const command=retry?pending:{
   id:pair.id,session,viewer,verb,payload,confirm:confirmEnd,
   expectedVersion:game.version,
   nonce:crypto.randomUUID?crypto.randomUUID().replace(/-/g,''):
    Math.random().toString(36).slice(2).padEnd(16,'0')
  };
  // Persist before sending so a refresh after lost Wi-Fi will reuse the nonce.
  pending=command;sessionStorage.setItem(pendingKey,JSON.stringify(command));
  updateButtons();
  try{
   const reply=await request('package_command',{
    session,viewer,verb:command.verb,payload:command.payload,confirm:command.confirm,
    expectedVersion:command.expectedVersion,nonce:command.nonce
   });
   clearPending();refreshGame(reply.game);
  }catch(e){
   status('Command not confirmed: '+e.message+'. Retry the same command or discard it.');
  }finally{busy=false;updateButtons();}
 }
 $('package-phone-retry').onclick=()=>action('',{},false,true);
 $('package-phone-discard').onclick=()=>{
  if(!pending||busy)return;
  if(!confirm('Discard this unconfirmed command? Check the current game state first.'))return;
  clearPending();status('Unconfirmed command discarded. Current game state will refresh.');
 };
 $('package-phone-pause').onclick=()=>action(game?.status==='paused'?'resume':'pause');
 $('package-phone-end').onclick=()=>{
  if(confirm('End this game for everyone? The desktop will return to the dashboard.'))action('end',{},true);
 };
 $('package-phone-disconnect').onclick=async()=>{
  try{await request('disconnect');}catch(_){}
  clearPending();sessionStorage.removeItem(key);pair=null;ended=true;
  closeGame();status('Phone disconnected.');show('finished');
 };
 window.addEventListener('message',event=>{
  if(!pair||ended||busy||pending||game?.status!=='active')return;
  const iframe=$('package-phone-frame');
  if(event.source!==iframe.contentWindow||event.origin!=='null')return;
  const input=event.data;
  if(!input||input.type!=='lucky:input')return;
  if(!valid(input.name,/^[a-z][a-z0-9_-]{0,39}$/))return;
  if(!(input.value===null||['string','number','boolean'].includes(typeof input.value)))return;
  if(typeof input.value==='string'&&input.value.length>240)return;
  if(typeof input.value==='number'&&(!Number.isSafeInteger(input.value)||Math.abs(input.value)>100000000))return;
  action('input',{name:input.name,value:input.value});
 });
 $('package-phone-frame').addEventListener('load',()=>{if(game&&!ended)refreshGame(game);});
 if(pair){show('wait');status(pending?
  'Recovering connection; unconfirmed command can be retried safely.':
  'Reconnecting approved phone…');}
 updateButtons();
 poll();setInterval(poll,1000);
})();
