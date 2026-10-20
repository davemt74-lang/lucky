<?php
declare(strict_types=1);
require __DIR__.'/lib/Layout.php';
require_once __DIR__.'/lib/MigrationSql.php';
if(!installed()){header('Location: install.php');exit;}
if(!isAdmin()){header('Location: login.php');exit;}
headerSecurity();
$message='';$failed=false;
if(($_SERVER['REQUEST_METHOD']??'')==='POST'){
 try{
  if(!hash_equals(csrf(),(string)($_POST['csrf']??'')))throw new DomainException('Invalid security token.',403);
  $db=db();
  // Only a fixed bundled SQL migration, never arbitrary SQL submitted by a visitor.
  $migrationFiles=[
   'lib/migrations/v5n.sql',
   'lib/migrations/v5p.sql',
   'lib/migrations/v5p_sync.sql',
   'lib/migrations/v5q_claim_media.sql',
   'lib/migrations/v5q3_renders.sql',
   'lib/migrations/v5q6_proof_revisions.sql',
   'lib/migrations/v5q13_capture_ui.sql',
   'lib/migrations/v5q15b_prize_decisions.sql'
  ];
  foreach($migrationFiles as $migrationFile){
   foreach(MigrationSql::statements(sql($migrationFile)) as $statement)$db->exec($statement);
  }
  // Previous V5Q installs already have claim_media: add camera identity safely once.
  $cameraColumn=$db->query("SHOW COLUMNS FROM claim_media LIKE 'source_label'")->fetch();
  if(!$cameraColumn)$db->exec("ALTER TABLE claim_media ADD COLUMN source_label VARCHAR(120) NOT NULL DEFAULT '' AFTER title");
  // Existing V5Q media tables may predate timed proof-angle recording.
  if(!$db->query("SHOW COLUMNS FROM claim_media LIKE 'angle_label'")->fetch())
    $db->exec("ALTER TABLE claim_media ADD COLUMN angle_label VARCHAR(80) NOT NULL DEFAULT '' AFTER source_label");
  if(!$db->query("SHOW COLUMNS FROM claim_media LIKE 'angle_index'")->fetch())
    $db->exec("ALTER TABLE claim_media ADD COLUMN angle_index TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER angle_label");
  if(!$db->query("SHOW COLUMNS FROM controller_proof_state LIKE 'consent_ack'")->fetch())
    $db->exec("ALTER TABLE controller_proof_state ADD COLUMN consent_ack TINYINT(1) NOT NULL DEFAULT 0 AFTER review_count");
  if(!$db->query("SHOW COLUMNS FROM controller_proof_state LIKE 'capture_phase'")->fetch())
    $db->exec("ALTER TABLE controller_proof_state ADD COLUMN capture_phase VARCHAR(12) NOT NULL DEFAULT 'live' AFTER consent_ack");
  audit($db,'platform.upgrade-v5q3');
  $message='Database upgrade complete, including V5Q.15F phone proof consent and recording state. Refresh the phone controller.';
 }catch(Throwable $e){$failed=true;$message='Upgrade did not complete: '.$e->getMessage();error_log('Lucky migration: '.$e->getMessage());}
}
shellStart('Upgrade Platform');
?><section class="platform-narrow"><p class="eyebrow">HOST SETUP</p><h1>Lucky Game Hub Database Upgrade</h1><p>For existing installations, apply pending database changes including V5Q.15B prize acceptance and phone controller pairing support. Your games, prizes and history are preserved. Back up the database before upgrading.</p>
<?php if($message):?><p class="<?= $failed?'platform-error':'voice-status' ?>" role="status"><?=h($message)?></p><?php endif; ?>
<form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><button class="btn btn-primary">Run database upgrade</button></form>
<p><a class="btn btn-outline" href="index.php">← Back to Games</a></p></section><?php shellEnd(); ?>
