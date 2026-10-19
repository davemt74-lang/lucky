(() => {
 'use strict';const L=Lucky,$=L.$,wheel=new LuckyWheel($('live-wheel'));
 const body=document.body,id=body.dataset.session,token=body.dataset.token;let lastSpin=0,lastVersion=0,animating=false,initialized=false;
 const render=v=>{
  if($('display-pair-phone'))$('display-pair-phone').hidden=!['active','paused'].includes(v.status);
  $('display-state').textContent=v.status==='active'?`LIVE · ${v.spinCount} SPINS`:v.status==='paused'?'GAME PAUSED':'GAME ENDED';
  $('display-latest').textContent=v.pendingBonus?'✧ BONUS ROUND':v.claims.length?`Most recent: ${v.claims[v.claims.length-1].name}`:'Spin for the first prize!';
  const list=$('display-claims');list.replaceChildren();v.claims.slice(-8).reverse().forEach(c=>{const e=document.createElement('div');e.className='platform-claim';e.textContent=`${c.name} — ${L.money(c.cents)}`;list.append(e);});
  if(!animating){const slices=v.pendingBonus?(v.bonusSlices||[]):v.slots.map(s=>({name:s.name,cents:s.value,tier:s.tier,kind:s.tier?'prize':'empty'}));
    if(!v.pendingBonus&&v.bonusEnabled&&(v.bonusSlices||[]).length)slices.push({name:'BONUS ROUND',tier:'bonus',kind:'trigger'});wheel.setSlices(slices);}
 };
 async function tick(){try{const r=await L.api('watch',{query:{id,token}}),v=r.view;
   if(!initialized){initialized=true;lastSpin=v.lastSpin?.spinCount||0;lastVersion=v.version;render(v);return;}
   if(v.version!==lastVersion){lastVersion=v.version;
     if(v.lastSpin && v.lastSpin.spinCount!==lastSpin){lastSpin=v.lastSpin.spinCount;animating=true;
      await wheel.spinTo(v.lastSpin.index,v.lastSpin.slices,matchMedia('(prefers-reduced-motion: reduce)').matches?250:3800);
      animating=false;if(v.lastSpin.type==='award')L.beep('win');else L.beep('bonus');}
     render(v);}
  }catch(e){$('display-state').textContent='Connection interrupted: '+e.message;}
 }
 if($('display-pair-qr')&&window.LuckyQR){
  const url=new URL('controller.php',document.baseURI);url.searchParams.set('session',id);url.searchParams.set('viewer',token);
  $('display-pair-qr').innerHTML=window.LuckyQR.svg(url.href);
 }
 window.LuckyDisplayRemote=async function(command){
  const menu=$('display-remote-menu');
  if(command==='menu')return window.LuckyConsole?.emit('menu')??false;
  if(command==='back')return window.LuckyConsole?.emit('back')??false;
  if(command==='music-on'){if(!L.pref.musicOn)await L.toggleMusic();$('music-toggle').textContent='♪ Music on';return true;}
  if(command==='music-off'){L.stopMusic();$('music-toggle').textContent='♪ Music off';return true;}
  if(['left','right','up','down','select','done'].includes(command))return window.LuckyConsole?.emit(command)??false;
  if(command==='music-next'||command==='music-previous'){
   if(!L.tracks.length)throw Error('No music in library.');await L.nextMusic(command==='music-next'?1:-1);return true;
  }
  if(['volume-up','volume-down'].includes(command)){
   const v=L.volume('music',L.pref.musicVolume+(command==='volume-up'?10:-10));$('music-volume').value=v;return true;
  }
  const exactVolume=command.match(/^volume-(100|[0-9]{1,2})$/);
  if(exactVolume){const v=L.volume('music',Number(exactVolume[1]));$('music-volume').value=v;return true;}
  if(command.startsWith('music-find-')){const slug=command.slice(11),normalize=t=>String(t).toLowerCase().normalize('NFKD').replace(/[\u0300-\u036f]/g,'').replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'');const song=L.tracks.find(t=>normalize(t.title)===slug);if(!song)throw Error('Song not found.');await L.playMusic(song.id);return true;}
  if(command==='music-play'){if(!L.pref.musicOn)await L.toggleMusic();return true;}
  if(command==='music-pause'){L.stopMusic();return true;}
  if(command==='home'){location.href='index.php';return true;}
  if(command==='mute'){L.audioToggleMute(true);return true;}
  if(command==='unmute'){L.audioToggleMute(false);return true;}
  if(command==='effects-on'){L.volume('effects',75);return true;}
  if(command==='effects-off'){L.volume('effects',0);return true;}
  if(command==='speech-on'||command==='speech-off')return true;
  return false;
 };
 const toggle=$('music-toggle');
 Promise.all([L.loadTracks(),tick()]).then(()=>{L.bindMusic();}).catch(e=>{$('display-state').textContent=e.message;});
 setInterval(()=>{if(!animating)tick();},950);
})();
