<?php
declare(strict_types=1);
if(getenv('CI')!=='true')throw new RuntimeException('HF8C5 database acceptance runs in CI MariaDB only.');
define('LUCKY_TESTING',true);
require_once __DIR__.'/../lib/Bootstrap.php';
require_once __DIR__.'/../lib/MigrationSql.php';
require_once __DIR__.'/../lib/RecordingLiveSignaling.php';
function c5db(bool $ok,string $label):void {
 if(!$ok)throw new RuntimeException('HF8C5: '.$label);
 echo "PASS $label\n";
}
function c5reject(callable $fn):bool {
 try{$fn();return false;}catch(DomainException){return true;}
}
$db=new PDO('mysql:host=127.0.0.1;port=3306;dbname=lucky_test;charset=utf8mb4','root','luckytestpassword',[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(MigrationSql::statements((string)file_get_contents(__DIR__.'/../lib/schema.sql')) as $sql)$db->exec($sql);
foreach(['v5q29e1_recording_sessions.sql','v5q29e2_phone_camera.sql','v5q29e3_multicamera_coordination.sql','v5q29e4d1hf8c5_recording_live.sql'] as $migration)
 foreach(MigrationSql::statements((string)file_get_contents(__DIR__.'/../lib/migrations/'.$migration)) as $sql)$db->exec($sql);
$host=0;$other=0;$session=bin2hex(random_bytes(16));$foreign=bin2hex(random_bytes(16));
$pairId=bin2hex(random_bytes(16));$otherPairId=bin2hex(random_bytes(16));$stalePairId=bin2hex(random_bytes(16));
$nonce=bin2hex(random_bytes(16));$nextNonce=bin2hex(random_bytes(16));$offer="v=0\r\no=- 2 2 IN IP4 127.0.0.1\r\ns=recording-live-offer\r\n";
$answer="v=0\r\no=- 3 3 IN IP4 127.0.0.1\r\ns=recording-live-answer\r\n";
try{
 foreach(['c5-host','c5-foreign'] as $name){
  $db->prepare('INSERT INTO admins(username,password_hash) VALUES (?,?)')->execute([$name.'-'.bin2hex(random_bytes(5)),password_hash('test',PASSWORD_DEFAULT)]);
  if($name==='c5-host')$host=(int)$db->lastInsertId();else $other=(int)$db->lastInsertId();
 }
 $makeSession=$db->prepare('INSERT INTO recording_sessions(id,owner_admin_id,recording_type,title,description,status,create_nonce,request_hash) VALUES (?,?,?,?,?,?,?,?)');
 $makeSession->execute([$session,$host,'production','C5 production','','active',bin2hex(random_bytes(12)),hash('sha256','c5-session')]);
 $makeSession->execute([$foreign,$other,'live_event','C5 foreign','','active',bin2hex(random_bytes(12)),hash('sha256','c5-foreign')]);
 $db->prepare('INSERT INTO recording_phone_permissions(recording_session_id,phone_enabled) VALUES (?,1),(?,1)')->execute([$session,$foreign]);
 $makePair=$db->prepare("INSERT INTO controller_pairs(id,host_admin_id,invite_hash,controller_hash,device_name,status,invite_expires_at,paired_expires_at) VALUES (?,?,?,?,?,'paired',DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 HOUR),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 HOUR))");
 $makePair->execute([$pairId,$host,hash('sha256','invite'),hash('sha256','controller'),'Camera Alpha']);
 $makePair->execute([$otherPairId,$other,hash('sha256','invite2'),hash('sha256','controller2'),'Other camera']);
 $makePair->execute([$stalePairId,$host,hash('sha256','invite3'),hash('sha256','controller3'),'Stale camera']);
 $arm=$db->prepare("INSERT INTO recording_camera_arms(recording_session_id,pair_id,capture_kind,armed,last_state,last_seen_at) VALUES (?,?,'video',1,'standby',UTC_TIMESTAMP(3))");
 $arm->execute([$session,$pairId]);$arm->execute([$foreign,$otherPairId]);
 $arm->execute([$session,$stalePairId]);
 $db->prepare('UPDATE recording_camera_arms SET last_seen_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 60 SECOND) WHERE pair_id=?')->execute([$stalePairId]);
 $phone=['id'=>$pairId,'host_admin_id'=>$host,'status'=>'paired','paired_expires_at'=>gmdate('Y-m-d H:i:s',time()+3600)];
 $otherPhone=['id'=>$otherPairId,'host_admin_id'=>$other,'status'=>'paired','paired_expires_at'=>gmdate('Y-m-d H:i:s',time()+3600)];
 $stalePhone=['id'=>$stalePairId,'host_admin_id'=>$host,'status'=>'paired','paired_expires_at'=>gmdate('Y-m-d H:i:s',time()+3600)];
 c5db(c5reject(static fn()=>RecordingLiveSignaling::listOffers($db,$session,$other)),'Foreign host cannot list recording camera SDP');
 c5db(c5reject(static fn()=>RecordingLiveSignaling::offer($db,$otherPhone,$session,$nonce,$offer)),'Foreign phone cannot offer into host recording');
 c5db(c5reject(static fn()=>RecordingLiveSignaling::offer($db,$stalePhone,$session,$nonce,$offer)),'Stale arm cannot advertise preview');
 c5db(c5reject(static fn()=>RecordingLiveSignaling::offer($db,$phone,$session,$nonce,'not an SDP')),'Invalid SDP refused');
 RecordingLiveSignaling::offer($db,$phone,$session,$nonce,$offer);
 $offers=RecordingLiveSignaling::listOffers($db,$session,$host);
 c5db(count($offers)===1&&$offers[0]['id']===$pairId&&$offers[0]['name']==='Camera Alpha','Host sees only approved, armed live phone');
 c5db($offers[0]['offer']===$offer&&$offers[0]['nonce']===$nonce,'Offer SDP and nonce are scoped to paired phone');
 c5db(RecordingLiveSignaling::poll($db,$phone,$session,$nonce)['answer']===null,'Phone polls before host accepts connection');
 c5db(c5reject(static fn()=>RecordingLiveSignaling::answer($db,$session,$other,$pairId,$nonce,$answer)),'Foreign host cannot answer offer');
 c5db(c5reject(static fn()=>RecordingLiveSignaling::answer($db,$session,$host,$pairId,bin2hex(random_bytes(16)),$answer)),'Wrong nonce cannot answer offer');
 RecordingLiveSignaling::answer($db,$session,$host,$pairId,$nonce,$answer);
 c5db(RecordingLiveSignaling::poll($db,$phone,$session,$nonce)['answer']===$answer,'Authorized host answer reaches phone');
 c5db(c5reject(static fn()=>RecordingLiveSignaling::answer($db,$session,$host,$pairId,$nonce,$answer)),'Answer is single-use');
 RecordingLiveSignaling::offer($db,$phone,$session,$nextNonce,$offer);
 c5db(c5reject(static fn()=>RecordingLiveSignaling::poll($db,$phone,$session,$nonce)),'Reoffer invalidates stale phone nonce');
 c5db(RecordingLiveSignaling::poll($db,$phone,$session,$nextNonce)['answer']===null,'Reoffer clears stale host answer');
 $db->prepare('UPDATE recording_camera_arms SET armed=0 WHERE recording_session_id=? AND pair_id=?')->execute([$session,$pairId]);
 c5db(c5reject(static fn()=>RecordingLiveSignaling::poll($db,$phone,$session,$nextNonce)),'Disarm immediately denies private phone signaling');
 c5db(count(RecordingLiveSignaling::listOffers($db,$session,$host))===0,'Disarmed phone disappears from host live list');
 $db->prepare('UPDATE recording_camera_arms SET armed=1,last_seen_at=UTC_TIMESTAMP(3) WHERE recording_session_id=? AND pair_id=?')->execute([$session,$pairId]);
 $db->prepare('UPDATE recording_phone_permissions SET phone_enabled=0 WHERE recording_session_id=?')->execute([$session]);
 c5db(c5reject(static fn()=>RecordingLiveSignaling::listOffers($db,$session,$host))&&c5reject(static fn()=>RecordingLiveSignaling::poll($db,$phone,$session,$nextNonce)),'Revoking phone permission blocks host and phone');
 $db->prepare('UPDATE recording_phone_permissions SET phone_enabled=1 WHERE recording_session_id=?')->execute([$session]);
 RecordingLiveSignaling::end($db,$phone,$nextNonce);
 c5db(RecordingLiveSignaling::listOffers($db,$session,$host)===[],'Phone exit removes its private signal');
 RecordingLiveSignaling::offer($db,$phone,$session,$nonce,$offer);
 $db->prepare("UPDATE recording_sessions SET status='completed' WHERE id=?")->execute([$session]);
 c5db(c5reject(static fn()=>RecordingLiveSignaling::listOffers($db,$session,$host))&&c5reject(static fn()=>RecordingLiveSignaling::poll($db,$phone,$session,$nonce)),'Completed session revokes preview immediately');
 $db->prepare('DELETE FROM recording_sessions WHERE id=?')->execute([$session]);
 $q=$db->prepare('SELECT COUNT(*) FROM recording_live_streams WHERE recording_session_id=?');$q->execute([$session]);
 c5db((int)$q->fetchColumn()===0,'Deleting session cascades private live signal');
 echo "HF8C5 RECORDING LIVE DATABASE PASSED\n";
}finally{
 $db->prepare('DELETE FROM recording_sessions WHERE id IN (?,?)')->execute([$session,$foreign]);
 $db->prepare('DELETE FROM controller_pairs WHERE id IN (?,?,?)')->execute([$pairId,$otherPairId,$stalePairId]);
 if($host)$db->prepare('DELETE FROM admins WHERE id=?')->execute([$host]);
 if($other)$db->prepare('DELETE FROM admins WHERE id=?')->execute([$other]);
}
