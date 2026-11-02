(() => {
 'use strict';
 const L=window.Lucky,$=L.$;
 if(!$('studio-parties-form'))return;
 let library={parties:[],themes:[],costumes:[]};
 const sections=['parties','themes','costumes'];
 const form=section=>$('studio-'+section+'-form');
 const field=(section,name)=>form(section).elements.namedItem(name);
 const notice=(section,message,bad=false)=>{const el=$('studio-'+section+'-status');el.textContent=message;el.classList.toggle('platform-error',bad);};
 const make=(tag,cls='',content='')=>{const node=document.createElement(tag);if(cls)node.className=cls;if(content)node.textContent=content;return node;};
 const named=(name,value='',placeholder='')=>{const input=make('input');input.name=name;input.value=value;input.placeholder=placeholder;input.maxLength=100;return input;};
 const picker=(name,values,value='')=>{const select=make('select');select.name=name;values.forEach(([id,label])=>select.add(new Option(label,id)));select.value=value;return select;};
 const costumeChoices=()=>[['','No costume'],...library.costumes.map(c=>[c.id,c.name])];
 const themeChoices=()=>[['','No theme'],...library.themes.map(t=>[t.id,t.name])];
 const rowControl=(row,list)=>{
  const up=make('button','btn btn-outline','↑');up.type='button';up.title='Move layer up';
  const down=make('button','btn btn-outline','↓');down.type='button';down.title='Move layer down';
  const del=make('button','btn btn-outline','Remove');del.type='button';
  up.onclick=()=>{if(row.previousElementSibling)list.insertBefore(row,row.previousElementSibling);numberLayers(list);};
  down.onclick=()=>{if(row.nextElementSibling)list.insertBefore(row.nextElementSibling,row);numberLayers(list);};
  del.onclick=()=>{row.remove();numberLayers(list);};
  row.append(up,down,del);
 };
 function numberLayers(list){[...list.children].forEach((row,i)=>{const label=row.querySelector('.party-layer-label');if(label)label.textContent=`Layer ${i+1}`;});}
 function addItem(item={}){
  const list=$('studio-costume-items');if(list.children.length>=32){notice('costumes','Limit: 32 items per costume.',true);return;}
  const row=make('div','party-studio-item');const label=make('strong','party-layer-label');
  row.append(label,named('itemName',item.name||'','Item name'));
  row.append(picker('itemCategory',[['Clothing','Clothing'],['Accessory','Accessory'],['Prop','Prop'],['Makeup','Makeup'],['Footwear','Footwear'],['Other','Other']],item.category||'Accessory'));
  row.append(named('itemNotes',item.notes||'','Notes / gameplay cue'));rowControl(row,list);list.append(row);numberLayers(list);
 }
 function addAttendee(attendee={}){
  const list=$('studio-attendees');if(list.children.length>=100){notice('parties','Limit: 100 attendees per party.',true);return;}
  const row=make('div','party-studio-item');
  row.append(picker('attendeeRole',[['guest','Guest / attendee'],['player','Player'],['host','Host']],attendee.role||'guest'));
  row.append(named('attendeeName',attendee.name||'','Name (optional)'));
  row.append(picker('attendeeCostume',costumeChoices(),attendee.costumeId||''));
  rowControl(row,list);list.append(row);
 }
 function renderThemeCostumes(selected=[]){
  const root=$('studio-theme-costumes');root.replaceChildren();
  if(!library.costumes.length){root.append(make('p','','Create a costume first to attach it to a theme.'));return;}
  library.costumes.forEach(c=>{const label=make('label','party-studio-check');const input=make('input');input.type='checkbox';input.value=c.id;input.checked=selected.includes(c.id);label.append(input,document.createTextNode(c.name));root.append(label);});
 }
 function refreshChoices(){
  for(const select of [field('parties','themeId')]){const value=select.value;select.replaceChildren();themeChoices().forEach(([id,name])=>select.add(new Option(name,id)));select.value=value;}
 }
 function choose(section,id=''){
  const entry=library[section].find(x=>x.id===id)||null;
  const target=form(section);target.reset();field(section,'id').value=entry?.id||'';
  field(section,'name').value=entry?.name||'';
  field(section,'description').value=entry?.description||'';
  if(section==='costumes'){
   $('studio-costume-items').replaceChildren();(entry?.items||[]).forEach(addItem);
  }else if(section==='themes'){
   field(section,'accent').value=entry?.accent||'#f6cb74';renderThemeCostumes(entry?.costumeIds||[]);
  }else{
   refreshChoices();field(section,'themeId').value=entry?.themeId||'';
   $('studio-attendees').replaceChildren();(entry?.attendees||[]).forEach(addAttendee);
  }
  paintLists();notice(section,entry?'Editing '+entry.name:'Create a new '+section.slice(0,-1)+'.');
 }
 function paintLists(){sections.forEach(section=>{
  const root=$('studio-'+section+'-list');root.replaceChildren();
  if(!library[section].length){root.append(make('p','party-studio-empty','No '+section+' saved yet.'));return;}
  library[section].forEach(entry=>{
   const button=make('button','party-studio-record');button.type='button';
   if(field(section,'id').value===entry.id)button.classList.add('selected');
   button.append(make('strong','',entry.name));
   button.append(make('small','',section==='costumes'?`${entry.items.length} pieces`:section==='parties'?`${entry.attendees.length} attendees`: `${entry.costumeIds.length} costumes`));
   button.onclick=()=>choose(section,entry.id);root.append(button);
  });
 });}
 async function load(){
  try{library=await L.api('party_studio');paintLists();renderThemeCostumes();refreshChoices();}
  catch(e){sections.forEach(section=>notice(section,e.message+' · Run upgrade.php if this is the first time using Party Studio.',true));}
 }
 const collect=section=>{
  const result={section,id:field(section,'id').value,name:field(section,'name').value.trim(),description:field(section,'description').value.trim()};
  if(section==='costumes'){
   result.items=[...$('studio-costume-items').children].map(row=>({name:row.querySelector('[name=itemName]').value.trim(),category:row.querySelector('[name=itemCategory]').value,
    notes:row.querySelector('[name=itemNotes]').value.trim()}));
  }else if(section==='themes'){
   result.accent=field(section,'accent').value;
   result.costumeIds=[...$('studio-theme-costumes input:checked')].map(input=>input.value);
  }else{
   result.themeId=field(section,'themeId').value;
   result.attendees=[...$('studio-attendees').children].map(row=>({role:row.querySelector('[name=attendeeRole]').value,
    name:row.querySelector('[name=attendeeName]').value.trim(),costumeId:row.querySelector('[name=attendeeCostume]').value}));
  }
  return result;
 };
 sections.forEach(section=>{
  form(section).onsubmit=async event=>{
   event.preventDefault();const submit=form(section).querySelector('[type=submit]');submit.disabled=true;
   try{
    const result=await L.api('party_studio_save',{body:collect(section)});
    library=await L.api('party_studio');choose(section,result.record.id);notice(section,'Saved successfully.');
   }catch(e){notice(section,e.message,true);}finally{submit.disabled=false;}
  };
  document.querySelector(`[data-studio-new="${section}"]`).onclick=()=>choose(section);
  document.querySelector(`[data-studio-delete="${section}"]`).onclick=async()=>{
   const id=field(section,'id').value;if(!id)return;
   const name=field(section,'name').value;if(!confirm('Delete '+name+'? Existing game snapshots will remain unchanged.'))return;
   try{await L.api('party_studio_delete',{body:{section,id}});library=await L.api('party_studio');choose(section);notice(section,'Deleted '+name+'.');}
   catch(e){notice(section,e.message,true);}
  };
  document.querySelector(`[data-tab="${section}"]`).addEventListener('click',()=>{if(!library[section].length)void load();});
 });
 document.querySelector('[data-studio-add="attendee"]').onclick=()=>addAttendee();
 document.querySelector('[data-studio-add="item"]').onclick=()=>addItem();
 void load();
})();
