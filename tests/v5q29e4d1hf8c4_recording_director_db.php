<?php
declare(strict_types=1);
if(getenv('CI')!=='true')throw new RuntimeException('CI MariaDB only.');
define('LUCKY_TESTING',true);
require_once __DIR__.'/../lib/Bootstrap.php';
require_once __DIR__.'/../lib/MigrationSql.php';
require_once __DIR__.'/../lib/DirectorBroadcast.php';
require_once __DIR__.'/../lib/DirectorRecordingScenes.php';
function standaloneCheck(bool $ok,string $label):void {if(!$ok)throw new RuntimeException('HF8C4: '.$label);echo "PASS $label\n";}
function standaloneReject(callable $fn):bool {try{$fn();return false;}catch(DomainException){return true;}}
$db=new PDO('mysql:host=127.0.0.1;port=3306;dbname=lucky_test;charset=utf8mb4','root','luckytestpassword',[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(MigrationSql::statements((string)file_get_contents(__DIR__.'/../lib/schema.sql')) as $sql)$db->exec($sql);
foreach(['v5q29e1_recording_sessions.sql','v5q29e4d1hf8b_director_live.sql','v5q29e4d1hf8c4_director_recordings.sql'] as $migration)
 foreach(MigrationSql::statements((string)file_get_contents(__DIR__.'/../lib/migrations/'.$migration)) as $sql)$db->exec($sql);
$session=bin2hex(random_bytes(16));$foreign=bin2hex(random_bytes(16));$host=0;$other=0;
try{
 foreach(['c4-host','c4-other'] as $prefix){
  $db->prepare('INSERT INTO admins(username,password_hash) VALUES (?,?)')->execute([$prefix.'-'.bin2hex(random_bytes(6)),password_hash('test',PASSWORD_DEFAULT)]);
  if($prefix==='c4-host')$host=(int)$db->lastInsertId();else $other=(int)$db->lastInsertId();
 }
 $statement=$db->prepare('INSERT INTO recording_sessions(id,owner_admin_id,recording_type,title,description,status,create_nonce,request_hash) VALUES (?,?,?,? ,?,? ,?,?)');
 $statement->execute([$session,$host,'live_event','Concert stream','','active',bin2hex(random_bytes(12)),hash('sha256','c4')]);
 $statement->execute([$foreign,$other,'production','Foreign production','','active',bin2hex(random_bytes(12)),hash('sha256','c4b')]);
 standaloneCheck(standaloneReject(static fn()=>DirectorBroadcast::startRecording($db,$foreign,$host)),'Foreign recording cannot be published');
 standaloneCheck(standaloneReject(static fn()=>DirectorBroadcast::startRecording($db,$session,$host,'public')),'Public recording requires separate acknowledgement');
 $private=DirectorBroadcast::startRecording($db,$session,$host);
 standaloneCheck(strlen($private['id'])===32&&strlen($private['key'])===48,'Private production broadcast returns unguessable invitation');
 standaloneCheck(standaloneReject(static fn()=>DirectorBroadcast::watch($db,$private['id'],str_repeat('0',48))),'Wrong private invitation denied');
 standaloneCheck(DirectorBroadcast::watch($db,$private['id'],$private['key'])['live'],'Correct private invitation watches Program');
 standaloneCheck(!in_array($private['id'],array_column(DirectorBroadcast::publicList($db),'id'),true),'Private broadcast never appears in public directory');
 standaloneCheck(standaloneReject(static fn()=>DirectorBroadcast::offersForHost($db,$private['id'],$other)),'Other admin cannot inspect host offers');
 $public=DirectorBroadcast::startRecording($db,$session,$host,'public',true);
 standaloneCheck(standaloneReject(static fn()=>DirectorBroadcast::watch($db,$private['id'],$private['key'])),'Republish revokes old invitation');
 standaloneCheck(in_array($public['id'],array_column(DirectorBroadcast::publicList($db),'id'),true),'Public recording appears in Live View directory');
 standaloneCheck(DirectorBroadcast::watch($db,$public['id'],'')['visibility']==='public','Public visitor requires no invitation');
 $scene=DirectorRecordingScenes::save($db,$session,$host,['name'=>'Opening wide','source'=>'camera:cam_mock','transition'=>'fade','title'=>'Live show','sticker'=>'⭐']);
 standaloneCheck($scene['name']==='Opening wide'&&count(DirectorRecordingScenes::listing($db,$session,$host))===1,'Recording-owned scenes persist');
 standaloneCheck(standaloneReject(static fn()=>DirectorRecordingScenes::listing($db,$session,$other)),'Other admin cannot inspect scenes');
 standaloneCheck(standaloneReject(static fn()=>DirectorRecordingScenes::save($db,$session,$host,['name'=>'Foreign clip','source'=>'clip:'.str_repeat('f',32)])),'Unowned video cannot become a scene');
 $db->prepare("UPDATE recording_sessions SET status='completed' WHERE id=?")->execute([$session]);
 standaloneCheck(standaloneReject(static fn()=>DirectorBroadcast::watch($db,$public['id'],'')),'Completed recording immediately revokes viewer access');
 standaloneCheck(!in_array($public['id'],array_column(DirectorBroadcast::publicList($db),'id'),true),'Completed recording disappears from public directory');
 standaloneCheck(standaloneReject(static fn()=>DirectorBroadcast::startRecording($db,$session,$host)),'Completed recording cannot republish');
 standaloneCheck(standaloneReject(static fn()=>DirectorRecordingScenes::save($db,$session,$host,['name'=>'Post-close','source'=>'camera:cam_mock'])),'Completed recording scenes are read-only');
 $db->prepare('DELETE FROM recording_sessions WHERE id=?')->execute([$session]);
 $q=$db->prepare('SELECT COUNT(*) FROM director_broadcast_recordings WHERE recording_session_id=?');$q->execute([$session]);
 standaloneCheck((int)$q->fetchColumn()===0,'Deleting a recording cleans up broadcast mapping');
 echo "HF8C4 STANDALONE CAMERA DIRECTOR DATABASE: 18/18\n";
}finally{
 $db->prepare('DELETE FROM director_broadcasts WHERE session_id=?')->execute([$session]);
 $db->prepare('DELETE FROM recording_sessions WHERE id IN (?,?)')->execute([$session,$foreign]);
 if($host)$db->prepare('DELETE FROM admins WHERE id=?')->execute([$host]);
 if($other)$db->prepare('DELETE FROM admins WHERE id=?')->execute([$other]);
}
