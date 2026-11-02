/* V5Q31: game turns and costume changes are editing cues, not fabricated video timestamps. */
(() => {
 'use strict';
 const $=id=>document.getElementById(id);
 const rows=state=>{
  const roster=state?.multiplayer||{},items=[];
  for(const turn of roster.turnHistory||[])items.push({id:'turn-'+turn.turnNumber,kind:'prize',at:turn.at,
   playerId:turn.playerId,playerName:turn.playerName,turnNumber:turn.turnNumber,
   claimId:turn.claimId||'',prizeName:turn.prizeName,costumeItem:turn.costumeItem||null});
  for(const event of roster.costumeEvents||[])items.push({...event,kind:'costume'});
  items.sort((a,b)=>(Date.parse(a.at)||0)-(Date.parse(b.at)||0)||(a.kind==='prize'?-1:1));
  return items;
 };
 const caption=moment=>moment.kind==='costume'?
  `${moment.playerName} · ${moment.itemName} · ${moment.actionName||moment.action}${moment.note?' · '+moment.note:''}`:
  `${moment.playerName} wins ${moment.prizeName}${moment.costumeItem?' · Costume item: '+moment.costumeItem.itemName:''}`;
 function render(state,onCaption){
  const list=$('editor-game-moment-list'),selector=$('editor-game-moment-player'),count=$('editor-game-moment-count');
  if(!list||!selector||!count)return;
  const moments=rows(state),before=selector.value;
  const people=new Map(moments.map(m=>[m.playerId,m.playerName]));
  selector.replaceChildren(new Option('All players',''));
  for(const [id,name] of people)selector.add(new Option(name,id));
  selector.value=people.has(before)?before:'';
  const paint=()=>{
   list.replaceChildren();const visible=moments.filter(m=>!selector.value||m.playerId===selector.value);
   count.textContent=String(visible.length);
   if(!visible.length){const empty=document.createElement('li');empty.textContent='No game moments for this player yet.';list.append(empty);return;}
   for(const moment of visible){
    const li=document.createElement('li');li.className='studio-game-moment';
    const label=document.createElement('strong');label.textContent=caption(moment);
    const meta=document.createElement('small');meta.textContent=`Turn ${moment.turnNumber||'?'} · ${new Date(moment.at).toLocaleString()}${moment.claimId?' · prize footage linked':''}`;
    const button=document.createElement('button');button.type='button';button.className='btn btn-outline';button.textContent='Caption clip';
    button.onclick=()=>onCaption?.(moment);
    li.append(label,meta,button);list.append(li);
   }
  };
  selector.onchange=paint;paint();
 }
 window.LuckyEditorMoments=Object.freeze({rows,caption,render});
})();
