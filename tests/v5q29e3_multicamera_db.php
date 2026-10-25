<?php
declare(strict_types=1);
if(getenv('CI')!=='true')throw new RuntimeException('CI MariaDB only.');
define('LUCKY_TESTING',true);
require_once __DIR__.'/../lib/Bootstrap.php';
require_once __DIR__.'/../lib/MigrationSql.php';
require_once __DIR__.'/../lib/RecordingSessions.php';
require_once __DIR__.'/../lib/RecordingMulticam.php';
function mcAssert(bool $ok,string $label):void{if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label."\n";}
function mcReject(callable $fn,int $code,string $label):void{try{$fn();throw new RuntimeException('Expected rejection: '.$label);}catch(DomainException $e){mcAssert($e->getCode()===$code,$label);}}
$db=new PDO('mysql:host=127.0.0.1;port=3306;dbname=lucky_test;charset=utf8mb4','root','luckytestpassword',[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(MigrationSql::statements((string)file_get_contents(__DIR__.'/../lib/schema.sql')) as $sql)$db->exec($sql);
foreach(['v5q_claim_media.sql','v5q29e1_recording_sessions.sql','v5q29e2_phone_camera.sql','v5q29e3_multicamera_coordination.sql','v5q29e3_multicamera_coordination.sql'] as $file){
 foreach(MigrationSql::statements((string)file_get_contents(__DIR__.'/../lib/migrations/'.$file)) as $sql)$db->exec($sql);
}
$owner=0;$other=0;$phones=[];
try{
 foreach(['mc-owner','mc-other'] as $name){$db->prepare('INSERT INTO admins(username,password_hash) VALUES (?,?)')->execute([$name.'-'.bin2hex(random_bytes(6)),password_hash('demo',PASSWORD_DEFAULT)]);if(!$owner)$owner=(int)$db->lastInsertId();else $other=(int)$db->lastInsertId();}
 for($i=0;$i<2;$i++){
  $id=bin2hex(random_bytes(16));$token=bin2hex(random_bytes(32));
  $db->prepare("INSERT INTO controller_pairs(id,host_admin_id,invite_hash,controller_hash,device_name,status,invite_expires_at,paired_expires_at) VALUES (?,?,?,?,?,'paired',UTC_TIMESTAMP()+INTERVAL 30 MINUTE,UTC_TIMESTAMP()+INTERVAL 30 MINUTE)")
   ->execute([$id,$owner,hash('sha256','invite'),ControllerPairing::tokenHash($token),'Phone '.($i+1)]);
  $phones[]=ControllerPairing::authenticate($db,$id,$token,true);
 }
 $session=RecordingSessions::create($db,$owner,['type'=>'live_event','title'=>'Multicamera concert','nonce'=>'multicam-session-001']);
 mcReject(fn()=>RecordingMulticam::arm($db,$phones[0],$session['id'],true,'video'),403,'Draft session cannot arm a phone');
 RecordingPhone::grant($db,$owner,$session['id'],true);
 RecordingSessions::setStatus($db,$owner,$session['id'],'active',1);
 mcReject(fn()=>RecordingMulticam::arm($db,$phones[0],$session['id'],true,'photo'),422,'Photo is not a continuous multicamera capture mode');
 RecordingMulticam::arm($db,$phones[0],$session['id'],true,'video');
 RecordingMulticam::arm($db,$phones[1],$session['id'],true,'audio');
 RecordingMulticam::arm($db,$phones[0],$session['id'],true,'video');
 $devices=RecordingMulticam::devices($db,$owner,$session['id']);
 mcAssert(count($devices)===2&&$devices[0]['online']&&$devices[1]['online'],'Two distinct paired phones arm idempotently');
 mcReject(fn()=>RecordingMulticam::devices($db,$other,$session['id']),404,'Another administrator cannot list phones');
 mcAssert(RecordingMulticam::poll($db,$phones[0],$session['id'],'standby')['activeTake']===null,'Armed phone awaits host take');
 $take=RecordingSessions::startTake($db,$owner,$session['id'],['title'=>'Act 1','nonce'=>'multi-take-0001']);
 $a=RecordingMulticam::poll($db,$phones[0],$session['id'],'recording');$b=RecordingMulticam::poll($db,$phones[1],$session['id'],'recording');
 mcAssert($a['activeTake']['id']===$b['activeTake']['id']&&$a['activeTake']['startedAtMs']>1000000000000,'Both phones share one authoritative take ID and start timestamp');
 mcReject(fn()=>RecordingSessions::startTake($db,$owner,$session['id'],['title'=>'Overlapping','nonce'=>'multi-take-0002']),409,'A second take cannot overlap active multicamera take');
 RecordingSessions::finishTake($db,$owner,$session['id'],$take['id']);
 mcAssert(RecordingMulticam::poll($db,$phones[0],$session['id'],'standby')['activeTake']===null,'Host stop becomes idle command for both phones');
 RecordingMulticam::arm($db,$phones[0],$session['id'],false,'video');
 mcAssert(RecordingMulticam::poll($db,$phones[0],$session['id'],'standby')['armed']===false,'Phone can independently disarm');
 RecordingPhone::grant($db,$owner,$session['id'],false);
 mcReject(fn()=>RecordingMulticam::poll($db,$phones[1],$session['id'],'standby'),403,'Host revocation blocks next poll');
 echo 'V5Q.29E3 MULTICAMERA MARIADB CERTIFICATION PASSED' . "\n";
}finally{if($owner)$db->prepare('DELETE FROM admins WHERE id=?')->execute([$owner]);if($other)$db->prepare('DELETE FROM admins WHERE id=?')->execute([$other]);}
