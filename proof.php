<?php
declare(strict_types=1);
require __DIR__.'/lib/Layout.php';if(!installed()){header('Location: install.php');exit;}
if(!isAdmin()){header('Location: login.php');exit;}
shellStart('Edit Prize Proof','claims');
?>
<dialog id="win-dialog" class="claim-stage proof-editor-page" open aria-label="Edit prize proof and revision history"><div class="claim-stage-shell">
<header class="claim-stage-head"><div><p class="eyebrow" id="proof-heading-context">PRIZE PROOF</p><h2><span id="capture-prize-label">Claimed prize</span></h2></div><div class="proof-header-actions"><a class="btn btn-outline" id="proof-return" href="game.php">✓ Done · Back to game</a></div></header>
<div class="proof-editor-layout"><section class="claim-capture" aria-label="Capture prize reaction">
  <div class="proof-video-stage"><div id="camera-grid" class="camera-grid" aria-label="Live camera feeds"><div class="camera-waiting">Live video appears here after camera permission is granted.</div></div><canvas id="capture-photo-canvas" hidden></canvas>
  <label class="platform-checkbox proof-consent-overlay"><input type="checkbox" id="capture-consent"> Everyone recorded has agreed to this private video.</label>
  <details id="proof-camera-settings" class="proof-camera-settings"><summary>⚙ Camera settings</summary>
  <section id="capture-review" class="capture-review" hidden aria-label="Review recorded video"><h3>Review recording</h3><div id="capture-review-list" class="capture-review-list"></div></section>
  <div id="capture-recording-tools" class="capture-recording-tools">
  <div class="camera-toolbar"><button class="btn btn-outline" id="camera-enable" type="button" disabled>Enable cameras</button><button class="btn btn-outline" id="camera-refresh" type="button">Refresh sources</button><button class="btn btn-outline" id="camera-off" type="button">Cameras off</button><span class="mini-label" id="capture-live-status">Cameras off</span></div>
  <div class="proof-rotation-status" role="status" aria-live="polite"><div><strong id="proof-active-angle">Ready for proof</strong><span id="proof-next-angle">Next camera: —</span></div><div id="proof-countdown" class="proof-countdown">—</div></div>
  <div id="proof-angle-plan" class="proof-angle-plan" aria-label="Camera angles"></div>
  <p class="camera-ready-message" id="camera-ready-message" role="status"></p>
  <div class="capture-actions"><button class="btn btn-outline" id="capture-photo" type="button">📷 Capture selected photos</button><button class="btn btn-primary" id="proof-rotate" type="button">🎬 Start rotating proof (5-second countdown)</button><button class="btn btn-outline" id="capture-video" type="button">● Record selected cameras</button><button class="btn btn-outline" id="capture-rerecord-saved" type="button" hidden>↻ Re-record</button><button class="btn btn-outline" id="capture-audio" type="button">🎙 Record audio</button><button class="btn btn-primary" id="capture-stop" type="button" hidden>■ Stop & save clips</button></div>
  </div></details></div>
  <p id="capture-feedback" role="status" aria-live="polite" class="voice-status">Nothing has been recorded.</p>
 </section>
<aside class="claim-stage-award proof-history-panel" aria-label="Proof revision history">
<p id="proof-state" role="status" class="mini-label">Loading proof history…</p>
<div id="capture-clip-list" class="capture-clip-list" aria-label="Captured prize clips"></div><a id="capture-open-editor" class="btn btn-outline" href="editor.php">🎬 Open video timeline</a>
<details id="proof-history-disclosure" class="proof-history-disclosure"><summary>Revision History</summary>
<div id="proof-revision-list" class="proof-revision-list" aria-live="polite"></div>
<form id="proof-revision-form" class="proof-revision-form"><label class="proof-note-label">Revision note<textarea id="proof-note" rows="2" maxlength="250" placeholder="Optional revision note"></textarea></label>
<div class="proof-revision-actions"><button class="btn btn-primary" id="proof-save" type="submit">Save revision</button><button class="btn btn-outline" id="proof-request" type="button">Needs revision</button></div></form></details>
<p id="proof-errors" class="platform-error" role="alert" hidden></p>
<h3 id="proof-prize-title" class="sr-only">Loading prize…</h3><p id="proof-prize-context" class="sr-only"></p>
</aside></div></div></dialog>
<script src="public/client.js" defer></script><script src="public/proof-sequence.js?v=5q2" defer></script><script src="public/claim-capture.js?v=5q15a" defer></script><script src="public/proof.js?v=5q15bfix" defer></script><script src="public/ui-receiver.js?v=5q8b" defer></script>
<?php shellEnd(); ?>
