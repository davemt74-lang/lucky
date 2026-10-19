(() => {
 'use strict';
 const prefsKey='lucky-hub-platform-audio-v1';
 const defaults={musicOn:false,musicVolume:55,effectsVolume:75,speechVolume:85,muted:false};
 let pref;try{pref={...defaults,...JSON.parse(localStorage.getItem(prefsKey)||'{}')};}catch(_){pref={...defaults};}
 const tracks=[];let music=null,trackId='',speech=null,recognizer=null,voiceActive=false;
 const $=id=>document.getElementById(id);
 const money=c=>'$'+(Number(c||0)/100).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
 const dollars=c=>(Number(c||0)/100).toFixed(2);
 const escapeText=s=>String(s??'');
 function savePref(){try{localStorage.setItem(prefsKey,JSON.stringify(pref));}catch(_){}}
 async function api(action,options={}){
  const params=new URLSearchParams({action,...options.query});
  const headers=options.headers||{};
  if(options.body!==undefined){
   const auth=await getMe();headers['X-CSRF-Token']=auth.csrf||'';
   if(!(options.body instanceof FormData))headers['Content-Type']='application/json';
  }
  const r=await fetch('api/index.php?'+params,{method:options.body!==undefined?'POST':'GET',credentials:'same-origin',headers,
      body:options.body===undefined?undefined:(options.body instanceof FormData?options.body:JSON.stringify(options.body)),cache:'no-store'});
  let data;try{data=await r.json();}catch(_){throw Error('The server returned an unreadable response.');}
  if(!r.ok||!data.ok)throw Error(data.error||`HTTP ${r.status}`);return data;
 }
 let meCache=null;
 async function getMe(){if(!meCache)meCache=(await fetch('api/index.php?action=me',{credentials:'same-origin',cache:'no-store'})).json();return meCache;}
 function note(id,msg,bad=false){const el=$(id);if(el){el.textContent=msg;el.classList.toggle('platform-error',!!bad);}}
 async function loadTracks(){const result=await api('tracks');tracks.splice(0,tracks.length,...result.tracks);return tracks;}
 function fillTracks(select,value=''){
   const node=typeof select==='string'?$(select):select;if(!node)return;
   node.replaceChildren();node.add(new Option('No song selected',''));
   tracks.forEach(t=>node.add(new Option(t.title,t.id)));
   node.value=tracks.some(t=>t.id===value)?value:'';
 }
 function getTrack(id){return tracks.find(t=>t.id===id);}
 function ensureMusic(){if(!music){music=new Audio();music.loop=true;music.preload='none';music.addEventListener('error',()=>{pref.musicOn=false;savePref();});}return music;}
 async function playMusic(id=trackId){
  if(!id){pref.musicOn=false;savePref();return;}
  const song=getTrack(id);if(!song)throw Error('This song is not in the library.');
  const el=ensureMusic();
  if(trackId!==id||el.src===''){el.src=song.src;trackId=id;}
  el.volume=pref.muted?0:pref.musicVolume/100;
  pref.musicOn=true;savePref();try{await el.play();}catch(e){pref.musicOn=false;savePref();throw Error('Audio was blocked. Click Play or Music On to enable playback.');}
 }
 function stopMusic(){if(music)music.pause();pref.musicOn=false;savePref();}
 async function toggleMusic(){if(pref.musicOn){stopMusic();}else{if(!trackId&&tracks.length)trackId=tracks[0].id;await playMusic(trackId);}return pref.musicOn;}
 async function nextMusic(delta=1){if(!tracks.length)return;const index=tracks.findIndex(t=>t.id===trackId);await playMusic(tracks[(index+delta+tracks.length)%tracks.length].id);}
 function volume(channel,n){const key=channel==='effects'?'effectsVolume':channel==='speech'?'speechVolume':'musicVolume';
  if(!Number.isFinite(n))return pref[key];pref[key]=Math.min(100,Math.max(0,Math.round(n)));if(music)music.volume=pref.muted?0:pref.musicVolume/100;savePref();return pref[key];}
 function audioToggleMute(value){pref.muted=Boolean(value);if(music)music.volume=pref.muted?0:pref.musicVolume/100;savePref();}
 function beep(kind='tick'){
  if(pref.muted||pref.effectsVolume<=0)return;
  try{const C=window.AudioContext||window.webkitAudioContext;if(!C)return;
   if(!window.__luckyAudioCtx)window.__luckyAudioCtx=new C();const c=window.__luckyAudioCtx;
   const o=c.createOscillator(),g=c.createGain(),start=c.currentTime;
   o.type=kind==='win'?'triangle':'sine';o.frequency.setValueAtTime(kind==='win'?660:kind==='bonus'?850:360,start);
   o.frequency.exponentialRampToValueAtTime(kind==='win'?1046:kind==='bonus'?1260:230,start+.16);
   g.gain.setValueAtTime(.001,start);g.gain.linearRampToValueAtTime(.11*pref.effectsVolume/100,start+.012);g.gain.exponentialRampToValueAtTime(.001,start+.22);
   o.connect(g);g.connect(c.destination);o.start(start);o.stop(start+.23);
  }catch(_){}
 }
 function speak(text){if(pref.muted||pref.speechVolume===0||!('speechSynthesis' in window))return;
  try{speechSynthesis.cancel();const u=new SpeechSynthesisUtterance(String(text));u.rate=1;u.volume=pref.speechVolume/100;
   if(music){music.volume=pref.musicVolume/400;u.onend=()=>{music.volume=pref.muted?0:pref.musicVolume/100;};}
   speechSynthesis.speak(u);
  }catch(_){}}
 function listen(onSpeech,onStatus){
  const Recognition=window.SpeechRecognition||window.webkitSpeechRecognition;
  if(!Recognition){onStatus('Speech recognition is unavailable in this browser.');return ()=>{};}
  const r=new Recognition();recognizer=r;r.lang='en-US';r.continuous=true;r.interimResults=false;voiceActive=true;
  r.onresult=e=>{for(let i=e.resultIndex;i<e.results.length;i++){const result=e.results[i];if(result.isFinal&&result[0]?.transcript)onSpeech(result[0].transcript.trim());}};
  r.onend=()=>{if(voiceActive)setTimeout(()=>{if(voiceActive){try{r.start();}catch(_){}}},550);};
  r.onerror=e=>{if(['not-allowed','service-not-allowed','audio-capture'].includes(e.error)){voiceActive=false;onStatus('Microphone unavailable or permission denied.');}};
  try{r.start();onStatus('Listening…');}catch(_){voiceActive=false;onStatus('Could not enable microphone.');}
  return ()=>{voiceActive=false;try{r.abort();}catch(_){}};
 }
 function idempotency(){return crypto.randomUUID?crypto.randomUUID():Math.random().toString(36).slice(2)+Date.now();}
 function bindMusic(){
  const select=$('music-select');if(!select)return;
  fillTracks(select,trackId);select.onchange=()=>{trackId=select.value;if(pref.musicOn)playMusic(trackId).catch(e=>note('voice-status',e.message,true));};
  const t=$('music-toggle');function refresh(){if(t)t.textContent=pref.musicOn?'♪ Music on':'♪ Music off';}
  if(t)t.onclick=async()=>{try{await toggleMusic();}catch(e){note('voice-status',e.message,true);}refresh();};
  if($('music-next'))$('music-next').onclick=()=>nextMusic(1).catch(e=>note('voice-status',e.message,true));
  ['music','effects','speech'].forEach(ch=>{const input=$(ch+'-volume');if(!input)return;
   const key=ch==='music'?'musicVolume':ch+'Volume';input.value=pref[key];const out=$(ch+'-value');if(out)out.textContent=pref[key]+'%';
   input.oninput=()=>{volume(ch,Number(input.value));if(out)out.textContent=pref[key]+'%';};});refresh();
 }
 window.Lucky={api,getMe,$,money,dollars,note,tracks,getTrack,loadTracks,fillTracks,bindMusic,pref,playMusic,stopMusic,toggleMusic,nextMusic,volume,audioToggleMute,beep,speak,listen,idempotency};
})();
