<?php
declare(strict_types=1);
require __DIR__.'/lib/Layout.php';
if(!installed()){header('Location: install.php');exit;}
if(!isAdmin()){header('Location: login.php');exit;}
shellStart('Prize Proof · Video Timeline','editor');
?>
<section class="page-intro"><div><p class="eyebrow">PRIVATE HOME GAME NIGHT</p><h1>Prize proof <span class="accent">timeline.</span></h1><p>Photos, USB camera footage, and audio from each claimed prize become editable clips in one game montage.</p></div><div class="heading-actions"><a class="btn btn-outline" href="game.php">← Game</a><a class="btn btn-outline" href="claims.php">Claims</a></div></section>
<section class="surface media-editor-studio">
 <header class="media-editor-top"><label>Game session<select id="editor-session"><option value="">Choose a saved session</option></select></label><div class="platform-control-row"><button class="btn btn-primary" id="editor-play" type="button">▶ Preview montage</button><button class="btn btn-outline" id="editor-stop" type="button">■ Stop</button><button class="btn btn-outline" id="editor-auto" type="button">✨ Auto assemble</button><button class="btn btn-outline" id="editor-save-project" type="button">Save timeline</button><button class="btn btn-outline" id="editor-export" type="button">↓ Export edit project</button></div></header>
 <div class="media-editor-layout">
  <div class="media-editor-stage"><div id="editor-preview" class="media-preview" role="region" aria-label="Montage preview"><p>Select a game and press Preview to play the clips in sequence.</p></div><p id="editor-caption" role="status" class="voice-status">No clip playing.</p>
  <h2>Video timeline <small id="editor-count">0 clips</small></h2><p class="voice-status">Drag clips to reorder, use arrows, or split a clip into two editable sections. Original recordings remain private and unchanged.</p><div id="editor-timeline" class="editor-track" aria-label="Reorder clips"></div></div>
  <aside class="media-editor-settings"><h2>Selected clip</h2><p id="editor-selected">Select a clip to edit its title and timing.</p>
   <form id="editor-form" hidden><label>Clip title<input id="editor-title" maxlength="120" required></label><label>Camera / source<input id="editor-source" disabled></label>
   <div class="editor-form-grid"><label>Trim start (seconds)<input id="editor-trim-start" type="number" min="0" max="3600" step="0.1" value="0"></label><label>Trim end (seconds, 0 = natural end)<input id="editor-trim-end" type="number" min="0" max="3600" step="0.1" value="0"></label></div>
   <label>Transition<select id="editor-transition"><option value="fade">Fade through black</option><option value="cut">Direct cut</option></select></label><label>Photo display duration (seconds)<input id="editor-photo-seconds" type="number" min="1" max="20" step="0.5" value="4"></label>
   <button type="submit" class="btn btn-primary">Apply clip edits</button><button type="button" class="btn btn-outline" id="editor-split">Split at trim end</button><button type="button" class="btn btn-outline" id="editor-remove">Remove from timeline</button><button type="button" class="btn btn-outline" id="editor-delete">Delete original recording</button></form>
   <p class="claim-private-note">Edits are saved to this game session. Reorder with the arrow buttons on each clip. Preview plays the compiled sequence; Save timeline preserves your custom edit and Export edit project downloads a portable cut list. Queue MP4 uses the same edit.</p>
  </aside>
 </div>
 <section class="render-studio" aria-labelledby="render-studio-title"><h2 id="render-studio-title">Finish your video</h2>
 <p>Render an MP4 from the current timeline. The game keeps working while the server worker processes video. Only uploaded Music Library tracks can be mixed into exports.</p>
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
<script src="public/client.js" defer></script><script src="public/editor.js?v=5q3-2" defer></script>
<?php shellEnd(); ?>
