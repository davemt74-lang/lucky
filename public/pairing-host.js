/* Host-only approval and command receiver for personal wireless phone controllers. */
(() => {
 'use strict';
 const $=id=>document.getElementById(id),root=$('phone-pairing');if(!root)return;
 const state={csrf:null,connected:false,loading:false,receiving:root.dataset.controllerReceiver==='1'};
 const text=(s,bad=false)=>{const e=$('pair-host-status');e.textContent=s;e.classList.toggle('platform-error',bad);};
 async function request(action,body={}){
  if(!state.csrf){const auth=await (await fetch('api/index.php?action=me',{credentials:'same-origin',cache:'no-store'})).json();state.csrf=auth.csrf;if(!state.csrf)throw Error('Please sign in as host.');}
  const response=await fetch('api/controller.php?action='+encodeURIComponent(action),{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':state.csrf},body:JSON.stringify(body),cache:'no-store'});
  const data=await response.json();if(!response.ok||!data.ok)throw Error(data.error||'Pairing request failed.');return data;
 }
 function secure(){return window.isSecureContext||['localhost','127.0.0.1'].includes(location.hostname);}
 async function invite(){if(state.loading)return;if(!secure()){text('Pairing requires HTTPS (or localhost).',true);return;}
  state.loading=true;$('pair-new').disabled=true;
  try{const data=await request('invite');const href=new URL(data.url,document.baseURI).href;
   $('pair-qr').innerHTML=LuckyQR.svg(href);$('pair-link').value=href;$('pair-invite').hidden=false;
   text('Invitation ready. Scan the QR code and approve your phone when it appears.');await list();
  }catch(e){text(e.message,true);}finally{state.loading=false;$('pair-new').disabled=false;}
 }
 async function change(id,action){try{await request(action,{id});text(action==='approve'?'Phone approved. You can use its controls.':'Phone disconnected.');await list();}catch(e){text(e.message,true);}}
 async function list(){if(state.loading&&state.connected)return;
  try{const data=await request('list'),parent=$('pair-devices');parent.replaceChildren();
   const active=data.pairs.filter(p=>['pending','paired'].includes(p.status));window.dispatchEvent(new CustomEvent('lucky:paired-phones',{detail:{count:active.filter(p=>p.status==='paired').length}}));
   if(!active.length){const p=document.createElement('p');p.className='console-footnote';p.textContent='No pending or connected phones.';parent.append(p);return;}
   active.forEach(p=>{
    const row=document.createElement('div');row.className='phone-pair-device';
    const info=document.createElement('div'),title=document.createElement('strong'),status=document.createElement('span');
    title.textContent=p.device_name||'Waiting for phone';status.textContent=p.status==='pending'?'Waiting for approval':'Connected · controls enabled';
    status.className=p.status==='pending'?'phone-pair-pending':'phone-pair-connected';info.append(title,status);row.append(info);
    if(p.status==='pending'){const approve=document.createElement('button');approve.type='button';approve.className='btn btn-primary';approve.textContent='Approve';approve.onclick=()=>change(p.id,'approve');row.append(approve);}
    const revoke=document.createElement('button');revoke.type='button';revoke.className='btn btn-outline';revoke.textContent='Disconnect';revoke.onclick=()=>change(p.id,'revoke');row.append(revoke);parent.append(row);
   });
  }catch(e){text(e.message,true);}
 }
 let polling=false;
 async function poll(){if(polling||!state.receiving)return;polling=true;
  try{const data=await request('poll');for(const row of data.commands){
    window.dispatchEvent(new CustomEvent('lucky:remote-action',{detail:{command:row.command,deviceName:row.deviceName}}));
   }
  }catch(e){/* Logged-out or offline tabs recover when host returns. */}finally{polling=false;}
 }
 $('pair-new').addEventListener('click',invite);
 $('pair-copy').addEventListener('click',()=>navigator.clipboard?.writeText($('pair-link').value).then(()=>text('Private pairing link copied.')).catch(()=>{ $('pair-link').select();text('Copy the highlighted link.'); }));
 list();setInterval(()=>{if(document.visibilityState==='visible')list();},2000);
 // V5P.1: command reception/acknowledgment is handled by ui-receiver.js on host and TV.

})();
