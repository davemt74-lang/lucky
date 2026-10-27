<?php
declare(strict_types=1);
if(getenv('CI')!=='true')throw new RuntimeException('CI MariaDB only.');
define('LUCKY_TESTING',true);
require_once __DIR__.'/../lib/Bootstrap.php';
require_once __DIR__.'/../lib/MigrationSql.php';
require_once __DIR__.'/../lib/ProofHandoff.php';
require_once __DIR__.'/../lib/DirectorBroadcast.php';
function directorDb(bool $ok,string $label):void {if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label."\n";}
function directorReject(callable $fn):bool {try{$fn();return false;}catch(DomainException){return true;}}
$db=new PDO('mysql:host=127.0.0.1;port=3306;dbname=lucky_test;charset=utf8mb4','root','luckytestpassword',[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(MigrationSql::statements((string)file_get_contents(__DIR__.'/../lib/schema.sql')) as $sql)$db->exec($sql);
foreach(MigrationSql::statements((string)file_get_contents(__DIR__.'/../lib/migrations/v5q_claim_media.sql')) as $sql)$db->exec($sql);
foreach(MigrationSql::statements((string)file_get_contents(__DIR__.'/../lib/migrations/v5q29e4d1hf8b_director_live.sql')) as $sql)$db->exec($sql);
foreach(['v5q29e1_recording_sessions.sql','v5q29e4d1hf8c4_director_recordings.sql'] as $migration)
 foreach(MigrationSql::statements((string)file_get_contents(__DIR__.'/../lib/migrations/'.$migration)) as $sql)$db->exec($sql);
$session=bin2hex(random_bytes(16));$claim=bin2hex(random_bytes(16));$host=0;
$sdp="v=0\r\no=- 1 1 IN IP4 127.0.0.1\r\ns=-\r\nt=0 0\r\na=recvonly\r\n";
try{
 $db->prepare('INSERT INTO admins(username,password_hash) VALUES (?,?)')->execute(['director-'.bin2hex(random_bytes(6)),password_hash('test',PASSWORD_DEFAULT)]);
 $host=(int)$db->lastInsertId();
 $db->prepare("INSERT INTO game_sessions(id,game_id,status,version,state_json,viewer_token) VALUES (?,'spin-wheel','active',1,'{}',?)")
  ->execute([$session,bin2hex(random_bytes(24))]);
 $db->prepare('INSERT INTO game_claims(id,session_id,prize_id,tier,prize_name,cents,created_at) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP())')
  ->execute([$claim,$session,'prize-director','A','Director Proof',0]);
 ProofHandoff::activate($db,$session,$claim,$host);
 directorDb(directorReject(static fn()=>DirectorBroadcast::start($db,$session,$claim,$host)), 'Publishing without consent is denied');
 $db->prepare('UPDATE controller_proof_state SET consent_ack=1,updated_at=UTC_TIMESTAMP() WHERE session_id=?')->execute([$session]);
 $b=DirectorBroadcast::start($db,$session,$claim,$host);
 directorDb(strlen($b['id'])===32&&strlen($b['key'])===48,'Host creates unguessable invitation and broadcast');
 $q=$db->prepare('SELECT token_hash FROM director_broadcasts WHERE id=?');$q->execute([$b['id']]);$hash=(string)$q->fetchColumn();
 directorDb($hash===hash('sha256',$b['key'])&&!str_contains($hash,$b['key']),'Database stores only invitation hash');
 directorDb(directorReject(static fn()=>DirectorBroadcast::watch($db,$b['id'],str_repeat('0',48))),'Wrong token cannot view');
 directorDb(DirectorBroadcast::watch($db,$b['id'],$b['key'])['live']===true,'Valid private invitation can view');
 $viewer=bin2hex(random_bytes(16));
 DirectorBroadcast::offer($db,$b['id'],$b['key'],$viewer,$sdp);
 directorDb(count(DirectorBroadcast::offersForHost($db,$b['id'],$host))===1,'Host sees authorized pending viewer offer');
 directorDb(directorReject(static fn()=>DirectorBroadcast::offersForHost($db,$b['id'],$host+100)), 'Other administrator cannot read viewer SDP');
 DirectorBroadcast::answer($db,$b['id'],$host,$viewer,$sdp);
 directorDb(DirectorBroadcast::answerForViewer($db,$b['id'],$b['key'],$viewer)['answer']===$sdp,'Authorized WebRTC answer delivered');
 directorDb(directorReject(static fn()=>DirectorBroadcast::answer($db,$b['id'],$host+100,$viewer,$sdp)),'Another host cannot answer');
 $db->prepare('UPDATE controller_proof_state SET consent_ack=0,updated_at=UTC_TIMESTAMP() WHERE session_id=?')->execute([$session]);
 directorDb(directorReject(static fn()=>DirectorBroadcast::watch($db,$b['id'],$b['key'])),'Consent revocation immediately blocks Live View');
 $db->prepare('UPDATE controller_proof_state SET consent_ack=1,updated_at=UTC_TIMESTAMP() WHERE session_id=?')->execute([$session]);
 $next=DirectorBroadcast::start($db,$session,$claim,$host);
 directorDb(directorReject(static fn()=>DirectorBroadcast::watch($db,$b['id'],$b['key'])),'Republishing revokes the previous invitation');
 DirectorBroadcast::stop($db,$next['id'],$host);
 directorDb(directorReject(static fn()=>DirectorBroadcast::watch($db,$next['id'],$next['key'])),'Ending Live View revokes token and viewer signaling');
 echo "HF8B DIRECTOR LIVE DATABASE: 12/12\n";
}finally{
 $db->prepare('DELETE FROM director_broadcasts WHERE session_id=?')->execute([$session]);
 $db->prepare('DELETE FROM controller_proof_state WHERE session_id=?')->execute([$session]);
 $db->prepare('DELETE FROM game_sessions WHERE id=?')->execute([$session]);
 if($host)$db->prepare('DELETE FROM admins WHERE id=?')->execute([$host]);
}
