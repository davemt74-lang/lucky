(() => {
 'use strict';
 const $=id=>document.getElementById(id),L=window.Lucky;
 const game=new URLSearchParams(location.search).get('game');
 const validGame=typeof game==='string'&&/^[a-z0-9][a-z0-9-]{1,62}$/.test(game);
 const status=$('package-status'),frame=$('package-frame');
 let session=null,loading=false,ending=false,polling=false;
 const say=(message,bad=false)=>{status.textContent=message;status.classList.toggle('platform-error',bad);};
 function viewURL(s){
  return 'package-view.php?'+new URLSearchParams({
   game,version:s.session.packageVersion,session:s.view.id,
   viewer:s.viewerToken,role:'host'
  });
 }
 function configure(s){
  session=s;
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
 $('pkg-start').onclick=async()=>{
  if(loading||!validGame)return;loading=true;configure(session);
  try{const result=await L.api('start',{body:{game}});configure(result.data);}
  catch(e){say(e.message,true);}finally{loading=false;configure(session);}
 };
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
 window.addEventListener('message',event=>{
  if(!session||session.view.status!=='active'||event.source!==frame.contentWindow||event.origin!=='null')return;
  const input=event.data;
  if(!input||input.type!=='lucky:input'||typeof input.name!=='string'||!(/^[a-z][a-z0-9_-]{0,39}$/).test(input.name))return;
  if(!['string','number','boolean'].includes(typeof input.value)&&input.value!==null)return;
  if(typeof input.value==='string'&&input.value.length>240)return;
  if(typeof input.value==='number'&&(!Number.isSafeInteger(input.value)||Math.abs(input.value)>100000000))return;
  command('input',{name:input.name,value:input.value});
 });
 frame.addEventListener('load',()=>{if(session)sendState(session.view);});
 if(!validGame){say('Invalid game identifier.',true);return;}
 reload();setInterval(reload,1000);
})();
