<?php
declare(strict_types=1);
require __DIR__.'/lib/Bootstrap.php';
require_once __DIR__.'/lib/MigrationSql.php';
headerSecurity();
if(installed()){header('Location: index.php',true,302);exit;}
$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 try {
  if(!extension_loaded('pdo_mysql'))throw new RuntimeException('Host requires the PHP PDO MySQL extension.');
  if(!hash_equals(csrf(),strval($_POST['csrf']??'')))throw new RuntimeException('Invalid form security token.');
  $host=trim(strval($_POST['host']??''));$port=(int)($_POST['port']??3306);$name=trim(strval($_POST['database']??''));
  $user=trim(strval($_POST['db_user']??''));$pass=strval($_POST['db_password']??'');
  $admin=trim(strval($_POST['admin']??''));$password=strval($_POST['password']??'');
  if(!preg_match('/^[A-Za-z0-9._-]{1,150}$/D',$host) || $port<1||$port>65535 || !preg_match('/^[A-Za-z0-9_]{1,64}$/D',$name)||
    !preg_match('/^[A-Za-z0-9_@.%-]{1,100}$/D',$user)||!preg_match('/^[A-Za-z0-9_.-]{3,80}$/D',$admin)||strlen($password)<12||strlen($password)>256)
    throw new RuntimeException('Check the MySQL connection and use a 12+ character admin password.');
  $lock=fopen(__DIR__.'/storage/installer.lock','c');if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Unable to lock the installation directory.');
  if(installed())throw new RuntimeException('The installation is already complete.');
  $pdo=new PDO('mysql:host='.$host.';port='.$port.';dbname='.$name.';charset=utf8mb4',$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
  $schema=file_get_contents(__DIR__.'/lib/schema.sql');
  if($schema===false)throw new RuntimeException('Bundled database schema is missing.');
  foreach(MigrationSql::statements($schema) as $statement)$pdo->exec($statement);
  // Fresh installs use the same comment-safe SQL parser as upgrades.
  foreach([
   'lib/migrations/v5q_claim_media.sql',
   'lib/migrations/v5q3_renders.sql',
   'lib/migrations/v5q6_proof_revisions.sql',
   'lib/migrations/v5q15b_prize_decisions.sql',
   'lib/migrations/v5q29e1_recording_sessions.sql',
   'lib/migrations/v5q29e2_phone_camera.sql',
   'lib/migrations/v5q29e3_multicamera_coordination.sql',
   'lib/migrations/v5q29e4a_multicamera_timeline.sql',
   'lib/migrations/v5q29e4b_multicamera_exports.sql',
   'lib/migrations/v5q29e4c_recording_profiles.sql'
  ] as $migrationFile){
   $migration=file_get_contents(__DIR__.'/'.$migrationFile);
   if($migration===false)throw new RuntimeException('Bundled migration is missing: '.$migrationFile);
   foreach(MigrationSql::statements($migration) as $statement)$pdo->exec($statement);
  }
  $q=$pdo->prepare('INSERT INTO admins(username,password_hash) VALUES (?,?)');
  $existing=(int)$pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn();
  if($existing!==0)throw new RuntimeException('Existing administrators detected. Use a clean database for first-time setup.');
  $q->execute([$admin,password_hash($password,PASSWORD_DEFAULT)]);
  $default=GameEngine::defaults();
  $q=$pdo->prepare('INSERT INTO game_settings(game_id,config_json,intro_json,theme_json) VALUES (?,?,?,?)');
  $q->execute(['spin-wheel',json_encode($default),json_encode(['title'=>'Spin the Wheel','subtitle'=>'Let the prizes begin!','trackId'=>'','volume'=>70,'announcement'=>true]),json_encode(['accent'=>'#f6cb74','background'=>'#0d1423'])]);
  $q->execute(['game-2',json_encode(['pools'=>[]]),json_encode(['title'=>'Game 2','subtitle'=>'Coming soon','trackId'=>'','volume'=>70,'announcement'=>true]),json_encode(['accent'=>'#91b4ff','background'=>'#0d1423'])]);
  $settings=['db_host'=>$host,'db_port'=>$port,'db_name'=>$name,'db_user'=>$user,'db_pass'=>$pass];
  $file='<?php return '.var_export($settings,true).';';
  $tmp=__DIR__.'/storage/.config-'.bin2hex(random_bytes(8));
  if(file_put_contents($tmp,$file,LOCK_EX)===false)throw new RuntimeException('Could not write configuration. Check storage folder permissions.');
  @chmod($tmp,0600);if(!rename($tmp,__DIR__.'/storage/config.php'))throw new RuntimeException('Unable to activate the configuration.');
  flock($lock,LOCK_UN);fclose($lock);
  header('Location: login.php?installed=1',true,303);exit;
 }catch(Throwable $e){$error=$e->getMessage();error_log('Lucky install: '.get_class($e).' '.$e->getMessage());}
}
function esc(string $s):string{return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Lucky Games — First-time setup</title><link rel="stylesheet" href="public/style.css"><link rel="stylesheet" href="public/platform.css"></head><body><main class="platform-narrow"><h1>Install Lucky Game Hub</h1><p>Connect an empty MySQL database and create the platform's first administrator. No API key is required.</p><?php if($error):?><p class="platform-error" role="alert"><?=esc($error)?></p><?php endif;?><form method="post" class="platform-form"><input type="hidden" name="csrf" value="<?=esc(csrf())?>"><label>MySQL host<input name="host" value="<?=esc(strval($_POST['host']??'localhost'))?>" required></label><label>Port<input name="port" type="number" value="3306" min="1" max="65535" required></label><label>Database<input name="database" required value="<?=esc(strval($_POST['database']??''))?>"></label><label>Database user<input name="db_user" required value="<?=esc(strval($_POST['db_user']??''))?>"></label><label>Database password<input name="db_password" type="password" autocomplete="new-password"></label><hr><label>First administrator<input name="admin" maxlength="80" required autocomplete="username"></label><label>Administrator password (12+ characters)<input name="password" type="password" minlength="12" maxlength="256" required autocomplete="new-password"></label><button class="btn btn-primary">Install platform</button></form><p>Setup automatically locks once installed. Use HTTPS and keep a backup of your database and storage directory.</p></main></body></html>
