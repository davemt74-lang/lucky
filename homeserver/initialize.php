<?php
declare(strict_types=1);
/**
 * Local-only first-boot database setup, executed from the bundled PHP CLI.
 * Never expose this command via the web server.
 */
if (PHP_SAPI!=='cli' || getenv('LUCKY_BOOTSTRAP')!=='homeserver')
    {if(PHP_SAPI==='cli')fwrite(STDERR,"Local HomeServer CLI only.\n");else http_response_code(403);exit(3);}
require_once __DIR__.'/../lib/InstallationSetup.php';
require_once __DIR__.'/../lib/SetupConfiguration.php';
try{
$home=realpath(__DIR__.'/..');
if(!$home)throw new RuntimeException('Application directory not found.');
$host='127.0.0.1';
$port=(int)(getenv('LUCKY_DB_PORT')?:3307);
$rootPassword=(string)getenv('LUCKY_DB_ROOT_PASSWORD');
$appPassword=(string)getenv('LUCKY_DB_APP_PASSWORD');
if($port<1||$port>65535||strlen($rootPassword)<24||strlen($appPassword)<24)
    throw new RuntimeException('Missing strong local database credentials.');
$storage=$home.'/storage';
if(!is_dir($storage))throw new RuntimeException('Persistent data storage not mounted.');
SetupConfiguration::withLock($storage,static function()use($storage,$host,$port,$rootPassword,$appPassword):void{
 $config=$storage.'/config.php';
 if(SetupConfiguration::exists($storage)){
  if(!is_file($config)||is_link($config))throw new RuntimeException('Configuration is already present at a non-regular path. Restore the original configuration manually; initialization will not replace it.');
  // Do not execute or rewrite an existing configuration here. The launcher's
  // migration/readiness step validates the configured application separately.
  echo "HomeServer already initialized.\n";return;
 }
 $root=new PDO('mysql:host='.$host.';port='.$port.';charset=utf8mb4','root',$rootPassword,
  [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>8]);
 $root->exec("CREATE DATABASE IF NOT EXISTS lucky_games CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
 $secret=$root->quote($appPassword);
 $root->exec("CREATE USER IF NOT EXISTS 'lucky_app'@'127.0.0.1' IDENTIFIED BY ".$secret);
 // Never rotate a pre-existing local account during recovery. A missing/wrong
 // stored secret must fail authentication, not silently change database access.
 $db=new PDO('mysql:host='.$host.';port='.$port.';charset=utf8mb4','lucky_app',$appPassword,
  [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>8]);
 // Prove the existing app secret before modifying even its database grants.
 $root->exec("GRANT ALL PRIVILEGES ON lucky_games.* TO 'lucky_app'@'127.0.0.1'");
 $root->exec("FLUSH PRIVILEGES");
 unset($root);
 $db->exec('USE lucky_games');
 $cfg=['deployment'=>'homeserver','db_host'=>'127.0.0.1','db_port'=>$port,'db_name'=>'lucky_games',
  'db_user'=>'lucky_app','db_pass'=>$appPassword];
 $prepared=SetupConfiguration::prepare($storage,$cfg);
 try{
  InstallationSetup::run($db,'homeserver',$prepared->token(),$prepared->configHmac(),null,static function()use($prepared):void{$prepared->publish();});
 }finally{$prepared->discard();}
 echo "Local game database initialized. Create your first admin in the local browser.\n";
});
}catch(Throwable $e){
 // Never print an uncaught PDO/IO trace containing private local credentials.
 error_log('Lucky HomeServer initialization failed ('.get_class($e).').');
 fwrite(STDERR,"HomeServer setup could not finish. Preserve the existing local database, secrets and setup identity; restore the matching files or retry with the same credentials. Existing configuration and administrator accounts are not reset.\n");
 exit(1);
}
