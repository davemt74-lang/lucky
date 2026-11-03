<?php
declare(strict_types=1);
if(getenv('CI')!=='true')throw new RuntimeException('CI MariaDB only.');
define('LUCKY_TESTING',true);
require_once __DIR__.'/../lib/Bootstrap.php';
require_once __DIR__.'/../lib/MigrationSql.php';
require_once __DIR__.'/../lib/DirectorBroadcast.php';
function e1DbCheck(bool $test,string $label):void {if(!$test)throw new RuntimeException('V5Q32E1 database: '.$label);echo "PASS $label\n";}
function e1DbReject(callable $run):bool {try{$run();return false;}catch(DomainException){return true;}}
$db=new PDO('mysql:host=127.0.0.1;port=3306;dbname=lucky_test;charset=utf8mb4','root','luckytestpassword',[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(MigrationSql::statements((string)file_get_contents(__DIR__.'/../lib/schema.sql')) as $sql)$db->exec($sql);
foreach(['v5q29e1_recording_sessions.sql','v5q29e4d1hf8c4_director_recordings.sql','v5q32e1_permanent_show_url.sql'] as $migration)
 foreach(MigrationSql::statements((string)file_get_contents(__DIR__.'/../lib/migrations/'.$migration)) as $sql)$db->exec($sql);
$recording=bin2hex(random_bytes(16));$foreign=bin2hex(random_bytes(16));$host=0;$other=0;
try{
 foreach(['e1-owner','e1-other'] as $name){
  $db->prepare('INSERT INTO admins(username,password_hash) VALUES (?,?)')->execute([$name.'-'.bin2hex(random_bytes(6)),password_hash('test',PASSWORD_DEFAULT)]);
  if($name==='e1-owner')$host=(int)$db->lastInsertId();else $other=(int)$db->lastInsertId();
 }
 $insert=$db->prepare('INSERT INTO recording_sessions(id,owner_admin_id,recording_type,title,description,status,create_nonce,request_hash) VALUES (?,?,?,?,?,?,?,?)');
 $insert->execute([$recording,$host,'live_event','Permanent Show','','active',bin2hex(random_bytes(12)),hash('sha256','e1')]);
 $insert->execute([$foreign,$other,'live_event','Foreign Show','','active',bin2hex(random_bytes(12)),hash('sha256','e1-other')]);
 e1DbCheck(e1DbReject(static fn()=>DirectorBroadcast::ensureShow($db,$foreign,$host)),'Foreign recording cannot claim a public address');
 $show=DirectorBroadcast::ensureShow($db,$recording,$host);
 e1DbCheck(strlen($show)===32&&ctype_xdigit($show),'Show URL slug is 128-bit random opaque identifier');
 e1DbCheck(DirectorBroadcast::ensureShow($db,$recording,$host)===$show,'Repeated allocations return the same show URL');
 e1DbCheck(e1DbReject(static fn()=>DirectorBroadcast::ensureShow($db,$recording,$other)),'Other host cannot steal existing permalink');
 e1DbCheck(!DirectorBroadcast::resolveShow($db,$show)['live'],'New show remains OFF AIR without publishing');
 e1DbCheck(e1DbReject(static fn()=>DirectorBroadcast::resolveShow($db,str_repeat('0',32))),'Unknown public URL returns not found');
 $private=DirectorBroadcast::startRecording($db,$recording,$host);
 e1DbCheck($private['showId']===$show&&$private['visibility']==='private','Private publication retains same show slug');
 e1DbCheck(!DirectorBroadcast::resolveShow($db,$show)['live'],'Permanent public page hides private Program');
 e1DbCheck(DirectorBroadcast::hostStatus($db,$recording,$host)['visibility']==='private','Host telemetry sees own private publication');
 e1DbCheck(e1DbReject(static fn()=>DirectorBroadcast::hostStatus($db,$recording,$other)),'Other host cannot inspect live status');
 $public=DirectorBroadcast::startRecording($db,$recording,$host,'public',true);
 $resolved=DirectorBroadcast::resolveShow($db,$show);
 e1DbCheck($public['showId']===$show&&$resolved['live']&&$resolved['broadcastId']===$public['id'],'Public broadcast resolves at the same bookmarked address');
 e1DbCheck(e1DbReject(static fn()=>DirectorBroadcast::watch($db,$private['id'],$private['key'])),'Switching public revokes previous private token');
 $viewer=bin2hex(random_bytes(16));
 $db->prepare('INSERT INTO director_viewers(id,broadcast_id,offer_sdp,updated_at) VALUES (?,?,?,UTC_TIMESTAMP())')
  ->execute([$viewer,$public['id'],"v=0\r\n"]);
 $status=DirectorBroadcast::hostStatus($db,$recording,$host);
 e1DbCheck($status['viewerCount']===1&&$status['maxViewers']===8,'Director dashboard counts recent signaling viewer sessions');
 $db->prepare('UPDATE director_broadcasts SET updated_at=UTC_TIMESTAMP()-INTERVAL 35 SECOND WHERE id=?')->execute([$public['id']]);
 e1DbCheck(!DirectorBroadcast::resolveShow($db,$show)['live'],'Stale broadcast heartbeats automatically switch page OFF AIR');
 DirectorBroadcast::stop($db,$public['id'],$host);
 e1DbCheck(!DirectorBroadcast::resolveShow($db,$show)['live'],'Stopped Program immediately becomes OFF AIR');
 $restarted=DirectorBroadcast::startRecording($db,$recording,$host,'public',true);
 e1DbCheck($restarted['id']!==$public['id']&&DirectorBroadcast::resolveShow($db,$show)['broadcastId']===$restarted['id'],'Second public publication reconnects same show permalink');
 $db->prepare("UPDATE recording_sessions SET status='completed' WHERE id=?")->execute([$recording]);
 e1DbCheck(e1DbReject(static fn()=>DirectorBroadcast::ensureShow($db,$recording,$host))&&!DirectorBroadcast::resolveShow($db,$show)['live'],'Completed session cannot publish and permalink remains OFF AIR');
 $db->prepare('DELETE FROM recording_sessions WHERE id=?')->execute([$recording]);
 e1DbCheck(e1DbReject(static fn()=>DirectorBroadcast::resolveShow($db,$show)),'Deleting recording cascades public show address');
 echo "V5Q32E1 PERMANENT SHOW DATABASE: 18/18\n";
}finally{
 $db->prepare('DELETE FROM director_broadcasts WHERE session_id=?')->execute([$recording]);
 $db->prepare('DELETE FROM recording_sessions WHERE id IN (?,?)')->execute([$recording,$foreign]);
 if($host)$db->prepare('DELETE FROM admins WHERE id=?')->execute([$host]);
 if($other)$db->prepare('DELETE FROM admins WHERE id=?')->execute([$other]);
}
