<?php
declare(strict_types=1);
require __DIR__.'/lib/Layout.php';
if(!installed()){header('Location: install.php');exit;}
if(!isAdmin()){header('Location: login.php');exit;}
shellStart('Video Studio','editor','editor-workspace');
?>
<link rel="stylesheet" href="public/editor-workspace.css?v=5q15j">
<section class="media-editor-studio" aria-label="Video Studio workspace">
 <div class="studio-topbar">
  <a id="editor-back-game" class="btn btn-outline studio-back" href="game.php">← Back to game</a>
  <label class="studio-session">SESSION <select id="editor-session" aria-label="Select game session"><option value="">Choose a saved session</option></select></label>
  <div class="studio-top-actions"><button class="btn btn-outline" id="editor-save-project" type="button">Save edit</button><button class="btn btn-outline" id="editor-open-inspector" type="button" aria-expanded="false" aria-controls="clip-inspector">☷ Clip inspector</button></div>
  <?php accountMenu(); ?>
 </div>
 <div class="studio-body">
  <aside class="studio-bin surface" aria-label="Source media library"><div class="studio-panel-head"><div><strong>Media bin</strong><small id="editor-bin-count">0 files</small></div></div><div class="studio-bin-filters" role="group" aria-label="Filter media"><button type="button" class="is-active" data-media-filter="all" aria-pressed="true">All</button><button type="button" data-media-filter="video" aria-pressed="false">Video</button><button type="button" data-media-filter="photo" aria-pressed="false">Photo</button><button type="button" data-media-filter="audio" aria-pressed="false">Audio</button></div><div id="editor-media-bin" class="studio-bin-list" aria-label="Captured media available to add"></div>
  <div class="studio-tool-library">
   <div class="studio-tool-heading">Creative tools <small>Applied to selected timeline clip</small></div>
   <details class="studio-tool-section" id="studio-filters-section" open><summary>◐ Video filters</summary><div class="studio-tool-inner">
     <label>Filter preset<select id="editor-look"><option value="none">Original</option><option value="vivid">Vivid</option><option value="warm">Warm</option><option value="mono">Black &amp; white</option><option value="cinematic">Cinematic</option><option value="vintage">Vintage</option></select></label>
     <button type="button" class="btn btn-outline" id="editor-apply-look">Apply to selected clip</button>
   </div></details>
   <details class="studio-tool-section" id="studio-titles-section"><summary>▤ Text overlays</summary><div class="studio-tool-inner">
     <label>Overlay text <input id="editor-overlay-text" maxlength="120" placeholder="Add a title or caption"></label>
     <div class="studio-overlay-fields"><label>Position<select id="editor-overlay-position"><option value="bottom">Bottom</option><option value="center">Center</option><option value="top">Top</option></select></label><label>Size<select id="editor-overlay-size"><option value="medium">Medium</option><option value="small">Small</option><option value="large">Large</option></select></label></div>
     <button type="button" class="btn btn-outline" id="editor-apply-overlay">Apply text to selected clip</button>
     <button type="button" class="btn btn-outline" id="editor-clear-overlay">Remove text from clip</button>
   </div></details>
   <details class="studio-tool-section" id="studio-templates-section"><summary>▣ Video templates</summary><div class="studio-tool-inner">
     <label>Starter template<select id="editor-template"><option value="clean">Clean · original footage</option><option value="highlight">Highlight · vivid</option><option value="classic">Classic · black &amp; white</option><option value="film">Film · cinematic</option><option value="retro">Retro · vintage</option></select></label>
     <button type="button" class="btn btn-outline" id="editor-apply-template">Apply to selected clip</button>
     <button type="button" class="btn btn-outline" id="editor-template-all">Apply to entire timeline</button>
     <p class="studio-tool-hint">Starter templates adjust the visual look. More reusable templates and effects can be added here.</p>
   </div></details>
  </div>
 </aside>
  <div class="studio-monitor-panel surface"><div id="editor-preview" class="media-preview studio-monitor" role="region" aria-label="Video preview"><div class="studio-empty-monitor"><span>✦</span><p>Select a clip to preview your edit.</p></div></div></div>
  <aside id="clip-inspector" class="media-editor-settings studio-inspector surface" aria-label="Clip inspector" aria-hidden="true" inert><div class="studio-panel-head"><strong>Clip inspector</strong><button class="btn btn-outline" id="editor-close-inspector" type="button" aria-label="Close clip inspector">✕</button></div><p id="editor-selected">Select a clip to edit.</p><form id="editor-form" hidden><label>Title<input id="editor-title" maxlength="120" required></label><label>Camera / source<input id="editor-source" disabled></label><div class="editor-form-grid"><label>In (seconds)<input id="editor-trim-start" type="number" min="0" max="3600" step="0.1" value="0"></label><label>Out (seconds, 0 = end)<input id="editor-trim-end" type="number" min="0" max="3600" step="0.1" value="0"></label></div><label>Transition<select id="editor-transition"><option value="fade">Fade through black</option><option value="cut">Hard cut</option></select></label><label>Photo hold (seconds)<input id="editor-photo-seconds" type="number" min="1" max="20" step="0.5" value="4"></label><fieldset class="studio-audio-fields"><legend>Clip audio</legend><label>Original audio<select id="editor-audio-mode"><option value="normal">On</option><option value="muted">Muted</option><option value="detached">Detached to A1</option></select></label><label>Detached audio offset (seconds)<input id="editor-detached-offset" type="number" min="0" max="120" step="0.1" value="0"></label><label>Recorded voice offset (seconds)<input id="editor-voice-offset" type="number" min="0" max="120" step="0.1" value="0"></label><label class="studio-audio-toggle"><input id="editor-voice-mute" type="checkbox"> Mute recorded audio</label><button class="btn btn-outline" id="editor-record-audio" type="button">● Record audio</button></fieldset><button type="submit" class="btn btn-primary">Apply clip edits</button><div class="studio-inspector-buttons"><button class="btn btn-outline" type="button" id="editor-split">Split</button><button class="btn btn-outline" type="button" id="editor-remove">Remove from edit</button></div><button class="btn btn-danger" type="button" id="editor-delete">Delete original recording</button></form></aside>
 </div>
 <section class="studio-sequence surface" aria-label="Editable sequence timeline"><header class="studio-sequence-head"><div class="studio-sequence-left"><button class="btn btn-outline" id="editor-play" type="button">▶ Play</button><button class="btn btn-outline" id="editor-stop" type="button">■ Stop</button><strong>Sequence 01</strong><small id="editor-count">0 clips</small></div><div class="studio-sequence-right"><span id="editor-runtime" class="studio-timecode">00:00 / 00:00</span><button class="btn btn-outline" id="editor-fullscreen" type="button" aria-label="Fullscreen video preview">⛶</button><button class="btn btn-primary" id="open-render-dialog" type="button">▣ Render &amp; Export</button></div></header><div class="studio-sequence-scroll"><div id="editor-ruler" class="studio-ruler" aria-hidden="true"></div><div class="studio-sequence-lane"><div class="studio-lane-label">V1 · Picture / clips</div><div id="editor-timeline" class="editor-track studio-track" aria-label="Reorder timeline segments"></div></div><div class="studio-sequence-lane studio-audio-lane"><div class="studio-lane-label">A1 · Source audio</div><div id="editor-audio-lane" class="studio-audio-track" aria-label="Audio contained in sequence"></div></div></div></section>
 <div id="editor-inspector-backdrop" class="studio-inspector-backdrop" hidden></div>
 <div id="clip-context-menu" class="studio-clip-menu" role="menu" hidden aria-label="Clip actions"></div>
 <dialog id="editor-audio-dialog" class="studio-audio-dialog" aria-labelledby="editor-audio-title">
  <div class="studio-render-dialog-head"><h2 id="editor-audio-title">Record clip audio</h2><button id="editor-audio-close" class="btn btn-outline" type="button">✕ Close</button></div>
  <p id="editor-audio-status" role="status" aria-live="polite">Record a voiceover with your microphone. Preview before saving.</p>
  <div class="studio-audio-record-actions"><button class="btn btn-primary" id="editor-audio-start" type="button">● Start recording</button><button class="btn btn-outline" id="editor-audio-stop" type="button" disabled>■ Stop</button></div>
  <audio id="editor-audio-preview" controls hidden></audio>
  <div class="studio-audio-record-actions"><button class="btn btn-primary" id="editor-audio-save" type="button" disabled>Save audio to this clip</button><button class="btn btn-outline" id="editor-audio-discard" type="button">Discard</button></div>
 </dialog>
 <dialog id="render-dialog" class="studio-render-dialog" aria-labelledby="render-dialog-title">
  <div class="studio-render-dialog-head"><div><p class="eyebrow">VIDEO STUDIO</p><h2 id="render-dialog-title">Render &amp; Export</h2></div><button class="btn btn-outline" id="close-render-dialog" type="button" aria-label="Close export settings">✕ Close</button></div>
  <p class="studio-render-note">Export the current edit to MP4. Render jobs and ready downloads appear below.</p>
  <section class="render-studio studio-export surface" aria-labelledby="render-studio-title"><div class="studio-export-head"><strong id="render-studio-title">Render & export</strong><small>MP4 export requires configured FFmpeg worker</small></div><div class="render-options"><label>Quality<select id="render-profile"><option value="standard">HD · 720p</option><option value="high">Full HD · 1080p</option></select></label><label>Music<select id="render-track"><option value="">No soundtrack</option></select></label><label>Music <output id="render-volume-text">18%</output><input id="render-volume" type="range" min="0" max="100" value="18"></label><button class="btn btn-primary" id="render-queue" type="button">Export MP4</button><button class="btn btn-outline" id="render-refresh" type="button">Refresh</button></div><div id="render-jobs" class="render-jobs" aria-label="Rendered video exports"></div></section>
  <div class="studio-json-export"><button class="btn btn-outline" id="editor-export" type="button">Download project JSON</button></div>
 </dialog>
 <p id="editor-feedback" role="status" class="studio-feedback studio-feedback-overlay" aria-live="polite"></p><span id="editor-caption" class="sr-only" role="status">No clip playing.</span>
</section>
<script src="public/client.js" defer></script><script src="public/proof-sequence.js?v=5q8b" defer></script><script src="public/editor.js?v=5q15j" defer></script><script src="public/ui-receiver.js?v=5q8" defer></script>
<?php shellEnd(); ?>
