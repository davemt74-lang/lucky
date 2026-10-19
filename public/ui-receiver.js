/* Cross-device control receiver: commands are never "delivered" until a screen confirms execution. */
(() => {
 'use strict';
 const body=document.body,display=body.dataset.page==='display',session=body.dataset.session||'',viewer=body.dataset.token||'';
 const receiver=display?()=>window.LuckyDisplayRemote:()=>window.LuckyHostRemote;
 let csrf=null,busy=false,cursor=0;const pendingAcks=new Set();
 const info=msg=>{const el=document.getElementById(display?'display-state':'game-message');if(el)el.textContent=msg;};
 async function post(action,payload){
  if(!display&&!csrf){const r=await fetch('api/index.php?action=me',{credentials:'same-origin',cache:'no-store'});const d=await r.json();csrf=d.csrf||null;if(!csrf)throw Error('Host sign-in required.');}
  const res=await fetch('api/controller.php?action='+action,{method:'POST',cache:'no-store',credentials:display?'omit':'same-origin',headers:{'Content-Type':'application/json',...(!display?{'X-CSRF-Token':csrf}:{})},body:JSON.stringify({...payload,...(display?{session,viewer}:{})})});
  const data=await res.json();if(!res.ok||!data.ok)throw Error(data.error||'Connection error');return data;
 }
 async function confirmAcks(){
  for(const id of [...pendingAcks]){try{await post('ui_ack',{commandId:id});pendingAcks.delete(id);}catch(_){/* Retry receipt without repeating the actual button action. */}}
 }
 async function poll(){if(busy||document.visibilityState==='hidden')return;busy=true;
  try{
   await confirmAcks();
   const result=await post('ui_events',{after:cursor});
   for(const event of result.events){
    if(!pendingAcks.has(event.id)){
     if(typeof receiver()!=='function')break;
     try{
      const applied=await receiver()(event.command);
      if(applied!==false)pendingAcks.add(event.id);
      else await post('ui_ack',{commandId:event.id,success:false,error:'This control is not available on the current screen.'});
     }catch(err){
      info(`Phone control needs attention: ${err.message}`);
      try{await post('ui_ack',{commandId:event.id,success:false,error:String(err.message||'Action failed').slice(0,180)});}catch(_){}
     }
    }
    cursor=Math.max(cursor,event.id);
   }
   await confirmAcks();
  }catch(_){ /* Retry during server or network interruption. */ }
  finally{busy=false;}
 }
 setInterval(poll,650);setTimeout(poll,300);
})();
