<?php
declare(strict_types=1);
if(getenv('CI')!=='true')throw new RuntimeException('CI MariaDB only.');
define('LUCKY_TESTING',true);
require_once __DIR__.'/../lib/Bootstrap.php';
require_once __DIR__.'/../lib/MigrationSql.php';
require_once __DIR__.'/../lib/ProofHandoff.php';
require_once __DIR__.'/../lib/DirectorBroadcast.php';

function heartbeatDb():PDO {
 $db=new PDO('mysql:host=127.0.0.1;port=3306;dbname=lucky_test;charset=utf8mb4','root','luckytestpassword',[
  PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
 $db->exec("SET time_zone='+00:00'");
 $db->exec('SET SESSION innodb_lock_wait_timeout=5');
 return $db;
}
$sdp="v=0\r\no=- 1 1 IN IP4 127.0.0.1\r\ns=-\r\nt=0 0\r\na=recvonly\r\n";
// Parallel processes use their own real MariaDB connections and only the
// parent's isolated test broadcast. No HTTP service or external network call.
if(($argv[1]??'')==='--offer-worker'){
 $worker=heartbeatDb();
 echo "READY\n";fflush(STDOUT);
 if(fgets(STDIN)!=="GO\n")throw new RuntimeException('Missing admission barrier.');
 try{
  DirectorBroadcast::offer($worker,(string)$argv[2],(string)$argv[3],(string)$argv[4],$sdp);
  echo "accepted\n";
 }catch(DomainException $e){
  if($e->getCode()!==409||!str_contains($e->getMessage(),'viewer limit'))throw $e;
  echo "capacity\n";
 }
 exit(0);
}

$checks=0;
function heartbeatCheck(bool $ok,string $label):void {
 global $checks;
 if(!$ok)throw new RuntimeException('FAIL '.$label);
 ++$checks;echo "PASS $label\n";
}
function heartbeatReject(callable $run,int $code):bool {
 try{$run();return false;}catch(DomainException $e){return $e->getCode()===$code;}
}
$db=heartbeatDb();
foreach(MigrationSql::statements((string)file_get_contents(__DIR__.'/../lib/schema.sql')) as $sql)$db->exec($sql);
foreach(['v5q_claim_media.sql','v5q29e1_recording_sessions.sql','v5q29e4d1hf8b_director_live.sql',
 'v5q29e4d1hf8c4_director_recordings.sql','v5q32e1_permanent_show_url.sql'] as $migration)
 foreach(MigrationSql::statements((string)file_get_contents(__DIR__.'/../lib/migrations/'.$migration)) as $sql)$db->exec($sql);
$host=0;$other=0;$children=[];
$recording=bin2hex(random_bytes(16));$foreignRecording=bin2hex(random_bytes(16));
$session=bin2hex(random_bytes(16));$claim=bin2hex(random_bytes(16));
$clock=(int)$db->query('SELECT UNIX_TIMESTAMP()')->fetchColumn();
// MariaDB's session clock drives UTC_TIMESTAMP, TIMESTAMP defaults and expiry
// predicates. Advance real database behavior deterministically, without sleeps.
$db->exec('SET timestamp='.$clock);
$advance=static function(int $seconds)use($db,&$clock):void{$clock+=$seconds;$db->exec('SET timestamp='.$clock);};
$stamp=static function(string $viewer)use($db):string|false{
 $q=$db->prepare('SELECT updated_at FROM director_viewers WHERE id=?');$q->execute([$viewer]);return $q->fetchColumn();
};
$answer=static function(string $viewer)use($db):array|false{
 $q=$db->prepare('SELECT answer_sdp FROM director_viewers WHERE id=?');$q->execute([$viewer]);return $q->fetch();
};
$age=static function(string $viewer,int $seconds)use($db):void{
 $q=$db->prepare('UPDATE director_viewers SET updated_at=UTC_TIMESTAMP()-INTERVAL '.(int)$seconds.' SECOND WHERE id=?');$q->execute([$viewer]);
};
try{
 foreach(['heartbeat-host','heartbeat-other'] as $name){
  $db->prepare('INSERT INTO admins(username,password_hash) VALUES (?,?)')
   ->execute([$name.'-'.bin2hex(random_bytes(6)),password_hash('fixture',PASSWORD_DEFAULT)]);
  if($host===0)$host=(int)$db->lastInsertId();else $other=(int)$db->lastInsertId();
 }
 $insert=$db->prepare('INSERT INTO recording_sessions(id,owner_admin_id,recording_type,title,description,status,create_nonce,request_hash) VALUES (?,?,?,?,?,?,?,?)');
 foreach([$recording,$foreignRecording] as $recordingId)
  $insert->execute([$recordingId,$host,'live_event','Viewer heartbeat fixture','','active',bin2hex(random_bytes(12)),hash('sha256',$recordingId)]);

 $broadcast=DirectorBroadcast::startRecording($db,$recording,$host);
 $id=$broadcast['id'];$key=$broadcast['key'];$viewer=bin2hex(random_bytes(16));
 DirectorBroadcast::offer($db,$id,$key,$viewer,$sdp);
 heartbeatCheck(DirectorBroadcast::answerForViewer($db,$id,$key,$viewer)===['answer'=>null],
  'Pending authorized viewer can heartbeat before the host answer');
 $initial=$stamp($viewer);$advance(5);
 DirectorBroadcast::watch($db,$id,$key);DirectorBroadcast::offersForHost($db,$id,$host);
 heartbeatCheck($stamp($viewer)===$initial,'Watch and host offer polling do not impersonate viewer activity');
 DirectorBroadcast::answer($db,$id,$host,$viewer,$sdp);
 heartbeatCheck($stamp($viewer)===$initial,'Host answer preserves the viewer heartbeat timestamp');
 heartbeatCheck(DirectorBroadcast::answerForViewer($db,$id,$key,$viewer)['answer']===$sdp&&$stamp($viewer)!==$initial,
  'Viewer poll delivers the answer and refreshes only its admitted row');
 heartbeatCheck(DirectorBroadcast::answerForViewer($db,$id,$key,$viewer)['answer']===$sdp,
  'Repeated polls within one database second are valid despite zero changed rows');
 for($i=0;$i<12;$i++){
  $advance(10);DirectorBroadcast::offersForHost($db,$id,$host);
  heartbeatCheck(DirectorBroadcast::answerForViewer($db,$id,$key,$viewer)['answer']===$sdp&&
   DirectorBroadcast::hostStatus($db,$recording,$host)['viewerCount']===1,
   'Authorized active viewer survives '.(($i+1)*10).' seconds with accurate host count');
 }
 $q=$db->prepare('SELECT TIMESTAMPDIFF(SECOND,created_at,updated_at) FROM director_viewers WHERE id=?');$q->execute([$viewer]);
 heartbeatCheck((int)$q->fetchColumn()>=120,'Database confirms the same admitted row remains active beyond 30 seconds');

 $advance(1);$before=$stamp($viewer);
 foreach(['',str_repeat('0',48),'malformed'] as $invalid)
  heartbeatCheck(heartbeatReject(static fn()=>DirectorBroadcast::answerForViewer($db,$id,$invalid,$viewer),403)&&$stamp($viewer)===$before,
   'Missing, wrong or malformed private bearer cannot refresh a viewer');
 heartbeatCheck(heartbeatReject(static fn()=>DirectorBroadcast::answerForViewer($db,$id,$key,bin2hex(random_bytes(16))),409)&&$stamp($viewer)===$before,
  'Unknown viewer cannot create a heartbeat row or refresh another viewer');
 $foreign=DirectorBroadcast::startRecording($db,$foreignRecording,$host,'public',true);
 heartbeatCheck(heartbeatReject(static fn()=>DirectorBroadcast::answerForViewer($db,$foreign['id'],'',$viewer),409)&&$stamp($viewer)===$before,
  'A valid public broadcast cannot heartbeat another broadcast viewer');
 heartbeatCheck(!in_array($id,array_column(DirectorBroadcast::publicList($db),'id'),true),'Heartbeat does not disclose a private broadcast in the public directory');

 $advance(15);
 heartbeatCheck(heartbeatReject(static fn()=>DirectorBroadcast::answerForViewer($db,$id,$key,$viewer),403)&&$stamp($viewer)===$before,
  'Stale publisher heartbeat rejects viewer activity without extending it');
 DirectorBroadcast::offersForHost($db,$id,$host);
 $db->prepare("UPDATE recording_sessions SET status='completed' WHERE id=?")->execute([$recording]);
 heartbeatCheck(heartbeatReject(static fn()=>DirectorBroadcast::answerForViewer($db,$id,$key,$viewer),403)&&$stamp($viewer)===$before,
  'Completed recording cannot refresh a viewer');
 $db->prepare("UPDATE recording_sessions SET status='active',owner_admin_id=? WHERE id=?")->execute([$other,$recording]);
 heartbeatCheck(heartbeatReject(static fn()=>DirectorBroadcast::answerForViewer($db,$id,$key,$viewer),403)&&$stamp($viewer)===$before,
  'Recording ownership mismatch cannot refresh a viewer');
 $db->prepare('UPDATE recording_sessions SET owner_admin_id=? WHERE id=?')->execute([$host,$recording]);

 $replacement=DirectorBroadcast::startRecording($db,$recording,$host,'public',true);
 heartbeatCheck($stamp($viewer)===false&&heartbeatReject(static fn()=>DirectorBroadcast::answerForViewer($db,$id,$key,$viewer),403),
  'Republish revokes the old invitation and cascades its viewer row');
 $id=$replacement['id'];$key='';$viewer=bin2hex(random_bytes(16));
 DirectorBroadcast::offer($db,$id,$key,$viewer,$sdp);
 heartbeatCheck(DirectorBroadcast::answerForViewer($db,$id,$key,$viewer)===['answer'=>null],
  'A newly admitted public viewer heartbeats without a private invitation');
 DirectorBroadcast::stop($db,$id,$host);
 heartbeatCheck($stamp($viewer)===false&&heartbeatReject(static fn()=>DirectorBroadcast::answerForViewer($db,$id,$key,$viewer),403),
  'Stopped broadcast never revives its deleted viewer');

 $capacity=DirectorBroadcast::startRecording($db,$recording,$host,'public',true);$id=$capacity['id'];$viewers=[];
 for($i=0;$i<8;$i++){
  $viewers[]=bin2hex(random_bytes(16));DirectorBroadcast::offer($db,$id,'',$viewers[$i],$sdp);
 }
 heartbeatCheck(DirectorBroadcast::hostStatus($db,$recording,$host)['viewerCount']===8&&
  heartbeatReject(static fn()=>DirectorBroadcast::offer($db,$id,'',bin2hex(random_bytes(16)),$sdp),409),
  'Eight active viewers fill capacity and the ninth is rejected');
 $age($viewers[0],30);
 heartbeatCheck(DirectorBroadcast::hostStatus($db,$recording,$host)['viewerCount']===7,'Exactly 30-second-old viewer is not counted active');
 $expiredStamp=$stamp($viewers[0]);
 heartbeatCheck(heartbeatReject(static fn()=>DirectorBroadcast::answerForViewer($db,$id,'',$viewers[0]),409)&&$stamp($viewers[0])===$expiredStamp,
  'Poll at the exact expiry boundary cannot resurrect a row');
 heartbeatCheck(heartbeatReject(static fn()=>DirectorBroadcast::answer($db,$id,$host,$viewers[0],$sdp),409)&&
  $stamp($viewers[0])===$expiredStamp&&$answer($viewers[0])['answer_sdp']===null,
  'Late host answer cannot resurrect an expired pending viewer');
 $newViewer=bin2hex(random_bytes(16));DirectorBroadcast::offer($db,$id,'',$newViewer,$sdp);
 heartbeatCheck($stamp($viewers[0])===false&&DirectorBroadcast::hostStatus($db,$recording,$host)['viewerCount']===8,
  'Admission reclaims the exact expired slot without exceeding eight viewers');
 $advance(31);
 // A current broadcast alone is not evidence that an abandoned viewer is alive.
 $db->prepare('UPDATE director_broadcasts SET updated_at=UTC_TIMESTAMP() WHERE id=?')->execute([$id]);
 $abandoned=$stamp($newViewer);
 heartbeatCheck(DirectorBroadcast::hostStatus($db,$recording,$host)['viewerCount']===0&&
  heartbeatReject(static fn()=>DirectorBroadcast::answerForViewer($db,$id,'',$newViewer),409)&&$stamp($newViewer)===$abandoned,
  'Abandoned viewer expires after 30 seconds even while the broadcast is active');
 heartbeatCheck(DirectorBroadcast::offersForHost($db,$id,$host)===[]&&$stamp($newViewer)===false,
  'Host offer polling removes abandoned viewers and never reanswers them');

 // Proof-scoped publishers have an additional, independently revocable consent boundary.
 $db->prepare("INSERT INTO game_sessions(id,game_id,status,version,state_json,viewer_token) VALUES (?,'spin-wheel','active',1,'{}',?)")
  ->execute([$session,bin2hex(random_bytes(24))]);
 $db->prepare('INSERT INTO game_claims(id,session_id,prize_id,tier,prize_name,cents,created_at) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP())')
  ->execute([$claim,$session,'heartbeat-proof','A','Heartbeat proof fixture',0]);
 ProofHandoff::activate($db,$session,$claim,$host);
 $resetProof=static function()use($db,$session,$host):void{
  $db->prepare('UPDATE controller_proof_state SET consent_ack=1,is_open=1,host_admin_id=?,updated_at=UTC_TIMESTAMP() WHERE session_id=?')->execute([$host,$session]);
 };
 $resetProof();$proof=DirectorBroadcast::start($db,$session,$claim,$host);$proofViewer=bin2hex(random_bytes(16));
 DirectorBroadcast::offer($db,$proof['id'],$proof['key'],$proofViewer,$sdp);
 $proofStamp=$stamp($proofViewer);$advance(1);
 foreach(['consent_ack=0','is_open=0','host_admin_id='.(int)$other,'updated_at=UTC_TIMESTAMP()-INTERVAL 13 SECOND'] as $change){
  $resetProof();$db->prepare('UPDATE controller_proof_state SET '.$change.' WHERE session_id=?')->execute([$session]);
  heartbeatCheck(heartbeatReject(static fn()=>DirectorBroadcast::answerForViewer($db,$proof['id'],$proof['key'],$proofViewer),403)&&
   $stamp($proofViewer)===$proofStamp,'Revoked, closed, foreign or stale proof authorization never extends viewer activity');
 }
 $resetProof();

 // Real concurrent MariaDB admissions must serialize on the same last slot.
 // Return to wall clock so independent worker connections share the same time.
 $db->exec('SET timestamp=0');
 $concurrent=DirectorBroadcast::startRecording($db,$recording,$host,'public',true);$id=$concurrent['id'];
 for($i=0;$i<7;$i++)DirectorBroadcast::offer($db,$id,'',bin2hex(random_bytes(16)),$sdp);
 $db->beginTransaction();$q=$db->prepare('SELECT id FROM director_broadcasts WHERE id=? FOR UPDATE');$q->execute([$id]);$q->fetch();
 for($i=0;$i<2;$i++){
  $pipes=[];$process=proc_open([PHP_BINARY,__FILE__,'--offer-worker',$id,'',bin2hex(random_bytes(16))],
   [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));
  if(!is_resource($process))throw new RuntimeException('Unable to launch concurrent admission fixture.');
  $children[]=['process'=>$process,'pipes'=>$pipes];
  stream_set_timeout($pipes[1],10);stream_set_timeout($pipes[2],10);
  if(fgets($pipes[1])!=="READY\n")throw new RuntimeException('Concurrent admission fixture did not reach the barrier.');
 }
 foreach($children as &$child){fwrite($child['pipes'][0],"GO\n");fclose($child['pipes'][0]);$child['pipes'][0]=null;}unset($child);
 usleep(100000);$db->commit();
 $results=[];
 foreach($children as &$child){
  $results[]=trim((string)fgets($child['pipes'][1]));$errors=stream_get_contents($child['pipes'][2]);
  fclose($child['pipes'][1]);fclose($child['pipes'][2]);$child['pipes'][1]=$child['pipes'][2]=null;
  $exit=proc_close($child['process']);$child['process']=null;
  heartbeatCheck($exit===0&&trim((string)$errors)==='','Concurrent admission worker completes without database deadlock or error');
 }unset($child);
 sort($results);
 heartbeatCheck($results===['accepted','capacity']&&DirectorBroadcast::hostStatus($db,$recording,$host)['viewerCount']===8,
  'Two concurrent requests for the final slot admit exactly one viewer');
 echo "V5Q32G2 VIEWER HEARTBEAT DATABASE: $checks/$checks\n";
}finally{
 if($db->inTransaction())$db->rollBack();
 foreach($children as $child){
  foreach($child['pipes'] as $pipe)if(is_resource($pipe))fclose($pipe);
  if(is_resource($child['process'])){proc_terminate($child['process']);proc_close($child['process']);}
 }
 $db->exec('SET timestamp=0');
 if($host)$db->prepare('DELETE FROM director_broadcasts WHERE host_admin_id=?')->execute([$host]);
 $db->prepare('DELETE FROM controller_proof_state WHERE session_id=?')->execute([$session]);
 $db->prepare('DELETE FROM game_sessions WHERE id=?')->execute([$session]);
 $db->prepare('DELETE FROM recording_sessions WHERE id IN (?,?)')->execute([$recording,$foreignRecording]);
 if($host)$db->prepare('DELETE FROM admins WHERE id=?')->execute([$host]);
 if($other)$db->prepare('DELETE FROM admins WHERE id=?')->execute([$other]);
}
