(() => {
 'use strict';const L=Lucky,$=L.$;let intro=null;
 async function init(){const [s]=await Promise.all([L.api('settings',{query:{game:document.body.dataset.game||'spin-wheel'}}),L.loadTracks()]);intro=s.settings.intro;
  $('intro-title').textContent=intro.title;$('intro-subtitle').textContent=intro.subtitle;
  document.body.style.setProperty('--game-accent',s.settings.theme?.accent||'#f6cb74');
  document.body.style.background=s.settings.theme?.background||'#0d1423';
 }
 $('intro-play').onclick=async()=>{try{if(intro?.trackId){L.volume('music',Number(intro.volume));await L.playMusic(intro.trackId);}
   else{L.beep('bonus');}$('intro-status').textContent='Intro is playing.';
   if(intro?.announcement)L.speak(`Welcome to ${intro.title}!`);
  }catch(e){$('intro-status').textContent=e.message;}};
 $('intro-stop').onclick=()=>{L.stopMusic();$('intro-status').textContent='Intro stopped.';};
 init().catch(e=>$('intro-status').textContent='Unable to load intro settings: '+e.message);
})();
