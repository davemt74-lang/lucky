<?php
declare(strict_types=1);
if(getenv('CI')!=='true')throw new RuntimeException('CI MariaDB only.');
define('LUCKY_TESTING',true);
require_once __DIR__.'/../lib/Bootstrap.php';
require_once __DIR__.'/../lib/MigrationSql.php';
require_once __DIR__.'/../lib/ProofHandoff.php';
require_once __DIR__.'/../lib/DirectorBroadcast.php';
function publicCheck(bool $ok,string $label):void {if(!$ok)throw new RuntimeException('FAIL '.$label);echo "PASS $label\n";}
function publicReject(callable $fn):bool {try{$fn();return false;}catch(DomainException){return true;}}
$db=new PDO('mysql:host=127.0.0.1;port=3306;dbname=lucky_test;charset=utf8mb4','root','luckytestpassword',[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(MigrationSql::statements((string)file_get_contents(__DIR__.'/../lib/schema.sql')) as $sql)$db->exec($sql);
foreach(MigrationSql::statements((string)file_get_contents(__DIR__.'/../lib/migrations/v5q_claim_media.sql')) as $sql)$db->exec($sql);
foreach(['v5q29e1_recording_sessions.sql','v5q29e4d1hf8c4_director_recordings.sql'] as $migration)
 foreach(MigrationSql::statements((string)file_get_contents(__DIR__.'/../lib/migrations/'.$migration)) as $sql)$db->exec($sql);
// Existing installations upgrade in place; this test also checks the column after fresh schema.
publicCheck((bool)$db->query("SHOW COLUMNS FROM director_broadcasts LIKE 'visibility'")->fetch(),'Fresh schema has private-by-default visibility');
$session=bin2hex(random_bytes(16));$claim=bin2hex(random_bytes(16));$host=0;
$sdp="v=0\r\no=- 1 1 IN IP4 127.0.0.1\r\ns=-\r\nt=0 0\r\na=recvonly\r\n";
try{
 $db->prepare('INSERT INTO admins(username,password_hash) VALUES (?,?)')->execute(['public-'.bin2hex(random_bytes(6)),password_hash('test',PASSWORD_DEFAULT)]);
 $host=(int)$db->lastInsertId();
 $db->prepare("INSERT INTO game_sessions(id,game_id,status,version,state_json,viewer_token) VALUES (?,'spin-wheel','active',1,'{}',?)")
  ->execute([$session,bin2hex(random_bytes(24))]);
 $db->prepare('INSERT INTO game_claims(id,session_id,prize_id,tier,prize_name,cents,created_at) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP())')
  ->execute([$claim,$session,'prize-public','A','Public Proof',0]);
 ProofHandoff::activate($db,$session,$claim,$host);
 $db->prepare('UPDATE controller_proof_state SET consent_ack=1,updated_at=UTC_TIMESTAMP() WHERE session_id=?')->execute([$session]);
 publicCheck(publicReject(static fn()=>DirectorBroadcast::start($db,$session,$claim,$host,'public')),'Public publish requires additional explicit acknowledgement');
 publicCheck(publicReject(static fn()=>DirectorBroadcast::start($db,$session,$claim,$host,'unlisted',true)),'Unknown visibility mode rejected');
 $private=DirectorBroadcast::start($db,$session,$claim,$host);
 publicCheck($private['visibility']==='private'&&strlen($private['key'])===48,'Existing private-by-default invitations are preserved');
 publicCheck(publicReject(static fn()=>DirectorBroadcast::watch($db,$private['id'],'')),'Private feed rejects visitors without invitation');
 publicCheck(!in_array($private['id'],array_column(DirectorBroadcast::publicList($db),'id'),true),'Private feed never appears in public directory');
 $live=DirectorBroadcast::start($db,$session,$claim,$host,'public',true);
 publicCheck($live['visibility']==='public'&&$live['key']==='','Public host receives no secret viewer link');
 publicCheck(publicReject(static fn()=>DirectorBroadcast::watch($db,$private['id'],$private['key'])),'Switching from private to public revokes old invitation');
 publicCheck(DirectorBroadcast::watch($db,$live['id'],'')['visibility']==='public','Public viewer joins without token');
 publicCheck(in_array($live['id'],array_column(DirectorBroadcast::publicList($db),'id'),true),'Public directory lists the current broadcast');
 $viewer=bin2hex(random_bytes(16));
 DirectorBroadcast::offer($db,$live['id'],'',$viewer,$sdp);
 publicCheck(count(DirectorBroadcast::offersForHost($db,$live['id'],$host))===1,'Public viewer can request WebRTC Program feed');
 DirectorBroadcast::answer($db,$live['id'],$host,$viewer,$sdp);
 publicCheck(DirectorBroadcast::answerForViewer($db,$live['id'],'',$viewer)['answer']===$sdp,'Public viewer receives host Program answer');
 $db->prepare('UPDATE controller_proof_state SET consent_ack=0,updated_at=UTC_TIMESTAMP() WHERE session_id=?')->execute([$session]);
 publicCheck(DirectorBroadcast::publicList($db)===[]&&publicReject(static fn()=>DirectorBroadcast::watch($db,$live['id'],'')),'Revoked consent hides public listing and blocks viewing');
 $db->prepare('UPDATE controller_proof_state SET consent_ack=1,updated_at=UTC_TIMESTAMP() WHERE session_id=?')->execute([$session]);
 DirectorBroadcast::stop($db,$live['id'],$host);
 publicCheck(!in_array($live['id'],array_column(DirectorBroadcast::publicList($db),'id'),true)&&publicReject(static fn()=>DirectorBroadcast::watch($db,$live['id'],'')),'Host stop immediately removes public feed');
 echo "HF8C0 VISIBILITY DATABASE: 14/14\n";
}finally{
 $db->prepare('DELETE FROM director_broadcasts WHERE session_id=?')->execute([$session]);
 $db->prepare('DELETE FROM controller_proof_state WHERE session_id=?')->execute([$session]);
 $db->prepare('DELETE FROM game_sessions WHERE id=?')->execute([$session]);
 if($host)$db->prepare('DELETE FROM admins WHERE id=?')->execute([$host]);
}
