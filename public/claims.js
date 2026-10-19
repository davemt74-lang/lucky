(() => {
 'use strict';const L=Lucky,$=L.$;let claims=[],sessionId='';
 const render=()=>{const tier=$('claims-filter').value,items=claims.filter(c=>tier==='all'||tier===c.tier);
  $('claims-summary').textContent=`${claims.length} prizes claimed · ${L.money(claims.reduce((n,p)=>n+p.cents,0))} awarded`;const root=$('claims-rows');root.replaceChildren();
  if(!items.length){const e=document.createElement('p');e.textContent='No claimed prizes for this filter.';root.append(e);return;}
  [...items].reverse().forEach(c=>{const row=document.createElement('div');row.className='platform-claim';
   const text=document.createElement('span');text.textContent=`${c.name} · ${c.tier.toUpperCase()} · ${L.money(c.cents)} · ${new Date(c.at).toLocaleString()}`;row.append(text);
   if(c.url){const a=document.createElement('a');a.href=c.url;a.target='_blank';a.rel='noopener noreferrer';a.className='small-link';a.textContent='Prize URL ↗';row.append(a);}
   root.append(row);
  });
 };
 const csvField=value=>{let s=String(value??'');if(/^[=+@\-\t\r]/.test(s))s="'"+s;return '"'+s.replaceAll('"','""')+'"';};
 $('claims-filter').onchange=render;
 $('claims-export').onclick=()=>{const rows=[['Game ID','Prize','Tier','Amount USD','Slot','Time','URL'],...claims.map(c=>[sessionId,c.name,c.tier,L.dollars(c.cents),c.slot||'',c.at,c.url||''])];
  const csv=rows.map(row=>row.map(csvField).join(',')).join('\r\n');const a=document.createElement('a');
  a.href=URL.createObjectURL(new Blob([csv],{type:'text/csv;charset=utf-8;'}));a.download='lucky-game-claims.csv';document.body.append(a);a.click();a.remove();setTimeout(()=>URL.revokeObjectURL(a.href),1000);};
 L.api('last').then(r=>{if(!r.data){$('claims-summary').textContent='No game session found.';return;}
  const session=r.data.session;sessionId=session.id;claims=session.claims||[];render();}).catch(e=>L.note('claims-error',e.message,true));
})();
