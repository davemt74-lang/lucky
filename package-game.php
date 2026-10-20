<?php
declare(strict_types=1);
require __DIR__.'/lib/Layout.php';
require_once __DIR__.'/lib/GamePackageActivation.php';
if(!installed()){header('Location: install.php');exit;}
requireAdmin();
$id=GameCatalog::slug($_GET['game']??null);
$active=GamePackageActivation::active($id);
if(!$active){http_response_code(404);exit('Game is not activated.');}
$title=$active['receipt']['title'];$phone=$active['receipt']['phone']!==null;
shellStart($title.' — Game Console');
?>
<section class="surface" style="padding:20px;max-width:1300px;margin:auto">
 <div class="card-title-row"><div><p class="mini-label">GAME CONSOLE · LOCAL SESSION</p><h1><?=htmlspecialchars($title,ENT_QUOTES)?></h1><p><?=htmlspecialchars($active['receipt']['publisher'],ENT_QUOTES)?> · v<?=htmlspecialchars($active['version'],ENT_QUOTES)?></p></div><a href="index.php" class="btn btn-outline">Game dashboard</a></div>
 <p id="package-status" role="status" aria-live="polite">Loading your game…</p>
 <div class="platform-control-row">
  <button id="pkg-start" class="btn btn-primary" type="button">Start game</button>
  <button id="pkg-pause" class="btn btn-outline" type="button" disabled>Pause</button>
  <button id="pkg-end" class="btn btn-outline" type="button" disabled>End game</button>
 </div>
 <div class="package-console-stage" style="min-height:60vh;background:#070f1d;border:1px solid #35435a;border-radius:12px;overflow:hidden">
 <iframe id="package-frame" sandbox="allow-scripts" referrerpolicy="no-referrer" title="<?=htmlspecialchars($title,ENT_QUOTES)?> gameplay" style="width:100%;height:68vh;border:0;background:#0b1422" hidden></iframe>
 </div>
 <?php if($phone):?><p class="mini-label">This game contains a separate phone controller. Remote controller pairing and its approved action bridge are being certified in the next section.</p><?php else:?><p class="mini-label">This game does not require a phone controller.</p><?php endif;?>
</section>
<script src="public/package-game.js?v=5q22g" defer></script>
<script>/* No inline state or secrets are required for host game startup. */</script>
<?php shellEnd(); ?>
