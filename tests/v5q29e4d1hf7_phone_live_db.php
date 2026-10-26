<?php
declare(strict_types=1);
if(getenv('CI')!=='true')throw new RuntimeException('CI MariaDB only.');
define('LUCKY_TESTING',true);
require_once __DIR__.'/../lib/Bootstrap.php';
require_once __DIR__.'/../lib/MigrationSql.php';
require_once __DIR__.'/../lib/ProofHandoff.php';
require_once __DIR__.'/../lib/PhoneLiveSignaling.php';
function liveDb(bool $yes,string $label):void {if(!$yes)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label."\n";}
function liveDbReject(callable $fn):bool {try{$fn();return false;}catch(DomainException){return true;}}
$db=new PDO('mysql:host=127.0.0.1;port=3306;dbname=lucky_test;charset=utf8mb4','root','luckytestpassword',[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(MigrationSql::statements((string)file_get_contents(__DIR__.'/../lib/schema.sql')) as $sql)$db->exec($sql);
foreach(MigrationSql::statements((string)file_get_contents(__DIR__.'/../lib/migrations/v5q_claim_media.sql')) as $sql)$db->exec($sql);
foreach(MigrationSql::statements((string)file_get_contents(__DIR__.'/../lib/migrations/v5q29e4d1hf7_phone_live.sql')) as $sql)$db->exec($sql);
$session=bin2hex(random_bytes(16));$claim=bin2hex(random_bytes(16));$otherClaim=bin2hex(random_bytes(16));
$id=bin2hex(random_bytes(16));$nonce=bin2hex(random_bytes(16));$host=0;
$sdp="v=0\r\no=- 1 1 IN IP4 127.0.0.1\r\ns=-\r\nt=0 0\r\na=recvonly\r\n";
try{
 $db->prepare('INSERT INTO admins(username,password_hash) VALUES (?,?)')->execute(['live-'.bin2hex(random_bytes(6)),password_hash('test',PASSWORD_DEFAULT)]);
 $host=(int)$db->lastInsertId();
 $db->prepare("INSERT INTO game_sessions(id,game_id,status,version,state_json,viewer_token) VALUES (?,'spin-wheel','active',1,'{}',?)")->execute([$session,bin2hex(random_bytes(24))]);
 $db->prepare('INSERT INTO game_claims(id,session_id,prize_id,tier,prize_name,cents,created_at) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP())')
  ->execute([$claim,$session,'prize-a','A','Live proof',0]);
 $db->prepare('INSERT INTO game_claims(id,session_id,prize_id,tier,prize_name,cents,created_at) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP())')
  ->execute([$otherClaim,$session,'prize-b','B','Other proof',0]);
 $db->prepare("INSERT INTO controller_pairs(id,host_admin_id,invite_hash,controller_hash,device_name,status,invite_expires_at,paired_expires_at) VALUES (?,?,?,?,?,'paired',DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 HOUR),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 HOUR))")
  ->execute([$id,$host,str_repeat('0',64),str_repeat('1',64),'Living room phone']);
 ProofHandoff::activate($db,$session,$claim,$host);
 $q=$db->prepare('SELECT * FROM controller_pairs WHERE id=?');$q->execute([$id]);$pair=$q->fetch();
 liveDb(liveDbReject(static fn()=>PhoneLiveSignaling::offer($db,$pair,$session,$claim,$nonce,$sdp)),
  'Live feed denied before phone camera and consent enabled');
 $db->prepare("UPDATE controller_proof_state SET phone_camera_enabled=1,consent_ack=1,capture_phase='live',updated_at=UTC_TIMESTAMP() WHERE session_id=?")->execute([$session]);
 PhoneLiveSignaling::offer($db,$pair,$session,$claim,$nonce,$sdp);
 $offers=PhoneLiveSignaling::listOffers($db,$session,$claim,$host);
 liveDb(count($offers)===1&&$offers[0]['id']===$id&&$offers[0]['nonce']===$nonce&&$offers[0]['name']==='Living room phone',
  'Authorized phone offer visible only to its proof host');
 liveDb(liveDbReject(static fn()=>PhoneLiveSignaling::listOffers($db,$session,$claim,$host+100)),
  'Other admins cannot receive phone SDP');
 liveDb(liveDbReject(static fn()=>PhoneLiveSignaling::offer($db,$pair,$session,$otherClaim,$nonce,$sdp)),
  'Phone cannot stream to another prize');
 liveDb(liveDbReject(static fn()=>PhoneLiveSignaling::answer($db,$session,$claim,$host,$id,str_repeat('a',32),$sdp)),
  'Stale connection nonce cannot overwrite an answer');
 PhoneLiveSignaling::answer($db,$session,$claim,$host,$id,$nonce,$sdp);
 liveDb(PhoneLiveSignaling::poll($db,$pair,$session,$claim,$nonce)['answer']===$sdp,
  'Encrypted peer answer reaches approved phone through short-lived signaling');
 $db->prepare('UPDATE controller_proof_state SET consent_ack=0,updated_at=UTC_TIMESTAMP() WHERE session_id=?')->execute([$session]);
 liveDb(liveDbReject(static fn()=>PhoneLiveSignaling::poll($db,$pair,$session,$claim,$nonce)),
  'Revoked consent immediately blocks phone polling');
 liveDb(liveDbReject(static fn()=>PhoneLiveSignaling::listOffers($db,$session,$claim,$host)),
  'Revoked host consent immediately blocks desktop signaling');
 $db->prepare('UPDATE controller_proof_state SET consent_ack=1,updated_at=UTC_TIMESTAMP() WHERE session_id=?')->execute([$session]);
 PhoneLiveSignaling::end($db,$pair,$nonce);
 liveDb(PhoneLiveSignaling::listOffers($db,$session,$claim,$host)===[],'Stopping live preview removes desktop offer');
 PhoneLiveSignaling::offer($db,$pair,$session,$claim,$nonce,$sdp);
 $db->prepare("UPDATE controller_pairs SET status='revoked' WHERE id=?")->execute([$id]);
 $q->execute([$id]);$revoked=$q->fetch();
 liveDb(liveDbReject(static fn()=>PhoneLiveSignaling::poll($db,$revoked,$session,$claim,$nonce)),
  'Pair revocation rejects subsequent phone polling');
 liveDb(PhoneLiveSignaling::listOffers($db,$session,$claim,$host)===[],
  'Revoked paired camera disappears from host selection');
 echo "V5Q.29E4D1 HF7 PHONE LIVE DB PASSED\n";
}finally{
 $db->prepare('DELETE FROM controller_live_streams WHERE pair_id=?')->execute([$id]);
 $db->prepare('DELETE FROM controller_pairs WHERE id=?')->execute([$id]);
 $db->prepare('DELETE FROM controller_proof_state WHERE session_id=?')->execute([$session]);
 $db->prepare('DELETE FROM game_sessions WHERE id=?')->execute([$session]);
 if($host)$db->prepare('DELETE FROM admins WHERE id=?')->execute([$host]);
}
