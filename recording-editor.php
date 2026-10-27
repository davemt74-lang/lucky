<?php
declare(strict_types=1);
require __DIR__.'/lib/Layout.php';
if(!installed()){header('Location: install.php');exit;}
if(!isAdmin()){header('Location: login.php');exit;}
shellStart('Multicamera Studio','editor','mc-editor-page');
?>
<link rel="stylesheet" href="public/recording-editor.css?v=5q29e4d1">
<main class="mc-editor" aria-label="Multicamera Video Studio">
 <header class="mc-header"><a class="btn btn-outline mc-back" href="admin.php?tab=recordings">← Recordings</a><div class="mc-heading"><h1 id="mc-session-name">Multicamera studio</h1><p id="mc-session-meta">Loading recording…</p></div><div class="mc-actions"><a id="mc-director-link" class="btn btn-outline" href="admin.php?tab=recordings" hidden>◉ Camera Director</a><button type="button" id="mc-play" class="btn btn-outline" disabled>▶ Play</button><button type="button" id="mc-inspector-toggle" class="btn btn-outline" aria-expanded="false" aria-controls="mc-inspector">Clip inspector</button><button type="button" id="mc-edl" class="btn btn-outline" disabled>↓ Edit list</button><button type="button" id="mc-save" class="btn btn-primary" disabled>Save alignment</button><button type="button" id="mc-export-open" class="btn btn-primary">Render &amp; export</button></div></header>
 <p id="mc-status" role="status" aria-live="polite" class="mc-status">Loading media and existing edit…</p>
 <div class="mc-stage">
  <aside class="mc-library" aria-label="Session media library"><header><h2>Media library</h2><output id="mc-library-count">0 clips</output></header><label class="mc-search-label" for="mc-media-search">Find recording<input id="mc-media-search" type="search" placeholder="Search clips or cameras" autocomplete="off"></label><div class="mc-library-filters" role="group" aria-label="Filter media"><button type="button" data-media-filter="all" aria-pressed="true">All</button><button type="button" data-media-filter="video" aria-pressed="false">Video</button><button type="button" data-media-filter="photo" aria-pressed="false">Photo</button><button type="button" data-media-filter="audio" aria-pressed="false">Audio</button></div><div id="mc-media-list" class="mc-media-list" aria-label="Recordings in this session"></div><p class="mc-library-help">Select a source to locate it on the shared timeline. Originals stay unchanged.</p></aside>
  <div class="mc-workspace">
  <section class="mc-main"><div class="mc-monitor"><video id="mc-video" playsinline controls hidden></video><img id="mc-photo" alt="Selected camera frame" hidden><audio id="mc-audio" controls hidden></audio><div id="mc-empty">Select an available camera angle on the timeline.</div><div class="mc-monitor-mark" id="mc-monitor-mark">PREVIEW</div></div>
   <div class="mc-switch"><h2>Live angle switcher</h2><p>At the playhead, select the preferred angle. A cut is saved in the shared edit list.</p><div id="mc-angles" class="mc-angle-buttons"></div></div>
  </section>
  </div>
  <div id="mc-inspector-backdrop" class="mc-inspector-backdrop" hidden></div>
  <aside id="mc-inspector" class="mc-inspector" aria-label="Clip inspector" hidden><div class="mc-inspector-head"><h2>Clip inspector</h2><button type="button" id="mc-inspector-close" class="btn btn-outline" aria-label="Close clip inspector">✕</button></div><p id="mc-selected-title">Click a camera clip to adjust its alignment.</p><div id="mc-inspector-fields" hidden><dl><dt>Source</dt><dd id="mc-source"></dd><dt>Take</dt><dd id="mc-take"></dd><dt>Synchronization</dt><dd id="mc-confidence"></dd><dt>Original position</dt><dd id="mc-original"></dd></dl><label>Aligned start (seconds)<input id="mc-offset" type="number" min="-600" max="86400" step="0.05"></label><div class="mc-nudge"><button type="button" data-nudge="-100" class="btn btn-outline">−0.1 s</button><button type="button" data-nudge="100" class="btn btn-outline">+0.1 s</button><button type="button" id="mc-reset" class="btn btn-outline">Reset</button></div><button type="button" id="mc-select-angle" class="btn btn-primary">Cut to angle at playhead</button></div><p class="mc-hint">Changes are non-destructive. Original files and recorded timestamps are preserved.</p></aside>
 </div>
 <section class="mc-timeline-section" aria-label="Shared camera timeline"><div class="mc-timeline-head"><h2>Session timeline</h2><output id="mc-playhead-time">00:00.0</output></div><div class="mc-scrub"><input id="mc-scrubber" aria-label="Scrub timeline" type="range" min="0" max="1000" value="0" disabled></div><div id="mc-timeline-scroll" class="mc-timeline-scroll"><div id="mc-ruler" class="mc-ruler"></div><div id="mc-lanes" class="mc-lanes"></div></div><section class="mc-cuts" aria-label="Angle cuts"><h3>Saved camera cuts</h3><div id="mc-cuts-list" class="mc-cuts-list"></div></section></section>
 <dialog id="mc-export-dialog" class="mc-export-dialog" aria-labelledby="mc-export-title"><div class="mc-export-dialog-head"><h2 id="mc-export-title">Render &amp; export</h2><button type="button" id="mc-export-close" class="btn btn-outline" aria-label="Close export dialog">✕ Close</button></div><section class="mc-export-panel" aria-label="Multicamera export">
  <div><h2>Export multicamera video</h2><p>Render your saved angle cuts into a private MP4. Original phone recordings remain unchanged.</p></div>
  <label for="mc-export-audio">Audio track<select id="mc-export-audio"><option value="follow">Follow selected camera audio</option><option value="silent">Silent video</option></select></label>
  <button type="button" id="mc-export-start" class="btn btn-primary" disabled>Render MP4</button>
  <div id="mc-export-status" role="status" aria-live="polite">Preparing export options…</div>
  <div id="mc-export-list" class="mc-export-list" aria-label="Previous exports"></div>
 </section></dialog>
</main>
<script src="public/client.js" defer></script><script src="public/recording-editor.js?v=5q29e4d1-hf8d0d" defer></script>
<?php shellEnd(); ?>
