<?php
declare(strict_types=1);
require __DIR__.'/lib/Layout.php';if(!installed()){header('Location: install.php');exit;}
shellStart('Games & Gear','shop');
?><section class="page-intro"><div><p class="eyebrow">YOUR GAME COLLECTION · EXTRAS</p><h1>Games & <span class="accent">Gear.</span></h1><p>Discover games, hardware, and accessories for your home gaming setup. Shopping stays separate from gameplay.</p></div><a href="index.php" class="btn btn-outline">← Play games</a></section>
<section class="surface console-section"><div class="card-title-row"><div><span class="mini-label">PRODUCT CATALOG</span><h2>Browse the collection</h2></div><label for="catalog-filter">Filter by type <select id="catalog-filter"><option value="all">All products</option><option value="game">Games</option><option value="hardware">Hardware</option><option value="accessory">Accessories</option><option value="other">Other</option></select></label></div><p id="catalog-status" role="status">Loading catalog…</p><div id="catalog-products" class="catalog-grid"></div></section>
<p class="console-footnote">Products marked Available link to an external HTTPS purchase page. Orders and payments are not processed in Lucky Game Hub.</p>
<script src="public/client.js" defer></script><script src="public/catalog.js" defer></script><?php shellEnd(); ?>
