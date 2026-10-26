<?php
declare(strict_types=1);
require_once __DIR__.'/ControllerPairing.php';
require_once __DIR__.'/PhoneLiveSignaling.php';

/** Temporary, bearer-authorized Program-only WebRTC broadcast signaling. */
final class DirectorBroadcast {
 private const LIVE_SECONDS=14;
 private const MAX_VIEWERS=8;
 public static function id(mixed $value):string {
  if(!is_string($value)||!preg_match('/^[a-f0-9]{32}$/D',$value))throw new DomainException('Invalid broadcast identifier.',422);
  return $value;
 }
 public static function key(mixed $value):string {
  if(!is_string($value)||!preg_match('/^[a-f0-9]{48}$/D',$value))throw new DomainException('Invalid broadcast invitation.',403);
  return $value;
 }
 private static function proof(PDO $db,string $session,string $claim,int $host):void {
  ControllerPairing::checkId($session);ControllerPairing::checkId($claim);
  $q=$db->prepare('SELECT 1 FROM controller_proof_state WHERE session_id=? AND claim_id=? AND host_admin_id=? AND is_open=1 AND consent_ack=1 AND updated_at>UTC_TIMESTAMP()-INTERVAL 12 SECOND');
  $q->execute([$session,$claim,$host]);
  if(!$q->fetchColumn())throw new DomainException('Open the current proof and accept recording consent before publishing.',403);
 }
 public static function start(PDO $db,string $session,string $claim,int $host):array {
  self::proof($db,$session,$claim,$host);
  $id=bin2hex(random_bytes(16));$key=token();
  // A new publish replaces any previously issued token for this proof session.
  $db->beginTransaction();
  try{
   $db->prepare('DELETE FROM director_broadcasts WHERE session_id=?')->execute([$session]);
   $db->prepare('INSERT INTO director_broadcasts(id,session_id,claim_id,host_admin_id,token_hash,updated_at) VALUES (?,?,?,?,?,UTC_TIMESTAMP())')
    ->execute([$id,$session,$claim,$host,hash('sha256',$key)]);
   $db->commit();
  }catch(Throwable $e){$db->rollBack();throw $e;}
  return ['id'=>$id,'key'=>$key];
 }
 private static function host(PDO $db,string $broadcast,int $host):array {
  self::id($broadcast);
  $q=$db->prepare('SELECT * FROM director_broadcasts WHERE id=? AND host_admin_id=?');$q->execute([$broadcast,$host]);
  $row=$q->fetch(PDO::FETCH_ASSOC);
  if(!$row)throw new DomainException('This Program broadcast has ended.',404);
  self::proof($db,(string)$row['session_id'],(string)$row['claim_id'],$host);
  return $row;
 }
 private static function viewer(PDO $db,string $id,string $key):array {
  self::id($id);self::key($key);
  $q=$db->prepare('SELECT b.*,p.is_open,p.consent_ack,p.host_admin_id AS current_host,p.claim_id AS current_claim,p.updated_at AS proof_updated
    FROM director_broadcasts b JOIN controller_proof_state p ON p.session_id=b.session_id
    WHERE b.id=? AND b.updated_at>UTC_TIMESTAMP()-INTERVAL 14 SECOND');
  $q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
  if(!$row||!hash_equals((string)$row['token_hash'],hash('sha256',$key))||
   (int)$row['is_open']!==1||(int)$row['consent_ack']!==1||
   (int)$row['current_host']!==(int)$row['host_admin_id']||
   (string)$row['current_claim']!==(string)$row['claim_id']||
   strtotime((string)$row['proof_updated'].' UTC')<time()-12)
   throw new DomainException('The private Program feed is not live or your viewing invitation is no longer valid.',403);
  return $row;
 }
 public static function watch(PDO $db,string $id,string $key):array {
  self::viewer($db,$id,$key);
  return ['live'=>true,'maxViewers'=>self::MAX_VIEWERS];
 }
 public static function offer(PDO $db,string $id,string $key,string $viewer,string $sdp):void {
  self::viewer($db,$id,$key);self::id($viewer);PhoneLiveSignaling::sdp($sdp,'offer');
  $db->prepare('DELETE FROM director_viewers WHERE updated_at<UTC_TIMESTAMP()-INTERVAL 30 SECOND')->execute();
  $q=$db->prepare('SELECT COUNT(*) FROM director_viewers WHERE broadcast_id=?');$q->execute([$id]);
  if((int)$q->fetchColumn()>=self::MAX_VIEWERS)throw new DomainException('This broadcast has reached its viewer limit.',409);
  $db->prepare('INSERT INTO director_viewers(id,broadcast_id,offer_sdp,updated_at) VALUES (?,?,?,UTC_TIMESTAMP())')
   ->execute([$viewer,$id,$sdp]);
 }
 public static function answerForViewer(PDO $db,string $id,string $key,string $viewer):array {
  self::viewer($db,$id,$key);self::id($viewer);
  $q=$db->prepare('SELECT answer_sdp FROM director_viewers WHERE id=? AND broadcast_id=? AND updated_at>UTC_TIMESTAMP()-INTERVAL 30 SECOND');
  $q->execute([$viewer,$id]);$answer=$q->fetchColumn();
  if($answer===false)throw new DomainException('The viewer connection has expired.',409);
  $db->prepare('UPDATE director_viewers SET updated_at=UTC_TIMESTAMP() WHERE id=? AND broadcast_id=?')->execute([$viewer,$id]);
  return ['answer'=>$answer?:null];
 }
 public static function offersForHost(PDO $db,string $id,int $host):array {
  self::host($db,$id,$host);
  $db->prepare('UPDATE director_broadcasts SET updated_at=UTC_TIMESTAMP() WHERE id=?')->execute([$id]);
  $db->prepare('DELETE FROM director_viewers WHERE updated_at<UTC_TIMESTAMP()-INTERVAL 30 SECOND')->execute();
  $q=$db->prepare('SELECT id,offer_sdp FROM director_viewers WHERE broadcast_id=? AND answer_sdp IS NULL ORDER BY created_at LIMIT 8');
  $q->execute([$id]);
  return array_map(static fn($row)=>['id'=>$row['id'],'offer'=>$row['offer_sdp']],$q->fetchAll(PDO::FETCH_ASSOC));
 }
 public static function answer(PDO $db,string $id,int $host,string $viewer,string $sdp):void {
  self::host($db,$id,$host);self::id($viewer);PhoneLiveSignaling::sdp($sdp,'answer');
  $q=$db->prepare('UPDATE director_viewers SET answer_sdp=?,updated_at=UTC_TIMESTAMP() WHERE id=? AND broadcast_id=? AND answer_sdp IS NULL');
  $q->execute([$sdp,$viewer,$id]);
  if($q->rowCount()!==1)throw new DomainException('Viewer already answered or disconnected.',409);
 }
 public static function stop(PDO $db,string $id,int $host):void {
  self::id($id);
  $q=$db->prepare('DELETE FROM director_broadcasts WHERE id=? AND host_admin_id=?');$q->execute([$id,$host]);
  if($q->rowCount()!==1)throw new DomainException('Broadcast not found or not owned by this host.',404);
 }
}
