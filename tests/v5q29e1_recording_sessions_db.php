<?php
declare(strict_types=1);
if(getenv('CI')!=='true')throw new RuntimeException('CI MariaDB only.');
define('LUCKY_TESTING',true);
require_once __DIR__.'/../lib/Bootstrap.php';
require_once __DIR__.'/../lib/MigrationSql.php';
require_once __DIR__.'/../lib/RecordingSessions.php';

function ensureRec(bool $ok,string $label):void{if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label."\n";}
function rejectRec(callable $fn,int $code,string $label):void {
 try{$fn();throw new RuntimeException('Expected rejection: '.$label);}catch(DomainException $e){ensureRec($e->getCode()===$code,$label);}
}
$db=new PDO('mysql:host=127.0.0.1;port=3306;dbname=lucky_test;charset=utf8mb4','root','luckytestpassword',[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(MigrationSql::statements((string)file_get_contents(__DIR__.'/../lib/schema.sql')) as $sql)$db->exec($sql);
foreach(['v5q_claim_media.sql','v5q29e1_recording_sessions.sql','v5q29e1_recording_sessions.sql'] as $name){
 foreach(MigrationSql::statements((string)file_get_contents(__DIR__.'/../lib/migrations/'.$name)) as $sql)$db->exec($sql);
}
ensureRec((bool)$db->query("SHOW TABLES LIKE 'recording_assets'")->fetch(),'Recording schema migration is idempotent');
$owner=0;$stranger=0;$game=bin2hex(random_bytes(16));$otherGame=bin2hex(random_bytes(16));
$claim=bin2hex(random_bytes(16));$clip=bin2hex(random_bytes(16));
$otherClaim=bin2hex(random_bytes(16));$otherClip=bin2hex(random_bytes(16));
try{
 $db->prepare('INSERT INTO admins(username,password_hash) VALUES (?,?)')->execute(['rec-admin-'.bin2hex(random_bytes(6)),password_hash('demo',PASSWORD_DEFAULT)]);$owner=(int)$db->lastInsertId();
 $db->prepare('INSERT INTO admins(username,password_hash) VALUES (?,?)')->execute(['rec-other-'.bin2hex(random_bytes(6)),password_hash('demo',PASSWORD_DEFAULT)]);$stranger=(int)$db->lastInsertId();
 foreach([$game,$otherGame] as $sessionId)$db->prepare("INSERT INTO game_sessions(id,game_id,status,version,state_json,viewer_token) VALUES (?,'spin-wheel','active',1,'{}',?)")->execute([$sessionId,bin2hex(random_bytes(24))]);
 foreach([[$claim,$game,$clip],[$otherClaim,$otherGame,$otherClip]] as [$claimId,$sessionId,$mediaId]){
  $db->prepare("INSERT INTO game_claims(id,session_id,prize_id,tier,prize_name,cents,created_at) VALUES (?,?,?,'tier1','Sample',0,UTC_TIMESTAMP())")->execute([$claimId,$sessionId,'p-'.bin2hex(random_bytes(6))]);
  $db->prepare("INSERT INTO claim_media(id,session_id,claim_id,kind,mime,title,bytes,sort_order) VALUES (?,?,?,'video','video/webm','Original media',20,1)")->execute([$mediaId,$sessionId,$claimId]);
 }
 rejectRec(fn()=>RecordingSessions::create($db,$owner,['type'=>'invalid','title'=>'Bad','nonce'=>'validnonce123']),422,'Unknown recording types rejected');
 rejectRec(fn()=>RecordingSessions::create($db,$owner,['type'=>'game','title'=>'No game','nonce'=>'validnonce123']),422,'Game recording requires real game link');
 $details=['type'=>'game','title'=>'Friday Game Night','description'=>'Spin the Wheel footage','gameSessionId'=>$game,'nonce'=>'recording-make-001'];
 $first=RecordingSessions::create($db,$owner,$details);
 ensureRec($first['type']==='game'&&$first['status']==='draft'&&$first['gameSessionId']===$game,'Game recordings are linked and initially inactive');
 ensureRec(RecordingSessions::create($db,$owner,$details)['id']===$first['id'],'Repeated creation nonce returns one session');
 rejectRec(fn()=>RecordingSessions::create($db,$owner,array_merge($details,['title'=>'Different title'])),409,'Changed payload with same nonce cannot silently overwrite session');
 $independent=RecordingSessions::create($db,$owner,['type'=>'live_event','title'=>'Birthday party','description'=>'Live event outside a game','nonce'=>'live-recording-001']);
 ensureRec($independent['gameSessionId']===null,'Live events can exist without a game or prize');
 rejectRec(fn()=>RecordingSessions::get($db,$stranger,$first['id']),404,'Another administrator cannot read recording session');
 rejectRec(fn()=>RecordingSessions::setStatus($db,$stranger,$first['id'],'active',1),404,'Another administrator cannot update recording session');
 $source=RecordingSessions::source($db,$owner,$first['id'],['key'=>'daves-phone','kind'=>'phone','name'=>'Dave phone']);
 ensureRec(RecordingSessions::source($db,$owner,$first['id'],['key'=>'daves-phone','kind'=>'phone','name'=>'Dave phone'])['id']===$source['id'],'Phone source registration is idempotent');
 rejectRec(fn()=>RecordingSessions::source($db,$owner,$first['id'],['key'=>'daves-phone','kind'=>'desktop','name'=>'Desktop']),409,'Source key cannot be rebound to a different camera');
 $take=RecordingSessions::startTake($db,$owner,$first['id'],['title'=>'Winner reaction','nonce'=>'take-request-001']);
 ensureRec($take['index']===1&&$take['status']==='recording','First take starts and activates the group');
 ensureRec(RecordingSessions::startTake($db,$owner,$first['id'],['title'=>'Winner reaction','nonce'=>'take-request-001'])['id']===$take['id'],'Repeated take request cannot duplicate recording');
 rejectRec(fn()=>RecordingSessions::setStatus($db,$owner,$first['id'],'completed',2),409,'Cannot complete session while a take is recording');
 $asset=RecordingSessions::attachClaim($db,$owner,$first['id'],$clip,$take['id'],$source['id']);
 ensureRec($asset['existing']===false,'Legacy media linked without moving or duplicating original file');
 ensureRec(RecordingSessions::attachClaim($db,$owner,$first['id'],$clip,$take['id'],$source['id'])['existing']===true,'Repeated media attachment is idempotent');
 rejectRec(fn()=>RecordingSessions::attachClaim($db,$owner,$first['id'],$otherClip),403,'Different game clip cannot cross recording boundary');
 rejectRec(fn()=>RecordingSessions::attachClaim($db,$owner,$independent['id'],$clip),422,'Event cannot claim a prize recording outside a linked game');
 $done=RecordingSessions::finishTake($db,$owner,$first['id'],$take['id']);
 ensureRec($done['status']==='completed','Take can be stopped independently');
 ensureRec(RecordingSessions::finishTake($db,$owner,$first['id'],$take['id'])['status']==='completed','Repeated stop does not duplicate or alter completed take');
 rejectRec(fn()=>RecordingSessions::setStatus($db,$owner,$first['id'],'completed',1),409,'Stale session revision is refused');
 $completed=RecordingSessions::setStatus($db,$owner,$first['id'],'completed',2);
 ensureRec($completed['status']==='completed'&&$completed['revision']===3,'Optimistic session lifecycle persists final state');
 rejectRec(fn()=>RecordingSessions::startTake($db,$owner,$first['id'],['title'=>'Too late','nonce'=>'late-take-001']),409,'Completed recording cannot silently start new take');
 $import=RecordingSessions::importGame($db,$owner,$otherGame);
 ensureRec($import['added']===1&&$import['session']['type']==='game','Existing game footage groups into its own recording session');
 $again=RecordingSessions::importGame($db,$owner,$otherGame);
 ensureRec($again['added']===0&&$again['session']['id']===$import['session']['id'],'Legacy import resumes safely without duplicate assets');
 $q=$db->prepare('SELECT COUNT(*) FROM claim_media WHERE id IN (?,?)');$q->execute([$clip,$otherClip]);
 ensureRec((int)$q->fetchColumn()===2,'Legacy grouping preserves all original claim media');
 $detail=RecordingSessions::detail($db,$owner,$first['id']);
 ensureRec(count($detail['takes'])===1&&count($detail['sources'])===1&&count($detail['assets'])===1,'Detail returns bounded session-scoped takes, sources, and assets');
 ensureRec(count(RecordingSessions::list($db,$owner))>=3&&RecordingSessions::list($db,$stranger)===[],'Catalog isolates session owners');
 echo 'V5Q.29E1 RECORDING SESSIONS MARIADB CERTIFICATION PASSED'."\n";
}finally{
 if($owner){$db->prepare('DELETE FROM admins WHERE id=?')->execute([$owner]);}
 if($stranger){$db->prepare('DELETE FROM admins WHERE id=?')->execute([$stranger]);}
 foreach([$game,$otherGame] as $id)$db->prepare('DELETE FROM game_sessions WHERE id=?')->execute([$id]);
}
