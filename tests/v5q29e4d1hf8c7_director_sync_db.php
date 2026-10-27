<?php
declare(strict_types=1);
if(getenv('CI')!=='true')throw new RuntimeException('CI MariaDB only.');
define('LUCKY_TESTING',true);
require_once __DIR__.'/../lib/Bootstrap.php';
require_once __DIR__.'/../lib/MigrationSql.php';
require_once __DIR__.'/../lib/RecordingHostMedia.php';
function c7db(bool $ok,string $msg):void {if(!$ok)throw new RuntimeException('HF8C7: '.$msg);echo "PASS $msg\n";}
function c7reject(callable $fn):bool {try{$fn();return false;}catch(DomainException){return true;}}
$db=new PDO('mysql:host=127.0.0.1;port=3306;dbname=lucky_test;charset=utf8mb4','root','luckytestpassword',[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(MigrationSql::statements((string)file_get_contents(__DIR__.'/../lib/schema.sql')) as $sql)$db->exec($sql);
foreach(['v5q29e1_recording_sessions.sql','v5q29e2_phone_camera.sql','v5q29e3_multicamera_coordination.sql','v5q29e4c_recording_profiles.sql'] as $migration)
 foreach(MigrationSql::statements((string)file_get_contents(__DIR__.'/../lib/migrations/'.$migration)) as $sql)$db->exec($sql);
$owner=0;$other=0;$session=bin2hex(random_bytes(16));$take=bin2hex(random_bytes(16));$asset=bin2hex(random_bytes(16));
try{
 foreach(['host','other'] as $label){
  $db->prepare('INSERT INTO admins(username,password_hash) VALUES (?,?)')->execute(['c7-'.$label.'-'.bin2hex(random_bytes(5)),password_hash('test',PASSWORD_DEFAULT)]);
  if($label==='host')$owner=(int)$db->lastInsertId();else $other=(int)$db->lastInsertId();
 }
 $db->prepare("INSERT INTO recording_sessions(id,owner_admin_id,recording_type,title,description,status,create_nonce,request_hash) VALUES (?,?,'live_event','Director sync','', 'active',?,?)")
  ->execute([$session,$owner,bin2hex(random_bytes(12)),hash('sha256','director-sync')]);
 $db->prepare("INSERT INTO recording_takes(id,recording_session_id,take_index,title,client_nonce,request_hash) VALUES (?,?,1,'Director take',?,?)")
  ->execute([$take,$session,bin2hex(random_bytes(12)),hash('sha256','take')]);
 c7db(strlen(RecordingHostMedia::assetId($owner,'camera-take-unique-001'))===32,'Host asset key is storage-safe');
 c7db(RecordingHostMedia::assetId($owner,'camera-take-unique-001')===RecordingHostMedia::assetId($owner,'camera-take-unique-001'),'Retry keeps stable asset id');
 c7db(RecordingHostMedia::assetId($owner,'camera-take-unique-001')!==RecordingHostMedia::assetId($other,'camera-take-unique-001'),'Other owner has separate asset namespace');
 c7db(c7reject(static fn()=>RecordingHostMedia::assetId($owner,'?')),'Invalid client nonce is rejected');
 c7db(c7reject(static fn()=>RecordingHostMedia::store($db,$owner,['session'=>$session,'takeId'=>$take,'nonce'=>'camera-take-unique-001','sourceKey'=>'program'],null)),'Forged upload without PHP upload temp file rejected');
 $source=RecordingSessions::source($db,$owner,$session,['key'=>'director-program','kind'=>'camera','name'=>'Program Output']);
 c7db(strlen($source['id'])===32&&$source['kind']==='camera','Program source is a recording-session source');
 c7db(c7reject(static fn()=>RecordingSessions::source($db,$other,$session,['key'=>'director-other','kind'=>'desktop','name'=>'Forbidden'])),'Other admin cannot register a Director source');
 $db->prepare("INSERT INTO recording_assets(id,recording_session_id,take_id,source_id,storage_key,kind,start_offset_ms,sync_confidence) VALUES (?,?,?,?,?,'video',120,2)")
  ->execute([$asset,$session,$take,$source['id'],$asset]);
 $db->prepare("INSERT INTO recording_media_files(asset_id,mime,title,bytes,sha256,duration_ms) VALUES (?,'video/webm','Program Output',?,?,?)")
  ->execute([$asset,200,hash('sha256','mock-video'),5800]);
 $start=(int)round(microtime(true)*1000);
 $db->prepare("INSERT INTO recording_capture_sync(asset_id,recorded_start_ms,recorded_end_ms,clock_offset_ms,sync_method) VALUES (?,?,?,0,'director_clock')")
  ->execute([$asset,$start,$start+5800]);
 $q=$db->prepare("SELECT a.take_id,a.source_id,a.start_offset_ms,a.sync_confidence,s.sync_method FROM recording_assets a JOIN recording_capture_sync s ON s.asset_id=a.id WHERE a.id=?");$q->execute([$asset]);$row=$q->fetch(PDO::FETCH_ASSOC);
 c7db($row['take_id']===$take&&$row['source_id']===$source['id'],'Program original attaches to the group take and Program source');
 c7db((int)$row['start_offset_ms']===120&&(int)$row['sync_confidence']===2&&$row['sync_method']==='director_clock','Editor metadata distinguishes Director-clock alignment');
 $db->prepare('DELETE FROM recording_sessions WHERE id=?')->execute([$session]);
 $q=$db->prepare('SELECT COUNT(*) FROM recording_capture_sync WHERE asset_id=?');$q->execute([$asset]);
 c7db((int)$q->fetchColumn()===0,'Removing session cascades to synchronized originals');
 echo "HF8C7 DIRECTOR SYNC DATABASE: 10/10\n";
}finally{
 $db->prepare('DELETE FROM recording_sessions WHERE id=?')->execute([$session]);
 if($owner)$db->prepare('DELETE FROM admins WHERE id=?')->execute([$owner]);
 if($other)$db->prepare('DELETE FROM admins WHERE id=?')->execute([$other]);
}
