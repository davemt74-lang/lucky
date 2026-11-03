<?php
declare(strict_types=1);
/**
 * Local-only first-boot database setup, executed from the bundled PHP CLI.
 * Never expose this command via the web server.
 */
if (PHP_SAPI!=='cli' || getenv('LUCKY_BOOTSTRAP')!=='homeserver')
    {fwrite(STDERR,"Local HomeServer CLI only.\n");exit(3);}
require_once __DIR__.'/../lib/SchemaMigrations.php';
require_once __DIR__.'/../lib/GameEngine.php';
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
$config=$storage.'/config.php';
if(is_file($config)){echo "HomeServer already initialized.\n";exit(0);}
$root=new PDO('mysql:host='.$host.';port='.$port.';charset=utf8mb4','root',$rootPassword,
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$root->exec("CREATE DATABASE IF NOT EXISTS lucky_games CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$secret=$root->quote($appPassword);
$root->exec("CREATE USER IF NOT EXISTS 'lucky_app'@'127.0.0.1' IDENTIFIED BY ".$secret);
$root->exec("ALTER USER 'lucky_app'@'127.0.0.1' IDENTIFIED BY ".$secret);
$root->exec("GRANT ALL PRIVILEGES ON lucky_games.* TO 'lucky_app'@'127.0.0.1'");
$root->exec("FLUSH PRIVILEGES");
unset($root);
$db=new PDO('mysql:host='.$host.';port='.$port.';dbname=lucky_games;charset=utf8mb4','lucky_app',$appPassword,
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
SchemaMigrations::ensure($db);
$q=$db->prepare('INSERT IGNORE INTO game_settings(game_id,config_json,intro_json,theme_json) VALUES (?,?,?,?)');
$q->execute(['spin-wheel',json_encode(GameEngine::defaults(),JSON_THROW_ON_ERROR),
    json_encode(['title'=>'Spin the Wheel','subtitle'=>'Let the prizes begin!','trackId'=>'','volume'=>70,'announcement'=>true],JSON_THROW_ON_ERROR),
    json_encode(['accent'=>'#f6cb74','background'=>'#0d1423'],JSON_THROW_ON_ERROR)]);
$q->execute(['game-2','{"pools":[]}',
    json_encode(['title'=>'Game 2','subtitle'=>'Coming soon','trackId'=>'','volume'=>70,'announcement'=>true],JSON_THROW_ON_ERROR),
    json_encode(['accent'=>'#91b4ff','background'=>'#0d1423'],JSON_THROW_ON_ERROR)]);
$cfg=['deployment'=>'homeserver','db_host'=>'127.0.0.1','db_port'=>$port,'db_name'=>'lucky_games',
    'db_user'=>'lucky_app','db_pass'=>$appPassword];
$tmp=$storage.'/config-'.bin2hex(random_bytes(8)).'.tmp';
if(file_put_contents($tmp,'<?php return '.var_export($cfg,true).';',LOCK_EX)===false || !rename($tmp,$config))
    throw new RuntimeException('Cannot write persistent HomeServer configuration.');
echo "Local game database initialized. Create your first admin in the local browser.\n";
