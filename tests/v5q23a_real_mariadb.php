<?php
declare(strict_types=1);
/**
 * V5Q.23A real MariaDB certification; run only on isolated CI database.
 * Two test-only static game packages, no consumer game is released.
 */
if(getenv('CI')!=='true')throw new RuntimeException('This destructive database fixture runs in CI only.');
define('LUCKY_TESTING',true);
$root=sys_get_temp_dir().'/lucky-cert-db-'.bin2hex(random_bytes(7));
mkdir($root,0700,true);
putenv('LUCKY_TEST_GAME_PACKAGES='.$root.'/packages');
require_once __DIR__.'/../lib/Bootstrap.php';
require_once __DIR__.'/../lib/MigrationSql.php';
require_once __DIR__.'/../lib/GamePackageActivation.php';
require_once __DIR__.'/../lib/PackageControllerBridge.php';
require_once __DIR__.'/../lib/GameSessionLifecycle.php';
function v23db(bool $result,string $name):void {
 if(!$result)throw new RuntimeException('FAIL '.$name);
 echo 'PASS '.$name."\n";
}
function v23dbConflict(callable $action,string $name):void {
 try{$action();}catch(DomainException $e){v23db($e->getCode()===409,$name);return;}
 throw new RuntimeException('Expected conflict: '.$name);
}
function v23dbZip(string $path,string $id,string $version,bool $phone):void {
 $files=['host.html'=>'<!doctype html><p>CI game host</p>'];
 if($phone)$files['phone.html']='<!doctype html><button data-game-input="tap">Tap</button>';
 $manifest=['schema'=>'lucky.game-package.v1','id'=>$id,'version'=>$version,
  'title'=>'Temporary Certification Fixture','publisher'=>'Internal CI Only',
  'host'=>'host.html','phone'=>$phone?'phone.html':null,
  'files'=>array_map(fn($v)=>hash('sha256',$v),$files)];
 $z=new ZipArchive();
 if($z->open($path,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)throw new RuntimeException('ZIP unavailable');
 $z->addFromString('manifest.json',json_encode($manifest,JSON_THROW_ON_ERROR));
 foreach($files as $name=>$bytes)$z->addFromString($name,$bytes);
 $z->close();
}
function v23dbClean(string $dir):void {
 if(!is_dir($dir))return;
 foreach(new FilesystemIterator($dir) as $child){
  if($child->isDir()&&!$child->isLink())v23dbClean($child->getPathname());
  else unlink($child->getPathname());
 }
 rmdir($dir);
}
$db=new PDO('mysql:host=127.0.0.1;port=3306;dbname=lucky_test;charset=utf8mb4','root','luckytestpassword',
 [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$db->exec("SET time_zone='+00:00'");
foreach(MigrationSql::statements(file_get_contents(__DIR__.'/../lib/schema.sql')) as $sql)$db->exec($sql);
$suffix=bin2hex(random_bytes(5));$one='cert-phone-'.$suffix;$two='cert-tv-'.$suffix;
$sessionId=bin2hex(random_bytes(16));$otherId=bin2hex(random_bytes(16));
$viewer=bin2hex(random_bytes(24));$otherViewer=bin2hex(random_bytes(24));
$pairId=bin2hex(random_bytes(16));$nonce='cert-nonce-'.$suffix;
$ownerId=null;
try {
 $zip=$root.'/test.zip';
 v23dbZip($zip,$one,'1.0.0',true);GamePackageInstaller::install($zip);
 v23dbZip($zip,$two,'1.0.0',false);GamePackageInstaller::install($zip);
 GamePackageActivation::activate($one,'1.0.0',null,true);
 GamePackageActivation::activate($two,'1.0.0',null,true);
 v23db(GameRegistry::module($one) instanceof PackageGameModule,'Activated phone game registered in actual platform registry.');
 v23db(GameRegistry::controller($two)===null,'TV-only module does not acquire a phone controller.');
 $stmt=$db->prepare('INSERT INTO admins(username,password_hash) VALUES (?,?)');
 $stmt->execute(['cert-'.$suffix,password_hash(bin2hex(random_bytes(10)),PASSWORD_DEFAULT)]);
 $ownerId=(int)$db->lastInsertId();
 $sessionStart=function(string $game,string $id,string $token) use($db):void {
  $db->beginTransaction();
  try{
   $defaults=GameSessionLifecycle::lockGame($db,$game);
   GameSessionLifecycle::assertVacant($db,$game);
   $module=GameRegistry::module($game);
   $state=$module->createSession($defaults,$id);GameFoundation::checkSession($module,$state,$id);
   $insert=$db->prepare('INSERT INTO game_sessions(id,game_id,status,version,state_json,viewer_token) VALUES (?,?,?,?,?,?)');
   $insert->execute([$id,$game,'active',1,json_encode($state,JSON_THROW_ON_ERROR),$token]);
   $db->commit();
  }catch(Throwable $e){$db->rollBack();throw $e;}
 };
 $sessionStart($one,$sessionId,$viewer);$sessionStart($two,$otherId,$otherViewer);
 v23dbConflict(fn()=>GamePackageActivation::assertNoLiveSessions($db,$one),'Live game blocks version activation and repair.');
 $q=$db->prepare("INSERT INTO controller_pairs(id,host_admin_id,invite_hash,controller_hash,device_name,status,invite_expires_at,paired_expires_at) VALUES (?,?,?,?,?,'paired',UTC_TIMESTAMP()+INTERVAL 1 HOUR,UTC_TIMESTAMP()+INTERVAL 1 HOUR)");
 $q->execute([$pairId,$ownerId,str_repeat('a',64),str_repeat('b',64),'CI phone']);
 $pair=$db->prepare('SELECT * FROM controller_pairs WHERE id=?');$pair->execute([$pairId]);
 $pairRow=$pair->fetch();
 PackageControllerBridge::bound($pairRow,sessionRow($db,$sessionId),$viewer,$ownerId);
 $module=GameRegistry::module($one);
 $apply=function(string $action,string $key,array $payload=[])use($db,$sessionId,$module,$pairId):bool {
  $db->beginTransaction();
  try{
   GameSessionLifecycle::lockGame($db,$module->id());
   $check=$db->prepare('SELECT id FROM game_events WHERE session_id=? AND idempotency_key=?');
   $check->execute([$sessionId,$key]);if($check->fetch()){$db->commit();return false;}
   $state=stateFromRow(sessionRow($db,$sessionId,true));
   $event=$module->action($state,$action,$payload);
   saveState($db,$state);
   $q=$db->prepare('INSERT INTO game_events(session_id,idempotency_key,event_type,payload_json) VALUES (?,?,?,?)');
   $q->execute([$sessionId,$key,$event['type'],json_encode(['event'=>$event],JSON_THROW_ON_ERROR)]);
   $q=$db->prepare('INSERT INTO controller_commands(pair_id,client_nonce,command,delivered_at) VALUES (?,?,?,UTC_TIMESTAMP())');
   $q->execute([$pairId,$key,$action]);
   $db->commit();return true;
  }catch(Throwable $e){$db->rollBack();throw $e;}
 };
 $key=PackageControllerBridge::key($pairId,$nonce);
 v23db($apply('input',$key,['name'=>'tap','value'=>1]),'Phone input writes one real transactional game event.');
 v23db(!$apply('input',$key,['name'=>'tap','value'=>1]),'Repeated phone command is idempotent at real database boundary.');
 $row=sessionRow($db,$sessionId);
 v23db((int)$row['version']===2 && stateFromRow($row)['lastInput']['name']==='tap','Duplicate delivery cannot advance session version.');
 $check=$db->prepare('SELECT COUNT(*) FROM controller_commands WHERE pair_id=? AND client_nonce=?');
 $check->execute([$pairId,$key]);v23db((int)$check->fetchColumn()===1,'One persisted command for the nonce.');
 $apply('pause',PackageControllerBridge::key($pairId,'pause-'.$nonce));
 $before=sessionRow($db,$sessionId);
 try{$apply('input',PackageControllerBridge::key($pairId,'bad-'.$nonce),['name'=>'tap','value'=>1]);throw new RuntimeException('Paused input accepted.');}
 catch(DomainException $e){v23db((int)$e->getCode()===409,'Paused game rejects inputs and rolls back transaction.');}
 v23db(sessionRow($db,$sessionId)['version']===$before['version'],'Rejected input leaves stored session unchanged.');
 $apply('resume',PackageControllerBridge::key($pairId,'resume-'.$nonce));
 $state=stateFromRow(sessionRow($db,$sessionId));
 $storedVersion=$state['version'];
 GameSessionLifecycle::transition($state,'save');
 $db->beginTransaction();saveState($db,$state);$db->commit();
 v23db(stateFromRow(sessionRow($db,$sessionId))['status']==='saved','Game save survives local MariaDB commit.');
 v23db((int)sessionRow($db,$sessionId)['version']===$storedVersion+1,'Save advances optimistic session version.');
 // Test a newer package while the old session is saved.
 v23dbZip($zip,$one,'1.1.0',true);GamePackageInstaller::install($zip);
 GamePackageActivation::assertNoLiveSessions($db,$one);
 GamePackageActivation::activate($one,'1.1.0','1.0.0',true);
 $again=stateFromRow(sessionRow($db,$sessionId));
 v23db($again['packageVersion']==='1.0.0','Saved session remains pinned to earlier release after update.');
 GameSessionLifecycle::transition($again,'resume_saved');
 $db->beginTransaction();saveState($db,$again);$db->commit();
 $reopened=sessionRow($db,$sessionId);
 v23db($reopened['status']==='active'&&stateFromRow($reopened)['packageVersion']==='1.0.0','Saved game resumes with original data after simulated restart.');
 $apply('end',PackageControllerBridge::key($pairId,'end-'.$nonce));
 v23db(sessionRow($db,$sessionId)['status']==='ended','Phone End Game archives session in local MariaDB.');
 v23dbConflict(fn()=>$apply('input',PackageControllerBridge::key($pairId,'later-'.$nonce),['name'=>'tap','value'=>1]),'Ended game rejects further phone actions.');
 // The second game is completely independent.
 v23db(sessionRow($db,$otherId)['status']==='active','Unrelated no-phone game remains active.');
 GamePackageActivation::assertNoLiveSessions($db,$one);
 v23dbConflict(fn()=>GamePackageActivation::assertNoLiveSessions($db,$two),'Other game still blocks its own updates.');
 GamePackageActivation::activate($one,'1.0.0','1.1.0',true);
 v23db(GamePackageActivation::active($one)['version']==='1.0.0','Verified rollback retains earlier package.');
 $second=stateFromRow(sessionRow($db,$otherId));
 GameSessionLifecycle::transition($second,'save');$db->beginTransaction();saveState($db,$second);$db->commit();
 echo "V5Q.23A REAL MariaDB sessions, phone replay, restart, rollback and isolation PASSED\n";
}finally{
 if($db->inTransaction())$db->rollBack();
 // Only delete specifically generated records in shared CI test schema.
 $q=$db->prepare('DELETE FROM game_sessions WHERE id IN (?,?)');$q->execute([$sessionId,$otherId]);
 $q=$db->prepare('DELETE FROM game_settings WHERE game_id IN (?,?)');$q->execute([$one,$two]);
 $q=$db->prepare('DELETE FROM controller_pairs WHERE id=?');$q->execute([$pairId]);
 if($ownerId!==null){$q=$db->prepare('DELETE FROM admins WHERE id=?');$q->execute([$ownerId]);}
 putenv('LUCKY_TEST_GAME_PACKAGES');
 v23dbClean($root);
}
