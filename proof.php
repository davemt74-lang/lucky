<?php
declare(strict_types=1);
require __DIR__.'/lib/Layout.php';if(!installed()){header('Location: install.php');exit;}
if(!isAdmin()){header('Location: login.php');exit;}
shellStart('Edit Prize Proof','claims');
?>
<dialog id="win-dialog" class="claim-stage proof-editor-page" open aria-label="Edit prize proof and revision history"><div class="claim-stage-shell">
<header class="claim-stage-head"><div><p class="eyebrow">PRIZE HISTORY · EDIT PROOF</p><h2>Proof for <span id="capture-prize-label">claimed prize</span></h2></div><a class="btn btn-outline" id="proof-return" href="game.php">← Back to game</a></header>
<div class="proof-editor-layout"><section class="claim-stage-award proof-history-panel"><h3 id="proof-prize-title">Loading prize…</h3><p id="proof-prize-context" class="mini-label"></p><p id="proof-state" role="status">Loading proof history…</p>
<form id="proof-revision-form"><label class="proof-note-label">Revision note<textarea id="proof-note" rows="3" maxlength="250" placeholder="What changed, or what needs to be corrected?"></textarea></label>
<div class="proof-revision-actions"><button class="btn btn-primary" id="proof-save" type="submit">Save proof revision</button><button class="btn btn-outline" id="proof-request" type="button">Mark needs revision</button></div></form>
<h3>Revision history</h3><div id="proof-revision-list" class="proof-revision-list" aria-live="polite"></div><p id="proof-errors" class="platform-error" role="alert" hidden></p>
</section>
 <section class="claim-capture" aria-label="Capture prize reaction">
  <div class="claim-capture-heading"><h3>Multi-camera proof studio</h3><span class="mini-label" id="capture-live-status">Cameras off</span></div>
  <p class="claim-private-note">Choose your USB and built-in cameras, then capture photos or record video. Each camera adds its own identified clip to the game timeline.</p>
  <div class="camera-toolbar"><button class="btn btn-outline" id="camera-enable" type="button" disabled>📹 Enable & find cameras</button><button class="btn btn-outline" id="camera-refresh" type="button">⟳ Refresh sources</button><button class="btn btn-outline" id="camera-off" type="button">Turn cameras off</button></div>
  <div class="proof-rotation-status" role="status" aria-live="polite"><div><strong id="proof-active-angle">Ready for proof</strong><span id="proof-next-angle">Next: choose your cameras</span></div><div id="proof-countdown" class="proof-countdown" aria-label="Countdown">—</div></div>
  <div id="proof-angle-plan" class="proof-angle-plan" aria-label="Prize proof camera angles"></div>
  <p class="camera-ready-message" id="camera-ready-message" role="status">Connect cameras to see live preview tiles.</p>
  <div id="camera-grid" class="camera-grid" aria-label="Available camera feeds"></div><canvas id="capture-photo-canvas" hidden></canvas>
  <div class="capture-actions"><button class="btn btn-outline" id="capture-photo" type="button">📷 Capture selected photos</button><button class="btn btn-primary" id="proof-rotate" type="button">🎬 Start rotating proof (5-second countdown)</button><button class="btn btn-outline" id="capture-video" type="button">● Record selected cameras</button><button class="btn btn-outline" id="capture-audio" type="button">🎙 Record audio</button><button class="btn btn-primary" id="capture-stop" type="button" hidden>■ Stop & save clips</button></div>
  <label class="claim-upload-label">Upload existing photo/video/audio <input type="file" id="capture-file" multiple accept="image/jpeg,image/png,image/webp,video/mp4,video/webm,video/quicktime,audio/mpeg,audio/mp4,audio/ogg,audio/wav,audio/webm"></label>
  <label class="platform-checkbox"><input type="checkbox" id="capture-consent"> Everyone recorded has agreed to be included in this private video.</label>
  <p id="capture-feedback" role="status" aria-live="polite" class="voice-status">Nothing has been recorded.</p>
  <div id="capture-clip-list" class="capture-clip-list"></div><a id="capture-open-editor" class="btn btn-primary" href="editor.php">🎬 Open video timeline</a>
 </section>
</div></div></dialog>
<script src="public/client.js" defer></script><script src="public/proof-sequence.js?v=5q2" defer></script><script src="public/claim-capture.js?v=5q8b" defer></script><script src="public/proof.js?v=5q8b" defer></script><script src="public/ui-receiver.js?v=5q8b" defer></script>
<?php shellEnd(); ?>
