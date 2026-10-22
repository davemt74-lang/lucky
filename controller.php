<?php
declare(strict_types=1);
require __DIR__.'/lib/Bootstrap.php';
if(!installed()){http_response_code(503);exit('The game platform is not installed.');}
headerSecurity();header('Referrer-Policy: no-referrer');header('X-Robots-Tag: noindex, nofollow');
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="referrer" content="no-referrer"><meta name="theme-color" content="#0d1423"><title>Phone Controller · Lucky Games</title><link rel="stylesheet" href="public/style.css"><link rel="stylesheet" href="public/platform.css?v=5q27a"></head>
<body class="phone-controller-page"><main class="phone-controller" aria-label="Lucky Games phone controller"><div class="phone-controller-top"><div class="phone-identity"><div class="phone-brand"><span class="phone-brand-icon" aria-hidden="true">✦</span><span class="phone-brand-label"><strong>LUCKY<span>GAMES</span></strong><small>GAME CONTROLLER</small></span></div><span id="phone-connection-state" class="phone-connection-state" data-state="waiting" role="status" aria-live="polite">● Not connected</span><button id="phone-menu-toggle" type="button" aria-label="Controller menu" aria-haspopup="dialog" aria-controls="phone-quick-menu" hidden>☰</button></div><p id="phone-status" role="status" aria-live="polite">Connecting…</p></div>
<section id="phone-dashboard" class="phone-game-dashboard" hidden aria-labelledby="phone-dashboard-title">
 <div class="surface phone-dashboard-heading"><p class="mini-label">YOUR HOME ARCADE</p><h1 id="phone-dashboard-title">Choose a game</h1>
 <p>Choose a game to start it on the computer or TV. Your approved phone stays connected.</p>
 <p id="phone-dashboard-status" role="status" aria-live="polite">Loading installed games…</p>
 <label id="phone-dashboard-package-picker" class="phone-package-picker" hidden>Prize package for the next Spin the Wheel game
  <select id="phone-dashboard-package-select" aria-label="Choose prize package for next Spin the Wheel game"></select><img id="phone-dashboard-package-image" class="phone-dashboard-package-image" alt="Selected prize package cover" hidden>
 </label></div>
 <div id="phone-dashboard-games" class="phone-dashboard-games" aria-label="Installed games"></div>
 </section>
<section id="phone-movie" class="surface phone-movie-controller" hidden aria-label="Movie preview remote control">
 <p class="mini-label">VIDEO STUDIO · CONNECTED</p><h2>Movie Preview</h2>
 <p id="phone-movie-state" role="status" aria-live="polite">Playing on the desktop</p>
 <div class="phone-movie-buttons" role="group" aria-label="Preview playback controls"><button type="button" id="phone-movie-play">▶ Play</button><button type="button" id="phone-movie-pause">Ⅱ Pause</button><button type="button" id="phone-movie-stop">■ Stop</button></div>
 <p class="phone-movie-hint">Controls the assembled timeline on your desktop or TV. No movie is uploaded to your phone.</p>
</section>
<section id="phone-join" class="surface phone-join" hidden><span class="phone-connect-icon" aria-hidden="true">↗</span><h2>Connect your phone</h2><p class="phone-connect-copy">Use the QR code on the game screen, name this controller, then ask the host to approve it once.</p><form id="phone-join-form"><label>Phone name <input id="phone-name" maxlength="48" required autocomplete="nickname" placeholder="Living room phone"></label><button class="btn btn-primary" type="submit">Request connection</button></form></section>
<section id="phone-wait" class="surface phone-join phone-approval" hidden><span class="phone-connect-icon" aria-hidden="true">⌛</span><h2>Waiting for host approval</h2><p>Your request is on the host screen. Once approved, game controls appear here automatically.</p><p class="phone-approval-footnote">Keep this page open. You won't need to pair again for each game.</p></section>
<section id="phone-controls" hidden><div id="phone-gameplay-shell"><div class="phone-wheel-visual" aria-hidden="true"><span class="phone-wheel-title">SPIN THE WHEEL</span><div class="phone-wheel-face" id="phone-wheel-face"><i></i></div><div class="phone-wheel-pointer"></div><small id="phone-wheel-hint">Watch the wheel on the TV</small></div><label id="phone-package-picker" class="phone-package-picker" hidden>Prize package for the next game<select id="phone-package-select" aria-label="Choose enabled prize package"></select></label><div class="phone-actions"><button type="button" data-phone="start" class="btn btn-outline">▶ Start</button><button type="button" data-phone="menu" class="btn btn-outline">☰ Menu</button><button type="button" data-phone="back" class="btn btn-outline">← Back</button></div>
<button type="button" data-phone="spin" class="phone-spin">✦<span>SPIN</span><small>Spin / Spin Again</small></button>
<div class="phone-actions"><button type="button" data-phone="select" class="btn btn-outline">✓ Select</button><button type="button" data-phone="pause" class="btn btn-outline">Ⅱ Pause / Resume</button><button type="button" data-phone="end" class="btn btn-outline">■ End</button></div><div id="phone-game-state" class="surface phone-game-state" role="status" aria-live="polite">Connecting to game state…</div>

</div><section id="phone-prize-offer" class="phone-prize-offer surface" hidden aria-label="Prize award and acceptance">
 <div class="phone-prize-win-banner"><span class="phone-prize-win-dot" aria-hidden="true">✦</span><span>SPIN COMPLETE</span><span class="phone-prize-win-label">YOU WON!</span></div><h2>Congratulations!</h2>
 <p id="phone-offer-tier" class="phone-prize-tier" hidden></p><h3 id="phone-offer-name" class="phone-prize-title" aria-live="polite">Waiting for prize details…</h3><strong id="phone-offer-value" class="phone-prize-value"></strong>
 <div class="phone-prize-media"><img id="phone-offer-photo" class="phone-prize-photo" alt="Prize photo" hidden><div class="phone-prize-media-empty" aria-hidden="true">🎁</div></div>
 <p id="phone-offer-description" class="phone-prize-description"></p>
 <div class="phone-offer-actions" aria-label="Prize details actions"><button type="button" id="phone-offer-speak" class="btn btn-outline">♫ Hear prize again</button><button type="button" id="phone-offer-play-video" class="btn btn-outline" hidden>▶ Play Video on desktop</button></div>
 <p id="phone-offer-help" class="phone-prize-help">Choose on your phone after the wheel stops. Recording controls follow acceptance.</p>
 <div class="phone-actions phone-prize-decision"><button type="button" data-phone="prize-accept" class="btn btn-primary">✓ Accept Prize</button><button type="button" data-phone="prize-decline" class="btn btn-outline">Decline this prize</button></div>
 </section><div id="phone-proof-mode" hidden><div id="phone-proof-live-banner" class="phone-proof-live-banner" role="status" aria-live="polite" hidden><span aria-hidden="true">●</span><strong id="phone-proof-live-title">Desktop camera ready</strong></div><section id="phone-proof-prize" class="phone-proof-prize surface" hidden aria-label="Claimed prize details">
 <span class="phone-proof-win-label">✓ Prize Accepted</span><p id="phone-proof-prize-tier" class="phone-prize-tier" hidden></p><h3 id="phone-proof-prize-name" class="phone-prize-title"></h3><strong id="phone-proof-prize-value" class="phone-prize-value"></strong><div class="phone-prize-media"><img id="phone-proof-prize-photo" class="phone-prize-photo" alt="Claimed prize photo" hidden><div class="phone-prize-media-empty" aria-hidden="true">🎁</div></div><p id="phone-proof-prize-description" class="phone-prize-description"></p>
 <button id="phone-proof-play-video" type="button" class="btn btn-outline" hidden>▶ Play Video on desktop</button>
 </section><p class="phone-proof-context" id="phone-proof-context">Prize proof · Computer camera</p><details id="phone-proof-controls" class="phone-proof-controls" hidden><summary>🎥 Prize proof controls</summary><label class="phone-proof-consent"><input type="checkbox" id="phone-proof-consent"> Everyone recorded has agreed to this private video.</label><p id="phone-proof-phase" role="status" aria-live="polite">Live camera ready</p><p id="phone-proof-countdown" class="phone-proof-countdown" hidden role="status" aria-live="polite">Get ready — desktop countdown in progress</p><div class="phone-actions" id="phone-proof-recording" aria-label="Record prize proof"><button type="button" data-phone="proof-record">● Record</button><button type="button" data-phone="proof-stop">■ Stop</button></div><div class="phone-actions" id="phone-proof-saved" hidden><button type="button" id="phone-proof-rerecord-saved" data-phone="proof-rerecord">↻ Re-record</button></div><div class="phone-actions" id="phone-proof-review" aria-label="Review prize proof" hidden><button type="button" data-phone="proof-play">▶ Play</button><button type="button" data-phone="proof-save">✓ Save Video</button><button type="button" data-phone="proof-rerecord">↻ Re-record</button><button type="button" data-phone="proof-delete">🗑 Delete</button></div><div class="phone-actions"><button type="button" data-phone="back" class="btn btn-outline">✓ Done · Return to game</button></div></details></div>
</section><p id="phone-voice-status" class="phone-voice-status" role="status" aria-live="polite"></p>
<nav id="phone-bottom-nav" class="phone-bottom-nav" aria-label="Phone navigation" hidden>
<button type="button" id="phone-mic-toggle" aria-label="Toggle phone microphone" aria-pressed="false"><span aria-hidden="true">🎙</span><small>Mic</small></button>
<button type="button" data-phone-view="game" disabled><span aria-hidden="true">🎮</span><small>Game</small></button>
<button type="button" id="phone-arrows-toggle" aria-label="Open arrow navigation" aria-haspopup="dialog" aria-controls="phone-arrows-dialog" aria-expanded="false" disabled><span aria-hidden="true">✥</span><small>Arrows</small></button>
<button type="button" data-phone-view="prize" disabled><span aria-hidden="true">🎁</span><small>Prize</small></button>
<button type="button" data-phone-view="camera" disabled><span aria-hidden="true">▣</span><small>Camera</small></button>
<button type="button" data-phone-view="more"><span aria-hidden="true">☰</span><small>More</small></button>
</nav>
<dialog id="phone-arrows-dialog" class="phone-arrows-dialog" aria-labelledby="phone-arrows-title">
<header><h2 id="phone-arrows-title">Arrow navigation</h2><button id="phone-arrows-close" type="button" aria-label="Close arrow navigation">✕</button></header>
<p>Control the current game on your computer or TV.</p>
<div class="phone-direction phone-direction-popup" role="group" aria-label="Directional remote">
<button type="button" data-phone="up" aria-label="Up">▲</button>
<button type="button" data-phone="left" aria-label="Left">◀</button>
<button type="button" data-phone="select" aria-label="Select">SELECT</button>
<button type="button" data-phone="right" aria-label="Right">▶</button>
<button type="button" data-phone="down" aria-label="Down">▼</button>
</div>
</dialog>
<dialog id="phone-quick-menu" class="phone-quick-menu" aria-labelledby="phone-quick-menu-title">
<header><strong>✦ LUCKY<span>GAMES</span></strong><button id="phone-menu-close" type="button" aria-label="Close menu">✕</button></header>
<h2 id="phone-quick-menu-title">Controller menu</h2>
<div class="phone-menu-tabs" role="tablist" aria-label="More menu sections">
<button type="button" id="phone-menu-tab-controls" data-phone-menu-tab="controls" role="tab" aria-selected="true" aria-controls="phone-menu-panel-controls" tabindex="0">Controls</button>
<button type="button" id="phone-menu-tab-music" data-phone-menu-tab="music" role="tab" aria-selected="false" aria-controls="phone-menu-panel-music" tabindex="-1">Music</button>
</div>
<section id="phone-menu-panel-controls" class="phone-menu-panel" role="tabpanel" aria-labelledby="phone-menu-tab-controls">
<p id="phone-menu-context">Your private home arcade.</p>
<div class="phone-menu-items">
<button type="button" data-phone-view="home" disabled>⌂ <span>Dashboard<small>Choose the next game</small></span></button>
<button type="button" data-phone-view="game" disabled>🎮 <span>Current game<small>Desktop gameplay controls</small></span></button>
<button type="button" data-phone-view="prize" disabled>🎁 <span>Prize claim<small>View the pending prize</small></span></button>
<button type="button" data-phone-view="camera" disabled>▣ <span>Camera proof<small>Record, review and save</small></span></button>
</div>
<p class="phone-menu-privacy">Video playback and recording happen on the host computer, not your phone.</p>
<button id="phone-disconnect" type="button" class="btn btn-outline phone-menu-disconnect" hidden>Disconnect this phone</button>
</section>
<section id="phone-menu-panel-music" class="phone-menu-panel" role="tabpanel" aria-labelledby="phone-menu-tab-music" hidden>
<div id="phone-game-audio" class="surface phone-audio"><h2>Sound &amp; music</h2><p>Adjust playback on the computer or TV.</p><div class="phone-actions"><button type="button" data-phone="music-on">♫ Music on</button><button type="button" data-phone="music-off">♫ Music off</button><button type="button" data-phone="music-next">Next song</button><button type="button" data-phone="volume-down">Vol −</button><button type="button" data-phone="volume-up">Vol +</button><button type="button" data-phone="mute">Mute</button><button type="button" data-phone="unmute">Unmute</button></div></div>
</section>
</dialog><dialog id="phone-decline-dialog" class="phone-confirm-dialog" aria-labelledby="phone-decline-title" aria-describedby="phone-decline-note"><h2 id="phone-decline-title">Decline this prize?</h2><p id="phone-decline-note">The prize won't be claimed, and this game will end. This can't be undone.</p><div class="phone-actions"><button type="button" id="phone-decline-cancel" class="btn btn-primary">Keep my prize</button><button type="button" id="phone-decline-confirm" class="btn btn-outline">Decline &amp; end game</button></div></dialog></main><script src="public/voice-commands.js?v=5p2" defer></script><script src="public/phone-controller.js?v=5q27a" defer></script></body></html>
