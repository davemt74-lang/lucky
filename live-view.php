<?php
declare(strict_types=1);
require __DIR__.'/lib/Bootstrap.php';
headerSecurity();header('Referrer-Policy: no-referrer');header('X-Robots-Tag: noindex, nofollow');
$id=(string)($_GET['id']??'');$key=(string)($_GET['key']??'');
if(!preg_match('/^[a-f0-9]{32}$/D',$id))$id='';
if(!preg_match('/^[a-f0-9]{48}$/D',$key))$key='';
$directory=$id==='';
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="referrer" content="no-referrer"><meta name="theme-color" content="#0d1423"><title>Camera Director Live View — Lucky Game Hub</title><link rel="stylesheet" href="public/style.css"><link rel="stylesheet" href="public/platform.css?v=5q29e4d1hf8c0"></head><body class="director-view-page" data-broadcast-id="<?=htmlspecialchars($id,ENT_QUOTES)?>" data-broadcast-key="<?=htmlspecialchars($key,ENT_QUOTES)?>"><main class="director-view-wrap"><header><a href="index.php">✦ LUCKY GAMES</a><span class="director-live-view-label">CAMERA DIRECTOR · LIVE VIEW</span></header>
<?php if($directory): ?>
<section id="director-public-directory" aria-label="Public broadcasts"><h1>Live Now</h1><p>Public broadcasts chosen by their hosts appear here. Private invitation-only streams are never listed.</p><div id="director-public-list" class="director-public-list" aria-live="polite"></div><button id="director-public-refresh" type="button" class="btn btn-outline">↻ Refresh broadcasts</button><p id="director-view-status" role="status" aria-live="polite">Finding live public broadcasts…</p></section>
<?php else: ?>
<div class="director-view-screen"><video id="director-view-video" autoplay playsinline muted aria-label="Live Program feed from Camera Director"></video><div id="director-view-watermark">PROGRAM</div></div><p id="director-view-status" role="status" aria-live="polite">Connecting to Live View…</p><p class="mini-label">Read-only Program video · The host controls the camera and overlays. Preview is never shared. Currently limited to 8 concurrent viewers.</p><p><a href="live-view.php">Browse public broadcasts</a></p>
<?php endif; ?>
</main><script src="public/director-view.js?v=5q29e4d1hf8c0" defer></script></body></html>
