(() => {
 'use strict';
 const $=id=>document.getElementById(id),L=window.Lucky;
 const game=new URLSearchParams(location.search).get('game');
 const validGame=typeof game==='string'&&/^[a-z0-9][a-z0-9-]{1,62}$/.test(game);
 const status=$('package-status'),frame=$('package-frame');
 let session=null,loading=false,ending=false,polling=false;
 const phonePanel=$('pkg-phone-panel');
 let lastPhoneURL='',hostCsrf='',phoneLoading=false;
 function updatePhoneInvite(s){
  if(!phonePanel)return;
  const display=$('pkg-phone-invite');
  const live=s&&['active','paused'].includes(s.view.status);
  display.hidden=!live;
  if(!live){lastPhoneURL='';$('pkg-phone-state').textContent='Start a game to enable the phone controller.';return;}
  const href=new URL('package-controller.php?'+new URLSearchParams({
    session:s.view.id,viewer:s.viewerToken
  }),location.href).href;
  if(lastPhoneURL!==href){
   lastPhoneURL=href;$('pkg-phone-link').value=href;
   if(window.LuckyQR)$('pkg-phone-qr').innerHTML=LuckyQR.svg(href);
  }
  $('pkg-phone-state').textContent='Scan the QR code and approve each phone below.';
 }
 async function hostController(action,body={}){
  if(!hostCsrf){const me=await L.getMe();hostCsrf=me.csrf;
   if(!hostCsrf)throw Error('Host sign-in required.');}
  const response=await fetch('api/controller.php?action='+encodeURIComponent(action),{
   method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':hostCsrf},
   credentials:'same-origin',cache:'no-store',body:JSON.stringify(body)
  });
  const data=await response.json();
  if(!response.ok||!data.ok)throw Error(data.error||'Pairing request failed.');
  return data;
 }
 async function listPhones(){
  if(!phonePanel||phoneLoading||!session||ending)return;
  phoneLoading=true;
  try{
   const answer=await hostController('list'),panel=$('pkg-phone-pairs');
   panel.replaceChildren();
   const phones=(answer.pairs||[]).filter(p=>['pending','paired'].includes(p.status));
   if(!phones.length){const empty=document.createElement('p');empty.textContent='No phones awaiting approval.';panel.append(empty);}
   for(const phone of phones){
    const row=document.createElement('div');row.className='phone-pair-device';
    const title=document.createElement('strong');title.textContent=phone.device_name||'Phone';
    const state=document.createElement('span');state.textContent=phone.status==='paired'?'Connected':'Awaiting approval';
    row.append(title,state);
    if(phone.status==='pending'){
     const approve=document.createElement('button');approve.className='btn btn-primary';approve.textContent='Approve';approve.type='button';
     approve.onclick=()=>hostController('approve',{id:phone.id}).then(listPhones).catch(e=>say(e.message,true));
     row.append(approve);
    }
    const reject=document.createElement('button');reject.className='btn btn-outline';reject.textContent='Disconnect';reject.type='button';
    reject.onclick=()=>hostController('revoke',{id:phone.id}).then(listPhones).catch(e=>say(e.message,true));
    row.append(reject);panel.append(row);
   }
  }catch(e){$('pkg-phone-state').textContent=e.message;}
  finally{phoneLoading=false;}
 }

 const say=(message,bad=false)=>{status.textContent=message;status.classList.toggle('platform-error',bad);};
 function viewURL(s){
  return 'package-view.php?'+new URLSearchParams({
   game,version:s.session.packageVersion,session:s.view.id,
   viewer:s.viewerToken,role:'host'
  });
 }
 function configure(s){
  session=s;
  updatePhoneInvite(s);
  const live=s&&['active','paused'].includes(s.view.status);
  $('pkg-start').disabled=loading||live;
  $('pkg-pause').disabled=loading||!live;
  $('pkg-pause').textContent=s?.view?.status==='paused'?'Resume':'Pause';
  $('pkg-end').disabled=loading||!live;
  $('pkg-save').disabled=loading||!live;
  if(!live){frame.hidden=true;frame.removeAttribute('src');return;}
  // Only run the exact immutable version pinned by this game session.
  const src=viewURL(s);
  if(frame.dataset.source!==src){frame.src=src;frame.dataset.source=src;}
  frame.hidden=false;
  say(s.view.status==='paused'?'Game paused · session saved locally':
   'Playing · session '+s.view.id.slice(0,8)+' · v'+s.session.packageVersion);
  sendState(s.view);
 }
 function sendState(view){if(!frame.hidden&&frame.contentWindow)
  frame.contentWindow.postMessage({type:'lucky:state',state:view},'*');}
 async function reload(){
  if(polling||ending||!validGame)return;polling=true;
  try{
   const response=session?.view?.id?await L.api('session',{query:{id:session.view.id}}):
     (await L.api('last',{query:{game}})).data;
   if(response){
    if(session&&['active','paused'].includes(session.view.status)&&response.view.status==='ended'){
     ending=true;location.assign('index.php');return;
    }
    if(!session){if(['active','paused'].includes(response.view.status))configure(response);}
    else{session={...response,viewerToken:response.viewerToken||session.viewerToken};configure(session);}
   }else if(!session)say('Ready. Start a game to create a local session.');
  }catch(e){say('Game connection unavailable: '+e.message,true);}
  finally{polling=false;}
 }
 async function startGame(){
  if(loading||!validGame)return;loading=true;configure(session);
  try{const result=await L.api('start',{body:{game}});configure(result.data);}
  catch(e){say(e.message,true);}finally{loading=false;configure(session);}
 }
 async function phoneAutostart(){
  try{
   const req=JSON.parse(sessionStorage.getItem('lucky-phone-dashboard-autostart')||'null');
   sessionStorage.removeItem('lucky-phone-dashboard-autostart');
   if(req?.game===game&&Date.now()-req.created<30000){
    await startGame();
   }
  }catch(_){}
 }
 $('pkg-start').onclick=startGame;
 async function command(verb,payload){
  if(loading||!session||session.view.status==='ended')return;
  loading=true;
  try{
   const action=['pause','resume','end'].includes(verb)?verb:'game_action';
   const result=await L.api(action,{body:{
    id:session.view.id,idempotencyKey:L.idempotency(),
    ...(action==='game_action'?{verb,payload}: {})
   }});
   if(result.result?.view?.status==='ended'){ending=true;location.assign('index.php');return;}
   session={...session,view:result.result.view};configure(session);
  }catch(e){say(e.message,true);}finally{loading=false;if(!ending)configure(session);}
 }
 $('pkg-pause').onclick=()=>command(session?.view.status==='paused'?'resume':'pause');
 $('pkg-end').onclick=()=>{if(confirm('Finish game? This session stays in history.'))command('end');};
 $('pkg-save').onclick=async()=>{
  if(loading||!session)return;loading=true;
  try{
   await L.api('save_session',{body:{id:session.view.id,idempotencyKey:L.idempotency()}});
   ending=true;location.assign('index.php');
  }catch(e){say(e.message,true);}finally{loading=false;}
 };
 let capabilitiesBusy=false;
 async function handleCapability(request){
  const reply=(ok,value,error)=>{
   if(frame.contentWindow)frame.contentWindow.postMessage({
    type:'lucky:capability-result',requestId:request.requestId,ok,
    ...(ok?{value}:{error:String(error||'SDK operation unavailable')})
   },'*');
  };
  if(capabilitiesBusy||loading||!session||session.view.status!=='active'){
   reply(false,null,'Game is busy or paused');return;
  }
  if(typeof request.operation!=='string'||!/^[a-z]+\.[a-z]+$/.test(request.operation)||
     !request.args||typeof request.args!=='object'||Array.isArray(request.args)||
     JSON.stringify(request.args).length>8192){
   reply(false,null,'Invalid game capability request');return;
  }
  capabilitiesBusy=true;
  try{
   const result=await L.api('plugin_capability',{body:{
    id:session.view.id,operation:request.operation,args:request.args,
    expectedVersion:session.view.version,idempotencyKey:L.idempotency()
   }});
   // Resolve a request before possibly closing its origin sandbox.
   reply(true,result.result.capability.value);
   session={...session,view:result.result.view};
   sendState(session.view);
   if(session.view.status==='saved'){
    ending=true;location.assign('index.php');return;
   }
   configure(session);
  }catch(e){reply(false,null,e.message);}
  finally{capabilitiesBusy=false;}
 }
 window.addEventListener('message',event=>{
  if(!session||event.source!==frame.contentWindow||event.origin!=='null')return;
  const input=event.data;
  if(input?.type==='lucky:capability'){
   if(typeof input.requestId!=='string'||!/^sdk-[a-zA-Z0-9-]{3,64}$/.test(input.requestId))return;
   void handleCapability(input);return;
  }
  if(session.view.status!=='active')return;
  if(!input||input.type!=='lucky:input'||typeof input.name!=='string'||!(/^[a-z][a-z0-9_-]{0,39}$/).test(input.name))return;
  if(!['string','number','boolean'].includes(typeof input.value)&&input.value!==null)return;
  if(typeof input.value==='string'&&input.value.length>240)return;
  if(typeof input.value==='number'&&(!Number.isSafeInteger(input.value)||Math.abs(input.value)>100000000))return;
  command('input',{name:input.name,value:input.value});
 });
 frame.addEventListener('load',()=>{if(session)sendState(session.view);});
 if(!validGame){say('Invalid game identifier.',true);return;}
 reload();void phoneAutostart();setInterval(reload,1000);
 if(phonePanel){setInterval(listPhones,2000);}
})();
