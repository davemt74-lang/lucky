(() => {
 'use strict';
 const $=id=>document.getElementById(id);
 const endpoint='api/montage.php';
 let rows=[],filter='ready',request=0;
 const elem=(tag,content='',className='')=>{const el=document.createElement(tag);el.textContent=content;if(className)el.className=className;return el;};
 async function api(action,{query={},body}={}){
  const params=new URLSearchParams({action,...query});const headers={};
  if(body!==undefined){
   const response=await fetch('api/index.php?action=me',{credentials:'same-origin',cache:'no-store'});
   const auth=await response.json();if(!auth?.ok||!auth.csrf)throw Error('Admin sign-in required.');
   headers['Content-Type']='application/json';headers['X-CSRF-Token']=auth.csrf;
  }
  const response=await fetch(endpoint+'?'+params,{credentials:'same-origin',cache:'no-store',method:body===undefined?'GET':'POST',headers,body:body===undefined?undefined:JSON.stringify(body)});
  const data=await response.json();if(!response.ok||!data?.ok)throw Error(data.error||`HTTP ${response.status}`);return data;
 }
 const prettyDate=value=>{try{return new Date(value.replace(' ','T')+'Z').toLocaleString();}catch(_){return value||'';}};
 const fmtBytes=n=>n>=1048576?(n/1048576).toFixed(1)+' MB':Math.round(n/1024)+' KB';
 const fmtDuration=ms=>{if(!ms||ms<0)return 'Duration unavailable';const secs=Math.ceil(ms/1000);return Math.floor(secs/60)+':'+String(secs%60).padStart(2,'0');};
 const labelFor=r=>r.game+' · Session '+r.session.slice(0,8);
 function openPlayer(row){
  if(!row.play||!row.available)return;
  const modal=$('video-gallery-player'),video=$('video-gallery-player-video');
  $('video-gallery-player-title').textContent=labelFor(row);
  video.src=row.play;video.load();modal.showModal();video.play().catch(()=>{});
 }
 function closePlayer(){const modal=$('video-gallery-player'),video=$('video-gallery-player-video');video.pause();video.removeAttribute('src');video.load();if(modal.open)modal.close();}
 function visible(row){return filter==='all'||(filter==='ready'&&row.status==='ready')||(filter==='processing'&&['queued','running'].includes(row.status))||(filter==='failed'&&['failed','cancelled'].includes(row.status));}
 function paint(){
  const root=$('video-gallery-list');if(!root)return;root.replaceChildren();
  const list=rows.filter(visible);
  if(!list.length){root.append(elem('p',filter==='ready'?'No finished movies yet. Export clips from an active session to create one.':'No exports in this category.','video-gallery-empty'));return;}
  for(const r of list){
   const card=elem('article','','video-gallery-card');card.dataset.videoId=r.id;
   const media=elem('div','','video-gallery-poster');
   if(r.thumbnail&&r.available){const poster=elem('img');poster.src=r.thumbnail;poster.alt='Poster frame for '+labelFor(r);poster.loading='lazy';media.append(poster);}
   else if(r.play&&r.available){const v=elem('video');v.src=r.play;v.preload='metadata';v.muted=true;v.playsInline=true;v.setAttribute('aria-label','Video thumbnail for '+labelFor(r));media.append(v);}
   else media.append(elem('span',r.status==='ready'?'File missing':r.status==='failed'?'!':'▸','video-gallery-symbol'));
   const content=elem('div','','video-gallery-content');content.append(elem('strong',labelFor(r)),elem('span',prettyDate(r.finishedAt||r.createdAt),'video-gallery-date'));
   content.append(elem('small','Duration '+fmtDuration(r.durationMs)+' · '+r.game+' · Session '+r.session.slice(0,8),'video-gallery-meta'));
   const status=elem('span',r.status==='ready'?(r.available?'Finished · '+fmtBytes(r.bytes):'Finished · file missing'):r.status,'video-gallery-state');status.dataset.status=r.status;content.append(status);
   if(r.error&&['failed','cancelled'].includes(r.status))content.append(elem('small',r.error,'video-gallery-error'));
   const actions=elem('div','','video-gallery-actions');
   if(r.play&&r.available){const watch=elem('button','▶ Watch','btn btn-primary');watch.type='button';watch.onclick=()=>openPlayer(r);actions.append(watch);
    const download=elem('a','↓ Download MP4','btn btn-outline');download.href=r.download;download.setAttribute('download','');actions.append(download);}
   const editor=elem('a','Edit timeline ↗','btn btn-outline');editor.href='editor.php?session='+encodeURIComponent(r.session);actions.append(editor);
   content.append(actions);card.append(media,content);root.append(card);
  }
 }
 async function refresh(){const id=++request;const result=await api('gallery');if(id!==request)return;rows=result.renders||[];
  $('video-gallery-status').textContent=rows.filter(x=>x.status==='ready'&&x.available).length+' finished movies · '+rows.filter(x=>['queued','running'].includes(x.status)).length+' processing';paint();}
 function init(){
  $('video-gallery-refresh').onclick=()=>refresh().catch(e=>{$('video-gallery-status').textContent=e.message;});
  document.querySelectorAll('[data-video-filter]').forEach(button=>button.onclick=()=>{filter=button.dataset.videoFilter;
   document.querySelectorAll('[data-video-filter]').forEach(b=>{const selected=b===button;b.classList.toggle('selected',selected);b.setAttribute('aria-pressed',String(selected));});paint();});
  $('video-gallery-player-close').onclick=closePlayer;
  $('video-gallery-player').addEventListener('cancel',e=>{e.preventDefault();closePlayer();});
  $('video-gallery-player').addEventListener('click',e=>{if(e.target===$('video-gallery-player'))closePlayer();});
 }
 window.LuckyVideoGallery={refresh,queueCurrent:session=>api('queue_current',{body:{session}})};
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init,{once:true});else init();
})();
