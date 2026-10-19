<?php
declare(strict_types=1);
require __DIR__.'/lib/Layout.php';
if(!installed()){header('Location: install.php');exit;}
if(!isAdmin()){header('Location: login.php');exit;}
headerSecurity();
$message='';$failed=false;
if(($_SERVER['REQUEST_METHOD']??'')==='POST'){
 try{
  if(!hash_equals(csrf(),(string)($_POST['csrf']??'')))throw new DomainException('Invalid security token.',403);
  $db=db();
  // Only a fixed bundled SQL migration, never arbitrary SQL submitted by a visitor.
  $statements=array_filter(array_map('trim',explode(';',sql('lib/migrations/v5n.sql')."\n".sql('lib/migrations/v5p.sql')."\n".sql('lib/migrations/v5p_sync.sql')."\n".sql('lib/migrations/v5q_claim_media.sql')."\n".sql('lib/migrations/v5q3_renders.sql'))));
  foreach($statements as $statement)$db->exec($statement);
  // Previous V5Q installs already have claim_media: add camera identity safely once.
  $cameraColumn=$db->query("SHOW COLUMNS FROM claim_media LIKE 'source_label'")->fetch();
  if(!$cameraColumn)$db->exec("ALTER TABLE claim_media ADD COLUMN source_label VARCHAR(120) NOT NULL DEFAULT '' AFTER title");
  // Existing V5Q media tables may predate timed proof-angle recording.
  if(!$db->query("SHOW COLUMNS FROM claim_media LIKE 'angle_label'")->fetch())
    $db->exec("ALTER TABLE claim_media ADD COLUMN angle_label VARCHAR(80) NOT NULL DEFAULT '' AFTER source_label");
  if(!$db->query("SHOW COLUMNS FROM claim_media LIKE 'angle_index'")->fetch())
    $db->exec("ALTER TABLE claim_media ADD COLUMN angle_index TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER angle_label");
  audit($db,'platform.upgrade-v5q3');
  $message='V5Q.3 video render queue, proof angles and timeline metadata are ready. Re-running the migrations is safe.';
 }catch(Throwable $e){$failed=true;$message='Upgrade did not complete: '.$e->getMessage();error_log('Lucky migration: '.$e->getMessage());}
}
shellStart('Upgrade Platform');
?><section class="platform-narrow"><p class="eyebrow">HOST SETUP</p><h1>V5Q.3 Video Studio Upgrade</h1><p>For existing V5P.2 installs, this adds private prize-claim media and game video timeline tables, as well as prior migrations when needed. Your games, music, prizes, and claimed history are preserved. Back up the database before upgrading.</p>
<?php if($message):?><p class="<?= $failed?'platform-error':'voice-status' ?>" role="status"><?=h($message)?></p><?php endif; ?>
<form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><button class="btn btn-primary">Run V5Q.3 database upgrade</button></form>
<p><a class="btn btn-outline" href="index.php">← Back to Games</a></p></section><?php shellEnd(); ?>
