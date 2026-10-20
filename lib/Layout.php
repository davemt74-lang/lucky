<?php
declare(strict_types=1);
require_once __DIR__.'/Bootstrap.php';
function h(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
require_once __DIR__.'/AccountUi.php';
function shellStart(string $title,string $active='hub',string $bodyClass=''):void {
 headerSecurity();
 echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#0d1423"><title>'.h($title).' — Lucky Games</title><link rel="stylesheet" href="public/style.css"><link rel="stylesheet" href="public/platform.css?v=5q21"></head><body class="'.h($bodyClass).'"><div class="shell"><header class="header"><a class="brand" href="index.php"><span class="logo">✦</span><span>LUCKY<span class="brand-gold">GAMES</span><small>GAME HUB</small></span></a>';
 if($active==='admin'){echo '<a class="btn btn-outline admin-global-game-link" href="game.php">▶ Host game</a>';accountMenu();echo '</header><main>';return;}
 echo '<nav class="nav" aria-label="Main navigation"><a href="index.php"'.($active==='hub'?' aria-current="page"':'').'>Games</a>';
 echo '<a href="shop.php"'.($active==='shop'?' aria-current="page"':'').'>Shop</a><a href="devices.php"'.($active==='devices'?' aria-current="page"':'').'>Devices</a>';
 if(isAdmin())echo '<a href="catalog-admin.php"'.($active==='catalog-admin'?' aria-current="page"':'').'>Products</a>';
 else echo '<a href="login.php">Host Login</a>';
 echo '</nav>';accountMenu();echo '</header><main>';
}
function shellEnd():void {echo '</main><script src="public/console.js?v=5q15e" defer></script><footer class="footer"><span>LUCKY GAMES · SECURE GAME ENGINE</span><nav class="footer-menu" aria-label="Footer menu"><a href="devices.php">Controllers</a><span class="footer-version">V5Q.12 · GAME CONSOLE</span></nav></footer></div></body></html>';}
