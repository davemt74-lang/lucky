(() => {
 'use strict';
 const $=id=>document.getElementById(id),query=new URLSearchParams(location.search);
 const key='lucky-package-controller-v1';
 let pair=null,game=null,busy=false,polling=false,ended=false;
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
 function refreshGame(view){
  if(!view)return;
  game=view;
  if(view.status==='ended'||view.status==='saved'){ended=true;show('finished');status('Game ended · controller disconnected from gameplay.');return;}
  const playing=view.status==='active';
  show('play');
  $('package-phone-pause').textContent=playing?'Pause':'Resume';
  const iframe=$('package-phone-frame');
  const url='package-view.php?'+new URLSearchParams({
    game:view.game,version:view.packageVersion,session,viewer,role:'phone'
  });
  if(iframe.dataset.source!==url){iframe.dataset.source=url;iframe.src=url;}
  iframe.contentWindow?.postMessage({type:'lucky:state',state:view},'*');
  status(playing?'Connected · game-specific controls active':'Game paused');
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
    sessionStorage.removeItem(key);pair=null;show('join');
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
 async function action(verb,payload={},confirmEnd=false){
  if(!pair||busy||ended)return;busy=true;
  try{
   const nonce=crypto.randomUUID?crypto.randomUUID().replace(/-/g,''):Math.random().toString(36).slice(2).padEnd(16,'0');
   const reply=await request('package_command',{session,viewer,verb,payload,confirm:confirmEnd,nonce});
   refreshGame(reply.game);
  }catch(e){status(e.message);}
  finally{busy=false;}
 }
 $('package-phone-pause').onclick=()=>action(game?.status==='paused'?'resume':'pause');
 $('package-phone-end').onclick=()=>{
  if(confirm('End this game for everyone? The desktop will return to the dashboard.'))action('end',{},true);
 };
 $('package-phone-disconnect').onclick=async()=>{
  try{await request('disconnect');}catch(_){}
  sessionStorage.removeItem(key);pair=null;ended=true;
  status('Phone disconnected.');show('finished');
 };
 window.addEventListener('message',event=>{
  if(!pair||ended||busy||game?.status!=='active')return;
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
 $('package-phone-frame').addEventListener('load',()=>{if(game)refreshGame(game);});
 if(pair){show('wait');status('Reconnecting approved phone…');}
 poll();setInterval(poll,1000);
})();
