(() => {
 'use strict';const L=Lucky,$=L.$;let claims=[],sessionId='',states={};
 const render=()=>{const tier=$('claims-filter').value,items=claims.filter(c=>tier==='all'||tier===c.tier);
  $('claims-summary').textContent=`${claims.length} prizes claimed · ${L.money(claims.reduce((n,p)=>n+p.cents,0))} awarded`;const root=$('claims-rows');root.replaceChildren();
  if(!items.length){const e=document.createElement('p');e.textContent='No claimed prizes for this filter.';root.append(e);return;}
  [...items].reverse().forEach(c=>{const row=document.createElement('div');row.className='platform-claim claim-proof-row';
   const info=document.createElement('div');info.className='claim-proof-info';const title=document.createElement('strong');title.textContent=c.name;
   const detail=document.createElement('span');detail.textContent=`${c.tier.toUpperCase()} · ${L.money(c.cents)} · ${new Date(c.at).toLocaleString()}`;info.append(title,detail);
   const status=states[c.id];if(status?.needsRevision){const badge=document.createElement('span');badge.className='proof-revision-needed';badge.textContent='Needs revision';info.append(badge);}
   else if(status?.latestRevision){const label=document.createElement('span');label.textContent=`Proof revision ${status.latestRevision}`;info.append(label);}
   const actions=document.createElement('div');actions.className='claim-proof-actions';const edit=document.createElement('a');edit.className='btn btn-primary';edit.textContent=status?.needsRevision?'Revise proof':'Edit proof';edit.href=`proof.php?session=${encodeURIComponent(sessionId)}&claim=${encodeURIComponent(c.id)}&from=claims`;
   edit.addEventListener('click',()=>{try{sessionStorage.setItem('lucky-proof-origin',JSON.stringify({session:sessionId,claim:c.id,from:'claims',at:Date.now()}));}catch(_){}});actions.append(edit);
   if(c.url){const a=document.createElement('a');a.href=c.url;a.target='_blank';a.rel='noopener noreferrer';a.className='small-link';a.textContent='Prize URL ↗';actions.append(a);}
   row.append(info,actions);root.append(row);
  });
 };
 const csvField=value=>{let s=String(value??'');if(/^[=+@\-\t\r]/.test(s))s="'"+s;return '"'+s.replaceAll('"','""')+'"';};
 $('claims-filter').onchange=render;
 $('claims-export').onclick=()=>{const rows=[['Game ID','Prize','Tier','Amount USD','Slot','Time','URL','Proof revision','Needs revision'],...claims.map(c=>[sessionId,c.name,c.tier,L.dollars(c.cents),c.slot||'',c.at,c.url||'',states[c.id]?.latestRevision||0,states[c.id]?.needsRevision?'yes':'no'])];
  const csv=rows.map(row=>row.map(csvField).join(',')).join('\r\n');const a=document.createElement('a');
  a.href=URL.createObjectURL(new Blob([csv],{type:'text/csv;charset=utf-8;'}));a.download='lucky-game-claims.csv';document.body.append(a);a.click();a.remove();setTimeout(()=>URL.revokeObjectURL(a.href),1000);};
 async function init(){try{const requested=new URL(location.href).searchParams.get('session');let data;
  if(requested&&/^[a-f0-9]{32}$/.test(requested))data=(await L.api('session',{query:{id:requested}}));
  else data=(await L.api('last')).data;
  if(!data){$('claims-summary').textContent='No game session found.';return;}
  sessionId=data.session.id;claims=data.session.claims||[];
  try{const r=await fetch('api/claim-proof.php?action=summary&session='+encodeURIComponent(sessionId),{credentials:'same-origin'});const result=await r.json();if(result.ok)states=result.states||{};else L.note('claims-error',result.error||'Could not retrieve proof history.',true);}catch(e){L.note('claims-error',e.message,true);}
  render();}catch(e){L.note('claims-error',e.message,true);}
 }
 init();
})();
