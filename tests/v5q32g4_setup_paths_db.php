<?php
declare(strict_types=1);
/**
 * Real HTTP installers/upgrades and real HomeServer CLI entry points, unchanged
 * in isolated application copies. The dedicated CI MariaDB on port 3307 is NOT
 * the database used by other suites. Its deliberately long fixture password
 * satisfies the production HomeServer credential validation without a bypass.
 */
if(getenv('CI')!=='true')throw new RuntimeException('Dedicated CI MariaDB only.');
require_once __DIR__.'/../lib/SchemaMigrations.php';

const G4_SETUP_PASSWORD='lucky-schema-matrix-fixture-password-2026';
const G4_ADMIN_PASSWORD='fixture-administrator-password-2026';
$checks=0;
function setupCheck(bool $pass,string $message):void {
 global $checks;
 if(!$pass)throw new RuntimeException('FAIL '.$message);
 ++$checks;echo 'PASS '.$message."\n";
}
function setupCopy(string $source,string $target):void {
 if(!mkdir($target,0700,true)&&!is_dir($target))throw new RuntimeException('Cannot create fixture directory.');
 foreach(new DirectoryIterator($source) as $entry){
  if($entry->isDot())continue;
  if($entry->isLink())throw new RuntimeException('Fixture sources must not be symbolic links.');
  $to=$target.'/'.$entry->getFilename();
  if($entry->isDir())setupCopy($entry->getPathname(),$to);
  elseif(!copy($entry->getPathname(),$to))throw new RuntimeException('Cannot copy fixture source.');
 }
}
function setupRemove(string $path):void {
 if(is_link($path)||!is_dir($path)){if(file_exists($path)||is_link($path))unlink($path);return;}
 foreach(new DirectoryIterator($path) as $entry)if(!$entry->isDot())setupRemove($entry->getPathname());
 rmdir($path);
}
function setupApp(string $fixture,string $name):string {
 $app=$fixture.'/'.$name;mkdir($app,0700);
 foreach(['lib','api','homeserver'] as $directory)setupCopy(__DIR__.'/../'.$directory,$app.'/'.$directory);
 foreach(['install.php','upgrade.php','login.php','first-admin.php','release.json'] as $file)
  if(!copy(__DIR__.'/../'.$file,$app.'/'.$file))throw new RuntimeException('Cannot copy '.$file);
 mkdir($app.'/storage',0700);mkdir($app.'/sessions',0700);
 file_put_contents($app.'/ready.txt','ready');
 foreach(['install.php','upgrade.php','homeserver/initialize.php','homeserver/migrate.php','lib/SchemaMigrations.php'] as $file)
  setupCheck(hash_file('sha256',$app.'/'.$file)===hash_file('sha256',__DIR__.'/../'.$file),$name.' uses exact production '.$file);
 return $app;
}
function setupHttp(string $base,string $path,string $cookie='',?array $form=null):array {
 if(!preg_match('~^http://127\.0\.0\.1:[0-9]+$~D',$base)||!str_starts_with($path,'/'))throw new LogicException('Loopback fixture only.');
 $headers=['Connection: close'];
 if($cookie!=='')$headers[]='Cookie: '.$cookie;
 if($form!==null)$headers[]='Content-Type: application/x-www-form-urlencoded';
 $context=stream_context_create(['http'=>['method'=>$form===null?'GET':'POST','timeout'=>20,'ignore_errors'=>true,
  'follow_location'=>0,'header'=>$headers,'content'=>$form===null?'':http_build_query($form)]]);
 $body=file_get_contents($base.$path,false,$context);$lines=$http_response_header??[];
 if(!is_string($body)||!preg_match('~^HTTP/\S+ ([0-9]{3})~',$lines[0]??'',$match))throw new RuntimeException('No fixture HTTP response.');
 $result=['status'=>(int)$match[1],'body'=>$body,'headers'=>[]];
 foreach(array_slice($lines,1) as $line){
  if(!str_contains($line,':'))continue;
  [$key,$value]=explode(':',$line,2);$result['headers'][strtolower(trim($key))][]=trim($value);
 }
 return $result;
}
function setupCookie(array $response):string {
 foreach($response['headers']['set-cookie']??[] as $header)
  if(preg_match('/^(lucky_hub_sid=[A-Za-z0-9,-]+);/D',$header,$match))return $match[1];
 throw new RuntimeException('Missing real session cookie.');
}
function setupCsrf(array $response):string {
 if(!preg_match('/name="csrf" value="([a-f0-9]{64})"/D',$response['body'],$match))
  throw new RuntimeException('Missing real CSRF form: '.substr($response['body'],0,400));
 return $match[1];
}
function setupServer(string $app,array &$processes):string {
 $socket=stream_socket_server('tcp://127.0.0.1:0',$code,$message);
 if($socket===false)throw new RuntimeException('Cannot reserve fixture port.');
 $address=stream_socket_get_name($socket,false);fclose($socket);
 $environment=getenv();unset($environment['PHP_CLI_SERVER_WORKERS'],$environment['LUCKY_BOOTSTRAP']);
 $process=proc_open([PHP_BINARY,'-d','display_errors=0','-d','log_errors=1','-d','session.save_path='.$app.'/sessions','-S',$address,'-t',$app],
  [0=>['file','/dev/null','r'],1=>['file',$app.'/server.log','a'],2=>['file',$app.'/server.log','a']],$pipes,$app,$environment,['bypass_shell'=>true]);
 if(!is_resource($process))throw new RuntimeException('Cannot start fixture server.');
 $processes[]=$process;$base='http://'.$address;
 for($i=0;$i<50;$i++){
  if(!proc_get_status($process)['running'])throw new RuntimeException('Fixture server exited: '.file_get_contents($app.'/server.log'));
  if(@file_get_contents($base.'/ready.txt',false,stream_context_create(['http'=>['timeout'=>0.2]]))==='ready')return $base;
  usleep(100000);
 }
 throw new RuntimeException('Fixture server startup timed out.');
}
function setupCli(string $app,string $entry,bool $authorized=true):array {
 $environment=getenv();unset($environment['LUCKY_BOOTSTRAP']);
 if($authorized)$environment['LUCKY_BOOTSTRAP']='homeserver';
 $environment['LUCKY_DB_PORT']='3307';
 $environment['LUCKY_DB_ROOT_PASSWORD']=G4_SETUP_PASSWORD;
 $environment['LUCKY_DB_APP_PASSWORD']='lucky-local-application-fixture-password-2026';
 $log=$app.'/cli-'.bin2hex(random_bytes(5)).'.log';
 $process=proc_open([PHP_BINARY,'-d','display_errors=1','-d','log_errors=0',$app.'/'.$entry],
  [0=>['file','/dev/null','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,$app,$environment,['bypass_shell'=>true]);
 if(!is_resource($process))throw new RuntimeException('Cannot start real HomeServer CLI.');
 $until=microtime(true)+30;
 do{$state=proc_get_status($process);if(!$state['running'])break;usleep(50000);}while(microtime(true)<$until);
 if($state['running']){proc_terminate($process);proc_close($process);throw new RuntimeException('HomeServer CLI timeout.');}
 $code=$state['exitcode'];$closed=proc_close($process);if($code<0)$code=$closed;
 return ['code'=>$code,'output'=>(string)file_get_contents($log)];
}
function setupDb(string $name):PDO {
 if(!preg_match('/^(lucky_g4_[a-f0-9]+|lucky_games)$/D',$name))throw new LogicException('Invalid fixture database.');
 return new PDO('mysql:host=127.0.0.1;port=3307;dbname='.$name.';charset=utf8mb4','root',G4_SETUP_PASSWORD,
  [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
}
function setupSchema(PDO $db):array {
 return $db->query('SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,COLUMN_NAME')->fetchAll(PDO::FETCH_ASSOC);
}
function setupLegacy(PDO $db):void {
 // Only isolated fixture data is modified. These empty modern tables/columns
 // emulate the exact omissions found in older HomeServer upgrade paths.
 foreach(['director_show_channels','lucky_parties','lucky_themes','lucky_costumes','lucky_costume_actions'] as $table)
  $db->exec('DROP TABLE `'.$table.'`');
 foreach(['controller_proof_state'=>['phone_camera_enabled','capture_phase','consent_ack'],
  'claim_media'=>['angle_index','angle_label','source_label'],
  'director_broadcasts'=>['visibility'],
  'prize_packages'=>['image_url','budget_cents','phone_camera_enabled','publication_status']] as $table=>$columns)
  foreach($columns as $column)$db->exec('ALTER TABLE `'.$table.'` DROP COLUMN `'.$column.'`');
}
function setupLogin(string $base,string $username):string {
 $form=setupHttp($base,'/login.php');$anonymous=setupCookie($form);
 $login=setupHttp($base,'/login.php',$anonymous,['csrf'=>setupCsrf($form),'admin'=>$username,'password'=>G4_ADMIN_PASSWORD]);
 setupCheck($login['status']===303,'Existing administrator can log in through the real endpoint');
 return setupCookie($login);
}
function setupPreserved(PDO $db,string $app,array $expected,string $label):void {
 setupCheck($db->query('SELECT username,password_hash FROM admins ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)===$expected['admins'],$label.' preserves administrators and passwords');
 setupCheck($db->query('SELECT * FROM game_settings ORDER BY game_id')->fetchAll(PDO::FETCH_ASSOC)===$expected['settings'],$label.' preserves custom game settings');
 setupCheck(hash_file('sha256',$app.'/storage/config.php')===$expected['config'],$label.' preserves configuration credentials');
 setupCheck(file_get_contents($app.'/storage/existing-video-fixture.bin')==='existing-local-media',$label.' preserves existing media bytes');
}
function setupSnapshot(PDO $db,string $app):array {
 $db->exec("UPDATE game_settings SET theme_json='{\"custom\":\"preserve-me\"}' WHERE game_id='spin-wheel'");
 file_put_contents($app.'/storage/existing-video-fixture.bin','existing-local-media');
 return ['admins'=>$db->query('SELECT username,password_hash FROM admins ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
  'settings'=>$db->query('SELECT * FROM game_settings ORDER BY game_id')->fetchAll(PDO::FETCH_ASSOC),
  'config'=>hash_file('sha256',$app.'/storage/config.php')];
}

$root=new PDO('mysql:host=127.0.0.1;port=3307;charset=utf8mb4','root',G4_SETUP_PASSWORD,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
setupCheck((int)$root->query("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='lucky_schema_matrix'")->fetchColumn()===1,'Dedicated schema-matrix CI service is present');
setupCheck((int)$root->query("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='lucky_games'")->fetchColumn()===0,'HomeServer fixture database does not preexist');
setupCheck((int)$root->query("SELECT COUNT(*) FROM mysql.user WHERE User='lucky_app'")->fetchColumn()===0,'HomeServer fixture account does not preexist');
$fixture=sys_get_temp_dir().'/lucky-g4-setup-'.bin2hex(random_bytes(8));mkdir($fixture,0700);
$cloudName='lucky_g4_'.bin2hex(random_bytes(8));$databases=[];$homeTouched=false;$processes=[];
try{
 $root->exec('CREATE DATABASE `'.$cloudName.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$databases[]=$cloudName;
 $cloud=setupApp($fixture,'cloud');$cloudBase=setupServer($cloud,$processes);$db=setupDb($cloudName);
 $form=setupHttp($cloudBase,'/install.php');$cookie=setupCookie($form);
 $fields=['csrf'=>setupCsrf($form),'host'=>'127.0.0.1','port'=>3307,'database'=>$cloudName,
  'db_user'=>'root','db_password'=>G4_SETUP_PASSWORD,'admin'=>'cloud-fixture','password'=>G4_ADMIN_PASSWORD];
 $invalid=setupHttp($cloudBase,'/install.php',$cookie,array_replace($fields,['csrf'=>'invalid']));
 setupCheck(str_contains($invalid['body'],'Invalid form security token')&&setupSchema($db)===[],'Cloud first install rejects bad CSRF before DDL');
 $missing=$cloud.'/lib/migrations/v5q32e1_permanent_show_url.sql';rename($missing,$missing.'.fixture-missing');
 $failed=setupHttp($cloudBase,'/install.php',$cookie,$fields);
 setupCheck($failed['status']===200&&!is_file($cloud.'/storage/config.php')&&setupSchema($db)===[],'Missing late migration fails cloud setup before schema/admin/config writes');
 rename($missing.'.fixture-missing',$missing);
 $installed=setupHttp($cloudBase,'/install.php',$cookie,$fields);
 setupCheck($installed['status']===303&&is_file($cloud.'/storage/config.php'),'Cloud first install succeeds through real HTTP');
 SchemaMigrations::assertReady($db);$freshSchema=setupSchema($db);
 setupCheck(count($freshSchema)>100,'Fresh cloud schema includes the complete shared foundation');
 setupCheck((int)$db->query('SELECT COUNT(*) FROM admins')->fetchColumn()===1,'Cloud installer creates only its own administrator');
 setupCheck((int)$db->query('SELECT COUNT(*) FROM game_settings')->fetchColumn()===2,'Cloud installer retains two default game settings');
 setupCheck(setupHttp($cloudBase,'/install.php')['status']===302,'Completed cloud installer remains locked');

 $cloudSaved=setupSnapshot($db,$cloud);setupLegacy($db);$legacySchema=setupSchema($db);
 $anonymous=setupHttp($cloudBase,'/upgrade.php','',['csrf'=>'invalid']);
 setupCheck($anonymous['status']===302&&setupSchema($db)===$legacySchema,'Anonymous HTTP upgrade cannot mutate the database');
 $signed=setupLogin($cloudBase,'cloud-fixture');$upgradeForm=setupHttp($cloudBase,'/upgrade.php',$signed);
 setupCheck(setupSchema($db)===$legacySchema,'Opening the upgrade page does not run migrations');
 $denied=setupHttp($cloudBase,'/upgrade.php',$signed,['csrf'=>'invalid']);
 setupCheck(str_contains($denied['body'],'Invalid security token')&&setupSchema($db)===$legacySchema,'Authenticated upgrade still requires valid CSRF');
 for($run=1;$run<=2;$run++){
  $upgraded=setupHttp($cloudBase,'/upgrade.php',$signed,['csrf'=>setupCsrf($upgradeForm)]);
  setupCheck($upgraded['status']===200&&str_contains($upgraded['body'],'Database upgrade complete'),'Cloud upgrade succeeds, run '.$run);
  SchemaMigrations::assertReady($db);setupCheck(setupSchema($db)===$freshSchema,'Cloud upgrade matches fresh schema, run '.$run);
  setupPreserved($db,$cloud,$cloudSaved,'Cloud upgrade '.$run);
 }
 setupCheck((int)$db->query("SELECT COUNT(*) FROM audit_log WHERE event='platform.upgrade-v5q3'")->fetchColumn()===2,'Both authorized cloud upgrades retain audit attribution');

 $home=setupApp($fixture,'home');$homeBase=setupServer($home,$processes);
 foreach(['homeserver/initialize.php','homeserver/migrate.php'] as $entry){
  $denied=setupCli($home,$entry,false);setupCheck($denied['code']===3,'HomeServer bootstrap guard rejects unapproved CLI '.$entry);
  $denied=setupHttp($homeBase,'/'.$entry);setupCheck($denied['status']>=400,'HomeServer CLI entry point is not usable over HTTP: '.$entry);
 }
 $missing=$home.'/lib/migrations/v5q32e1_permanent_show_url.sql';rename($missing,$missing.'.fixture-missing');$homeTouched=true;
 // GitHub service-container traffic arrives from a Docker bridge, not the
 // server's own 127.0.0.1. This fixture-only grant adapts that network boundary.
 // The unchanged initializer still creates its loopback-only production user;
 // the separate Windows installer job tests that real native loopback grant.
 $root->exec("CREATE USER 'lucky_app'@'%' IDENTIFIED BY 'lucky-local-application-fixture-password-2026'");
 $root->exec("GRANT ALL PRIVILEGES ON lucky_games.* TO 'lucky_app'@'%'");
 $failed=setupCli($home,'homeserver/initialize.php');
 setupCheck($failed['code']!==0&&!is_file($home.'/storage/config.php'),'Missing late migration does not publish HomeServer configuration');
 $homeDb=setupDb('lucky_games');setupCheck(setupSchema($homeDb)===[],'HomeServer preflight fails before partial schema creation');
 rename($missing.'.fixture-missing',$missing);
 $initialized=setupCli($home,'homeserver/initialize.php');
 setupCheck($initialized['code']===0&&is_file($home.'/storage/config.php'),'Fresh HomeServer initializes through real CLI: '.$initialized['output']);
 SchemaMigrations::assertReady($homeDb);setupCheck(setupSchema($homeDb)===$freshSchema,'Fresh HomeServer and cloud have identical shared columns and defaults');
 $homeConfig=require $home.'/storage/config.php';
 setupCheck($homeConfig['deployment']==='homeserver'&&$homeConfig['db_host']==='127.0.0.1'&&$homeConfig['db_user']==='lucky_app','HomeServer preserves independent loopback application credentials');
 setupCheck((int)$homeDb->query('SELECT COUNT(*) FROM admins')->fetchColumn()===0,'HomeServer does not import cloud administrators');
 $first=setupHttp($homeBase,'/first-admin.php');
 $created=setupHttp($homeBase,'/first-admin.php',setupCookie($first),['csrf'=>setupCsrf($first),'username'=>'home-fixture','password'=>G4_ADMIN_PASSWORD]);
 setupCheck($created['status']===303,'Local HomeServer first administrator remains separate');
 $homeSaved=setupSnapshot($homeDb,$home);setupLegacy($homeDb);$homeLegacy=setupSchema($homeDb);
 $already=setupCli($home,'homeserver/initialize.php');
 setupCheck($already['code']===0&&setupSchema($homeDb)===$homeLegacy,'Existing HomeServer initialization remains non-destructive and leaves upgrades to migrate.php');
 for($run=1;$run<=2;$run++){
  $upgraded=setupCli($home,'homeserver/migrate.php');
  setupCheck($upgraded['code']===0&&str_contains($upgraded['output'],'HomeServer schema current.'),'Existing HomeServer upgrade succeeds, run '.$run.': '.$upgraded['output']);
  SchemaMigrations::assertReady($homeDb);setupCheck(setupSchema($homeDb)===$freshSchema,'HomeServer upgrade matches fresh schema, run '.$run);
  setupPreserved($homeDb,$home,$homeSaved,'HomeServer upgrade '.$run);
 }
 setupPreserved($db,$cloud,$cloudSaved,'HomeServer operations leave educational installation unchanged');
 setupCheck($homeDb->query('SELECT username FROM admins')->fetchColumn()==='home-fixture'&&$db->query('SELECT username FROM admins')->fetchColumn()==='cloud-fixture','Products retain independent customer/admin accounts');
 echo 'G4 REAL SETUP MATRIX PASSED: '.$checks." checks.\n";
}finally{
 foreach($processes as $process){if(proc_get_status($process)['running'])proc_terminate($process);proc_close($process);}
 foreach($databases as $name)$root->exec('DROP DATABASE `'.$name.'`');
 if($homeTouched){$root->exec('DROP DATABASE IF EXISTS lucky_games');$root->exec("DROP USER IF EXISTS 'lucky_app'@'127.0.0.1'");$root->exec("DROP USER IF EXISTS 'lucky_app'@'%'");}
 setupRemove($fixture);
}
