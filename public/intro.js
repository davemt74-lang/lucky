(() => {
 'use strict';const L=Lucky,$=L.$;let intro=null,stop=null;
 async function init(){const [s]=await Promise.all([L.api('settings',{query:{game:'spin-wheel'}}),L.loadTracks()]);intro=s.settings.intro;
  $('intro-title').textContent=intro.title;$('intro-subtitle').textContent=intro.subtitle;
  document.body.style.setProperty('--game-accent',s.settings.theme?.accent||'#f6cb74');
  document.body.style.background=s.settings.theme?.background||'#0d1423';
 }
 $('intro-play').onclick=async()=>{try{if(intro?.trackId){L.volume('music',Number(intro.volume));await L.playMusic(intro.trackId);}
   else{L.beep('bonus');}$('intro-status').textContent='Intro is playing.';
   if(intro?.announcement)L.speak(`Welcome to ${intro.title}!`);
  }catch(e){$('intro-status').textContent=e.message;}};
 $('intro-stop').onclick=()=>{L.stopMusic();$('intro-status').textContent='Intro stopped.';};
 $('intro-voice').onclick=()=>{if(stop){stop();stop=null;$('intro-voice').textContent='🎙 Voice off';return;}
  stop=L.listen(phrase=>{const cmd=phrase.toLowerCase();
   if(/play intro|start intro/.test(cmd))$('intro-play').click();
   else if(/stop intro|pause intro/.test(cmd))$('intro-stop').click();
   else if(/enter game|start game|play game/.test(cmd))location.href='game.php';
   else if(/game hub|all games/.test(cmd))location.href='index.php';
  },m=>$('intro-status').textContent=m);$('intro-voice').textContent='⏹ Stop voice';};
 init().catch(e=>$('intro-status').textContent='Unable to load intro settings: '+e.message);
})();
