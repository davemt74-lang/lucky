<?php
declare(strict_types=1);
if(getenv('CI')!=='true')throw new RuntimeException('CI MariaDB only.');
define('LUCKY_TESTING',true);
require_once __DIR__.'/../lib/Bootstrap.php';
require_once __DIR__.'/../lib/MigrationSql.php';
require_once __DIR__.'/../lib/LiveGameDirector.php';
function checkLiveDirectorDb(bool $ok,string $label):void {if(!$ok)throw new RuntimeException('FAIL '.$label);echo "PASS $label\n";}
function rejectLiveDirectorDb(callable $fn):bool {try{$fn();return false;}catch(DomainException){return true;}}
$db=new PDO('mysql:host=127.0.0.1;port=3306;dbname=lucky_test;charset=utf8mb4','root','luckytestpassword',[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(MigrationSql::statements((string)file_get_contents(__DIR__.'/../lib/schema.sql')) as $sql)$db->exec($sql);
foreach(MigrationSql::statements((string)file_get_contents(__DIR__.'/../lib/migrations/v5q29e1_recording_sessions.sql')) as $sql)$db->exec($sql);
$host=0;$phone=bin2hex(random_bytes(16));$session=bin2hex(random_bytes(16));
$recording=bin2hex(random_bytes(16));$take=bin2hex(random_bytes(16));$prize=bin2hex(random_bytes(16));
try{
 $db->prepare('INSERT INTO admins(username,password_hash) VALUES (?,?)')
  ->execute(['live-director-'.bin2hex(random_bytes(5)),password_hash('test',PASSWORD_DEFAULT)]);
 $host=(int)$db->lastInsertId();$_SESSION['admin_id']=$host;
 $db->prepare("INSERT INTO controller_pairs(id,host_admin_id,invite_hash,controller_hash,device_name,status,invite_expires_at,paired_expires_at) VALUES (?,?,?,?,?,'paired',UTC_TIMESTAMP(),UTC_TIMESTAMP()+INTERVAL 2 HOUR)")
  ->execute([$phone,$host,str_repeat('a',64),str_repeat('b',64),'Ada phone']);
 $state=GameEngine::newSession(GameEngine::defaults(),$session);$state['status']='active';
 $state['multiplayer']=['players'=>[['id'=>'player-1','name'=>'Ada','phonePairId'=>$phone],
  ['id'=>'player-2','name'=>'Ben','phonePairId'=>'']],'hosts'=>[['id'=>'host-1','name'=>'Host 1']],
  'turnIndex'=>0,'turnHistory'=>[['turnNumber'=>1,'at'=>gmdate('c'),'playerId'=>'player-1',
   'playerName'=>'Ada','prizeName'=>'Rocket','claimId'=>$prize]]];
 $state['claims']=[['id'=>$prize,'name'=>'Rocket','playerId'=>'player-1','playerName'=>'Ada','at'=>gmdate('c')]];
 $db->prepare("INSERT INTO game_sessions(id,game_id,status,version,state_json,viewer_token) VALUES (?,'spin-wheel','active',?,?,?)")
  ->execute([$session,$state['version'],json_encode($state,JSON_THROW_ON_ERROR),bin2hex(random_bytes(24))]);
 $db->prepare("INSERT INTO recording_sessions(id,owner_admin_id,recording_type,title,game_session_id,status,create_nonce,request_hash,started_at) VALUES (?,?,'game','Spin Wheel Recording',?,'active',?,?,UTC_TIMESTAMP(3))")
  ->execute([$recording,$host,$session,'director-'.bin2hex(random_bytes(6)),str_repeat('c',64)]);
 $db->prepare("INSERT INTO recording_takes(id,recording_session_id,take_index,title,status,client_nonce,request_hash,started_at) VALUES (?,?,1,'Live Take','recording',?,?,UTC_TIMESTAMP(3)-INTERVAL 1 MINUTE)")
  ->execute([$take,$recording,'take-'.bin2hex(random_bytes(5)),str_repeat('d',64)]);
 $context=LiveGameDirector::context($db,$state,$host);
 checkLiveDirectorDb($context['recording']['id']===$recording&&count($context['takes'])===1,
  'Director retrieves the recording bound to the same game across computers');
 checkLiveDirectorDb($context['operators'][0]['approved']===true&&$context['operators'][0]['pairId']===$phone,
  'Only paired approved player phones are available for camera assignment');
 checkLiveDirectorDb(count($context['moments'])===1&&$context['moments'][0]['takeId']===$take&&
  $context['moments'][0]['sync']==='estimated'&&$context['moments'][0]['offsetMs']>=0,
  'Prize moment is timestamped against recording take as estimated offset');
 $command=['action'=>'assign_camera','requestKey'=>'director_assign_phone8','claimId'=>$prize,'pairId'=>$phone,'note'=>''];
 $changed=LiveGameDirector::apply($db,$state,$host,$command);
 checkLiveDirectorDb($changed['changed']&&$state['director']['assignments'][$prize]===$phone&&
  $changed['event']['cameraOperator']==='Ada','Approved player becomes the selected prize cameraman');
 checkLiveDirectorDb(!LiveGameDirector::apply($db,$state,$host,$command)['changed'],
  'Duplicate camera assignment request does not create another event');
 saveState($db,$state);
 $fetched=stateFromRow(sessionRow($db,$session));
 checkLiveDirectorDb($fetched['director']['assignments'][$prize]===$phone&&$fetched['version']===$state['version'],
  'Director changes persist into the gameplay session for a second browser');
 $read=LiveGameDirector::context($db,$fetched,$host);
 checkLiveDirectorDb($read['assignments'][$prize]===$phone&&count($read['moments'])===2,
  'Remote Director refresh reads camera assignments and synchronized moments');
 $db->prepare("UPDATE controller_pairs SET status='revoked' WHERE id=?")->execute([$phone]);
 checkLiveDirectorDb(rejectLiveDirectorDb(static function()use($db,&$state,$host,$phone,$prize){
  LiveGameDirector::apply($db,$state,$host,['action'=>'assign_camera','requestKey'=>'director_revoke_phone9',
   'claimId'=>$prize,'pairId'=>$phone,'note'=>'']);
 }), 'Revoked phone is rejected for new prize-camera assignments');
 checkLiveDirectorDb(LiveGameDirector::context($db,$state,$host)['operators'][0]['approved']===false,
  'Phone revocation is reflected on the other Director screen');
 echo "V5Q32A LIVE DIRECTOR MARIADB: 9/9\n";
}finally{
 $db->prepare('DELETE FROM recording_sessions WHERE id=?')->execute([$recording]);
 $db->prepare('DELETE FROM game_sessions WHERE id=?')->execute([$session]);
 $db->prepare('DELETE FROM controller_pairs WHERE id=?')->execute([$phone]);
 if($host)$db->prepare('DELETE FROM admins WHERE id=?')->execute([$host]);
}
