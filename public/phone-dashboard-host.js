/* Home dashboard follows only very recent, owner-bound game starts requested by a paired phone. */
(() => {
 'use strict';
 if(!window.Lucky||!document.getElementById('game-catalog'))return;
 let busy=false,navigating=false;
 async function check(){
  if(busy||navigating||document.visibilityState==='hidden')return;busy=true;
  try{
   const response=await window.Lucky.api('phone_dashboard_launch');
   const launch=response.launch;
   if(!launch||!(/^[a-f0-9]{32}$/.test(launch.session))||
      !(/^[a-z0-9][a-z0-9-]{1,62}$/.test(launch.game)))return;
   navigating=true;
   const dest=launch.game==='spin-wheel'
     ?'game.php?session='+encodeURIComponent(launch.session)
     :'package-game.php?game='+encodeURIComponent(launch.game);
   location.assign(dest);
  }catch(_){/* A signed-out desktop cannot receive private phone game launches. */}
  finally{busy=false;}
 }
 void check();setInterval(check,1600);
})();
