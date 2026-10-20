<?php
declare(strict_types=1);
/** Only the user sitting at the HomeServer computer can claim first admin. */
require __DIR__.'/lib/Bootstrap.php';
headerSecurity();
$peer=(string)($_SERVER['REMOTE_ADDR']??'');
if(!in_array($peer,['127.0.0.1','::1'],true)){http_response_code(403);exit('Finish first setup at the HomeServer computer.');}
if(!installed()){header('Location: install.php',true,302);exit;}
$db=db();
$hasAdmin=(int)$db->query('SELECT COUNT(*) FROM admins')->fetchColumn()>0;
if($hasAdmin){header('Location: login.php',true,302);exit;}
$error='';
if(($_SERVER['REQUEST_METHOD']??'')==='POST'){
 try{
  if(!hash_equals(csrf(),(string)($_POST['csrf']??'')))throw new DomainException('Invalid security token.',403);
  $user=trim((string)($_POST['username']??''));
  $password=(string)($_POST['password']??'');
  if(!preg_match('/^[A-Za-z0-9_.-]{3,80}$/D',$user)||strlen($password)<12||strlen($password)>256)
   throw new DomainException('Use a 3+ character username and 12+ character password.',422);
  $db->beginTransaction();
  $rows=$db->query('SELECT id FROM admins FOR UPDATE')->fetchAll();
  if(count($rows)!==0)throw new DomainException('Administrator already configured.',409);
  $q=$db->prepare('INSERT INTO admins(username,password_hash) VALUES (?,?)');
  $q->execute([$user,password_hash($password,PASSWORD_DEFAULT)]);
  audit($db,'homeserver.first-admin');
  $db->commit();
  header('Location: login.php?installed=1',true,303);exit;
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();$error=$e->getMessage();}
}
function hlocal(string $s):string{return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Lucky Games — Local setup</title><link rel="stylesheet" href="public/style.css"></head><body><main class="platform-narrow"><h1>Welcome to Lucky Game Hub</h1><p>The database is running on this computer. Create the first administrator to start playing. No cloud account or API key is required.</p><?php if($error):?><p class="platform-error" role="alert"><?=hlocal($error)?></p><?php endif;?><form method="post" class="platform-form"><input type="hidden" name="csrf" value="<?=hlocal(csrf())?>"><label>Administrator username<input name="username" required minlength="3" maxlength="80" autocomplete="username"></label><label>Password (12+ characters)<input name="password" required type="password" minlength="12" maxlength="256" autocomplete="new-password"></label><button class="btn btn-primary">Create local administrator</button></form></main></body></html>
