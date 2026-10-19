<?php
declare(strict_types=1);
require __DIR__.'/lib/Layout.php';if(!installed()){header('Location: install.php');exit;}
if(!isAdmin()){header('Location: login.php');exit;}
shellStart('Claimed Prizes','claims');
?><section class="page-intro"><div><p class="eyebrow">ACTIVE GAME CLAIMS</p><h1>Prize <span class="accent">History.</span></h1><p>All winnings from the current session, with award timestamps and values. No redemption status.</p></div><a class="btn btn-outline" href="game.php">← Host game</a></section>
<div class="platform-control-row"><label>Filter tier <select id="claims-filter"><option value="all">All tiers</option><option value="tier1">Tier 1</option><option value="tier2">Tier 2</option><option value="bonus">Bonus</option></select></label><button id="claims-export" class="btn btn-outline" type="button">↓ Export CSV</button></div>
<section class="surface platform-admin-panel"><p id="claims-summary">Loading claims…</p><div class="platform-claim-list" id="claims-rows"></div><p id="claims-error" class="voice-status" role="status"></p></section>
<script src="public/client.js" defer></script><script src="public/claims.js" defer></script><?php shellEnd(); ?>
