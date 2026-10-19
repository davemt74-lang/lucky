<?php
declare(strict_types=1);
require __DIR__.'/lib/Bootstrap.php';headerSecurity();
if(!installed()){header('Location: install.php');exit;}
$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
  try {
    if(!hash_equals(csrf(),strval($_POST['csrf']??'')))throw new RuntimeException('Form expired. Try again.');
    $attempt=$_SESSION['login_attempts']??['count'=>0,'started'=>time()];
    if(time()-$attempt['started']>900)$attempt=['count'=>0,'started'=>time()];
    if($attempt['count']>=5)throw new RuntimeException('Too many attempts. Try again in 15 minutes.');
    $attempt['count']++;$_SESSION['login_attempts']=$attempt;
    $q=db()->prepare('SELECT id,password_hash FROM admins WHERE username=?');$q->execute([strval($_POST['admin']??'')]);$row=$q->fetch();
    if(!$row||!password_verify(strval($_POST['password']??''),$row['password_hash']))throw new RuntimeException('Invalid credentials.');
    session_regenerate_id(true);$_SESSION['admin_id']=(int)$row['id'];$_SESSION['login_attempts']=['count'=>0,'started'=>time()];
    header('Location: admin.php',true,303);exit;
  }catch(Throwable $e){$error=$e->getMessage();}
}
function esc(string $s):string{return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Lucky Games — Admin Login</title><link rel="stylesheet" href="public/style.css"><link rel="stylesheet" href="public/platform.css"></head><body><main class="platform-narrow"><a href="index.php">← Game Hub</a><h1>Admin sign in</h1><p>Access prize pools, music, intros, game sessions, and history.</p><?php if($error):?><p class="platform-error" role="alert"><?=esc($error)?></p><?php endif;?><form method="post" class="platform-form"><input type="hidden" name="csrf" value="<?=esc(csrf())?>"><label>Administrator<input name="admin" required autocomplete="username"></label><label>Password<input name="password" type="password" required autocomplete="current-password"></label><button class="btn btn-primary">Sign in</button></form></main></body></html>
