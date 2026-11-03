<?php
declare(strict_types=1);
/** Actual web installer, MariaDB transactions and real filesystem publication. */
require_once __DIR__.'/fixtures/install_recovery_harness.php';
require_once __DIR__.'/../lib/SchemaMigrations.php';
require_once __DIR__.'/../lib/SetupConfiguration.php';
$root=g6Root();$fixture=g6Fixture('cloud-recovery');$owned=[];$held=[];
try{
 // Honest negative control: exact install.php from merged G5 commit
 // dd18392e16499ef11e41298f51caa41d8e026084, not a weakened new-code mock.
 $old=__DIR__.'/fixtures/install_g5.php.txt';
 g6Check(hash_file('sha256',$old)==='9f0f1d295ad7f4e5cc900ddb087fcc5f101a6b324dc227f9384d3a4a7f1aa64e','Frozen G5 installer matches the audited original source hash');
 $oldDb=g6Db($root,$owned);SchemaMigrations::ensure($oldDb);g6SeedFailure($oldDb);
 $oldApp=g6App($fixture,'g5-negative-control');copy($old,$oldApp.'/install.php');$oldServer=g6Server($oldApp);
 [$cookie,$fields]=g6Form($oldServer,$oldDb);
 $failed=g6Http($oldServer['base'],'/install.php',$cookie,$fields);
 g6Check($failed['status']===200&&!file_exists($oldApp.'/storage/config.php'),'Original G5 seed failure leaves installation unavailable');
 g6Check((int)$oldDb->query('SELECT COUNT(*) FROM admins')->fetchColumn()===1&&(int)$oldDb->query('SELECT COUNT(*) FROM game_settings')->fetchColumn()===1,'Negative control reproduces committed orphan admin and first seed before the second seed fails');
 $oldDb->exec('DROP TRIGGER g6_seed_fault');$orphan=g6Rows($oldDb);
 $retry=g6Http($oldServer['base'],'/install.php',$cookie,$fields);
 g6Check($retry['status']===200&&str_contains($retry['body'],'Existing administrators detected')&&!is_file($oldApp.'/storage/config.php')&&g6Rows($oldDb)===$orphan,'Negative control reproduces correct-credential retry being permanently rejected by G5');
 g6Stop($oldServer);

 $db=g6Db($root,$owned);SchemaMigrations::ensure($db);g6SeedFailure($db);
 $app=g6App($fixture,'atomic-seeds');$server=g6Server($app);[$cookie,$fields]=g6Form($server,$db);
 $bad=g6Http($server['base'],'/install.php',$cookie,array_replace($fields,['csrf'=>'invalid']));
 g6Check($bad['status']===200&&!is_file($app.'/storage/.setup-attempt.json'),'CSRF rejection occurs before staging an installation identity');g6Empty($db,'Invalid CSRF');
 $bad=g6Http($server['base'],'/install.php',$cookie,array_replace($fields,['db_password'=>'wrong-database-password']));
 g6Check($bad['status']===200&&!is_file($app.'/storage/.setup-attempt.json'),'A wrong initial DB password cannot poison the persisted configuration binding');g6Empty($db,'Invalid database credentials');
 SetupConfiguration::withLock($app.'/storage',static function():void{});
 try{
  chmod($app.'/storage',0500);clearstatcache(true,$app.'/storage');
  g6Check(!is_writable($app.'/storage'),'Fixture really denies creation of setup/config files without denying access to its existing lock');
  $failed=g6Http($server['base'],'/install.php',$cookie,$fields);
  g6Check($failed['status']===200&&!is_file($app.'/storage/config.php'),'Real filesystem staging failure cannot activate configuration');g6Empty($db,'Configuration staging write failure');
 }finally{chmod($app.'/storage',0700);clearstatcache(true,$app.'/storage');}
 $failed=g6Http($server['base'],'/install.php',$cookie,$fields);
 g6Check($failed['status']===200&&!is_file($app.'/storage/config.php'),'Real second-seed failure cannot activate configuration');g6Empty($db,'Second-seed rollback');
 $attempt=g6Attempt($app);$db->exec('DROP TRIGGER g6_seed_fault');
 $success=g6Http($server['base'],'/install.php',$cookie,$fields);
 g6Check($success['status']===303&&is_file($app.'/storage/config.php'),'Retry after a seed fault completes the real cloud installer');
 g6Check((int)$db->query('SELECT COUNT(*) FROM admins')->fetchColumn()===1&&(int)$db->query('SELECT COUNT(*) FROM game_settings')->fetchColumn()===2,'Successful retry creates one admin and exactly two defaults');
 g6Check(file_get_contents($app.'/storage/.setup-attempt.json')===$attempt['bytes'],'Successful retry retains the original private attempt identity');
 g6Check((fileperms($app.'/storage/config.php')&0077)===0,'Activated cloud configuration is not group/world readable');g6Receipt($db,'published','cloud');g6Login($server,'recovery-admin');
 $saved=g6Rows($db);$configHash=hash_file('sha256',$app.'/storage/config.php');
 g6Check(g6Http($server['base'],'/install.php',$cookie,$fields)['status']===302&&g6Rows($db)===$saved&&hash_file('sha256',$app.'/storage/config.php')===$configHash,'Completed installer stays locked without rewriting accounts, settings or configuration');
 g6Stop($server);

 // Simulate the historical partial database left by G5, including custom data.
 $legacyDb=g6Db($root,$owned);SchemaMigrations::ensure($legacyDb);
 $q=$legacyDb->prepare('INSERT INTO admins(username,password_hash) VALUES (?,?)');$q->execute(['existing-admin',password_hash(G6_ADMIN_PASSWORD,PASSWORD_DEFAULT)]);
 $legacyDb->exec("INSERT INTO game_settings(game_id,config_json,intro_json,theme_json) VALUES ('spin-wheel','{\"custom\":true}','{\"title\":\"Keep this\"}','{\"accent\":\"#123456\"}'),('custom-game','{}','{}','{}')");
 $legacyDb->exec("INSERT INTO catalog_products(id,title,category) VALUES ('11111111111111111111111111111111','Existing independent product','other')");
 $legacy=g6App($fixture,'legacy-partial');file_put_contents($legacy.'/storage/existing-media.bin','preserved legacy media bytes');
 $legacyServer=g6Server($legacy);[$legacyCookie,$legacyFields]=g6Form($legacyServer,$legacyDb,'existing-admin');$legacySaved=g6Rows($legacyDb);
 foreach([['admin'=>'unknown-admin'],['password'=>'incorrect-existing-password']] as $wrong){
  $denied=g6Http($legacyServer['base'],'/install.php',$legacyCookie,array_replace($legacyFields,$wrong));
  g6Check($denied['status']===200&&!is_file($legacy.'/storage/config.php')&&!is_file($legacy.'/storage/.setup-attempt.json')&&g6Rows($legacyDb)===$legacySaved,'Legacy recovery rejects wrong existing administrator proof before changing rows or staging a local identity');
 }
 $recovered=g6Http($legacyServer['base'],'/install.php',$legacyCookie,$legacyFields);g6Check($recovered['status']===303,'Historical partial setup recovers with its existing administrator password');
 $legacyAfter=g6Rows($legacyDb);
 g6Check($legacyAfter['admins']===$legacySaved['admins'],'Legacy recovery preserves the exact existing administrator ID, hash and timestamp');
 foreach($legacySaved['game_settings'] as $row){$q=$legacyDb->prepare('SELECT * FROM game_settings WHERE game_id=?');$q->execute([$row['game_id']]);g6Check($q->fetch()===$row,'Legacy recovery preserves existing '.$row['game_id'].' settings byte-for-byte');}
 g6Check(count($legacyAfter['game_settings'])===3&&$legacyDb->query("SELECT title FROM catalog_products WHERE id='11111111111111111111111111111111'")->fetchColumn()==='Existing independent product'&&file_get_contents($legacy.'/storage/existing-media.bin')==='preserved legacy media bytes','Legacy recovery inserts only the missing default and preserves products/media');
 g6Receipt($legacyDb,'published','cloud');g6Login($legacyServer,'existing-admin');g6Stop($legacyServer);

 // The actual entrypoint passes its initial pathname check, then waits inside
 // a real INSERT. A different actor creates the destination during that window.
 $raceDb=g6Db($root,$owned);SchemaMigrations::ensure($raceDb);$gate=g6SeedGate($raceDb,$root);$held[]=$gate;
 $race=g6App($fixture,'publish-conflict');$raceServer=g6Server($race);[$raceCookie,$raceFields]=g6Form($raceServer,$raceDb,'race-admin');
 $request=g6Begin($raceServer['base'],'/install.php',$raceCookie,$raceFields);g6WaitDbBlocked($root,(string)$raceDb->query('SELECT DATABASE()')->fetchColumn());
 g6Empty($raceDb,'Uncommitted admin/seed while publication race is arranged');mkdir($race.'/storage/config.php',0700);
 g6Unlock($root,$gate);$response=g6Finish($request);$raceDb->exec('DROP TRIGGER g6_seed_gate');
 g6Check($response['status']===200&&is_dir($race.'/storage/config.php'),'A config pathname created after preflight is never overwritten by publication');
 $receipt=g6Receipt($raceDb,'database_ready','cloud');$raceAttempt=g6Attempt($race);$readyRows=g6Rows($raceDb);
 g6Check(count($readyRows['admins'])===1&&count($readyRows['game_settings'])===2,'Publication failure preserves the committed complete admin/default transaction for authenticated retry');
 rmdir($race.'/storage/config.php');
 foreach([['admin'=>'other-admin'],['password'=>'incorrect-existing-password'],['db_password'=>'incorrect-database-password']] as $wrong){
  $denied=g6Http($raceServer['base'],'/install.php',$raceCookie,array_replace($raceFields,$wrong));
  g6Check($denied['status']===200&&!is_file($race.'/storage/config.php')&&g6Rows($raceDb)===$readyRows&&file_get_contents($race.'/storage/.setup-attempt.json')===$raceAttempt['bytes'],'Pending publication rejects wrong credentials without resetting its receipt or local identity');
 }
 $changed=$raceAttempt['value'];$changed['token']=str_repeat('a',64);file_put_contents($race.'/storage/.setup-attempt.json',json_encode($changed,JSON_THROW_ON_ERROR));
 $denied=g6Http($raceServer['base'],'/install.php',$raceCookie,$raceFields);
 g6Check($denied['status']===200&&!is_file($race.'/storage/config.php')&&g6Rows($raceDb)===$readyRows,'A changed local recovery token cannot attach the committed setup database');
 file_put_contents($race.'/storage/.setup-attempt.json',$raceAttempt['bytes']);
 $completed=g6Http($raceServer['base'],'/install.php',$raceCookie,$raceFields);g6Check($completed['status']===303,'Matching token, DB details and administrator proof finish interrupted publication');
 $after=g6Rows($raceDb);g6Check($after['admins']===$readyRows['admins']&&$after['game_settings']===$readyRows['game_settings'],'Publication retry does not duplicate or rewrite the already committed account/settings');g6Receipt($raceDb,'published','cloud');g6Login($raceServer,'race-admin');g6Stop($raceServer);

 // Real process death before commit must be rolled back by MariaDB, not merely
 // caught by application error handling. The DB gate provides deterministic timing.
 $crashDb=g6Db($root,$owned);SchemaMigrations::ensure($crashDb);$gate=g6SeedGate($crashDb,$root);$held[]=$gate;
 $crash=g6App($fixture,'crash-before-commit');$crashServer=g6Server($crash);[$crashCookie,$crashFields]=g6Form($crashServer,$crashDb);
 $request=g6Begin($crashServer['base'],'/install.php',$crashCookie,$crashFields);$ids=g6WaitDbBlocked($root,(string)$crashDb->query('SELECT DATABASE()')->fetchColumn());$crashAttempt=g6Attempt($crash);
 $ownedStage=$crash.'/storage/.setup-config-'.$crashAttempt['value']['token'].'.tmp';
 g6Check(is_file($ownedStage),'Real pending transaction has its fully staged configuration ready before commit');
 file_put_contents($crash.'/storage/.setup-config-unrelated.tmp','unrelated user-owned bytes');
 g6Stop($crashServer,true);fclose($request);g6Unlock($root,$gate);g6WaitDbGone($root,$ids);$crashDb->exec('DROP TRIGGER g6_seed_gate');g6Empty($crashDb,'Process death before database commit');
 g6Check(!is_file($crash.'/storage/config.php'),'Process death before commit does not expose active configuration');
 $crashServer=g6Server($crash);[$crashCookie,$crashFields]=g6Form($crashServer,$crashDb);$done=g6Http($crashServer['base'],'/install.php',$crashCookie,$crashFields);
 g6Check($done['status']===303&&file_get_contents($crash.'/storage/.setup-attempt.json')===$crashAttempt['bytes'],'A restarted real installer reuses the original attempt after transaction rollback');
 g6Check(!is_file($ownedStage)&&file_get_contents($crash.'/storage/.setup-config-unrelated.tmp')==='unrelated user-owned bytes','Recovery removes only its own stale stage and preserves unrelated temporary/media files');g6Receipt($crashDb,'published','cloud');g6Stop($crashServer);

 foreach(['before','after'] as $boundary){
  $boundaryDb=g6Db($root,$owned);SchemaMigrations::ensure($boundaryDb);$boundaryApp=g6App($fixture,'crash-'.$boundary.'-publish');
  $pause=g6PausePublication($boundaryApp,$boundary);$boundaryServer=g6Server($boundaryApp);[$boundaryCookie,$boundaryFields]=g6Form($boundaryServer,$boundaryDb);
  $request=g6Begin($boundaryServer['base'],'/install.php',$boundaryCookie,$boundaryFields);g6Marker($pause['marker']);
  g6Receipt($boundaryDb,'database_ready','cloud');$boundaryRows=g6Rows($boundaryDb);
  g6Check(count($boundaryRows['admins'])===1&&count($boundaryRows['game_settings'])===2,'Crash window '.$boundary.' publication has a durably committed complete transaction');
  g6Check(is_file($boundaryApp.'/storage/config.php')===($boundary==='after'),'Active configuration reflects the precise '.$boundary.'-publication crash boundary');
  $boundaryAttempt=g6Attempt($boundaryApp);$activeBytes=$boundary==='after'?file_get_contents($boundaryApp.'/storage/config.php'):null;
  g6Stop($boundaryServer,true);fclose($request);g6RestorePublication($pause);$boundaryServer=g6Server($boundaryApp);
  if($boundary==='before'){
   [$boundaryCookie,$boundaryFields]=g6Form($boundaryServer,$boundaryDb);$recovered=g6Http($boundaryServer['base'],'/install.php',$boundaryCookie,$boundaryFields);
   g6Check($recovered['status']===303,'Post-commit/pre-publication crash is resumable through the unchanged installer');g6Receipt($boundaryDb,'published','cloud');
  }else{
   $locked=g6Http($boundaryServer['base'],'/install.php');
   g6Check($locked['status']===302&&file_get_contents($boundaryApp.'/storage/config.php')===$activeBytes&&g6Rows($boundaryDb)===$boundaryRows,'Crash after activation never reopens setup or rewrites valid configuration despite pending receipt bookkeeping');
  }
  g6Check(g6Rows($boundaryDb)['admins']===$boundaryRows['admins']&&g6Rows($boundaryDb)['game_settings']===$boundaryRows['game_settings']&&file_get_contents($boundaryApp.'/storage/.setup-attempt.json')===$boundaryAttempt['bytes'],'Crash recovery preserves existing account/default data and local identity');
  g6Login($boundaryServer,'recovery-admin');g6Stop($boundaryServer);
 }

 // Distinct installations do not share a file lock. Both must reach the real
 // DB setup lock simultaneously; only one local attempt may win this database.
 $parallelDb=g6Db($root,$owned);SchemaMigrations::ensure($parallelDb);$parallelName=(string)$parallelDb->query('SELECT DATABASE()')->fetchColumn();
 $parallel=[];
 foreach(['a','b'] as $suffix){$pApp=g6App($fixture,'parallel-'.$suffix);$pServer=g6Server($pApp);[$pCookie,$pFields]=g6Form($pServer,$parallelDb,'parallel-'.$suffix);$parallel[]=['app'=>$pApp,'server'=>$pServer,'cookie'=>$pCookie,'fields'=>$pFields];}
 $setupLock='lucky-setup-'.substr(hash('sha256',$parallelName),0,48);$q=$root->prepare('SELECT GET_LOCK(?,0)');$q->execute([$setupLock]);g6Check((int)$q->fetchColumn()===1,'Fixture owns the setup gate before issuing concurrent real requests');$held[]=$setupLock;
 foreach($parallel as &$candidate)$candidate['socket']=g6Begin($candidate['server']['base'],'/install.php',$candidate['cookie'],$candidate['fields']);unset($candidate);
 g6WaitDbBlocked($root,$parallelName,2);g6Check(true,'Two distinct real installers are observed blocked at the database gate concurrently');g6Unlock($root,$setupLock);
 $statuses=[];foreach($parallel as $index=>$candidate){$result=g6Finish($candidate['socket']);$statuses[$index]=$result['status'];}
 $sorted=$statuses;sort($sorted);g6Check($sorted===[200,303],'Exactly one concurrent independent installation publishes; the other is rejected');
 $winner=array_search(303,$statuses,true);$loser=1-$winner;$parallelRows=g6Rows($parallelDb);$parallelReceipt=g6Receipt($parallelDb,'published','cloud');
 g6Check(count($parallelRows['admins'])===1&&count($parallelRows['game_settings'])===2&&is_file($parallel[$winner]['app'].'/storage/config.php')&&!is_file($parallel[$loser]['app'].'/storage/config.php'),'Concurrent setup leaves one account, one default set and one configured installation');
 $winnerAttempt=g6Attempt($parallel[$winner]['app']);g6Check(hash_equals($parallelReceipt['attempt_hash'],hash('sha256',$winnerAttempt['value']['token'])),'The winning receipt binds the actual winning local recovery identity');
 $loserRetry=g6Http($parallel[$loser]['server']['base'],'/install.php',$parallel[$loser]['cookie'],array_replace($parallel[$loser]['fields'],['admin'=>$parallel[$winner]['fields']['admin']]));
 g6Check($loserRetry['status']===200&&!is_file($parallel[$loser]['app'].'/storage/config.php')&&g6Rows($parallelDb)===$parallelRows,'Knowing the winning password cannot bypass a different local installation identity');foreach($parallel as $candidate)g6Stop($candidate['server']);

 foreach(['regular','directory','symlink'] as $kind){
  $closed=g6App($fixture,'closed-'.$kind);$target=$closed.'/storage/config.php';$marker=$closed.'/unexpected-config-execution';
  $malicious='<?php file_put_contents('.var_export($marker,true).',"executed"); return ["corrupt"=>true];';
  if($kind==='directory')mkdir($target,0700);
  elseif($kind==='symlink'){file_put_contents($closed.'/unrelated.php',$malicious);symlink($closed.'/unrelated.php',$target);}
  else file_put_contents($target,$malicious);
  $closedServer=g6Server($closed);$result=g6Http($closedServer['base'],'/install.php');
  g6Check($result['status']===($kind==='regular'?302:409)&&!file_exists($marker),'Existing '.$kind.' configuration locks the web installer without executing configuration PHP');
  g6Check($kind==='directory'?is_dir($target):file_get_contents($target)===$malicious,'Existing '.$kind.' configuration bytes/path are preserved');g6Stop($closedServer);
 }
 g6Check(g6Rows($db)===$saved&&hash_file('sha256',$app.'/storage/config.php')===$configHash,'Every other recovery fixture leaves the first independent installation untouched');
 echo 'G6 CLOUD INSTALLATION RECOVERY PASSED: '.$g6Checks." checks.\n";
}finally{
 foreach($held as $lock)g6Unlock($root,$lock);
 g6CleanupProcesses();g6DropDbs($root,$owned);g6RemoveTree($fixture);
}
