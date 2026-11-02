/* V5Q31: explicit, host-only costume-item actions; recording never starts here. */
(() => {
 'use strict';
 const L=window.Lucky,$=L.$,panel=$('game-costume-actions'),root=$('game-costume-action-list');
 if(!panel||!root)return;
 let snapshot=null,signature='',working=false;
 const note=$('game-costume-action-status');
 const status=(text,bad=false)=>{note.textContent=text;note.classList.toggle('platform-error',bad);};
 const elem=(tag,cls='',text='')=>{const e=document.createElement(tag);if(cls)e.className=cls;if(text)e.textContent=text;return e;};
 const requestKey=()=>window.crypto?.randomUUID?.().replaceAll('-','')||('costume'+Date.now()+Math.random().toString(36).slice(2));
 async function act(player,item,action,input,button){
  const view=snapshot?.view;if(!view||working)return;
  working=true;button.disabled=true;
  try{
   const result=await L.api('costume_item_action',{body:{session:view.id,expectedVersion:view.version,
    playerId:player.id,itemId:item.id,action:action.id,requestKey:requestKey(),note:input.value.trim()}});
   snapshot=result.data;status(result.changed?`${player.name}: ${item.name} · ${action.name} moment saved.`:'That costume item already has this status.');
   window.dispatchEvent(new CustomEvent('lucky:costume-action',{detail:{data:result.data}}));
  }catch(e){status(e.message,true);}finally{working=false;button.disabled=false;}
 }
 function render(data){
  snapshot=data;
  const view=data?.view,roster=data?.session?.multiplayer;
  const live=['active','paused'].includes(view?.status),players=[...(roster?.players||[]),...(roster?.hosts||[])];
  panel.hidden=!live||!players.some(p=>p.costume?.items?.length||p.prizeCostumeItems?.length);
  if(panel.hidden){signature='';root.replaceChildren();return;}
  const events=roster.costumeEvents||[];
  const next=JSON.stringify([view.id,players.map(p=>[p.id,p.name,[...(p.costume?.items||[]),...(p.prizeCostumeItems||[])].map(i=>[i.id,i.name,i.layer,i.equipped??true,i.actions])]),events.map(e=>e.id)]);
  if(signature===next)return;
  signature=next;root.replaceChildren();
  for(const player of players){
   if(!player.costume?.items?.length&&!player.prizeCostumeItems?.length)continue;
   const group=elem('section','game-costume-player');
   group.append(elem('h3','',`${player.name} · ${player.costume?.name||'Prize costume items'}`));
   for(const item of [...(player.costume?.items||[]),...(player.prizeCostumeItems||[])]){
    const row=elem('div','game-costume-item'),active=item.equipped!==false;
    row.append(elem('span','game-costume-item-name',`${item.prizeName?'🎁 Won with '+item.prizeName+' · ':''}Layer ${item.layer} · ${item.name} · ${item.category} · ${active?'Equipped':'Not equipped'}`));
    const input=elem('input');input.maxLength=240;input.placeholder='Optional moment note';input.setAttribute('aria-label',item.name+' moment note');
    row.append(input);
    const actions=item.actions||[{id:'equip',name:'Equip',kind:'equip'},{id:'remove',name:'Remove',kind:'remove'}];
    const enabled=actions.filter(action=>action.kind==='cue'||(action.kind==='equip'&&!active)||(action.kind==='remove'&&active));
    if(!enabled.length)row.append(elem('small','','No actions currently available'));
    for(const action of enabled){
     const button=elem('button','btn btn-outline',action.name);button.type='button';
     button.title=action.description||'';button.onclick=()=>void act(player,item,action,input,button);
     row.append(button);
    }
    group.append(row);
   }
   root.append(group);
  }
  const history=$('game-costume-action-history');history.replaceChildren();
  for(const event of events.slice(-8).reverse()){
   const item=elem('li','',`${event.playerName} · ${event.itemName} · ${event.actionName||event.action} · ${new Date(event.at).toLocaleTimeString()}`);
   history.append(item);
  }
 }
 window.LuckyCostumeGameplay={render};
})();
