/* HF8C6: editor-first Camera Director workspace. Existing control nodes are moved,
 * not cloned, so production camera, consent, session and broadcast handlers survive. */
(() => {
 'use strict';
 const $ = id => document.getElementById(id);
 const shell = document.querySelector('.director-studio-shell');
 if (!shell || shell.dataset.workspaceEnhanced) return;

 const session = shell.querySelector('.director-studio-session');
 const take = shell.querySelectorAll('.director-studio-session')[1];
 const inputs = shell.querySelector('.director-studio-inputs');
 const links = shell.querySelector('.director-studio-links');
 const phoneStatus = $('director-studio-phone-status');
 const director = $('camera-director');
 const monitors = director?.querySelector('.director-monitors');
 const switcher = director?.querySelector(':scope > .director-toolbar');
 const count = director?.querySelector('.director-count');
 const clips = director?.querySelector('.director-clip-toolbar');
 const graphics = director?.querySelector('.director-graphics');
 const audio = director?.querySelector('.director-audio');
 const scenes = director?.querySelector('.director-scenes');
 const exportControls = director?.querySelector('.director-export');
 const broadcast = director?.querySelector('.director-broadcast');
 const status = $('director-status');
 const grid = $('camera-grid');
 if (![session,take,inputs,monitors,switcher,clips,graphics,audio,scenes,exportControls,broadcast,status,grid].every(Boolean)) return;

 // This static template contains no server-supplied content. Session/media titles
 // are inserted only through textContent below.
 const workspace = document.createElement('div');
 workspace.className = 'director-workspace';
 workspace.innerHTML = `
  <header class="director-workspace-top">
   <div class="director-workspace-brand"><span class="director-workspace-mark" aria-hidden="true">✦</span><div><p class="mini-label">PRODUCTION STUDIO</p><h1>Camera Director</h1></div></div>
   <div class="director-workspace-session-indicator"><span class="director-workspace-signal" aria-hidden="true"></span><span id="director-workspace-session-title">No session selected</span><span id="director-workspace-camera-count">0 cameras</span></div>
   <div class="director-workspace-actions"><button type="button" class="btn btn-outline" data-director-panel="sessions">☷ Sessions</button><button type="button" class="btn btn-primary" data-director-panel="live">◉ Go Live</button><div id="director-workspace-record-actions"></div></div>
  </header>
  <div class="director-workspace-body">
   <nav class="director-workspace-rail" aria-label="Director tools">
    <button type="button" data-director-panel="cameras" aria-controls="director-workspace-drawer" aria-expanded="false"><span aria-hidden="true">▣</span>Cameras</button>
    <button type="button" data-director-panel="sessions" aria-controls="director-workspace-drawer" aria-expanded="false"><span aria-hidden="true">▤</span>Sessions</button>
    <button type="button" data-director-panel="scenes" aria-controls="director-workspace-drawer" aria-expanded="false"><span aria-hidden="true">▦</span>Scenes</button>
    <button type="button" data-director-panel="media" aria-controls="director-workspace-drawer" aria-expanded="false"><span aria-hidden="true">▧</span>Media</button>
    <button type="button" data-director-panel="graphics" aria-controls="director-workspace-drawer" aria-expanded="false"><span aria-hidden="true">T</span>Graphics</button>
    <button type="button" data-director-panel="audio" aria-controls="director-workspace-drawer" aria-expanded="false"><span aria-hidden="true">♫</span>Audio</button>
    <button type="button" data-director-panel="live" aria-controls="director-workspace-drawer" aria-expanded="false"><span aria-hidden="true">◉</span>Live</button>
    <button type="button" data-director-panel="settings" aria-controls="director-workspace-drawer" aria-expanded="false"><span aria-hidden="true">⚙</span>Settings</button>
   </nav>
   <div class="director-workspace-content">
    <section class="director-workspace-outputs" aria-label="Live switching monitors"><div id="director-workspace-monitors"></div><div id="director-workspace-switcher"></div></section>
    <section class="director-workspace-multiview" aria-label="Live camera inputs"><header><div><h2>Live Camera Inputs</h2><span id="director-workspace-source-count">0 connected</span></div><button type="button" class="btn btn-outline" data-director-panel="cameras">＋ Add camera</button></header><div id="director-workspace-grid"></div><div id="director-workspace-phone-note"></div></section>
    <section class="director-workspace-timeline" aria-label="Recording session timeline"><header><div><h2>Session Timeline</h2><span id="director-workspace-timeline-summary">Select a session to view recorded takes</span></div><div class="director-workspace-timeline-actions"><button class="btn btn-outline" type="button" id="director-workspace-timeline-refresh">↻ Refresh</button><a class="btn btn-outline" id="director-workspace-editor-link" aria-disabled="true" href="admin.php?tab=recordings">Open full editor ↗</a></div></header><div id="director-workspace-take"></div><div class="director-workspace-timeline-scroll"><div id="director-workspace-timeline-lanes" role="list" aria-label="Uploaded recordings by camera"><p class="director-workspace-empty">Open a recording session to load media.</p></div></div><p class="director-workspace-timeline-foot">Grouped recordings use estimated offsets. Fine alignment and edits are saved in the full multicamera editor.</p></section>
    <footer class="director-workspace-status"><div id="director-workspace-main-status"></div><span id="director-workspace-onair">OFF AIR</span></footer>
   </div>
   <div class="director-workspace-drawer-backdrop" id="director-workspace-drawer-backdrop" hidden></div>
   <aside class="director-workspace-drawer" id="director-workspace-drawer" aria-label="Director tool settings" hidden><header><div><p class="mini-label">DIRECTOR TOOLS</p><h2 id="director-workspace-drawer-title">Sessions</h2></div><button type="button" id="director-workspace-close" aria-label="Close tools">✕</button></header><div class="director-workspace-drawer-scroll">
    <div data-director-content="cameras" hidden></div><div data-director-content="sessions" hidden></div><div data-director-content="scenes" hidden></div><div data-director-content="media" hidden></div><div data-director-content="graphics" hidden></div><div data-director-content="audio" hidden></div><div data-director-content="live" hidden></div><div data-director-content="settings" hidden><h3>Workspace</h3><p>Live camera monitors stay on the main canvas. Connect devices from Cameras, open a recording from Sessions, and cue a source before cutting to Program.</p><p>Only the Program feed is broadcast or exported. Phone camera permission and public-broadcast consent remain explicit.</p><p>Prize-claim proof capture is a separate phone-camera upload workflow.</p></div>
   </div></aside>
  </div>`;
 shell.replaceChildren(workspace);
 shell.dataset.workspaceEnhanced = 'true';
 const target = name => workspace.querySelector(`[data-director-content="${name}"]`);
 target('sessions').append(session);
 if (links) target('sessions').append(links);
 const rawGrid = grid.parentElement;
 $('director-workspace-grid').append(grid);
 target('cameras').append(rawGrid);
 $('director-workspace-phone-note').append(phoneStatus);
 $('director-workspace-monitors').append(monitors);
 $('director-workspace-switcher').append(switcher);
 $('director-workspace-take').append(take);
 $('director-workspace-record-actions').append(exportControls);
 target('scenes').append(scenes);
 target('media').append(clips);
 target('graphics').append(graphics);
 target('audio').append(audio);
 target('live').append(broadcast);
 $('director-workspace-main-status').append(status);
 if (count) $('director-workspace-source-count').append(count);
 // The canvas compositor explicitly tests camera-director.isConnected on every
 // animation frame. Keep its original root mounted even though its visible
 // controls now live in the production console.
 director.hidden = true;
 target('settings').append(director);

 const drawer = $('director-workspace-drawer');
 const backdrop = $('director-workspace-drawer-backdrop');
 const panelNames = ['cameras','sessions','scenes','media','graphics','audio','live','settings'];
 let openName = '', previousFocus = null, timelineTimer = null, timelineGeneration = 0;
 function closePanel(restoreFocus = true) {
  if (drawer.hidden) return;
  drawer.hidden = true; backdrop.hidden = true; openName = '';
  workspace.querySelectorAll('[data-director-panel]').forEach(b => b.setAttribute('aria-expanded','false'));
  if (restoreFocus && previousFocus?.isConnected) previousFocus.focus();
 }
 function openPanel(name) {
  if (!panelNames.includes(name)) return;
  if (openName === name && !drawer.hidden) { closePanel(); return; }
  if (drawer.hidden) previousFocus = document.activeElement;
  openName = name;
  panelNames.forEach(n => { target(n).hidden = n !== name; });
  $('director-workspace-drawer-title').textContent = name[0].toUpperCase() + name.slice(1);
  drawer.hidden = false; backdrop.hidden = false;
  workspace.querySelectorAll('[data-director-panel]').forEach(b => b.setAttribute('aria-expanded',String(b.dataset.directorPanel === name)));
  $('director-workspace-close').focus();
 }
 workspace.querySelectorAll('[data-director-panel]').forEach(b => {
  b.setAttribute('aria-expanded','false');
  b.setAttribute('aria-controls','director-workspace-drawer');
  b.addEventListener('click',() => openPanel(b.dataset.directorPanel));
 });
 $('director-workspace-close').addEventListener('click',() => closePanel());
 backdrop.addEventListener('click',() => closePanel());
 document.addEventListener('keydown',e => { if (e.key === 'Escape' && !drawer.hidden) { e.preventDefault(); closePanel(); } });

 const cameraId = card => card.dataset.phonePair ? 'phone:' + card.dataset.phonePair :
  card.dataset.cameraId ? 'camera:' + card.dataset.cameraId : '';
 const markTally = () => {
  const state=window.LuckyCameraDirector?.state?.()||{};
  grid.querySelectorAll(':scope > .camera-source').forEach(card => {
   const id=cameraId(card);
   card.classList.toggle('is-cued',!!id&&state.preview===id);
   card.classList.toggle('is-program',!!id&&state.program===id);
  });
 };
 const cameraCount = () => {
  const live = grid.querySelectorAll(':scope > .camera-source').length;
  $('director-workspace-camera-count').textContent = `${live} camera${live === 1 ? '' : 's'}`;
  $('director-workspace-source-count').setAttribute('aria-label',`${live} connected camera sources`);
  grid.querySelectorAll(':scope > .camera-source').forEach(card => {
   if (!cameraId(card)) return;
   card.tabIndex=0;card.setAttribute('role','button');
   card.setAttribute('aria-label','Cue '+(card.querySelector('strong')?.textContent||'camera')+' in Preview');
  });
  markTally();
 };
 function cueTile(card) {
  const id=card&&cameraId(card);
  if (!id) return;
  if (window.LuckyCameraDirector?.select?.(id)) {markTally();status.textContent='Cued '+(card.querySelector('strong')?.textContent||'camera')+' in Preview. Press Cut to send it to Program.';}
 }
 grid.addEventListener('click',e=>{
  if (e.target.closest('button,a,input,select')) return;
  cueTile(e.target.closest('.camera-source'));
 });
 grid.addEventListener('keydown',e=>{
  if (e.target.closest('button,a,input,select')) return;
  if (['Enter',' '].includes(e.key)&&e.target.matches('.camera-source')) {e.preventDefault();cueTile(e.target);}
 });
 new MutationObserver(cameraCount).observe(grid,{childList:true});
 window.addEventListener('lucky:cameras-refreshed',cameraCount);
 window.addEventListener('lucky:director-sources-updated',markTally);
 ['director-cut','director-fade','director-wipe','director-dip'].forEach(id=>$(id).addEventListener('click',()=>requestAnimationFrame(markTally)));
 cameraCount();
 const badge = $('director-live-badge');
 function liveState() {
  const state = $('director-workspace-onair');
  const on = badge.classList.contains('is-live');
  state.textContent = on ? '● ON AIR' : 'OFF AIR';
  state.classList.toggle('is-live',on);
 }
 new MutationObserver(liveState).observe(badge,{childList:true,subtree:true,attributes:true,attributeFilter:['class']});
 liveState();

 const timelineRoot = $('director-workspace-timeline-lanes');
 const timelineSummary = $('director-workspace-timeline-summary');
 const editorLink = $('director-workspace-editor-link');
 const el = (tag,cls='',value='') => { const node=document.createElement(tag);node.className=cls;node.textContent=value;return node; };
 const duration = ms => {const n=Math.max(0,Math.floor(ms/1000));return `${String(Math.floor(n/60)).padStart(2,'0')}:${String(n%60).padStart(2,'0')}`;};
 const activeId = () => window.LuckyDirectorStudio?.state?.().recordingSession || '';
 function emptyTimeline(message) {timelineRoot.replaceChildren(el('p','director-workspace-empty',message));}
 async function refreshTimeline() {
  const id=activeId(),generation=++timelineGeneration;
  if (!id) {timelineSummary.textContent='Select a session to view recorded takes';emptyTimeline('Open a recording session to load camera recordings.');return;}
  try {
   const get = async action => {
    const response=await fetch('api/recording-sessions.php?'+new URLSearchParams({action,id}),{credentials:'same-origin',cache:'no-store'});
    const body=await response.json();
    if (!response.ok || !body.ok) throw Error(body.error||'Media unavailable.');
    return body;
   };
   const [detail,result]=await Promise.all([get('detail'),get('media_list')]);
   if (generation!==timelineGeneration || activeId()!==id) return;
   const sources=new Map((detail.sources||[]).map(s=>[s.id,s.display_name||'Camera']));
   const takes=new Map((detail.takes||[]).map(t=>[t.id,t]));
   const media=(result.media||[]).filter(a=>['video','audio','photo'].includes(a.kind));
   const rows=new Map();
   for (const item of media) {
    const label=item.source_id?(sources.get(item.source_id)||'Unregistered camera'):(item.kind==='audio'?'Program Audio':'Unassigned source');
    if (!rows.has(label)) rows.set(label,[]);
    rows.get(label).push(item);
   }
   timelineRoot.replaceChildren();
   if (!media.length) {emptyTimeline('No uploaded media yet. Start a grouped take, then wait for phones to upload their originals.');timelineSummary.textContent=`${(detail.takes||[]).length} take(s) · 0 uploaded clips`;return;}
   const extent=Math.max(10000,...media.map(a=>Math.max(0,Number(a.start_offset_ms)||0)+Math.max(1000,Number(a.duration_ms)||5000)));
   const ruler=el('div','director-workspace-ruler');
   ruler.append(el('span','','00:00'));
   for (let tick=1;tick<=4;tick++) ruler.append(el('span','',duration(extent*tick/4)));
   timelineRoot.append(ruler);
   for (const [name,items] of rows) {
    const row=el('div','director-workspace-lane');row.setAttribute('role','listitem');
    const label=el('div','director-workspace-lane-label',name);const track=el('div','director-workspace-lane-track');
    const kind=items[0]?.kind;
    track.dataset.kind=kind;
    for (const item of items) {
     const offset=Math.max(0,Number(item.start_offset_ms)||0),length=Math.max(1000,Number(item.duration_ms)||5000);
     const clip=el('a','director-workspace-timeline-clip',item.title||item.kind.toUpperCase());
     clip.href='recording-editor.php?session='+encodeURIComponent(id);
     clip.style.left=(offset/extent*100).toFixed(3)+'%';
     clip.style.width=Math.max(1,Math.min(100-offset/extent*100,length/extent*100)).toFixed(3)+'%';
     const takeRow=takes.get(item.take_id);
     clip.title=`${name} · ${item.title||item.kind} · ${takeRow?.title||'Ungrouped'} · ${duration(offset)} (estimated)`;
     track.append(clip);
    }
    row.append(label,track);timelineRoot.append(row);
   }
   timelineSummary.textContent=`${(detail.takes||[]).length} grouped takes · ${media.length} uploaded clips · ${rows.size} source tracks`;
  } catch (e) { if (generation===timelineGeneration) { timelineSummary.textContent='Timeline unavailable';emptyTimeline(e.message); } }
 }
 function selectSession(e) {
  const id=e.detail?.recordingSession || activeId();
  const selector=$('director-studio-session');
  $('director-workspace-session-title').textContent=selector.selectedOptions[0]?.textContent||'Recording session';
  editorLink.href='recording-editor.php?session='+encodeURIComponent(id);
  editorLink.removeAttribute('aria-disabled');
  if (timelineTimer) clearInterval(timelineTimer);
  timelineTimer=setInterval(()=>void refreshTimeline(),20000);
  void refreshTimeline();closePanel(false);
 }
 window.addEventListener('lucky:director-studio-open',selectSession);
 window.addEventListener('lucky:director-studio-close',()=>{
  if (timelineTimer) {clearInterval(timelineTimer);timelineTimer=null;}
  timelineGeneration++;
  $('director-workspace-session-title').textContent='No session selected';
  editorLink.href='admin.php?tab=recordings';editorLink.setAttribute('aria-disabled','true');
  timelineSummary.textContent='Select a session to view recorded takes';emptyTimeline('Open a recording session to load camera recordings.');
 });
 $('director-workspace-timeline-refresh').addEventListener('click',()=>void refreshTimeline());
 editorLink.addEventListener('click',e=>{if (!activeId()) {e.preventDefault();openPanel('sessions');}});
 window.addEventListener('pagehide',()=>{if(timelineTimer)clearInterval(timelineTimer);});
 // Session picker remains behind a deliberate action; the video workspace always
 // fills the first screen, even before any device permission has been granted.
 window.LuckyDirectorWorkspace={openPanel,closePanel,refreshTimeline};
})();
