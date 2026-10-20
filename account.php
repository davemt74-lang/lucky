<?php
declare(strict_types=1);
require __DIR__.'/lib/Layout.php';
require_once __DIR__.'/lib/AccountUi.php';
if(!installed()){header('Location: install.php');exit;}
if(!isAdmin()){header('Location: login.php',true,302);exit;}
headerSecurity();
$identity=accountIdentity();$adminId=$identity['id'];
if(($_GET['action']??'')==='avatar'){
 $file=accountAvatarPath($adminId);
 if(!is_file($file)){http_response_code(404);exit;}
 $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file);
 if(!in_array($mime,['image/jpeg','image/png','image/webp'],true)){http_response_code(404);exit;}
 header('Content-Type: '.$mime);header('X-Content-Type-Options: nosniff');header('Cache-Control: private, no-store');
 readfile($file);exit;
}
$message='';$error='';
if(($_SERVER['REQUEST_METHOD']??'')==='POST'){
 try{
  if(!hash_equals(csrf(),(string)($_POST['csrf']??'')))throw new DomainException('Form expired. Refresh this page.',403);
  if(($_POST['action']??'')==='logout'){
   $_SESSION=[];session_regenerate_id(true);header('Location: login.php',true,303);exit;
  }
  if(($_POST['action']??'')!=='save')throw new DomainException('Invalid account action.',400);
  $username=trim((string)($_POST['username']??''));
  if($username===''||strlen($username)>80||!preg_match('/^[a-zA-Z0-9_. -]+$/D',$username))
   throw new DomainException('Choose a username of up to 80 letters, numbers, spaces, dots, underscores, or dashes.',422);
  $newPassword=(string)($_POST['new_password']??'');
  if($newPassword!==''&&(strlen($newPassword)<10||strlen($newPassword)>128))throw new DomainException('New password must be 10–128 characters.',422);
  $db=db();$q=$db->prepare('SELECT username,password_hash FROM admins WHERE id=?');$q->execute([$adminId]);$row=$q->fetch();
  if(!$row)throw new RuntimeException('Account not found.');
  $changing=$username!==$row['username']||$newPassword!=='';
  if($changing&&!password_verify((string)($_POST['current_password']??''),$row['password_hash']))
   throw new DomainException('Enter your current password to update your username or password.',403);
  $upload=$_FILES['avatar']??null;
  $hasUpload=is_array($upload)&&($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE;
  if($hasUpload){
   if(($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file($upload['tmp_name']))
    throw new DomainException('Profile photo upload failed.',422);
   if(($upload['size']??0)>2*1024*1024||($upload['size']??0)<20)
    throw new DomainException('Profile photo must be under 2 MB.',422);
   $mime=(new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
   $dimensions=@getimagesize($upload['tmp_name']);
   if(!in_array($mime,['image/jpeg','image/png','image/webp'],true)||!$dimensions||$dimensions[0]<1||$dimensions[1]<1||$dimensions[0]>3000||$dimensions[1]>3000)
    throw new DomainException('Choose a valid JPG, PNG or WebP photo up to 3000 × 3000.',422);
  }
  if($changing){$update=$db->prepare('UPDATE admins SET username=?,password_hash=? WHERE id=?');$update->execute([$username,$newPassword!==''?password_hash($newPassword,PASSWORD_DEFAULT):$row['password_hash'],$adminId]);}
  if($hasUpload){
   $dir=LUCKY_ROOT.'/storage/avatars';if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('Cannot create private avatar directory.');
   $temp=tempnam($dir,'avatar-');if($temp===false)throw new RuntimeException('Cannot stage profile photo.');
   if(!move_uploaded_file($upload['tmp_name'],$temp)){@unlink($temp);throw new RuntimeException('Unable to save profile photo.');}
   @chmod($temp,0600);
   if(!rename($temp,accountAvatarPath($adminId))){@unlink($temp);throw new RuntimeException('Unable to finalize profile photo.');}
  }
  $message='Account settings saved.';$identity=accountIdentity();
 }catch(Throwable $e){$error=$e instanceof PDOException?'Username already in use.':$e->getMessage();}
}
shellStart('Account Settings','account');
?>
<section class="account-settings-page surface">
 <div class="account-settings-heading"><div><p class="eyebrow">HOST ACCOUNT</p><h1>Account settings</h1><p>Manage your existing Lucky Game Hub login and profile photo.</p></div><a href="game.php" class="btn btn-outline">← Back to game</a></div>
 <?php if($error):?><p class="platform-error" role="alert"><?=h($error)?></p><?php endif;?>
 <?php if($message):?><p class="voice-status" role="status"><?=h($message)?></p><?php endif;?>
 <form class="account-settings-form" action="account.php" method="post" enctype="multipart/form-data">
 <input type="hidden" name="csrf" value="<?=h(csrf())?>">
 <input type="hidden" name="action" value="save">
 <div class="account-photo-preview"><span class="profile-avatar account-avatar-large">
 <?php if($identity['hasAvatar']):?><img src="account.php?action=avatar&amp;v=<?=time()?>" alt="Current profile photo"><?php else:?><span class="profile-avatar-fallback">★</span><?php endif;?>
 </span><label>Profile photo <input type="file" name="avatar" accept="image/jpeg,image/png,image/webp"><small>JPG, PNG or WebP · maximum 2 MB</small></label></div>
 <label>Username<input name="username" value="<?=h($identity['username'])?>" required autocomplete="username" maxlength="80"></label>
 <label>Current password <small>(required to change username or password)</small><input name="current_password" type="password" autocomplete="current-password"></label>
 <label>New password <small>(leave blank to keep current password)</small><input name="new_password" type="password" autocomplete="new-password" minlength="10"></label>
 <button class="btn btn-primary" type="submit">Save account settings</button>
 </form>
</section>
<?php shellEnd(); ?>
