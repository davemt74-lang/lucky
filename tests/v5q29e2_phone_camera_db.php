<?php
declare(strict_types=1);
if(getenv('CI')!=='true')throw new RuntimeException('CI MariaDB only.');
define('LUCKY_TESTING',true);
require_once __DIR__.'/../lib/Bootstrap.php';
require_once __DIR__.'/../lib/MigrationSql.php';
require_once __DIR__.'/../lib/RecordingSessions.php';
require_once __DIR__.'/../lib/RecordingPhone.php';
function cameraAssert(bool $ok,string $label):void{if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label."\n";}
function cameraReject(callable $fn,int $code,string $label):void{
 try{$fn();throw new RuntimeException('Expected rejection: '.$label);}catch(DomainException $e){cameraAssert($e->getCode()===$code,$label);}
}
$db=new PDO('mysql:host=127.0.0.1;port=3306;dbname=lucky_test;charset=utf8mb4','root','luckytestpassword',[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(MigrationSql::statements((string)file_get_contents(__DIR__.'/../lib/schema.sql')) as $sql)$db->exec($sql);
foreach(['v5q_claim_media.sql','v5q29e1_recording_sessions.sql','v5q29e2_phone_camera.sql','v5q29e2_phone_camera.sql'] as $file){
 foreach(MigrationSql::statements((string)file_get_contents(__DIR__.'/../lib/migrations/'.$file)) as $sql)$db->exec($sql);
}
$owner=0;$other=0;$pairId=bin2hex(random_bytes(16));$token=bin2hex(random_bytes(32));
try{
 $db->prepare('INSERT INTO admins(username,password_hash) VALUES (?,?)')->execute(['camera-host-'.bin2hex(random_bytes(6)),password_hash('demo',PASSWORD_DEFAULT)]);$owner=(int)$db->lastInsertId();
 $db->prepare('INSERT INTO admins(username,password_hash) VALUES (?,?)')->execute(['camera-other-'.bin2hex(random_bytes(6)),password_hash('demo',PASSWORD_DEFAULT)]);$other=(int)$db->lastInsertId();
 $db->prepare("INSERT INTO controller_pairs(id,host_admin_id,invite_hash,controller_hash,device_name,status,invite_expires_at,paired_expires_at) VALUES (?,?,?,?,'Approved phone','paired',UTC_TIMESTAMP()+INTERVAL 30 MINUTE,UTC_TIMESTAMP()+INTERVAL 30 MINUTE)")
  ->execute([$pairId,$owner,hash('sha256','invite'),ControllerPairing::tokenHash($token)]);
 $event=RecordingSessions::create($db,$owner,['type'=>'live_event','title'=>'Family concert','nonce'=>'phone-session-001']);
 $pair=ControllerPairing::authenticate($db,$pairId,$token,true);
 cameraAssert(RecordingPhone::sessions($db,$pair)===[],'Draft recording cannot expose camera to phone');
 RecordingPhone::grant($db,$owner,$event['id'],true);
 cameraAssert(RecordingPhone::sessions($db,$pair)===[],'Host permission alone does not start recording');
 $active=RecordingSessions::setStatus($db,$owner,$event['id'],'active',1);
 $available=RecordingPhone::sessions($db,$pair);
 cameraAssert(count($available)===1&&$available[0]['id']===$event['id']&&$available[0]['takeId']===null,'Approved active event appears on paired phone without a game claim');
 $take=RecordingSessions::startTake($db,$owner,$event['id'],['title'=>'Camera angles','nonce'=>'phone-take-001']);
 $available=RecordingPhone::sessions($db,$pair);
 cameraAssert($available[0]['takeId']===$take['id'],'Multicamera take is shared with the phone camera');
 cameraReject(fn()=>RecordingPhone::grant($db,$other,$event['id'],false),404,'Another administrator cannot revoke owner camera');
 cameraReject(fn()=>RecordingPhone::store($db,$pairId,$token,['session'=>$event['id'],'nonce'=>'phone-clip-000001'],null),403,'Server rejects upload without explicit phone consent');
 RecordingPhone::grant($db,$owner,$event['id'],false);
 cameraAssert(RecordingPhone::sessions($db,$pair)===[],'Host revocation immediately removes active camera session');
 RecordingPhone::grant($db,$owner,$event['id'],true);
 RecordingSessions::finishTake($db,$owner,$event['id'],$take['id']);
 RecordingSessions::setStatus($db,$owner,$event['id'],'completed',(int)$active['revision']+0);
 cameraAssert(RecordingPhone::sessions($db,$pair)===[],'Completed event is unavailable even if phone toggle stays enabled');
 $db->prepare("UPDATE controller_pairs SET status='revoked',revoked_at=UTC_TIMESTAMP() WHERE id=?")->execute([$pairId]);
 cameraReject(fn()=>ControllerPairing::authenticate($db,$pairId,$token,true),403,'Revoked phone cannot authenticate');
 echo 'V5Q.29E2 PHONE CAMERA MARIADB CERTIFICATION PASSED'."\n";
}finally{
 if($owner)$db->prepare('DELETE FROM admins WHERE id=?')->execute([$owner]);
 if($other)$db->prepare('DELETE FROM admins WHERE id=?')->execute([$other]);
}
