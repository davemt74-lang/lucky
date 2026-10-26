<?php
declare(strict_types=1);
require_once __DIR__.'/ControllerPairing.php';
require_once __DIR__.'/PhoneCapturePolicy.php';

/**
 * Short-lived WebRTC signaling between one authenticated paired phone and
 * its approved proof host. RTP media never touches PHP or MySQL.
 */
final class PhoneLiveSignaling {
 public const MAX_SDP_BYTES=140000;
 private const LIVE_SECONDS=25;

 public static function nonce(mixed $value):string {
  if(!is_string($value)||!preg_match('/^[a-f0-9]{32}$/D',$value))
   throw new DomainException('Invalid live camera connection identifier.',422);
  return $value;
 }
 public static function sdp(mixed $value,string $type):string {
  if(!in_array($type,['offer','answer'],true)||!is_string($value)||strlen($value)<15||
    strlen($value)>self::MAX_SDP_BYTES||!preg_match('/^v=0\r?\n/D',$value)||
    str_contains($value,"\0"))
   throw new DomainException('Invalid live camera connection description.',422);
  return $value;
 }
 public static function proof(PDO $db,string $session,string $claim):array {
  ControllerPairing::checkId($session);ControllerPairing::checkId($claim);
  $q=$db->prepare('SELECT * FROM controller_proof_state WHERE session_id=?');
  $q->execute([$session]);$row=$q->fetch(PDO::FETCH_ASSOC);
  if(!$row||(string)$row['claim_id']!==$claim||
    (int)$row['is_open']!==1||(int)$row['phone_camera_enabled']!==1||
    (int)$row['consent_ack']!==1)
   throw new DomainException('The host must enable phone camera and recording consent for this prize.',403);
  $updated=strtotime((string)$row['updated_at'].' UTC');
  if(!$updated||$updated<time()-12||$updated>time()+5)
   throw new DomainException('The desktop proof is no longer active.',409);
  return $row;
 }
 public static function hostProof(PDO $db,string $session,string $claim,int $host):array {
  $proof=self::proof($db,$session,$claim);
  if((int)$proof['host_admin_id']!==$host)throw new DomainException('This live proof belongs to another host.',403);
  return $proof;
 }
 public static function phoneProof(PDO $db,array $pair,string $session,string $claim):array {
  $proof=self::proof($db,$session,$claim);
  PhoneCapturePolicy::verify($pair,$proof,$session,$claim,time());
  return $proof;
 }
 private static function expire(PDO $db):void {
  $db->exec('DELETE FROM controller_live_streams WHERE updated_at<UTC_TIMESTAMP()-INTERVAL 3 MINUTE');
 }
 public static function offer(PDO $db,array $pair,string $session,string $claim,string $nonce,string $sdp):void {
  self::phoneProof($db,$pair,$session,$claim);
  self::nonce($nonce);self::sdp($sdp,'offer');
  self::expire($db);
  $q=$db->prepare('SELECT COUNT(*) FROM controller_live_streams WHERE session_id=? AND claim_id=? AND updated_at>UTC_TIMESTAMP()-INTERVAL 25 SECOND AND pair_id<>?');
  $q->execute([$session,$claim,$pair['id']]);
  if((int)$q->fetchColumn()>=4)throw new DomainException('The proof is already showing four phone cameras.',409);
  $q=$db->prepare('INSERT INTO controller_live_streams(pair_id,session_id,claim_id,nonce,offer_sdp,answer_sdp,updated_at)
   VALUES (?,?,?,?,?,NULL,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE
   session_id=VALUES(session_id),claim_id=VALUES(claim_id),nonce=VALUES(nonce),
   offer_sdp=VALUES(offer_sdp),answer_sdp=NULL,updated_at=UTC_TIMESTAMP()');
  $q->execute([$pair['id'],$session,$claim,$nonce,$sdp]);
 }
 public static function listOffers(PDO $db,string $session,string $claim,int $host):array {
  $proof=self::hostProof($db,$session,$claim,$host);
  $q=$db->prepare('SELECT l.pair_id,l.nonce,l.offer_sdp,p.device_name,p.status,p.host_admin_id,
    p.invite_expires_at,p.paired_expires_at
   FROM controller_live_streams l INNER JOIN controller_pairs p ON p.id=l.pair_id
   WHERE l.session_id=? AND l.claim_id=? AND l.updated_at>UTC_TIMESTAMP()-INTERVAL 25 SECOND
   ORDER BY l.created_at,l.pair_id LIMIT 5');
  $q->execute([$session,$claim]);$out=[];
  foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
   try{PhoneCapturePolicy::verify($row,$proof,$session,$claim,time());}
   catch(DomainException){continue;}
   $out[]=['id'=>$row['pair_id'],'nonce'=>$row['nonce'],'name'=>$row['device_name'],'offer'=>$row['offer_sdp']];
  }
  return $out;
 }
 public static function answer(PDO $db,string $session,string $claim,int $host,string $pairId,string $nonce,string $sdp):void {
  self::hostProof($db,$session,$claim,$host);
  ControllerPairing::checkId($pairId);self::nonce($nonce);self::sdp($sdp,'answer');
  $q=$db->prepare('UPDATE controller_live_streams l INNER JOIN controller_pairs p ON p.id=l.pair_id
   SET l.answer_sdp=? WHERE l.pair_id=? AND l.nonce=? AND l.session_id=? AND l.claim_id=?
   AND l.updated_at>UTC_TIMESTAMP()-INTERVAL 25 SECOND AND p.host_admin_id=?
   AND p.status=\'paired\' AND p.paired_expires_at>UTC_TIMESTAMP()');
  $q->execute([$sdp,$pairId,$nonce,$session,$claim,$host]);
  if($q->rowCount()!==1)throw new DomainException('The phone live session was replaced or disconnected.',409);
 }
 public static function poll(PDO $db,array $pair,string $session,string $claim,string $nonce):array {
  self::phoneProof($db,$pair,$session,$claim);
  self::nonce($nonce);
  $q=$db->prepare('SELECT answer_sdp FROM controller_live_streams WHERE pair_id=? AND session_id=? AND claim_id=?
    AND nonce=? AND updated_at>UTC_TIMESTAMP()-INTERVAL 25 SECOND');
  $q->execute([$pair['id'],$session,$claim,$nonce]);$row=$q->fetch(PDO::FETCH_ASSOC);
  if(!$row)throw new DomainException('Live camera connection expired. Reopen the camera.',409);
  $db->prepare('UPDATE controller_live_streams SET updated_at=UTC_TIMESTAMP() WHERE pair_id=? AND nonce=?')->execute([$pair['id'],$nonce]);
  return ['answer'=>$row['answer_sdp']?:null];
 }
 public static function end(PDO $db,array $pair,string $nonce):void {
  self::nonce($nonce);
  $db->prepare('DELETE FROM controller_live_streams WHERE pair_id=? AND nonce=?')->execute([$pair['id'],$nonce]);
 }
}
