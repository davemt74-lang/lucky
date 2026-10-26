<?php
declare(strict_types=1);
if(getenv('CI')!=='true')throw new RuntimeException('CI MariaDB only.');
define('LUCKY_TESTING',true);
require_once __DIR__.'/../lib/Bootstrap.php';
require_once __DIR__.'/../lib/MigrationSql.php';
require_once __DIR__.'/../lib/ProofHandoff.php';
require_once __DIR__.'/../lib/PhoneMediaChunks.php';
function chunkDb(bool $ok,string $label):void {if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label."\n";}
function chunkDbReject(callable $fn):bool {try{$fn();return false;}catch(DomainException){return true;}}
$db=new PDO('mysql:host=127.0.0.1;port=3306;dbname=lucky_test;charset=utf8mb4','root','luckytestpassword',[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(MigrationSql::statements((string)file_get_contents(__DIR__.'/../lib/schema.sql')) as $sql)$db->exec($sql);
foreach(MigrationSql::statements((string)file_get_contents(__DIR__.'/../lib/migrations/v5q_claim_media.sql')) as $sql)$db->exec($sql);
$session=bin2hex(random_bytes(16));$claim=bin2hex(random_bytes(16));$otherClaim=bin2hex(random_bytes(16));
$pairId=bin2hex(random_bytes(16));$nonce=bin2hex(random_bytes(16));$host=0;$receipt=PhoneCapturePolicy::mediaId($pairId,$nonce);
$stagedKey=hash('sha256','lucky-phone-media-chunks-v1:'.$pairId.':'.$nonce);
$stage=LUCKY_ROOT.'/storage/phone-media-pending/'.$stagedKey;
$uploads=[];$filePath=LUCKY_ROOT.'/storage/claim-media/'.$receipt.'.bin';
try {
 $db->prepare('INSERT INTO admins(username,password_hash) VALUES (?,?)')->execute(['chunk-'.bin2hex(random_bytes(6)),password_hash('test',PASSWORD_DEFAULT)]);
 $host=(int)$db->lastInsertId();
 $db->prepare("INSERT INTO game_sessions(id,game_id,status,version,state_json,viewer_token) VALUES (?,'spin-wheel','active',1,'{}',?)")->execute([$session,bin2hex(random_bytes(24))]);
 $db->prepare('INSERT INTO game_claims(id,session_id,prize_id,tier,prize_name,cents,created_at) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP())')
  ->execute([$claim,$session,'prize-a','A','Photo proof',0]);
 $db->prepare('INSERT INTO game_claims(id,session_id,prize_id,tier,prize_name,cents,created_at) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP())')
  ->execute([$otherClaim,$session,'prize-b','B','Other proof',0]);
 $db->prepare("INSERT INTO controller_pairs(id,host_admin_id,invite_hash,controller_hash,device_name,status,invite_expires_at,paired_expires_at) VALUES (?,?,?,?,?,'paired',DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 HOUR),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 HOUR))")
  ->execute([$pairId,$host,str_repeat('0',64),str_repeat('1',64),'Camera phone']);
 ProofHandoff::activate($db,$session,$claim,$host);
 $db->prepare("UPDATE controller_proof_state SET phone_camera_enabled=1,consent_ack=1,capture_phase='live',updated_at=UTC_TIMESTAMP() WHERE session_id=?")
  ->execute([$session]);
 $q=$db->prepare('SELECT * FROM controller_pairs WHERE id=?');$q->execute([$pairId]);$pair=$q->fetch();
 // A PNG sample padded past 512 KB proves assembly without a real camera.
 $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL/nwAAAABJRU5ErkJggg==',true);
 $blob=$png.str_repeat('Q',PhoneMediaChunks::CHUNK_BYTES+91-strlen($png));
 $form=['nonce'=>$nonce,'session'=>$session,'claim'=>$claim,'kind'=>'photo','title'=>'Claim photo',
  'durationMs'=>'0','bytes'=>(string)strlen($blob),'total'=>'2'];
 $makeChunk=static function(int $index)use($blob,&$uploads):array {
  $piece=substr($blob,$index*PhoneMediaChunks::CHUNK_BYTES,PhoneMediaChunks::CHUNK_BYTES);
  $path=tempnam(sys_get_temp_dir(),'lucky-chunk-test-');
  if(!$path||file_put_contents($path,$piece)===false)throw new RuntimeException('Cannot create test upload.');
  $uploads[]=$path;
  return ['tmp_name'=>$path,'size'=>strlen($piece),'error'=>UPLOAD_ERR_OK];
 };
 $validate=static fn(string $path):bool=>is_file($path); // Only the CI test may supply this; HTTP uses is_uploaded_file().
 $one=$makeChunk(0);$two=$makeChunk(1);
 chunkDb(chunkDbReject(static fn()=>ClaimMediaUpload::store($db,$form,$one)),'Non-HTTP upload cannot bypass ordinary PHP file verification');
 chunkDb(chunkDbReject(static fn()=>PhoneMediaChunks::append($db,$pair,$form+['index'=>'1'],$two,$validate)),'Out-of-order initial chunk rejected');
 $ack=PhoneMediaChunks::append($db,$pair,array_replace($form,['index'=>'0']),$one,$validate);
 chunkDb($ack['next']===1 && $ack['receivedBytes']===PhoneMediaChunks::CHUNK_BYTES,'First chunk saved privately with precise progress');
 $again=PhoneMediaChunks::append($db,$pair,array_replace($form,['index'=>'0']),$one,$validate);
 chunkDb($again['next']===1,'Interrupted upload retries the same chunk idempotently');
 chunkDb(chunkDbReject(static fn()=>PhoneMediaChunks::append($db,$pair,array_replace($form,['index'=>'1','claim'=>$otherClaim]),$two,$validate)),'A different prize cannot receive the recording');
 $ack=PhoneMediaChunks::append($db,$pair,array_replace($form,['index'=>'1']),$two,$validate);
 chunkDb($ack['next']===2 && $ack['receivedBytes']===strlen($blob),'Final chunk confirms entire recording saved to private staging');
 $db->prepare('UPDATE controller_proof_state SET consent_ack=0,updated_at=UTC_TIMESTAMP() WHERE session_id=?')->execute([$session]);
 $authorize=static function(PDO $transaction,string $sessionId,string $claimId)use($pair):void {
  $q=$transaction->prepare('SELECT * FROM controller_proof_state WHERE session_id=? FOR UPDATE');
  $q->execute([$sessionId]);PhoneCapturePolicy::verify($pair,$q->fetch(PDO::FETCH_ASSOC),$sessionId,$claimId,time());
 };
 chunkDb(chunkDbReject(static fn()=>PhoneMediaChunks::finish($db,$pair,$form,$authorize,$receipt)),'Revoked consent prevents commit after all chunks transferred');
 $db->prepare('UPDATE controller_proof_state SET consent_ack=1,updated_at=UTC_TIMESTAMP() WHERE session_id=?')->execute([$session]);
 $saved=PhoneMediaChunks::finish($db,$pair,$form,$authorize,$receipt);
 chunkDb($saved['id']===$receipt && $saved['claimId']===$claim && $saved['kind']==='photo' && $saved['bytes']===strlen($blob),'Reassembled recording committed to correct prize');
 chunkDb(is_file($filePath)&&hash_file('sha256',$filePath)===hash('sha256',$blob),'Video Studio file content matches exact original upload bytes');
 chunkDb(!file_exists($stage.'.part')&&!file_exists($stage.'.meta'),'Temporary uploaded pieces cleaned after database commit');
 echo "V5Q.29E4D1 HF6 CHUNK DB PASSED\n";
}finally {
 foreach($uploads as $path)@unlink($path);
 foreach(['.part','.meta','.lock'] as $suffix)@unlink($stage.$suffix);
 @unlink($filePath);
 $db->prepare('DELETE FROM claim_media WHERE id=?')->execute([$receipt]);
 $db->prepare('DELETE FROM controller_pairs WHERE id=?')->execute([$pairId]);
 $db->prepare('DELETE FROM controller_proof_state WHERE session_id=?')->execute([$session]);
 $db->prepare('DELETE FROM game_sessions WHERE id=?')->execute([$session]);
 if($host)$db->prepare('DELETE FROM admins WHERE id=?')->execute([$host]);
}
