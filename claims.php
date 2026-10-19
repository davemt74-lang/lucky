<?php
declare(strict_types=1);
require __DIR__.'/lib/Layout.php';if(!installed()){header('Location: install.php');exit;}
if(!isAdmin()){header('Location: login.php');exit;}
// A claims page never aggregates multiple games; a signed-in host must open it from one session.
$sessionId=(string)($_GET['session']??'');
if(!preg_match('/^[a-f0-9]{32}$/D',$sessionId)){header('Location: game.php',true,302);exit;}
shellStart('Claimed Prizes','claims');
?><section class="page-intro"><div><p class="eyebrow">THIS GAME SESSION ONLY</p><h1>Claimed <span class="accent">prizes.</span></h1><p>Proof editing and revision history for this game session only.</p></div><a class="btn btn-outline" href="game.php?session=<?=h($sessionId)?>">← Back to this game</a></section>
<div class="platform-control-row"><label>Filter tier <select id="claims-filter"><option value="all">All tiers</option><option value="tier1">Tier 1</option><option value="tier2">Tier 2</option><option value="bonus">Bonus</option></select></label><button id="claims-export" class="btn btn-outline" type="button">↓ Export CSV</button></div>
<section class="surface platform-admin-panel"><p id="claims-summary">Loading claims…</p><div class="platform-claim-list" id="claims-rows"></div><p id="claims-error" class="voice-status" role="status"></p></section>
<script src="public/client.js" defer></script><script src="public/claims.js?v=5q10" defer></script><?php shellEnd(); ?>
