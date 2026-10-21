/* V5Q.25C: Game Catalog subtabs are presentation-only; forms and API handlers stay mounted. */
(() => {
 'use strict';
 const root=document.getElementById('tab-games');
 const bar=document.getElementById('game-catalog-tabs');
 if(!root||!bar)return;
 const tabs=Array.from(bar.querySelectorAll('[role="tab"][data-catalog-tab]'));
 const panes=new Map(tabs.map(tab=>[tab.dataset.catalogTab,document.getElementById(tab.getAttribute('aria-controls'))]));
 if(tabs.length!==4||tabs.some(tab=>!panes.get(tab.dataset.catalogTab)))return;
 let current='profiles';
 function select(name,focus=false,updateUrl=false){
  const target=tabs.find(tab=>tab.dataset.catalogTab===name);
  if(!target)return;
  current=name;
  for(const tab of tabs){
   const active=tab===target;
   tab.setAttribute('aria-selected',String(active));
   tab.tabIndex=active?0:-1;
   panes.get(tab.dataset.catalogTab).hidden=!active;
  }
  if(focus)target.focus();
  if(updateUrl&&window.history?.replaceState){
   const url=new URL(location.href);
   url.searchParams.set('tab','games');
   url.searchParams.set('catalogTab',name);
   try{history.replaceState(history.state,'',url.pathname+url.search+url.hash);}catch(_){/* Embedded preview has no navigable origin. */}
  }
 }
 for(const tab of tabs){
  tab.addEventListener('click',()=>select(tab.dataset.catalogTab,false,true));
  tab.addEventListener('keydown',event=>{
   const ix=tabs.indexOf(tab);let next;
   switch(event.key){
    case 'ArrowRight':next=(ix+1)%tabs.length;break;
    case 'ArrowLeft':next=(ix+tabs.length-1)%tabs.length;break;
    case 'Home':next=0;break;
    case 'End':next=tabs.length-1;break;
    default:return;
   }
   event.preventDefault();select(tabs[next].dataset.catalogTab,true,true);
  });
 }
 const requested=new URLSearchParams(location.search).get('catalogTab');
 select(tabs.some(tab=>tab.dataset.catalogTab===requested)?requested:'profiles');
 // Keep a previously selected tab on return from another Admin Studio section.
 root.addEventListener('lucky:catalog-tab-open',()=>select(current));
})();
