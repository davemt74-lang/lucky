/* Claim-scoped proof editor. Revision snapshots are saved on the server, not in browser storage. */
(() => {
 'use strict';
 const $=id=>document.getElementById(id), L=window.Lucky;
 const params=new URL(location.href).searchParams,session=params.get('session')||'',claim=params.get('claim')||'';
 let busy=false;
 function feedback(message,bad=false){const e=$('proof-errors');e.hidden=!bad;e.textContent=bad?message:'';$('proof-state').textContent=message;}
 async function call(action,body){const me=await L.getMe();const r=await fetch('api/claim-proof.php?action='+encodeURIComponent(action),{
  method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json','X-CSRF-Token':me.csrf},body:JSON.stringify({session,claim,...body})
 });const data=await r.json();if(!r.ok||!data.ok)throw Error(data.error||'Proof update failed');return data;}
 async function load(){const r=await fetch(`api/claim-proof.php?session=${encodeURIComponent(session)}&claim=${encodeURIComponent(claim)}`,{credentials:'same-origin',cache:'no-store'});
  const data=await r.json();if(!r.ok||!data.ok)throw Error(data.error||'Cannot load proof revisions');
  const {status,revisions}=data;$('proof-state').textContent=status.needsRevision?`Needs revision${status.requestNote?' · '+status.requestNote:''}`:`${status.latestRevision} saved revision${status.latestRevision===1?'':'s'} · ${data.clipCount} current clips`;
  $('proof-request').textContent=status.needsRevision?'Update revision request':'Mark needs revision';
  const root=$('proof-revision-list');root.replaceChildren();if(!revisions.length)root.textContent='No revisions saved yet.';
  for(const rev of revisions){const item=document.createElement('details');item.className='proof-revision-item';const header=document.createElement('summary');header.textContent=`Revision ${rev.number} · ${rev.at} · ${rev.clips.length} clip${rev.clips.length===1?'':'s'}`;
   const note=document.createElement('p');note.textContent=rev.note||'No note';item.append(header,note);
   for(const c of rev.clips){const line=document.createElement('div');line.className='proof-revision-clip';const label=document.createElement('span');label.textContent=`${c.title} · ${c.kind}${c.angleLabel?' · '+c.angleLabel:''}`;
    const view=document.createElement('a');view.href=c.src;view.target='_blank';view.rel='noopener';view.className='small-link';view.textContent='View clip ↗';line.append(label,view);item.append(line);}
   root.append(item);}
 }
 async function save(action){if(busy)return;busy=true;$('proof-save').disabled=true;$('proof-request').disabled=true;
  try{await call(action,{note:$('proof-note').value});$('proof-note').value='';await load();if(action==='save')feedback('New proof revision saved. Earlier revisions remain available.');}
  catch(e){feedback(e.message,true);}finally{busy=false;$('proof-save').disabled=false;$('proof-request').disabled=false;}
 }
 async function init(){try{
   if(!/^[a-f0-9]{32}$/.test(session)||!/^[a-f0-9]{32}$/.test(claim))throw Error('Open proof editing from a claimed-prize entry.');
   const r=await L.api('session',{query:{id:session}});
   const p=(r.session?.claims||[]).find(c=>c.id===claim);if(!p)throw Error('Claim not found in selected game.');
   $('proof-prize-title').textContent=p.name;$('capture-prize-label').textContent=p.name;
   $('proof-prize-context').textContent=`${p.tier.toUpperCase()} · ${L.money(p.cents)} · ${p.at||'Claimed'}`;
   $('proof-return').href='claims.php?session='+encodeURIComponent(session);
   const profile=r.session?.pools?.[p.tier]?.prizes?.find(x=>x.id===p.prizeId);
   // Idempotent baseline freezes pre-edit media and protects originals from deletion.
   await call('baseline',{});
   window.LuckyCapture?.show(session,claim,p.name,profile?.proofAngles||[]);
   await load();
   $('proof-revision-form').onsubmit=e=>{e.preventDefault();save('save');};
   $('proof-request').onclick=()=>save('request');
  }catch(e){feedback(e.message,true);$('proof-revision-form').hidden=true;}
 }
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
})();
