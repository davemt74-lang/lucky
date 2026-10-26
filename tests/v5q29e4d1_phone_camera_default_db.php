<?php
declare(strict_types=1);
if(getenv('CI')!=='true')throw new RuntimeException('CI MariaDB only.');
define('LUCKY_TESTING',true);
require_once __DIR__.'/../lib/Bootstrap.php';
require_once __DIR__.'/../lib/MigrationSql.php';
require_once __DIR__.'/../lib/RecordingSessions.php';
require_once __DIR__.'/../lib/RecordingPhone.php';
require_once __DIR__.'/../lib/PrizePackages.php';
function checkAuto(bool $ok,string $name):void {if(!$ok)throw new RuntimeException('FAIL '.$name);echo 'PASS '.$name."\n";}
$db=new PDO('mysql:host=127.0.0.1;port=3306;dbname=lucky_test;charset=utf8mb4','root','luckytestpassword',[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false
]);
foreach(MigrationSql::statements((string)file_get_contents(__DIR__.'/../lib/schema.sql')) as $sql)$db->exec($sql);
foreach(['v5q_claim_media.sql','v5q29e1_recording_sessions.sql','v5q29e2_phone_camera.sql',
 'v5q29e3_multicamera_coordination.sql','v5q29e4c_recording_profiles.sql'] as $name)
 foreach(MigrationSql::statements((string)file_get_contents(__DIR__.'/../lib/migrations/'.$name)) as $sql)$db->exec($sql);
checkAuto(PrizePackages::ensurePhoneCameraColumn($db)===false,'New prize packages include phone camera setting');
$db->prepare('INSERT INTO game_settings(game_id,config_json,intro_json,theme_json) VALUES (?,?,?,?)')->execute([
 'spin-wheel',json_encode(GameEngine::defaults(),JSON_THROW_ON_ERROR),'{}','{}'
]);
PrizePackages::read($db,true);
$db->beginTransaction();
$cameraOff=PrizePackages::create($db,'Camera disabled',null,null,false,false);
$cameraDefault=PrizePackages::create($db,'Default camera');
$cameraCloned=PrizePackages::create($db,'Default when cloning disabled',$cameraOff['id']);
$db->commit();
checkAuto($cameraOff['phoneCameraEnabled']===false,'New package honors disabled phone camera checkbox');
checkAuto($cameraDefault['phoneCameraEnabled']===true,'New package defaults camera on');
checkAuto($cameraCloned['phoneCameraEnabled']===true,'Cloning disabled package still defaults camera on');
$persisted=PrizePackages::read($db,true)['packages'];
$offRows=array_values(array_filter($persisted,fn($p)=>$p['id']===$cameraOff['id']));
checkAuto(count($offRows)===1&&$offRows[0]['phoneCameraEnabled']===false,'Disabled camera setting persists');
$owner=0;$game=bin2hex(random_bytes(16));
try {
 $db->prepare('INSERT INTO admins(username,password_hash) VALUES (?,?)')
  ->execute(['auto-camera-'.bin2hex(random_bytes(6)),password_hash('demo',PASSWORD_DEFAULT)]);
 $owner=(int)$db->lastInsertId();
 $db->prepare("INSERT INTO game_sessions(id,game_id,status,version,state_json,viewer_token) VALUES (?,'spin-wheel','active',1,'{}',?)")
  ->execute([$game,bin2hex(random_bytes(24))]);
 $db->beginTransaction();
 $recordingId=RecordingSessions::autoSpinWheel($db,$owner,$game,'Casual Adulting');
 checkAuto(RecordingSessions::autoSpinWheel($db,$owner,$game,'Casual Adulting')===$recordingId,'Automatic group is idempotent in one launch');
 $db->commit();
 $recording=RecordingSessions::get($db,$owner,$recordingId);
 checkAuto($recording['status']==='active'&&$recording['gameSessionId']===$game,'Game starts with linked active recording');
 checkAuto($recording['profile']['capture']['phoneCameraEnabled']===true,'Game profile allows paired phone camera');
 $pair=['status'=>'paired','paired_expires_at'=>gmdate('Y-m-d H:i:s',time()+3600),'host_admin_id'=>$owner];
 $available=RecordingPhone::sessions($db,$pair);
 checkAuto(count(array_filter($available,fn($r)=>$r['id']===$recordingId))===1,'Paired controller sees automatic game camera group');
 RecordingPhone::grant($db,$owner,$recordingId,false);
 $available=RecordingPhone::sessions($db,$pair);
 checkAuto(!array_filter($available,fn($r)=>$r['id']===$recordingId),'Host revocation removes the camera group immediately');
 echo "V5Q.29E4D1 PHONE CAMERA DEFAULT MARIADB CERTIFICATION PASSED\n";
} finally {
 if($owner)$db->prepare('DELETE FROM admins WHERE id=?')->execute([$owner]);
 $db->prepare('DELETE FROM game_sessions WHERE id=?')->execute([$game]);
}
