<?php
declare(strict_types=1);
if(getenv('CI')!=='true')throw new RuntimeException('Run only against the isolated CI MariaDB.');
define('LUCKY_TESTING',true);
require_once __DIR__.'/../lib/Bootstrap.php';
require_once __DIR__.'/../lib/MigrationSql.php';
require_once __DIR__.'/../lib/PhoneClaimHistory.php';
function chkPhoneClaims(bool $ok,string $label):void {if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
function deniedPhoneClaim(callable $fn,string $label):void {
 try{$fn();}catch(DomainException $e){chkPhoneClaims(in_array($e->getCode(),[403,409],true),$label);return;}
 throw new RuntimeException('Expected a forbidden claim: '.$label);
}
$db=new PDO('mysql:host=127.0.0.1;port=3306;dbname=lucky_test;charset=utf8mb4','root','luckytestpassword',[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['schema.sql','migrations/v5q15b_prize_decisions.sql','migrations/v5q_claim_media.sql'] as $file){
 foreach(MigrationSql::statements((string)file_get_contents(__DIR__.'/../lib/'.$file)) as $sql)$db->exec($sql);
}
$session=bin2hex(random_bytes(16));$owner=0;$other=0;
$first=bin2hex(random_bytes(16));$declined=bin2hex(random_bytes(16));$second=bin2hex(random_bytes(16));
try{
 foreach(['host','visitor'] as $which){
  $db->prepare('INSERT INTO admins(username,password_hash) VALUES (?,?)')->execute(['phone-claims-'.$which.'-'.bin2hex(random_bytes(5)),password_hash(bin2hex(random_bytes(8)),PASSWORD_DEFAULT)]);
  if($which==='host')$owner=(int)$db->lastInsertId();else $other=(int)$db->lastInsertId();
 }
 $state=['claims'=>[['id'=>$first],['id'=>$declined],['id'=>$second]]];
 $db->prepare('INSERT INTO game_sessions (id,game_id,status,version,state_json,viewer_token) VALUES (?,\'spin-wheel\',\'active\',1,?,?)')->execute([$session,json_encode($state,JSON_THROW_ON_ERROR),bin2hex(random_bytes(24))]);
 $db->prepare("INSERT INTO audit_log (admin_id,event,session_id,details) VALUES (?,'session.start',?,'{}')")->execute([$owner,$session]);
 $insert=$db->prepare('INSERT INTO game_claims (id,session_id,prize_id,tier,prize_name,cents,created_at) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP())');
 // Deliberately insert in reverse order: SQL row order must not override actual award order.
 $insert->execute([$second,$session,'product-3','tier3','Third Award',2599]);
 $insert->execute([$declined,$session,'product-2','tier2','Declined Award',4500]);
 $insert->execute([$first,$session,'product-1','tier1','First Award',1250]);
 $decision=$db->prepare('INSERT INTO prize_decisions (claim_id,session_id,status) VALUES (?,?,?)');
 foreach([[$second,'accepted'],[$declined,'declined'],[$first,'accepted']] as [$id,$status])$decision->execute([$id,$session,$status]);
 $db->prepare("INSERT INTO claim_media (id,session_id,claim_id,kind,mime,title,bytes,sort_order) VALUES (?, ?, ?, 'video', 'video/webm','Private recording',1024,0)")
   ->execute([bin2hex(random_bytes(16)),$session,$second]);
 chkPhoneClaims(PhoneClaimHistory::session($db,$owner)['id']===$session,'The approved host sees their current session');
 chkPhoneClaims(PhoneClaimHistory::session($db,$other)===null,'Another admin sees no foreign session');
 $rows=PhoneClaimHistory::accepted($db,PhoneClaimHistory::session($db,$owner));
 chkPhoneClaims(array_column($rows,'id')===[$first,$second],'Only accepted prizes, ordered by persisted spin sequence');
 chkPhoneClaims($rows[0]['cents']===1250&&$rows[1]['cents']===2599,'Prize amounts remain exact integer cents');
 chkPhoneClaims(!$rows[0]['hasVideo']&&$rows[1]['hasVideo'],'Video presence is reported without media URLs');
 chkPhoneClaims(!str_contains(json_encode($rows,JSON_THROW_ON_ERROR),'video/webm'),'No private media metadata leaks');
 chkPhoneClaims(PhoneClaimHistory::assertAccepted($db,$owner,$first)===$session,'Approved owner can edit accepted proof');
 deniedPhoneClaim(fn()=>PhoneClaimHistory::assertAccepted($db,$owner,$declined),'Declined claim cannot be reopened');
 deniedPhoneClaim(fn()=>PhoneClaimHistory::assertAccepted($db,$other,$first),'Foreign host cannot edit proof');
 $db->prepare("UPDATE game_sessions SET status='ended' WHERE id=?")->execute([$session]);
 chkPhoneClaims(PhoneClaimHistory::session($db,$owner)['id']===$session,'Proof history survives game end');
 echo "V5Q.27D claimed-prize security and order PASSED\n";
}finally{
 $db->prepare('DELETE FROM audit_log WHERE session_id=?')->execute([$session]);
 $db->prepare('DELETE FROM game_sessions WHERE id=?')->execute([$session]);
 foreach([$owner,$other] as $id)if($id)$db->prepare('DELETE FROM admins WHERE id=?')->execute([$id]);
}
