<?php
declare(strict_types=1);
require __DIR__.'/lib/Layout.php';
if(!installed()){header('Location: install.php');exit;}
if(!isAdmin()){header('Location: login.php');exit;}
shellStart('Prize Proof · Video Timeline','editor');
?>
<section class="media-editor-studio" aria-label="Lucky Game Hub video editor">
 <header class="media-editor-top"><div class="editor-brand"><strong>✦ LUCKY CUT</strong><span>Game highlight editor</span></div><label>Game session<select id="editor-session"><option value="">Choose a saved session</option></select></label><div class="platform-control-row editor-transport"><button class="btn btn-primary" id="editor-play" type="button">▶ Preview montage</button><button class="btn btn-outline" id="editor-stop" type="button">■ Stop</button><button class="btn btn-outline" id="editor-auto" type="button">✨ Auto assemble</button><button class="btn btn-outline" id="editor-save-project" type="button">Save timeline</button><button class="btn btn-outline" id="editor-export" type="button">↓ Export edit project</button></div><nav class="editor-exit" aria-label="Exit editor"><a class="btn btn-outline" href="game.php">← Game</a><a class="btn btn-outline" href="claims.php">Claims</a></nav></header>
 <div class="media-editor-layout">
  <div class="media-editor-stage"><div class="editor-stage-title"><strong>Preview monitor</strong><span>Private game footage · Non-destructive edits</span></div><div id="editor-preview" class="media-preview" role="region" aria-label="Montage preview"><p>Select a game and press Preview to play the clips in sequence.</p></div><p id="editor-caption" role="status" class="voice-status">No clip playing.</p>
  <div class="editor-track-heading"><h2>Timeline <small id="editor-count">0 clips</small></h2><span>Drag to reorder · Select a clip to edit</span></div><div id="editor-timeline" class="editor-track" aria-label="Reorder clips"></div></div>
  <aside class="media-editor-settings"><div class="editor-inspector-title"><h2>Clip inspector</h2><span>Adjust, split, or remove</span></div><p id="editor-selected">Select a clip to edit its title and timing.</p>
   <form id="editor-form" hidden><label>Clip title<input id="editor-title" maxlength="120" required></label><label>Camera / source<input id="editor-source" disabled></label>
   <div class="editor-form-grid"><label>Trim start (seconds)<input id="editor-trim-start" type="number" min="0" max="3600" step="0.1" value="0"></label><label>Trim end (seconds, 0 = natural end)<input id="editor-trim-end" type="number" min="0" max="3600" step="0.1" value="0"></label></div>
   <label>Transition<select id="editor-transition"><option value="fade">Fade through black</option><option value="cut">Direct cut</option></select></label><label>Photo display duration (seconds)<input id="editor-photo-seconds" type="number" min="1" max="20" step="0.5" value="4"></label>
   <button type="submit" class="btn btn-primary">Apply clip edits</button><button type="button" class="btn btn-outline" id="editor-split">Split at trim end</button><button type="button" class="btn btn-outline" id="editor-remove">Remove from timeline</button><button type="button" class="btn btn-outline" id="editor-delete">Delete original recording</button></form>

  </aside>
 </div>
 <section class="render-studio" aria-labelledby="render-studio-title"><div class="editor-render-heading"><h2 id="render-studio-title">Export studio</h2><span>Render the current timeline to MP4</span></div>
 <div class="render-options">
  <label>Export quality<select id="render-profile"><option value="standard">HD · 720p</option><option value="high">Full HD · 1080p</option></select></label>
  <label>Background music<select id="render-track"><option value="">No soundtrack</option></select></label>
  <label>Music level <output id="render-volume-text">18%</output><input id="render-volume" type="range" min="0" max="100" value="18"></label>
  <button class="btn btn-primary" type="button" id="render-queue">Export MP4</button>
  <button class="btn btn-outline" type="button" id="render-refresh">Refresh exports</button>
 </div>
 <p class="voice-status">Rendering requires FFmpeg and a PHP CLI worker. If your web host cannot run FFmpeg, the edit project remains exportable.</p>
 <div id="render-jobs" class="render-jobs" aria-label="Rendered video exports"></div>
 </section><p id="editor-feedback" role="status" class="voice-status"></p>
</section>
<script>document.body.classList.add("editor-workspace");</script>
<script src="public/client.js" defer></script><script src="public/editor.js?v=5q7" defer></script><script src="public/ui-receiver.js?v=5q7" defer></script>
<?php shellEnd(); ?>
