(() => {
 'use strict';const L=Lucky,$=L.$;let settings=null,activeTier='tier1',editIntroGame='spin-wheel',introConfig=null,history=[],sessions=[],readyGames=[];
 const amount=s=>{const n=Number(s);if(!Number.isFinite(n)||n<0||n>10000000)throw Error('Invalid dollar amount.');return Math.round(n*100);};
 const cents=c=>(Number(c||0)/100).toFixed(2);
 const elem=(tag,txt='',cls='')=>{const e=document.createElement(tag);if(txt)e.textContent=txt;if(cls)e.className=cls;return e;};
 const status=(message,bad=false)=>L.note('admin-status',message,bad);
 function selectTier(tier){activeTier=tier;document.querySelectorAll('[data-tier]').forEach(b=>b.classList.toggle('selected',b.dataset.tier===tier));renderTier();}
 function setBudget(){settings.config.pools[activeTier].budgetCents=amount($('tier-budget').value);}
 function renderTier(){const pool=settings.config.pools[activeTier],list=$('prize-items');list.replaceChildren();$('tier-budget').value=cents(pool.budgetCents);
  for(const p of pool.prizes){const row=elem('div','','platform-prize-item');const name=elem('input'),value=elem('input'),url=elem('input'),videoUrl=elem('input'),image=elem('input'),upload=elem('input'),remove=elem('button','✕','btn btn-outline');
   name.value=p.name;name.maxLength=80;name.setAttribute('aria-label','Prize name');name.onchange=()=>p.name=name.value.trim();
   value.type='number';value.min=0;value.step='0.01';value.value=cents(p.cents);value.setAttribute('aria-label',p.name+' dollar amount');
   value.onchange=()=>{try{p.cents=amount(value.value);}catch(e){status(e.message,true);}};
   url.type='url';url.placeholder='Optional prize URL';url.value=p.url||'';url.onchange=()=>p.url=url.value.trim();
   videoUrl.type='url';videoUrl.placeholder='Optional video URL (MP4/WebM/YouTube/Vimeo)';videoUrl.value=p.videoUrl||'';videoUrl.onchange=()=>p.videoUrl=videoUrl.value.trim();
   image.type='url';image.placeholder='Optional image URL';image.value=p.image||'';image.onchange=()=>p.image=image.value.trim();
   upload.type='file';upload.accept='image/png,image/jpeg,image/webp';upload.setAttribute('aria-label','Upload image for '+p.name);
   upload.onchange=async()=>{if(!upload.files?.[0])return;try{const form=new FormData();form.append('image',upload.files[0]);
     const r=await L.api('image_upload',{body:form});p.image=new URL(r.path,document.baseURI).href;image.value=p.image;status('Image uploaded. Save all prize settings to attach it to the prize.');
    }catch(e){status(e.message,true);}};
   remove.type='button';remove.setAttribute('aria-label','Remove '+p.name);remove.onclick=()=>{if(!confirm('Remove '+p.name+' from the game?'))return;
    pool.prizes=pool.prizes.filter(item=>item.id!==p.id);renderTier();};
   const proof=document.createElement('details');proof.className='proof-angle-settings';
   const summary=document.createElement('summary');summary.textContent=`📷 Proof angles for ${p.name} (${(p.proofAngles||[]).length} configured)`;
   const explanatory=elem('p','The selected active camera count controls the recording sequence. Set the preferred angle name and recording seconds for each view (3–30s).','mini-label');
   const sync=elem('button','Match cameras on this device','btn btn-outline');sync.type='button';
   const angleList=elem('div','','proof-angle-list');
   function paintAngles(){angleList.replaceChildren();summary.textContent=`📷 Proof angles for ${p.name} (${(p.proofAngles||[]).length} configured)`;
    (p.proofAngles||[]).forEach((a,i)=>{const item=elem('div','','proof-angle-row');const label=elem('input');label.value=a.label;label.maxLength=80;label.placeholder=`Angle ${i+1} — e.g. Front`;label.setAttribute('aria-label',`Prize ${p.name}, angle ${i+1} label`);
     label.oninput=()=>a.label=label.value;
     const duration=elem('input');duration.type='number';duration.min='3';duration.max='30';duration.step='1';duration.value=a.seconds;duration.setAttribute('aria-label',`Prize ${p.name}, angle ${i+1} seconds`);
     duration.onchange=()=>a.seconds=Number(duration.value);
     const count=elem('span',`View ${i+1}`,'mini-label');item.append(count,label,duration);angleList.append(item);
    });
   }
   sync.onclick=async()=>{try{if(!navigator.mediaDevices?.enumerateDevices)throw Error('Camera detection requires HTTPS and a supported browser.');
    // Only a user-initiated click requests camera permission; never start recording here.
    if(navigator.mediaDevices.getUserMedia){const probe=await navigator.mediaDevices.getUserMedia({video:true,audio:false});probe.getTracks().forEach(t=>t.stop());}
    const sources=(await navigator.mediaDevices.enumerateDevices()).filter(d=>d.kind==='videoinput').slice(0,4);
    const before=p.proofAngles||[];p.proofAngles=sources.map((d,i)=>({label:before[i]?.label||d.label?.slice(0,80)||`View ${i+1}`,seconds:before[i]?.seconds||8}));
    paintAngles();status(`${p.name}: matched ${sources.length} camera views. Save prize settings to publish.`);
   }catch(e){status('Could not detect cameras: '+e.message,true);}};
   proof.append(summary,explanatory,sync,angleList);paintAngles();
   row.append(name,value,url,videoUrl,image,upload,remove,proof);list.append(row);
  }
 }
 async function loadSettings(){const r=await L.api('settings',{query:{game:'spin-wheel'}});settings=r.settings;
   for(const [k,v] of Object.entries(settings.config.odds))if($('odds-'+k))$('odds-'+k).value=v;
   renderTier();}
 async function save(){try{
  setBudget();for(const k of Object.keys(settings.config.odds))settings.config.odds[k]=Number($('odds-'+k).value);
  const r=await L.api('settings_save',{body:{game:'spin-wheel',...settings}});settings=r.settings;status('Prize pools and odds saved to MySQL.');renderTier();
 }catch(e){status(e.message,true);}}
 $('tier-budget').onchange=()=>{try{setBudget();}catch(e){status(e.message,true);}};
 document.querySelectorAll('[data-tier]').forEach(b=>b.onclick=()=>{try{setBudget();selectTier(b.dataset.tier);}catch(e){status(e.message,true);}});
 $('add-prize').onsubmit=async e=>{e.preventDefault();try{setBudget();const f=e.currentTarget,pool=settings.config.pools[activeTier];
   if(pool.prizes.length>=24)throw Error('Limit: 24 prizes per tier.');
   const name=f.elements.name.value.trim();if(!name)throw Error('Enter a prize name.');
   const id='p'+(crypto.randomUUID?.().replace(/-/g,'')||Math.random().toString(36).slice(2)+Date.now());
   let imageUrl=f.elements.image.value.trim();
   if(f.elements.imagefile.files?.[0]){const form=new FormData();form.append('image',f.elements.imagefile.files[0]);const uploaded=await L.api('image_upload',{body:form});imageUrl=new URL(uploaded.path,document.baseURI).href;}
   pool.prizes.push({id,name,cents:amount(f.elements.value.value),url:f.elements.url.value.trim(),videoUrl:f.elements.videoUrl.value.trim(),image:imageUrl,proofAngles:[]});
   f.reset();renderTier();status('Prize added. Select Save all prize settings to publish.');
 }catch(err){status(err.message,true);}};
 $('auto-split').onclick=()=>{try{setBudget();const pool=settings.config.pools[activeTier],claimed=new Set((window.__lastSession?.session?.claims||[]).map(c=>c.prizeId));
  const fixed=pool.prizes.filter(p=>claimed.has(p.id)).reduce((n,p)=>n+p.cents,0),available=pool.prizes.filter(p=>!claimed.has(p.id));
  if(!available.length)throw Error('There are no unclaimed prizes to split.');const remaining=pool.budgetCents-fixed;
  if(remaining<0)throw Error('Claimed prizes exceed the tier budget.');const each=Math.floor(remaining/available.length);let extra=remaining%available.length;
  for(const p of available)p.cents=each+(extra-->0?1:0);
  renderTier();status('Unclaimed prizes auto-split. Save to publish.');
 }catch(e){status(e.message,true);}};
 $('save-prizes').onclick=save;
 async function loadIntro(){const r=await L.api('settings',{query:{game:editIntroGame}});introConfig=r.settings;
   $('intro-title').value=introConfig.intro.title;$('intro-subtitle').value=introConfig.intro.subtitle;
   L.fillTracks('intro-track',introConfig.intro.trackId);$('intro-volume').value=introConfig.intro.volume;
   $('intro-announcement').checked=introConfig.intro.announcement!==false;
   $('theme-accent').value=introConfig.theme.accent||'#f6cb74';$('theme-background').value=introConfig.theme.background||'#0d1423';}
 $('intro-game').onchange=async()=>{editIntroGame=$('intro-game').value;try{await loadIntro();}catch(e){status(e.message,true);}};
 $('save-intro').onclick=async()=>{try{const intro={title:$('intro-title').value,subtitle:$('intro-subtitle').value,trackId:$('intro-track').value,volume:Number($('intro-volume').value),announcement:$('intro-announcement').checked};
  const theme={accent:$('theme-accent').value,background:$('theme-background').value};
  await L.api('settings_save',{body:{game:editIntroGame,intro,theme}});status('Game intro and theme saved.');}
  catch(e){status(e.message,true);}};
 async function refreshMusic(){const tracks=await L.loadTracks();const list=$('music-items');list.replaceChildren();
  for(const t of tracks){const row=elem('div','','platform-claim');const title=elem('strong',t.title),desc=elem('span',t.kind==='upload'?'Uploaded audio':'Hosted URL'),remove=elem('button','Remove','btn btn-outline');
   remove.onclick=async()=>{if(!confirm('Remove '+t.title+'?'))return;try{await L.api('track_remove',{body:{id:t.id}});await refreshMusic();status('Track removed.');}catch(e){status(e.message,true);}};
   row.append(title,desc,remove);list.append(row);
  }
  if(introConfig)L.fillTracks('intro-track',introConfig.intro.trackId);
 }
 $('music-upload').onsubmit=async e=>{e.preventDefault();const f=e.currentTarget,button=f.querySelector('button');button.disabled=true;
  try{const form=new FormData(f);await L.api('upload',{body:form});f.reset();await refreshMusic();status('Uploaded song saved to the shared server library.');}
  catch(err){status(err.message,true);}finally{button.disabled=false;}};
 $('music-add-url').onsubmit=async e=>{e.preventDefault();const f=e.currentTarget;try{await L.api('track_add',{body:{title:f.elements.title.value.trim(),url:f.elements.url.value.trim()}});
  f.reset();await refreshMusic();status('Hosted track saved.');}catch(err){status(err.message,true);}};
 async function refreshHistory(){const r=await L.api('history');history=r.history;const list=$('history-items');list.replaceChildren();
  for(const item of history){const row=elem('div','','platform-claim');row.append(elem('strong',(item.game||'Game')+' · '+item.id.slice(0,8)+' · '+item.claims+' claims'),
    elem('span',`${L.money(item.awardedCents)} awarded · ${item.spins} spins · ${item.endedAt||'Ended'}`));list.append(row);}
  if(!history.length)list.append(elem('p','No completed sessions yet.'));
 }
 $('refresh-history').onclick=()=>refreshHistory().catch(e=>status(e.message,true));

 function makeSessionButton(label,kind,item){
  const b=elem('button',label,'btn btn-outline');b.type='button';b.dataset.sessionAction=kind;b.dataset.sessionId=item.id;
  b.onclick=async()=>{
   if(b.disabled)return;
   if(['end','finish_saved'].includes(kind)&&!confirm('Finish this session permanently? Finished sessions are archived and cannot be resumed.'))return;
   b.disabled=true;
   try{
    await L.api(kind,{body:{id:item.id,idempotencyKey:L.idempotency()}});
    status('Session '+(kind==='save_session'?'saved':kind==='resume_saved'?'resumed':'finished')+'.');
    await refreshSessions();if(['end','finish_saved'].includes(kind))await refreshHistory();
   }catch(e){status(e.message,true);}
   finally{b.disabled=false;}
  };
  return b;
 }
 function renderSessions(){
  const container=$('admin-session-list'),filter=$('session-game-filter').value;
  container.replaceChildren();
  const rows=sessions.filter(x=>!filter||x.game===filter);
  if(!rows.length){container.append(elem('p','No sessions for this game yet. Select a ready game and choose Start game.'));return;}
  for(const session of rows){
   const game=readyGames.find(x=>x.id===session.game);
   const card=elem('article','','admin-session-card');
   const head=elem('header'),title=elem('strong',(game?.name||session.game)+' · '+session.id.slice(0,8));
   const state=elem('span',session.status==='ended'?'Finished':session.status,'admin-session-status');state.dataset.status=session.status;
   head.append(title,state);
   const meta=elem('div',session.spins+' spins · '+session.claims+' claims · '+L.money(session.awardedCents)+' awarded · '+session.createdAt,'admin-session-metadata');
   const actions=elem('div','','admin-session-actions');
   if(['active','paused'].includes(session.status)){
    actions.append(makeSessionButton('Save session','save_session',session),makeSessionButton('Finish game','end',session));
   }
   if(session.status==='saved'){
    actions.append(makeSessionButton('Resume saved','resume_saved',session),makeSessionButton('Finish game','finish_saved',session));
   }
   if(game?.path&&session.status!=='ended'){
    const link=elem('a','Open game ↗','btn btn-outline');
    link.href=game.path+(game.path.includes('?')?'&':'?')+'session='+encodeURIComponent(session.id);
    actions.append(link);
   }
   card.append(head,meta,actions);container.append(card);
  }
 }
 async function refreshSessions(){
  const results=await Promise.all([L.api('manifest'),L.api('sessions')]);
  readyGames=(results[0].games||[]).filter(x=>x.status==='ready'&&typeof x.id==='string'&&x.path);
  sessions=results[1].sessions||[];
  const select=$('session-game-filter'),old=select.value||'spin-wheel';
  select.replaceChildren(new Option('All games',''));
  const ids=new Set([...readyGames.map(x=>x.id),...sessions.map(x=>x.game)]);
  for(const id of ids){const game=readyGames.find(x=>x.id===id);select.add(new Option(game?.name||id,id));}
  select.value=ids.has(old)?old:'';
  updateStartButton();renderSessions();
 }
 function updateStartButton(){
  const id=$('session-game-filter').value;
  $('start-selected-game').disabled=!readyGames.some(x=>x.id===id);
 }
 $('session-game-filter').onchange=()=>{updateStartButton();renderSessions();};
 $('refresh-sessions').onclick=()=>refreshSessions().catch(e=>status(e.message,true));
 $('start-selected-game').onclick=async()=>{
  const game=$('session-game-filter').value;
  if(!readyGames.some(x=>x.id===game))return status('Choose a ready game first.',true);
  const button=$('start-selected-game');button.disabled=true;
  try{
   await L.api('start',{body:{game}});
   await refreshSessions();status('New '+game+' session started.');
  }catch(e){status(e.message,true);}finally{updateStartButton();}
 };

 $('export-history').onclick=()=>{const rows=[['Game','Session','Started','Ended','Spins','Claims','Awarded USD'],...history.map(h=>[h.game,h.id,h.createdAt,h.endedAt,h.spins,h.claims,cents(h.awardedCents)])];
  const csv=rows.map(row=>row.map(value=>{const s=String(value??'');return '"'+(/^[=+@\-]/.test(s)?"'":'')+s.replaceAll('"','""')+'"';}).join(',')).join('\r\n');
  const a=elem('a');a.href=URL.createObjectURL(new Blob([csv],{type:'text/csv'}));a.download='lucky-session-history.csv';a.click();setTimeout(()=>URL.revokeObjectURL(a.href),2000);};
 $('import-legacy').onclick=async()=>{try{const raw=JSON.parse(localStorage.getItem('lucky-spin-v3')||'null');if(!raw?.pools)throw Error('No prior V5 prize pools found in this browser.');
  if(!confirm('Replace server prize definitions with this browser\'s saved tier lists? Current server game claims will NOT be overwritten.'))return;
  const pools={};for(const tier of ['tier1','tier2','bonus']){const p=raw.pools[tier];pools[tier]={budgetCents:p.budgetCents,prizes:p.prizes.map(x=>({id:x.id,name:x.name,cents:x.cents,url:x.url||'',videoUrl:x.videoUrl||'',image:(x.image||'').startsWith('data:')?'':x.image||'',proofAngles:x.proofAngles||[]}))};}
  settings.config.pools=pools;
  if(raw.odds)for(const k of Object.keys(settings.config.odds))if(Number.isFinite(raw.odds[k]))settings.config.odds[k]=raw.odds[k];
  await L.api('settings_save',{body:{game:'spin-wheel',...settings}});
  if((raw.claims||[]).length){await L.api('import_legacy',{body:{sourceGameId:raw.gameId||'legacy-'+raw.createdAt,legacy:{pools,odds:settings.config.odds,claims:raw.claims}}});}
  await loadSettings();await refreshHistory();status(`Imported browser prize pools and ${(raw.claims||[]).length} archived claims.`);
 }catch(e){status(e.message,true);}};
 // Account logout is handled by the shared CSRF-protected user dropdown.
 document.querySelectorAll('[data-tab]').forEach(b=>b.onclick=()=>{const tab=b.dataset.tab;
   document.querySelectorAll('[data-tab]').forEach(item=>{item.classList.toggle('selected',item===b);if(item===b)item.setAttribute('aria-current','page');else item.removeAttribute('aria-current');});
   document.querySelectorAll('.platform-admin-panel').forEach(el=>el.hidden=el.id!=='tab-'+tab);
   document.querySelector('.admin-console')?.classList.remove('nav-open');
   $('admin-nav-toggle')?.setAttribute('aria-expanded','false');
 });
 $('admin-nav-toggle').onclick=()=>{
  const open=document.querySelector('.admin-console').classList.toggle('nav-open');
  $('admin-nav-toggle').setAttribute('aria-expanded',String(open));
 };
 const initialTab=new URLSearchParams(location.search).get('tab');if(['prizes','intro','music','sessions','history','migration'].includes(initialTab))document.querySelector(`[data-tab="${initialTab}"]`)?.click();
 try{const legacy=JSON.parse(localStorage.getItem('lucky-spin-v3')||'null');$('legacy-status').textContent=legacy?.pools?'Legacy prize pools found. You can import their definitions.':'No V5 game configuration found in this browser.';}catch(_){$('legacy-status').textContent='No readable V5 game data found.';}
 Promise.all([loadSettings(),refreshMusic(),refreshHistory(),refreshSessions(),L.api('last')]).then(async ([_,__,___,____,last])=>{window.__lastSession=last.data;await loadIntro();status('Admin settings loaded.');}).catch(e=>status(e.message,true));
})();
