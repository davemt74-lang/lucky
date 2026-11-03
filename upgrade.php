<?php
declare(strict_types=1);
require __DIR__.'/lib/Layout.php';
require_once __DIR__.'/lib/SchemaMigrations.php';
if(!installed()){header('Location: install.php');exit;}
if(!isAdmin()){header('Location: login.php');exit;}
headerSecurity();
$message='';$failed=false;
if(($_SERVER['REQUEST_METHOD']??'')==='POST'){
 try{
  if(!hash_equals(csrf(),(string)($_POST['csrf']??'')))throw new DomainException('Invalid security token.',403);
  $db=db();
  SchemaMigrations::ensure($db);
  audit($db,'platform.upgrade-v5q3');
  $message='Database upgrade complete. Costume actions, parties, themes, gameplay cues and existing recordings are ready. Existing proof media and sessions were preserved.';
 }catch(Throwable $e){$failed=true;$message='Upgrade did not complete: '.$e->getMessage();error_log('Lucky migration: '.$e->getMessage());}
}
shellStart('Upgrade Platform');
?><section class="platform-narrow"><p class="eyebrow">HOST SETUP</p><h1>Lucky Game Hub Database Upgrade</h1><p>For existing installations, apply pending database changes including V5Q.15B prize acceptance and phone controller pairing support. Your games, prizes and history are preserved. Back up the database before upgrading.</p>
<?php if($message):?><p class="<?= $failed?'platform-error':'voice-status' ?>" role="status"><?=h($message)?></p><?php endif; ?>
<form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><button class="btn btn-primary">Run database upgrade</button></form>
<p><a class="btn btn-outline" href="index.php">← Back to Games</a></p></section><?php shellEnd(); ?>
