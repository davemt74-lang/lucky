<?php
declare(strict_types=1);
if(getenv('CI')!=='true')throw new RuntimeException('Run database fixture only in isolated CI MariaDB.');
define('LUCKY_TESTING',true);
$work=sys_get_temp_dir().'/lucky-phone-dash-'.bin2hex(random_bytes(8));
mkdir($work,0700,true);putenv('LUCKY_TEST_GAME_PACKAGES='.$work.'/packages');
require_once __DIR__.'/../lib/Bootstrap.php';
require_once __DIR__.'/../lib/MigrationSql.php';
require_once __DIR__.'/../lib/PhoneGameDashboard.php';
function ckDash(bool $ok,string $why):void {if(!$ok)throw new RuntimeException($why);echo "PASS $why\n";}
function rejectDash(callable $fn,int $code,string $why):void {
 try{$fn();}catch(DomainException $e){ckDash($e->getCode()===$code,$why);return;}
 throw new RuntimeException('Expected refusal '.$why);
}
function clearDash(string $path):void {
 if(!is_dir($path))return;
 foreach(new FilesystemIterator($path) as $file){
  if($file->isDir()&&!$file->isLink())clearDash($file->getPathname());
  else unlink($file->getPathname());
 }
 rmdir($path);
}
$db=new PDO('mysql:host=127.0.0.1;port=3306;dbname=lucky_test;charset=utf8mb4',
 'root','luckytestpassword',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
 PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(MigrationSql::statements((string)file_get_contents(__DIR__.'/../lib/schema.sql')) as $query)$db->exec($query);
foreach(['v5q15b_prize_decisions.sql','v5q_claim_media.sql','v5q29e1_recording_sessions.sql',
 'v5q29e2_phone_camera.sql','v5q29e3_multicamera_coordination.sql','v5q29e4c_recording_profiles.sql'] as $name)
 foreach(MigrationSql::statements((string)file_get_contents(__DIR__.'/../lib/migrations/'.$name)) as $query)$db->exec($query);
$game='dash-'.bin2hex(random_bytes(5));$pair=bin2hex(random_bytes(16));
$owner=0;$started=[];
try{
 $db->prepare('INSERT INTO admins(username,password_hash) VALUES (?,?)')
  ->execute(['test-dash-'.bin2hex(random_bytes(5)),password_hash(bin2hex(random_bytes(8)),PASSWORD_DEFAULT)]);
 $owner=(int)$db->lastInsertId();
 $q=$db->prepare("INSERT INTO controller_pairs(id,host_admin_id,invite_hash,controller_hash,device_name,status,invite_expires_at,paired_expires_at)
  VALUES (?,?,?,?,?,'paired',UTC_TIMESTAMP()+INTERVAL 5 MINUTE,UTC_TIMESTAMP()+INTERVAL 8 HOUR)");
 $q->execute([$pair,$owner,str_repeat('a',64),str_repeat('b',64),'Couch phone']);
 ckDash(count(PhoneGameDashboard::catalog())>=1,'Paired phone catalog includes built-in wheel');
 $zip=$work.'/test.zip';$file='<!doctype html><html><body>Test-only host game</body></html>';
 $manifest=['schema'=>'lucky.game-package.v1','id'=>$game,'title'=>'Dashboard Fixture',
  'publisher'=>'Test','version'=>'1.0.0','host'=>'host.html','phone'=>'phone.html',
  'files'=>['host.html'=>hash('sha256',$file),'phone.html'=>hash('sha256',$file)]];
 $z=new ZipArchive();
 if($z->open($zip,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)throw new RuntimeException('ZIP creation failed');
 $z->addFromString('manifest.json',json_encode($manifest,JSON_THROW_ON_ERROR));
 $z->addFromString('host.html',$file);$z->addFromString('phone.html',$file);$z->close();
 GamePackageInstaller::install($zip);
 GamePackageActivation::activate($game,'1.0.0',null,true);
 $catalog=PhoneGameDashboard::catalog();
 ckDash(count($catalog)===2&&$catalog[1]['id']===$game&&$catalog[1]['hasPhone']===true,
  'Only active, verified installed games appear on phone');
 $nonce='phone-launch-first-'.bin2hex(random_bytes(7));
 $db->beginTransaction();
 $q=$db->prepare('SELECT id FROM controller_pairs WHERE id=? FOR UPDATE');$q->execute([$pair]);$q->fetch();
 $first=PhoneGameDashboard::launch($db,$game,$pair,$nonce,$owner);$db->commit();
 $started[]=$first['id'];
 ckDash($first['game']===$game&&preg_match('/^[a-f0-9]{48}$/',$first['viewer'])===1,
  'Phone launches a real pinned package session and gets scoped viewer token');
 $host=PhoneGameDashboard::pendingForHost($db,$owner);
 ckDash($host&&$host['game']===$game&&$host['session']===$first['id'],
  'Desktop sees only recent launch for its own owner');
 ckDash(PhoneGameDashboard::pendingForHost($db,$owner+1)===null,
  'Another owner cannot see a phone-created game launch');
 $db->beginTransaction();
 $again=PhoneGameDashboard::launch($db,$game,$pair,$nonce,$owner);$db->commit();
 ckDash($again['repeat']===true&&$again['id']===$first['id'],
  'Retransmitted phone launch is idempotent and reuses first session');
 $db->beginTransaction();
 try{
  PhoneGameDashboard::launch($db,'spin-wheel',$pair,'new-game-attempt-123456',$owner);
  throw new RuntimeException('A second live game was started.');
 }catch(DomainException $e){ckDash($e->getCode()===409,'Different game cannot start while one remains live');}
 finally{$db->rollBack();}
 $db->beginTransaction();$state=stateFromRow(sessionRow($db,$first['id'],true));
 GameRegistry::module($game)->action($state,'end');saveState($db,$state);$db->commit();
 ckDash(stateFromRow(sessionRow($db,$first['id']))['status']==='ended',
  'Finished game leaves durable session history');
 $nonceWheel='phone-wheel-launch-'.bin2hex(random_bytes(7));
 $db->beginTransaction();
 $wheel=PhoneGameDashboard::launch($db,'spin-wheel',$pair,$nonceWheel,$owner);
 $db->commit();$started[]=$wheel['id'];
 ckDash($wheel['game']==='spin-wheel','Approved phone can launch built-in wheel after packaged game ends');
 $camera=$db->prepare("SELECT s.id,p.phone_enabled FROM recording_sessions s JOIN recording_phone_permissions p ON p.recording_session_id=s.id WHERE s.game_session_id=? AND s.owner_admin_id=? AND s.status='active'");
 $camera->execute([$wheel['id'],$owner]);$group=$camera->fetch(PDO::FETCH_ASSOC);
 ckDash(is_array($group)&&(int)$group['phone_enabled']===1,'Phone-launched wheel immediately creates approved automatic camera group');
 echo "V5Q.24 phone game dashboard: pairing, catalog, trusted launch, replay, owner scope and switching PASSED\n";
}finally{
 if($db->inTransaction())$db->rollBack();
 foreach($started as $sid){
  $db->prepare('DELETE FROM game_sessions WHERE id=?')->execute([$sid]);
  $db->prepare('DELETE FROM audit_log WHERE session_id=?')->execute([$sid]);
 }
 $db->prepare('DELETE FROM controller_pairs WHERE id=?')->execute([$pair]);
 if($owner)$db->prepare('DELETE FROM admins WHERE id=?')->execute([$owner]);
 $db->prepare('DELETE FROM game_settings WHERE game_id=?')->execute([$game]);
 putenv('LUCKY_TEST_GAME_PACKAGES');clearDash($work);
}
