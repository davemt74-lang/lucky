<?php
declare(strict_types=1);
require __DIR__.'/lib/Bootstrap.php';
require_once __DIR__.'/lib/InstallationSetup.php';
require_once __DIR__.'/lib/SetupConfiguration.php';
headerSecurity();
$storage=__DIR__.'/storage';
$error='';
$blocked=false;
try{
 if(SetupConfiguration::exists($storage)){
  if(is_file($storage.'/config.php')&&!is_link($storage.'/config.php')){header('Location: index.php',true,302);exit;}
  $blocked=true;$error='Configuration is already present at a non-regular path. Setup is locked and will not overwrite it. Restore your original configuration manually.';
  http_response_code(409);
 }
}catch(Throwable $e){
 $blocked=true;$error='Unable to inspect the existing configuration safely. Setup remains locked; check the storage directory before retrying.';
 http_response_code(503);error_log('Lucky installation configuration inspection failed ('.get_class($e).').');
}
if(!$blocked&&$_SERVER['REQUEST_METHOD']==='POST'){
 try {
  if(!extension_loaded('pdo_mysql'))throw new DomainException('Host requires the PHP PDO MySQL extension.',422);
  if(!hash_equals(csrf(),strval($_POST['csrf']??'')))throw new DomainException('Invalid form security token.',403);
  $host=trim(strval($_POST['host']??''));$port=(int)($_POST['port']??3306);$name=trim(strval($_POST['database']??''));
  $user=trim(strval($_POST['db_user']??''));$pass=strval($_POST['db_password']??'');
  $admin=trim(strval($_POST['admin']??''));$password=strval($_POST['password']??'');
  if(!preg_match('/^[A-Za-z0-9._-]{1,150}$/D',$host) || $port<1||$port>65535 || !preg_match('/^[A-Za-z0-9_]{1,64}$/D',$name)||
    !preg_match('/^[A-Za-z0-9_@.%-]{1,100}$/D',$user)||!preg_match('/^[A-Za-z0-9_.-]{3,80}$/D',$admin)||strlen($password)<12||strlen($password)>256)
    throw new DomainException('Check the MySQL connection and use a 12+ character admin password.',422);
  $settings=['db_host'=>$host,'db_port'=>$port,'db_name'=>$name,'db_user'=>$user,'db_pass'=>$pass];
  SetupConfiguration::withLock($storage,static function()use($storage,$settings,$admin,$password):void{
   if(SetupConfiguration::exists($storage))throw new RuntimeException('Configuration is already present. Setup will not replace it.');
   $pdo=new PDO('mysql:host='.$settings['db_host'].';port='.$settings['db_port'].';dbname='.$settings['db_name'].';charset=utf8mb4',
    $settings['db_user'],$settings['db_pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>8]);
   $credentials=['username'=>$admin,'password'=>$password];
   InstallationSetup::authorizeExistingAdministrator($pdo,$credentials);
   $prepared=SetupConfiguration::prepare($storage,$settings);
   try{
    InstallationSetup::run($pdo,'cloud',$prepared->token(),$prepared->configHmac(),$credentials,static function()use($prepared):void{$prepared->publish();});
   }finally{$prepared->discard();}
  });
  header('Location: login.php?installed=1',true,303);exit;
 }catch(Throwable $e){
  $error=$e instanceof DomainException?$e->getMessage():
   'Setup could not finish. Keep the same setup identity, database credentials and existing administrator password when retrying. Existing configuration and accounts are never replaced; check server setup logs or restore your original files if needed.';
  // Driver and filesystem messages can contain connection or SQL details. Keep
  // them out of the public form and avoid copying secrets into setup logs.
  error_log('Lucky installation failed ('.get_class($e).').');
 }
}
function esc(string $s):string{return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Lucky Games — First-time setup</title><link rel="stylesheet" href="public/style.css"><link rel="stylesheet" href="public/platform.css"></head><body><main class="platform-narrow"><h1>Install or resume Lucky Game Hub</h1><p>Connect your MySQL database to create the first administrator or finish an interrupted setup. For an existing database, enter its existing administrator credentials; setup never resets an account or replaces saved game settings. No API key is required.</p><?php if($error):?><p class="platform-error" role="alert"><?=esc($error)?></p><?php endif;?><?php if(!$blocked):?><form method="post" class="platform-form"><input type="hidden" name="csrf" value="<?=esc(csrf())?>"><label>MySQL host<input name="host" value="<?=esc(strval($_POST['host']??'localhost'))?>" required></label><label>Port<input name="port" type="number" value="<?=esc(strval($_POST['port']??3306))?>" min="1" max="65535" required></label><label>Database<input name="database" required value="<?=esc(strval($_POST['database']??''))?>"></label><label>Database user<input name="db_user" required value="<?=esc(strval($_POST['db_user']??''))?>"></label><label>Database password<input name="db_password" type="password" autocomplete="new-password"></label><hr><label>Administrator<input name="admin" maxlength="80" required autocomplete="username" value="<?=esc(strval($_POST['admin']??''))?>"></label><label>Administrator password (12+ characters)<input name="password" required type="password" minlength="12" maxlength="256" autocomplete="current-password"></label><button class="btn btn-primary">Install or resume setup</button></form><?php endif;?><p>Setup automatically locks once configuration is present. Keep the private setup identity when retrying, use HTTPS, and back up your database and storage directory. An existing corrupt configuration requires manual restoration, not a new installation.</p></main></body></html>
