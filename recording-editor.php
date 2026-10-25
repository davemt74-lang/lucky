<?php
declare(strict_types=1);
require __DIR__.'/lib/Layout.php';
if(!installed()){header('Location: install.php');exit;}
if(!isAdmin()){header('Location: login.php');exit;}
shellStart('Multicamera Studio','editor','mc-editor-page');
?>
<link rel="stylesheet" href="public/recording-editor.css?v=5q29e4a">
<main class="mc-editor" aria-label="Multicamera Video Studio">
 <header class="mc-header"><a class="btn btn-outline" href="admin.php?tab=recordings">← Recording library</a><div class="mc-heading"><h1 id="mc-session-name">Multicamera timeline</h1><p id="mc-session-meta">Loading recording…</p></div><div class="mc-actions"><button type="button" id="mc-play" class="btn btn-outline" disabled>▶ Play</button><button type="button" id="mc-save" class="btn btn-primary" disabled>Save alignment</button><button type="button" id="mc-edl" class="btn btn-outline" disabled>↓ Edit list</button></div></header>
 <p id="mc-status" role="status" aria-live="polite" class="mc-status">Loading media and existing edit…</p>
 <div class="mc-workspace">
  <section class="mc-main"><div class="mc-monitor"><video id="mc-video" playsinline controls hidden></video><img id="mc-photo" alt="Selected camera frame" hidden><audio id="mc-audio" controls hidden></audio><div id="mc-empty">Select an available camera angle on the timeline.</div><div class="mc-monitor-mark" id="mc-monitor-mark">PREVIEW</div></div>
   <div class="mc-switch"><h2>Live angle switcher</h2><p>At the playhead, select the preferred angle. A cut is saved in the shared edit list.</p><div id="mc-angles" class="mc-angle-buttons"></div></div>
  </section>
  <aside class="mc-inspector" aria-label="Clip inspector"><h2>Angle inspector</h2><p id="mc-selected-title">Click a camera clip to adjust its alignment.</p><div id="mc-inspector-fields" hidden><dl><dt>Source</dt><dd id="mc-source"></dd><dt>Take</dt><dd id="mc-take"></dd><dt>Synchronization</dt><dd id="mc-confidence"></dd><dt>Original position</dt><dd id="mc-original"></dd></dl><label>Aligned start (seconds)<input id="mc-offset" type="number" min="-600" max="86400" step="0.05"></label><div class="mc-nudge"><button type="button" data-nudge="-100" class="btn btn-outline">−0.1 s</button><button type="button" data-nudge="100" class="btn btn-outline">+0.1 s</button><button type="button" id="mc-reset" class="btn btn-outline">Reset</button></div><button type="button" id="mc-select-angle" class="btn btn-primary">Cut to angle at playhead</button></div><p class="mc-hint">Changes are non-destructive. Original files and recorded timestamps are preserved. This stage saves alignment and camera cuts; MP4 rendering follows in the export stage.</p></aside>
 </div>
 <section class="mc-timeline-section" aria-label="Shared camera timeline"><div class="mc-timeline-head"><h2>Session timeline</h2><output id="mc-playhead-time">00:00.0</output></div><div class="mc-scrub"><input id="mc-scrubber" aria-label="Scrub timeline" type="range" min="0" max="1000" value="0" disabled></div><div id="mc-timeline-scroll" class="mc-timeline-scroll"><div id="mc-ruler" class="mc-ruler"></div><div id="mc-lanes" class="mc-lanes"></div></div><section class="mc-cuts" aria-label="Angle cuts"><h3>Saved camera cuts</h3><div id="mc-cuts-list" class="mc-cuts-list"></div></section></section>
</main>
<script src="public/client.js" defer></script><script src="public/recording-editor.js?v=5q29e4a" defer></script>
<?php shellEnd(); ?>
