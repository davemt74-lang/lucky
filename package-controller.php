<?php
declare(strict_types=1);
require __DIR__.'/lib/Bootstrap.php';
if(!installed()){http_response_code(503);exit('Complete the Lucky Game Hub installation first.');}
headerSecurity();header('Referrer-Policy: no-referrer');header('X-Robots-Tag: noindex, nofollow');
?><!doctype html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="referrer" content="no-referrer"><title>Game Phone Controller · Lucky Games</title>
<link rel="stylesheet" href="public/style.css"><link rel="stylesheet" href="public/platform.css">
</head><body class="phone-controller-page"><main class="phone-controller" aria-label="Custom game phone controller">
<div class="phone-controller-top"><p class="eyebrow">LUCKY GAMES · GAME-SPECIFIC CONTROLLER</p><p role="status" aria-live="polite" id="package-phone-status">Connect your phone to the game.</p></div>
<section id="package-phone-join" class="surface phone-join"><h2>Join this game</h2>
<form id="package-phone-join-form"><label>Phone name<input id="package-phone-name" autocomplete="nickname" maxlength="48" placeholder="Living room phone" required></label>
<button type="submit" class="btn btn-primary">Request host approval</button></form></section>
<section id="package-phone-wait" class="surface phone-join" hidden><h2>Waiting for host approval</h2><p>Approve this device on the game screen.</p></section>
<section id="package-phone-play" hidden>
<div class="surface"><div class="platform-control-row">
<button id="package-phone-pause" type="button" class="btn btn-outline">Pause</button>
<button id="package-phone-end" type="button" class="btn btn-outline">End game</button>
<button id="package-phone-disconnect" type="button" class="btn btn-outline">Disconnect phone</button></div></div>
<iframe id="package-phone-frame" sandbox="allow-scripts" referrerpolicy="no-referrer" title="Game's custom phone buttons" style="width:100%;height:70dvh;border:0;border-radius:12px;background:#0b1422"></iframe></section>
<section id="package-phone-finished" class="surface phone-join" hidden><h2>Game finished</h2><p>The desktop game has returned to the main dashboard.</p><a href="index.php" class="btn btn-primary">Game dashboard</a></section>
</main><script src="public/package-controller.js?v=5q22h" defer></script></body></html>
