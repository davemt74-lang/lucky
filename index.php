<?php
declare(strict_types=1);
require __DIR__.'/lib/Layout.php';if(!installed()){header('Location: install.php');exit;}shellStart('Choose a Game');
?><section class="hub-hero"><div><p class="eyebrow">YOUR PRIVATE HOME ARCADE</p><h1>Your games, <span class="accent">your way.</span></h1><p>Play at home, pick up where you left off, and add games and hardware as your collection grows.</p></div><div class="hub-hero-icon" aria-hidden="true">✦</div></section><section class="hub-catalog"><div class="section-heading"><span class="mini-label">GAME LIBRARY</span><h2>Choose what to play</h2></div><div class="game-catalog" id="game-catalog"><article class="game-tile"><div class="game-tile-art">✦</div><div class="game-tile-body"><span class="mini-label">AVAILABLE</span><h2>Spin the Wheel</h2><p>Tier 1 → Tier 2 → Bonus with shared budgets, claims and live screens.</p><a class="btn btn-primary game-console-tile" href="intro.php?game=spin-wheel" data-game="spin-wheel">▶ Choose this game</a></div></article><?php
require_once __DIR__.'/lib/GamePackageActivation.php';
foreach(GamePackageActivation::all() as $installedGame):
 $gameId=htmlspecialchars($installedGame['id'],ENT_QUOTES);
 $gameName=htmlspecialchars($installedGame['title'],ENT_QUOTES);
 $publisher=htmlspecialchars($installedGame['publisher'],ENT_QUOTES);
?><article class="game-tile"><div class="game-tile-art">✦</div><div class="game-tile-body">
<span class="mini-label">INSTALLED · v<?=htmlspecialchars($installedGame['version'],ENT_QUOTES)?></span>
<h2><?=$gameName?></h2><p>Published by <?=$publisher?> · <?=$installedGame['phone']?'Phone controller included':'TV / desktop game'?></p>
<?php if(isAdmin()):?><a class="btn btn-primary game-console-tile" href="package-game.php?game=<?=$gameId?>">▶ Launch game</a>
<?php else:?><span class="btn btn-outline disabled-action">Host sign-in required</span><?php endif;?>
</div></article><?php endforeach; ?><article class="game-tile upcoming"><div class="game-tile-art">✧</div><div class="game-tile-body"><span class="mini-label">NEXT BUILD</span><h2>Game 2</h2><p>Second game reserved. The base services are ready for its independent rules.</p><span class="btn btn-outline disabled-action">Coming next</span></div></article></div></section><section class="surface hub-info"><h2>Made for the living room</h2><p>Play using a keyboard, optional gamepad, or voice. Intro music, prizes, and session history stay with the game.</p><?php if(isAdmin()):?><a href="admin.php" class="btn btn-outline">⚙ Open Admin Studio</a><?php else:?><a href="login.php" class="btn btn-outline">Host sign-in</a><?php endif;?></section><section class="console-cta"><a href="devices.php" class="btn btn-outline">🎮 Controllers</a><a href="shop.php" class="btn btn-outline">◈ Games & Gear Store</a></section><?php shellEnd(); ?>
