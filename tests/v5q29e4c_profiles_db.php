<?php
declare(strict_types=1);
if(getenv('CI')!=='true')throw new RuntimeException('CI MariaDB only.');
define('LUCKY_TESTING',true);
require_once __DIR__.'/../lib/Bootstrap.php';
require_once __DIR__.'/../lib/MigrationSql.php';
require_once __DIR__.'/../lib/RecordingSessions.php';
require_once __DIR__.'/../lib/RecordingPhone.php';
require_once __DIR__.'/../lib/RecordingMulticam.php';
function profileCheck(bool $pass,string $label):void{if(!$pass)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label."\n";}
function profileReject(callable $fn,int $code,string $label):void{
 try{$fn();throw new RuntimeException('Expected failure: '.$label);}catch(DomainException $e){profileCheck($e->getCode()===$code,$label);}
}
$db=new PDO('mysql:host=127.0.0.1;port=3306;dbname=lucky_test;charset=utf8mb4','root','luckytestpassword',
 [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(MigrationSql::statements((string)file_get_contents(__DIR__.'/../lib/schema.sql')) as $sql)$db->exec($sql);
foreach(['v5q_claim_media.sql','v5q29e1_recording_sessions.sql','v5q29e2_phone_camera.sql','v5q29e3_multicamera_coordination.sql',
 'v5q29e4c_recording_profiles.sql','v5q29e4c_recording_profiles.sql'] as $file)
 foreach(MigrationSql::statements((string)file_get_contents(__DIR__.'/../lib/migrations/'.$file)) as $sql)$db->exec($sql);
$owner=0;$other=0;$gameId=bin2hex(random_bytes(16));$phones=[];
try{
 foreach(['profile-owner','profile-other'] as $name){$db->prepare('INSERT INTO admins(username,password_hash) VALUES (?,?)')->execute([$name.'-'.bin2hex(random_bytes(5)),password_hash('demo',PASSWORD_DEFAULT)]);if(!$owner)$owner=(int)$db->lastInsertId();else $other=(int)$db->lastInsertId();}
 $db->prepare("INSERT INTO game_sessions(id,game_id,status,version,state_json,viewer_token) VALUES (?,'spin-wheel','active',1,'{}',?)")
  ->execute([$gameId,bin2hex(random_bytes(24))]);
 for($i=0;$i<2;$i++){
  $id=bin2hex(random_bytes(16));$token=bin2hex(random_bytes(32));
  $db->prepare("INSERT INTO controller_pairs(id,host_admin_id,invite_hash,controller_hash,device_name,status,invite_expires_at,paired_expires_at) VALUES (?,?,?,?,?,'paired',UTC_TIMESTAMP()+INTERVAL 30 MINUTE,UTC_TIMESTAMP()+INTERVAL 30 MINUTE)")
   ->execute([$id,$owner,hash('sha256','invite'),ControllerPairing::tokenHash($token),'Capture phone '.($i+1)]);
  $phones[]=ControllerPairing::authenticate($db,$id,$token,true);
 }
 $data=['type'=>'production','title'=>'Birthday campaign','nonce'=>'profile-campaign-0001','tags'=>['birthday','wide'],
  'eventDate'=>'2026-10-25','location'=>'Main room','brief'=>'Film the cake','shotList'=>['Wide shot','Candles'],
  'capture'=>['multicameraEnabled'=>false,'maxCameras'=>8,'audio'=>false,'countdownSeconds'=>4]];
 $created=RecordingSessions::create($db,$owner,$data);
 profileCheck($created['profile']['capture']['maxCameras']===1&&$created['profile']['tags']===['birthday','wide'],'New recording captures immutable metadata and clamps single-camera capacity');
 profileCheck(RecordingSessions::create($db,$owner,$data)['id']===$created['id'],'Identical client nonce is idempotent');
 profileReject(fn()=>RecordingSessions::create($db,$owner,array_replace($data,['tags'=>['different']])),409,'Same nonce cannot silently change tags');
 profileReject(fn()=>RecordingSessions::get($db,$other,$created['id']),404,'Profile remains owner scoped');
 profileReject(fn()=>RecordingProfiles::kind($db,$created['id'],'audio'),403,'Disallowed audio capture rejected server-side');
 RecordingPhone::grant($db,$owner,$created['id'],true);
 RecordingSessions::setStatus($db,$owner,$created['id'],'active',1);
 $listed=RecordingPhone::sessions($db,$phones[0]);
 profileCheck(count($listed)===1&&!$listed[0]['capture']['audio']&&$listed[0]['brief']==='Film the cake','Phone receives allowed modes and shoot instructions');
 RecordingMulticam::arm($db,$phones[0],$created['id'],true,'video');
 profileReject(fn()=>RecordingMulticam::arm($db,$phones[1],$created['id'],true,'video'),409,'Second camera cannot bypass single-camera policy');
 profileReject(fn()=>RecordingMulticam::arm($db,$phones[1],$created['id'],true,'audio'),403,'Disallowed audio cannot be armed');
 RecordingMulticam::arm($db,$phones[0],$created['id'],false,'video');
 RecordingMulticam::arm($db,$phones[1],$created['id'],true,'video');
 profileCheck(count(RecordingMulticam::devices($db,$owner,$created['id']))===2,'Released camera slot may be reassigned');
 $private=RecordingSessions::create($db,$owner,['type'=>'general','title'=>'Camera-off','nonce'=>'profile-camera-off-0002',
  'capture'=>['phoneCameraEnabled'=>false,'video'=>false,'photo'=>true,'audio'=>false]]);
 profileReject(fn()=>RecordingPhone::grant($db,$owner,$private['id'],true),403,'No phone approval when recording profile forbids it');
 $game=RecordingSessions::create($db,$owner,['type'=>'game','title'=>'Spin prize footage','gameSessionId'=>$gameId,'nonce'=>'profile-game-0003',
  'capture'=>['phoneCameraEnabled'=>false,'video'=>false]]);
 profileCheck($game['profile']['capture']['video']===true,'Game recording ignores untrusted submitted overrides');
 profileCheck((int)$db->query("SELECT COUNT(*) FROM recording_session_profiles")->fetchColumn()>=3,'All new profile rows persisted in migration table');
 profileCheck(count(RecordingSessions::list($db,$owner))>=3,'Recording library exposes metadata for every group');
 echo "V5Q.29E4C RECORDING PROFILES MARIADB CERTIFIED\n";
}finally{
 $db->prepare('DELETE FROM game_sessions WHERE id=?')->execute([$gameId]);
 if($owner)$db->prepare('DELETE FROM admins WHERE id=?')->execute([$owner]);
 if($other)$db->prepare('DELETE FROM admins WHERE id=?')->execute([$other]);
}
