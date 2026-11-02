(() => {
 'use strict';
 const L=window.Lucky,$=L.$,dialog=$('multiplayer-setup'),form=$('multiplayer-setup-form');
 if(!dialog||!form)return;
 let library={parties:[],themes:[],costumes:[]};
 let phones=[];
 const options=(select,entries)=>{select.replaceChildren();entries.forEach(([id,name])=>select.add(new Option(name,id)));};
 const limit=(input,max)=>{const n=Number(input.value);if(!Number.isSafeInteger(n)||n<1||n>max)throw Error('Choose between 1 and '+max+' '+(max===64?'players':'hosts')+'.');return n;};
 const personRows=role=>$('setup-'+role+'s');
 function costumeSelect(value=''){
  const select=document.createElement('select');select.className='setup-costume';select.setAttribute('aria-label','Costume');
  const theme=library.themes.find(t=>t.id===$('setup-theme').value),recommended=theme?.costumeIds||[];
  const sorted=[...library.costumes].sort((a,b)=>Number(recommended.includes(b.id))-Number(recommended.includes(a.id))||a.name.localeCompare(b.name));
  options(select,[['','No costume'],...sorted.map(c=>[c.id,c.name+(recommended.includes(c.id)?' · theme':'')])]);
  select.value=value;return select;
 }
 function showPieces(row){
  const id=row.querySelector('select').value,costume=library.costumes.find(c=>c.id===id),list=row.querySelector('.setup-costume-pieces');
  list.replaceChildren();if(!costume)return;
  if(!costume.items.length){list.textContent='No pieces added yet.';return;}
  costume.items.forEach((piece,i)=>{const line=document.createElement('li');line.textContent=`${i+1}. ${piece.name} · ${piece.category}${piece.notes?' · '+piece.notes:''}`;list.append(line);});
 }
 function makeRows(role,count,provided=[]){
  const root=personRows(role);root.replaceChildren();
  for(let i=0;i<count;i++){
   const item=provided[i]||{},row=document.createElement('div');row.className='setup-person';
   const title=document.createElement('strong');title.textContent=(role==='player'?'Player ':'Host ')+(i+1);
   const name=document.createElement('input');name.type='text';name.maxLength=100;name.className='setup-name';name.placeholder='Name (optional)';name.value=item.name||'';
   const costume=costumeSelect(item.costumeId||'');const pieces=document.createElement('ol');pieces.className='setup-costume-pieces';
   costume.onchange=()=>showPieces(row);
   row.append(title,name,costume,pieces);
   if(role==='player'){
    const phone=document.createElement('select');phone.className='setup-phone';phone.setAttribute('aria-label','Assigned player phone');
    options(phone,[['','Host controls this turn · no phone'],...phones.map(p=>[p.id,p.name])]);phone.value=item.phonePairId||'';
    row.append(phone);
   }
   root.append(row);showPieces(row);
  }
 }
 function partySelected(){
  const party=library.parties.find(p=>p.id===$('setup-party').value),members=party?.attendees||[];
  $('setup-theme').value=party?.themeId||'';
  const players=members.filter(p=>p.role==='player'),hosts=members.filter(p=>p.role==='host');
  $('setup-player-count').value=String(Math.min(64,Math.max(1,players.length)));
  $('setup-host-count').value=String(Math.min(16,Math.max(1,hosts.length)));
  makeRows('player',Number($('setup-player-count').value),players);
  makeRows('host',Number($('setup-host-count').value),hosts);
  $('multiplayer-setup-status').textContent=party?`${members.length} saved attendees. Guests not playing remain on the party list.`:'Add player and host names if you want to.';
 }
 function countChanged(role){
  const rows=[...personRows(role).children].map(row=>({name:row.querySelector('.setup-name').value,costumeId:row.querySelector('.setup-costume').value,phonePairId:row.querySelector('.setup-phone')?.value||''}));
  try{makeRows(role,limit($('setup-'+role+'-count'),role==='player'?64:16),rows);$('multiplayer-setup-status').textContent='';}
  catch(e){$('multiplayer-setup-status').textContent=e.message;}
 }
 $('setup-party').onchange=partySelected;
 $('setup-theme').onchange=()=>{
  ['player','host'].forEach(role=>{const rows=[...personRows(role).children].map(row=>({name:row.querySelector('.setup-name').value,costumeId:row.querySelector('.setup-costume').value,phonePairId:row.querySelector('.setup-phone')?.value||''}));makeRows(role,rows.length,rows);});
 };
 ['player','host'].forEach(role=>$('setup-'+role+'-count').onchange=()=>countChanged(role));
 $('setup-cancel').onclick=()=>dialog.close();
 async function open(){
  const results=await Promise.all([L.api('party_studio'),L.api('paired_phones')]);library=results[0];phones=results[1].phones||[];
  options($('setup-party'),[['','New / casual game'],...library.parties.map(p=>[p.id,p.name])]);
  options($('setup-theme'),[['','No theme'],...library.themes.map(t=>[t.id,t.name])]);
  $('setup-player-count').value='1';$('setup-host-count').value='1';partySelected();
  const selected=new URLSearchParams(location.search).get('party');
  if(selected&&library.parties.some(p=>p.id===selected)){$('setup-party').value=selected;partySelected();}
  $('multiplayer-setup-status').textContent='';
  dialog.showModal();
  return new Promise(resolve=>{
   let done=false;const finish=value=>{if(done)return;done=true;resolve(value);};
   const closed=()=>{dialog.removeEventListener('close',closed);form.onsubmit=null;finish(null);};
   dialog.addEventListener('close',closed);
   form.onsubmit=e=>{
    e.preventDefault();
    try{
     const collect=role=>[...personRows(role).children].map(row=>({name:row.querySelector('.setup-name').value.trim(),costumeId:row.querySelector('.setup-costume').value,phonePairId:row.querySelector('.setup-phone')?.value||''}));
     const players=collect('player'),hosts=collect('host');
     if(players.length!==limit($('setup-player-count'),64)||hosts.length!==limit($('setup-host-count'),16))throw Error('Update participant counts before starting.');
     finish({partyId:$('setup-party').value,themeId:$('setup-theme').value,players,hosts});dialog.close();
    }catch(err){$('multiplayer-setup-status').textContent=err.message;}
   };
  });
 }
 window.LuckyGameSetup={open};
})();
