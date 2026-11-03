<?php
declare(strict_types=1);
/** Actual local-only initializer, credentials, transactions and crash recovery. */
require_once __DIR__.'/fixtures/install_recovery_harness.php';
require_once __DIR__.'/../lib/SchemaMigrations.php';
require_once __DIR__.'/../lib/SetupConfiguration.php';
function homeG6Accounts(PDO $root):array {
 return $root->query("SELECT User,Host,plugin,authentication_string FROM mysql.user WHERE User='lucky_app' ORDER BY Host")->fetchAll(PDO::FETCH_ASSOC);
}
function homeG6Prepare(PDO $root,bool &$owned):PDO {
 // These fixed names are required by the real production HomeServer command.
 // The suite first proves they do not preexist on its dedicated CI service.
 // Each later reset is only of those names this invocation created itself.
 if($owned){$root->exec('DROP DATABASE IF EXISTS lucky_games');$root->exec("DROP USER IF EXISTS 'lucky_app'@'127.0.0.1'");$root->exec("DROP USER IF EXISTS 'lucky_app'@'%'");}
 $owned=true;$root->exec('CREATE DATABASE lucky_games CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
 // Docker's service bridge does not appear as 127.0.0.1 to MariaDB. This extra
 // fixture-only principal adapts transport, not the unchanged initializer.
 // Windows installer CI separately certifies the genuine loopback principal.
 $root->exec("CREATE USER 'lucky_app'@'%' IDENTIFIED BY '".G6_APP_PASSWORD."'");
 $root->exec("GRANT ALL PRIVILEGES ON lucky_games.* TO 'lucky_app'@'%'");
 $unused=[];$db=g6Db($root,$unused,'lucky_games');SchemaMigrations::ensure($db);return $db;
}
function homeG6NoSecrets(array $result,string $label):void {
 g6Check(!str_contains($result['output'],G6_DB_PASSWORD)&&!str_contains($result['output'],G6_APP_PASSWORD)&&!str_contains($result['output'],G6_ADMIN_PASSWORD),$label.' output contains no database/admin password');
}
function homeG6FirstAdmin(array $server,string $username):void {
 $form=g6Http($server['base'],'/first-admin.php');
 g6Check($form['status']===200,'Local first-administrator setup remains available after initialization');
 $created=g6Http($server['base'],'/first-admin.php',g6Cookie($form),['csrf'=>g6Csrf($form),'username'=>$username,'password'=>G6_ADMIN_PASSWORD]);
 g6Check($created['status']===303,'The unchanged local first-admin endpoint creates the local-only owner');g6Login($server,$username);
}
$root=g6Root();
g6Check((int)$root->query("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='lucky_games'")->fetchColumn()===0,'No preexisting HomeServer database will be touched');
g6Check(homeG6Accounts($root)===[],'No preexisting HomeServer account will be touched');
$fixture=g6Fixture('home-recovery');$databases=[];$homeOwned=false;$held=[];
try{
 // A real, separate cloud product remains active during every local recovery.
 $cloudDb=g6Db($root,$databases);$cloud=g6App($fixture,'independent-cloud');$cloudServer=g6Server($cloud);[$cloudCookie,$cloudFields]=g6Form($cloudServer,$cloudDb,'cloud-owner');
 g6Check(g6Http($cloudServer['base'],'/install.php',$cloudCookie,$cloudFields)['status']===303,'Independent cloud fixture is installed through its real HTTP entrypoint');
 $cloudDb->exec("UPDATE game_settings SET theme_json='{\"product\":\"cloud-only\"}' WHERE game_id='spin-wheel'");file_put_contents($cloud.'/storage/cloud-video.bin','cloud-only-media');
 $cloudRows=g6Rows($cloudDb);$cloudConfig=file_get_contents($cloud.'/storage/config.php');g6Stop($cloudServer);

 $home=g6App($fixture,'transaction-retry');$homeServer=g6Server($home);
 $guarded=g6Cli($home,['LUCKY_BOOTSTRAP'=>'']);g6Check($guarded['code']===3,'Unapproved local CLI remains blocked');
 g6Check(g6Http($homeServer['base'],'/homeserver/initialize.php')['status']===403,'HomeServer initializer remains unavailable over HTTP');
 $wrong=g6Cli($home,['LUCKY_DB_ROOT_PASSWORD'=>'incorrect-root-password-long-enough']);
 g6Check($wrong['code']!==0&&!is_file($home.'/storage/config.php')&&!is_file($home.'/storage/.setup-attempt.json')&&(int)$root->query("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='lucky_games'")->fetchColumn()===0,'Wrong initial root credentials cannot create a database, active config or bound setup identity');homeG6NoSecrets($wrong,'Root-authentication rejection');

 $db=homeG6Prepare($root,$homeOwned);g6SeedFailure($db);
 SetupConfiguration::withLock($home.'/storage',static function():void{});
 try{
  chmod($home.'/storage',0500);clearstatcache(true,$home.'/storage');g6Check(!is_writable($home.'/storage'),'HomeServer fixture really denies setup/config file creation');
  $failed=g6Cli($home);g6Check($failed['code']!==0&&!is_file($home.'/storage/config.php'),'Real local configuration staging write failure exits unsuccessfully');g6Empty($db,'HomeServer staging write failure');homeG6NoSecrets($failed,'Staging failure');
 }finally{chmod($home.'/storage',0700);clearstatcache(true,$home.'/storage');}
 $failed=g6Cli($home);g6Check($failed['code']!==0&&!is_file($home.'/storage/config.php'),'Actual HomeServer second-seed exception cannot activate configuration');g6Empty($db,'HomeServer second-seed failure');homeG6NoSecrets($failed,'Seed failure');
 $attempt=g6Attempt($home);$accounts=homeG6Accounts($root);$empty=g6Rows($db);
 foreach(['LUCKY_DB_ROOT_PASSWORD','LUCKY_DB_APP_PASSWORD'] as $key){
  $denied=g6Cli($home,[$key=>'incorrect-stored-secret-long-enough']);
  g6Check($denied['code']!==0&&homeG6Accounts($root)===$accounts&&g6Rows($db)===$empty&&!is_file($home.'/storage/config.php')&&file_get_contents($home.'/storage/.setup-attempt.json')===$attempt['bytes'],'Wrong existing '.$key.' neither rotates local credentials nor changes setup state');homeG6NoSecrets($denied,$key.' rejection');
 }
 $db->exec('DROP TRIGGER g6_seed_fault');$initialized=g6Cli($home);homeG6NoSecrets($initialized,'Successful initialization');
 g6Check($initialized['code']===0&&is_file($home.'/storage/config.php'),'Matching stored credentials complete the real HomeServer retry');g6Receipt($db,'published','homeserver');
 g6Check((int)$db->query('SELECT COUNT(*) FROM admins')->fetchColumn()===0&&(int)$db->query('SELECT COUNT(*) FROM game_settings')->fetchColumn()===2,'Local initializer seeds defaults without importing or creating an administrator');
 $configuration=require $home.'/storage/config.php';
 g6Check($configuration['deployment']==='homeserver'&&$configuration['db_host']==='127.0.0.1'&&$configuration['db_name']==='lucky_games'&&$configuration['db_user']==='lucky_app'&&$configuration['db_pass']===G6_APP_PASSWORD,'Published HomeServer configuration retains independent local application credentials');
 g6Check((fileperms($home.'/storage/config.php')&0077)===0,'Local active configuration is not group/world readable');homeG6FirstAdmin($homeServer,'home-owner');
 $db->exec("UPDATE game_settings SET theme_json='{\"product\":\"local-only\"}' WHERE game_id='spin-wheel'");
 $db->exec("INSERT INTO game_settings(game_id,config_json,intro_json,theme_json) VALUES ('local-custom-game','{}','{}','{}')");
 file_put_contents($home.'/storage/local-video.bin','local-only-media');$saved=g6Rows($db);$savedConfig=file_get_contents($home.'/storage/config.php');$accounts=homeG6Accounts($root);
 $noOp=g6Cli($home,['LUCKY_DB_ROOT_PASSWORD'=>'irrelevant-strong-secret-on-no-op','LUCKY_DB_APP_PASSWORD'=>'irrelevant-strong-secret-on-no-op']);
 g6Check($noOp['code']===0&&g6Rows($db)===$saved&&homeG6Accounts($root)===$accounts&&file_get_contents($home.'/storage/config.php')===$savedConfig,'An already configured HomeServer remains a non-destructive no-op without credential rotation');

 // A lost active file is not an account reset: matching retained identity is
 // necessary, and both the administrator and custom/local data must survive.
 unlink($home.'/storage/config.php');$foreign=g6App($fixture,'foreign-local-identity');$foreignResult=g6Cli($foreign);
 g6Check($foreignResult['code']!==0&&!is_file($foreign.'/storage/config.php')&&g6Rows($db)===$saved&&homeG6Accounts($root)===$accounts,'A different local setup identity cannot claim the existing HomeServer database');
 $foreignCloud=g6App($fixture,'foreign-cloud-identity');$foreignServer=g6Server($foreignCloud);[$foreignCookie,$foreignFields]=g6Form($foreignServer,$db,'home-owner');
 $wrongProduct=g6Http($foreignServer['base'],'/install.php',$foreignCookie,$foreignFields);
 g6Check($wrongProduct['status']===200&&!is_file($foreignCloud.'/storage/config.php')&&g6Rows($db)===$saved,'Correct owner credentials cannot repurpose a HomeServer receipt as a cloud installation');g6Stop($foreignServer);
 foreach(['LUCKY_DB_ROOT_PASSWORD','LUCKY_DB_APP_PASSWORD'] as $key){$wrong=g6Cli($home,[$key=>'incorrect-recovery-secret-long-enough']);g6Check($wrong['code']!==0&&g6Rows($db)===$saved&&homeG6Accounts($root)===$accounts&&!is_file($home.'/storage/config.php'),'Lost-config recovery rejects wrong '.$key.' without resetting the local owner or password');}
 $restored=g6Cli($home);
 g6Check($restored['code']===0&&g6Rows($db)===$saved&&homeG6Accounts($root)===$accounts&&file_get_contents($home.'/storage/config.php')===$savedConfig&&file_get_contents($home.'/storage/local-video.bin')==='local-only-media'&&file_get_contents($home.'/storage/.setup-attempt.json')===$attempt['bytes'],'Correct lost-config recovery preserves exact administrators, custom defaults, media, secrets and local identity');
 g6Login($homeServer,'home-owner');
 $marker=$home.'/config-executed';$corrupt='<?php file_put_contents('.var_export($marker,true).',"executed"); return ["corrupt"=>true];';
 file_put_contents($home.'/storage/config.php',$corrupt);$closed=g6Cli($home);
 g6Check($closed['code']===0&&!file_exists($marker)&&file_get_contents($home.'/storage/config.php')===$corrupt&&g6Rows($db)===$saved,'Existing corrupt regular configuration remains locked and unexecuted; initialization does not silently reset it');
 file_put_contents($home.'/storage/config.php',$savedConfig);g6Stop($homeServer);

 // Remaining scenarios intentionally use a new generation of the fixture-owned
 // fixed local DB; the cloud DB above is separate and is never reset.
 $db=homeG6Prepare($root,$homeOwned);$gate=g6SeedGate($db,$root);$held[]=$gate;
 $conflict=g6App($fixture,'publish-conflict');$task=g6CliStart($conflict);g6WaitDbBlocked($root,'lucky_games');g6Empty($db,'Local uncommitted seed before publication race');
 mkdir($conflict.'/storage/config.php',0700);g6Unlock($root,$gate);$failed=g6Wait($task);$db->exec('DROP TRIGGER g6_seed_gate');
 g6Check($failed['code']!==0&&is_dir($conflict.'/storage/config.php'),'HomeServer refuses a real config-path collision created after preflight');homeG6NoSecrets($failed,'Local publication collision');
 g6Receipt($db,'database_ready','homeserver');$pending=g6Rows($db);$pendingAttempt=g6Attempt($conflict);$pendingAccounts=homeG6Accounts($root);
 g6Check($pending['admins']===[]&&count($pending['game_settings'])===2,'Local failed publication retains a complete default transaction and no imported admin');
 rmdir($conflict.'/storage/config.php');$wrong=g6Cli($conflict,['LUCKY_DB_APP_PASSWORD'=>'incorrect-pending-secret-long-enough']);
 g6Check($wrong['code']!==0&&!is_file($conflict.'/storage/config.php')&&g6Rows($db)===$pending&&homeG6Accounts($root)===$pendingAccounts,'Wrong local app secret cannot finish pending publication or rotate the existing account');
 $done=g6Cli($conflict);g6Check($done['code']===0&&g6Rows($db)['game_settings']===$pending['game_settings']&&file_get_contents($conflict.'/storage/.setup-attempt.json')===$pendingAttempt['bytes'],'Matching local identity finishes publication without rewriting saved defaults');g6Receipt($db,'published','homeserver');

 $db=homeG6Prepare($root,$homeOwned);$gate=g6SeedGate($db,$root);$held[]=$gate;$crash=g6App($fixture,'crash-before-commit');
 $task=g6CliStart($crash);$ids=g6WaitDbBlocked($root,'lucky_games');$crashAttempt=g6Attempt($crash);g6Stop($task,true);g6Unlock($root,$gate);g6WaitDbGone($root,$ids);$db->exec('DROP TRIGGER g6_seed_gate');
 g6Empty($db,'Actual local CLI death before commit');g6Check(!is_file($crash.'/storage/config.php'),'Killed local initializer does not activate uncommitted configuration');
 $done=g6Cli($crash);g6Check($done['code']===0&&file_get_contents($crash.'/storage/.setup-attempt.json')===$crashAttempt['bytes'],'Restarted production local CLI reuses its attempt after database rollback');g6Receipt($db,'published','homeserver');

 foreach(['before','after'] as $boundary){
  $db=homeG6Prepare($root,$homeOwned);$boundaryApp=g6App($fixture,'crash-'.$boundary.'-publish');$pause=g6PausePublication($boundaryApp,$boundary);$task=g6CliStart($boundaryApp);g6Marker($pause['marker']);
  g6Receipt($db,'database_ready','homeserver');$boundaryRows=g6Rows($db);$boundaryAttempt=g6Attempt($boundaryApp);$boundaryAccounts=homeG6Accounts($root);
  g6Check($boundaryRows['admins']===[]&&count($boundaryRows['game_settings'])===2&&is_file($boundaryApp.'/storage/config.php')===($boundary==='after'),'Local '.$boundary.'-publication window has the exact expected committed/default/config state');
  $activeBytes=$boundary==='after'?file_get_contents($boundaryApp.'/storage/config.php'):null;g6Stop($task,true);g6RestorePublication($pause);$retry=g6Cli($boundaryApp);
  g6Check($retry['code']===0&&g6Rows($db)['game_settings']===$boundaryRows['game_settings']&&homeG6Accounts($root)===$boundaryAccounts&&file_get_contents($boundaryApp.'/storage/.setup-attempt.json')===$boundaryAttempt['bytes'],'Real local retry after '.$boundary.'-publication crash preserves credentials, identity and committed settings');
  if($boundary==='before')g6Receipt($db,'published','homeserver');
  else g6Check(file_get_contents($boundaryApp.'/storage/config.php')===$activeBytes&&g6Rows($db)===$boundaryRows,'An activated local config remains untouched after a crash before receipt-finalization');
  $boundaryServer=g6Server($boundaryApp);homeG6FirstAdmin($boundaryServer,'owner-'.$boundary);g6Stop($boundaryServer);
 }

 // Separate local installation directories share no filesystem lock. Both CLI
 // processes must contend at the actual database lock, not merely run in series.
 $db=homeG6Prepare($root,$homeOwned);$parallel=[];
 foreach(['a','b'] as $suffix)$parallel[]=['app'=>g6App($fixture,'parallel-'.$suffix)];
 $setupLock='lucky-setup-'.substr(hash('sha256','lucky_games'),0,48);$q=$root->prepare('SELECT GET_LOCK(?,0)');$q->execute([$setupLock]);g6Check((int)$q->fetchColumn()===1,'Local concurrency fixture owns the real setup gate');$held[]=$setupLock;
 foreach($parallel as &$candidate)$candidate['task']=g6CliStart($candidate['app']);unset($candidate);
 g6WaitDbBlocked($root,'lucky_games',2);g6Check(true,'Two independent local initializers are observed at the database gate concurrently');g6Unlock($root,$setupLock);
 $codes=[];foreach($parallel as $index=>$candidate){$result=g6Wait($candidate['task']);$codes[$index]=$result['code'];homeG6NoSecrets($result,'Concurrent local initializer');}
 $sorted=$codes;sort($sorted);g6Check($sorted===[0,1],'Only one of two competing local setup identities can initialize the shared database');
 $winner=array_search(0,$codes,true);$loser=1-$winner;$parallelReceipt=g6Receipt($db,'published','homeserver');$finalRows=g6Rows($db);$finalAccounts=homeG6Accounts($root);
 $winnerAttempt=g6Attempt($parallel[$winner]['app']);
 g6Check($finalRows['admins']===[]&&count($finalRows['game_settings'])===2&&is_file($parallel[$winner]['app'].'/storage/config.php')&&!is_file($parallel[$loser]['app'].'/storage/config.php')&&hash_equals($parallelReceipt['attempt_hash'],hash('sha256',$winnerAttempt['value']['token'])),'Local concurrent setup keeps one configuration/receipt binding, two defaults and no administrator import');
 // A completed installer is safely repeatable, including simultaneous no-ops.
 $sameA=g6CliStart($parallel[$winner]['app']);$sameB=g6CliStart($parallel[$winner]['app']);$sameA=g6Wait($sameA);$sameB=g6Wait($sameB);
 g6Check($sameA['code']===0&&$sameB['code']===0&&g6Rows($db)===$finalRows&&homeG6Accounts($root)===$finalAccounts,'Concurrent initialized local CLI no-ops preserve all database/account state');
 foreach(['directory','symlink'] as $kind){
  $closed=g6App($fixture,'closed-'.$kind);$target=$closed.'/storage/config.php';$marker=$closed.'/config-executed';$malicious='<?php file_put_contents('.var_export($marker,true).',"executed"); return [];';
  if($kind==='directory')mkdir($target,0700);else{file_put_contents($closed.'/unrelated.php',$malicious);symlink($closed.'/unrelated.php',$target);}
  $result=g6Cli($closed);
  g6Check($result['code']!==0&&!file_exists($marker)&&g6Rows($db)===$finalRows&&homeG6Accounts($root)===$finalAccounts,'Existing '.$kind.' local configuration fails closed without executing PHP or modifying database/accounts');
  g6Check($kind==='directory'?is_dir($target):is_link($target)&&file_get_contents($target)===$malicious,'Existing '.$kind.' local config path/bytes are preserved');homeG6NoSecrets($result,$kind.' config rejection');
 }
 g6Check(g6Rows($cloudDb)===$cloudRows&&file_get_contents($cloud.'/storage/config.php')===$cloudConfig&&file_get_contents($cloud.'/storage/cloud-video.bin')==='cloud-only-media','All local initialization/recovery/crash scenarios leave the independent cloud product untouched');
 echo 'G6 HOMESERVER INSTALLATION RECOVERY PASSED: '.$g6Checks." checks.\n";
}finally{
 foreach($held as $lock)g6Unlock($root,$lock);g6CleanupProcesses();g6DropDbs($root,$databases);
 if($homeOwned){$root->exec('DROP DATABASE IF EXISTS lucky_games');$root->exec("DROP USER IF EXISTS 'lucky_app'@'127.0.0.1'");$root->exec("DROP USER IF EXISTS 'lucky_app'@'%'");}
 g6RemoveTree($fixture);
}
