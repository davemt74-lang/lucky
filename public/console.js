/* Lucky Games V5P.2: directional focus navigation shared by host, TV and future games. */
(() => {
 'use strict';
 const key='lucky-hub-console-v1';
 let settings={gamepad:true,keyboard:true};try{settings={...settings,...JSON.parse(localStorage.getItem(key)||'{}')};}catch(_){}
 const actions=new Map();
 const on=(name,callback)=>{if(typeof callback!=='function')throw TypeError('Input handler required');actions.set(name,callback);return()=>actions.delete(name);};
 const emit=name=>{if(actions.has(name)){return actions.get(name)()!==false;}return false;};
 const game=()=>document.getElementById('spin-game');
 const menu=()=>document.getElementById('console-menu');
 const win=()=>document.getElementById('win-dialog');
 const displayMenu=()=>document.getElementById('display-remote-menu');
 const scope=()=>win()?.open?win():menu()?.open?menu():displayMenu()&&!displayMenu().hidden?displayMenu():document.body;
 const query='button:not([disabled]),a[href],input:not([disabled]):not([type="hidden"]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';
 function navigable(){return Array.from(scope().querySelectorAll(query)).filter(el=>{const r=el.getBoundingClientRect(),style=getComputedStyle(el);return !el.closest('[hidden],[inert],[aria-hidden="true"]')&&style.visibility!=='hidden'&&style.display!=='none'&&r.width>0&&r.height>0;});}
 function mark(el){document.querySelectorAll('.lucky-focus').forEach(x=>x.classList.remove('lucky-focus'));if(!el)return false;el.focus({preventScroll:true});el.classList.add('lucky-focus');el.scrollIntoView?.({block:'nearest',inline:'nearest',behavior:'instant'});return true;}
 function initial(list){return (document.querySelector('.game-console-tile')&&list.includes(document.querySelector('.game-console-tile'))&&document.querySelector('.game-console-tile')) || (game()&&!game().disabled&&list.includes(game())&&game()) || (document.getElementById('start-game')&&!document.getElementById('start-game').disabled&&list.includes(document.getElementById('start-game'))&&document.getElementById('start-game')) || list[0];}
 function move(direction){const list=navigable();if(!list.length)return false;const current=document.activeElement;
   // D-pad tab switching: same behavior for physical keyboard, gamepad and phone.
   const hostTabs=window.LuckyHostTabs;
   if(hostTabs&&current?.getAttribute?.('role')==='tab'&&['left','right'].includes(direction)){
     const items=hostTabs.items,index=items.findIndex(x=>document.getElementById('host-tab-'+x)===current);
     if(index>=0){const next=items[(index+(direction==='right'?1:-1)+items.length)%items.length];
       hostTabs.activate(next,{focus:true});return true;}
   }

   if(current?.matches?.('input[type="range"]')&&['left','right'].includes(direction)){
     const min=Number(current.min||0),max=Number(current.max||100),step=Number(current.step)||1;
     current.value=String(Math.max(min,Math.min(max,Number(current.value)+(direction==='left'?-step:step))));
     current.dispatchEvent(new Event('input',{bubbles:true}));current.dispatchEvent(new Event('change',{bubbles:true}));return true;
   }
   if(!list.includes(current))return mark(document.querySelector('.host-main-tabs [aria-selected="true"]')||initial(list));
   const r=current.getBoundingClientRect(),cx=r.left+r.width/2,cy=r.top+r.height/2;
   const candidates=list.filter(el=>el!==current).map(el=>{const e=el.getBoundingClientRect(),dx=e.left+e.width/2-cx,dy=e.top+e.height/2-cy;
     const axis=['left','right'].includes(direction)?dx:dy,cross=['left','right'].includes(direction)?dy:dx;
     return {el,axis,cross};}).filter(q=>(direction==='right'||direction==='down'?q.axis>3:q.axis< -3));
   candidates.sort((a,b)=>(Math.abs(a.axis)+Math.abs(a.cross)*2.25)-(Math.abs(b.axis)+Math.abs(b.cross)*2.25));
   if(candidates.length)return mark(candidates[0].el);
   const idx=list.indexOf(current);return mark(list[(idx+(['right','down'].includes(direction)?1:-1)+list.length)%list.length]);
 }
 function toggleMenu(){const el=menu();if(el){if(el.open){closeMenu();return true;}if(win()?.open)return false;
   const pause=document.getElementById('pause-game');if(pause&&!pause.disabled&&pause.textContent.includes('Pause'))pause.click();
   el.showModal();mark(el.querySelector('[data-console-resume]'));return true;}
   const d=displayMenu();if(d){d.hidden=!d.hidden;if(!d.hidden)mark(navigable()[0]);return true;}return false;
 }
 function closeMenu(){const el=menu();if(el?.open){el.close();const pause=document.getElementById('pause-game');if(pause){let attempts=0;(function resume(){if(pause.textContent.includes('Resume')&&!pause.disabled){pause.click();return;}if(pause.disabled&&attempts++<25)setTimeout(resume,100);})();}return true;}
   if(displayMenu()&&!displayMenu().hidden){displayMenu().hidden=true;return true;}return false;}
 function done(){if(win()?.open){document.getElementById('close-win')?.click();return true;}if(menu()?.open||displayMenu()&&!displayMenu().hidden)return closeMenu();return false;}
 function select(){const focused=document.activeElement;const list=navigable();if(!list.includes(focused)){const first=initial(list);return mark(first);}
  if(focused.matches('a,button,input[type="checkbox"],input[type="radio"]')){focused.click();return true;}
  if(focused.matches('select')){focused.focus();return true;}if(focused.matches('input[type="range"]'))return true;return focused.focus?mark(focused):false;
 }
 on('select',select);on('done',done);on('menu',toggleMenu);
 on('back',()=>{if(done())return true;const proofReturn=document.getElementById('proof-return');if(proofReturn?.getAttribute('href')){location.assign(proofReturn.href);return true;}if(menu()?.open||displayMenu()&&!displayMenu().hidden)return closeMenu();if(game())return toggleMenu();location.href='index.php';return true;});
 for(const dir of ['up','down','left','right'])on(dir,()=>move(dir));
 on('spin',()=>{if(!menu()?.open&&!win()?.open&&!game()?.disabled){game()?.click();return true;}return false;});
 if(settings.keyboard)document.addEventListener('keydown',ev=>{
  if(ev.altKey||ev.ctrlKey||ev.metaKey||ev.repeat)return;
  if(ev.key==='Escape'&&emit('back')){ev.preventDefault();return;}
  const editable=ev.target?.closest?.('input:not([type="range"]),textarea,[contenteditable="true"]');if(editable)return;
  const arrows={ArrowLeft:'left',ArrowRight:'right',ArrowUp:'up',ArrowDown:'down'};
  if(arrows[ev.key]){if(emit(arrows[ev.key]))ev.preventDefault();return;}
  if((ev.code==='Space'||ev.key==='Enter')&&document.activeElement?.matches?.('button,a,input,select'))return;
  if(ev.key==='Enter'){if(emit('select'))ev.preventDefault();return;}
  if(ev.code==='Space'&&game()&&!menu()?.open){if(emit('spin'))ev.preventDefault();}
 });
 document.querySelectorAll('a.game-console-tile').forEach(a=>a.addEventListener('click',()=>{try{localStorage.setItem('lucky-last-game',a.dataset.game||'spin-wheel');}catch(_){}}));
 const resume=document.querySelector('[data-console-resume]');if(resume)resume.onclick=closeMenu;
 const pause=document.querySelector('[data-console-pause]');if(pause)pause.onclick=()=>{const b=document.getElementById('pause-game');if(b&&!b.disabled)b.click();};
 const end=document.querySelector('[data-console-end]');if(end)end.onclick=()=>{closeMenu();const b=document.getElementById('end-game');if(b&&!b.disabled)b.click();};
 let prior=new Map();function poll(){if(!settings.gamepad||!navigator.getGamepads)return;let pads=[];try{pads=Array.from(navigator.getGamepads()).filter(Boolean);}catch(_){return;}
  const ids=new Set();for(const pad of pads){ids.add(pad.index);const b=pad.buttons||[];const old=prior.get(pad.index)||[];const now=b.map(x=>Boolean(x?.pressed));
    for(const [idx,action] of [[0,'select'],[1,'back'],[9,'menu'],[12,'up'],[13,'down'],[14,'left'],[15,'right']])if(now[idx]&&!old[idx])emit(action);prior.set(pad.index,now);}
  for(const id of prior.keys())if(!ids.has(id))prior.delete(id);
 }if(settings.gamepad)setInterval(poll,180);
 window.LuckyConsole=Object.freeze({on,emit,move,select,done,toggleMenu,closeMenu,navigable,settings});
})();
