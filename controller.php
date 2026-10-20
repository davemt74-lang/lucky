<?php
declare(strict_types=1);
require __DIR__.'/lib/Bootstrap.php';
if(!installed()){http_response_code(503);exit('The game platform is not installed.');}
headerSecurity();header('Referrer-Policy: no-referrer');header('X-Robots-Tag: noindex, nofollow');
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="referrer" content="no-referrer"><meta name="theme-color" content="#0d1423"><title>Phone Controller · Lucky Games</title><link rel="stylesheet" href="public/style.css"><link rel="stylesheet" href="public/platform.css?v=5q15m1"></head>
<body class="phone-controller-page"><main class="phone-controller" aria-label="Lucky Games phone controller"><div class="phone-controller-top"><p class="eyebrow">LUCKY GAMES</p><p id="phone-status" role="status" aria-live="polite">Connecting…</p></div>
<section id="phone-join" class="surface phone-join" hidden><h2>Join the game</h2><form id="phone-join-form"><label>Phone name <input id="phone-name" maxlength="48" required autocomplete="nickname" placeholder="Living room phone"></label><button class="btn btn-primary" type="submit">Request connection</button></form></section>
<section id="phone-wait" class="surface phone-join" hidden><h2>Waiting for host approval</h2></section>
<section id="phone-controls" hidden><div id="phone-gameplay-shell"><div id="phone-game-state" class="surface phone-game-state" role="status" aria-live="polite">Connecting to game state…</div><div class="phone-actions"><button type="button" data-phone="start" class="btn btn-outline">▶ Start</button><button type="button" data-phone="menu" class="btn btn-outline">☰ Menu</button><button type="button" data-phone="back" class="btn btn-outline">← Back</button></div>
<button type="button" data-phone="spin" class="phone-spin">✦<span>SPIN</span><small>Spin / Spin Again</small></button>
<div class="phone-actions"><button type="button" data-phone="select" class="btn btn-outline">✓ Select</button><button type="button" data-phone="pause" class="btn btn-outline">Ⅱ Pause / Resume</button><button type="button" data-phone="end" class="btn btn-outline">■ End</button></div>
<div class="phone-direction" aria-label="Directional controls"><button type="button" data-phone="up" aria-label="Up">▲</button><button type="button" data-phone="left" aria-label="Left">◀</button><button type="button" data-phone="down" aria-label="Down">▼</button><button type="button" data-phone="right" aria-label="Right">▶</button></div>
</div><section id="phone-prize-offer" class="phone-prize-offer surface" hidden aria-label="Your prize and acceptance choices">
 <div class="phone-prize-eyebrow"><span class="phone-prize-live-dot" aria-hidden="true"></span> SPIN COMPLETE</div>
 <div class="phone-prize-emblem" aria-hidden="true">✦</div>
 <h2>Congratulations!</h2>
 <p class="phone-prize-intro">You've won</p>
 <p id="phone-offer-name" class="phone-prize-name">Waiting for prize details…</p>
 <p class="phone-prize-instruction">Choose what to do with your prize. Your answer is saved to this game.</p>
 <div class="phone-prize-decision">
   <button type="button" data-phone="prize-accept" class="btn btn-primary">✓ Accept prize</button>
   <button type="button" data-phone="prize-decline" class="btn btn-outline">✕ Decline and end game</button>
 </div>
 <div class="phone-prize-voice">
   <label class="phone-prize-speaker"><input id="phone-announcements-enabled" type="checkbox" checked> Speak winning prizes</label>
   <button type="button" id="phone-prize-read" class="btn btn-outline">🔊 Hear prize</button>
 </div>
 <p id="phone-prize-speech-status" class="phone-prize-speech-status" aria-live="polite"></p>
 </section><div id="phone-proof-mode" hidden><p class="phone-proof-context" id="phone-proof-context">Prize proof · Computer camera</p><details id="phone-proof-controls" class="phone-proof-controls" hidden><summary>🎥 Prize proof controls</summary><label class="phone-proof-consent"><input type="checkbox" id="phone-proof-consent"> Everyone recorded has agreed to this private video.</label><p id="phone-proof-phase" role="status" aria-live="polite">Live camera ready</p><div class="phone-actions" id="phone-proof-recording" aria-label="Record prize proof"><button type="button" data-phone="proof-record">● Record</button><button type="button" data-phone="proof-stop">■ Stop</button></div><div class="phone-actions" id="phone-proof-saved" hidden><button type="button" id="phone-proof-rerecord-saved" data-phone="proof-rerecord">↻ Re-record</button></div><div class="phone-actions" id="phone-proof-review" aria-label="Review prize proof" hidden><button type="button" data-phone="proof-play">▶ Play</button><button type="button" data-phone="proof-save">✓ Save Video</button><button type="button" data-phone="proof-rerecord">↻ Re-record</button><button type="button" data-phone="proof-delete">🗑 Delete</button></div><div class="phone-actions"><button type="button" data-phone="back" class="btn btn-outline">✓ Done · Return to game</button></div></details></div>
<div id="phone-game-audio" class="surface phone-audio"><h2>Sound</h2><div class="phone-actions"><button type="button" data-phone="music-on">♫ Music on</button><button type="button" data-phone="music-off">♫ Music off</button><button type="button" data-phone="music-next">Next song</button><button type="button" data-phone="volume-down">Vol −</button><button type="button" data-phone="volume-up">Vol +</button><button type="button" data-phone="mute">Mute</button><button type="button" data-phone="unmute">Unmute</button></div></div>
<section class="phone-voice-dock" aria-label="Voice control"><button class="btn btn-outline" type="button" id="phone-mic-toggle" aria-label="Enable phone listening" aria-pressed="false">🎙</button><p id="phone-voice-status" role="status" aria-live="polite"></p></section></section></section><button type="button" id="phone-disconnect" class="btn btn-outline" hidden>Disconnect phone</button></main><script src="public/voice-commands.js?v=5p2" defer></script><script src="public/phone-controller.js?v=5q15m1" defer></script></body></html>
