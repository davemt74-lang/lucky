(() => {
 'use strict';
 // Only a signed-in HomeServer host dashboard accepts controller navigation.
 // The phone cannot redirect this browser or claim an owner session.
 const L=window.Lucky;
 if(!L)return;
 let polling=false,navigating=false;
 const key='lucky-phone-dashboard-autostart';
 async function poll(){
  if(polling||navigating||document.visibilityState!=='visible')return;
  polling=true;
  try{
   const response=await L.api('phone_dashboard_next');
   const next=response.launch;
   if(!next||!Number.isSafeInteger(next.id)||!/^[a-z0-9][a-z0-9-]{1,62}$/.test(next.game))return;
   const accepted=await L.api('phone_dashboard_accept',{body:{id:next.id,game:next.game}});
   const launch=accepted.launch;
   if(launch?.id!==next.id||launch?.game!==next.game)return;
   navigating=true;
   sessionStorage.setItem(key,JSON.stringify({id:launch.id,game:launch.game,created:Date.now()}));
   location.assign(launch.game==='spin-wheel'?'game.php':('package-game.php?game='+encodeURIComponent(launch.game)));
  }catch(e){/* Owner may have signed out; ordinary dashboard remains usable. */}
  finally{polling=false;}
 }
 poll();setInterval(poll,900);
})();
