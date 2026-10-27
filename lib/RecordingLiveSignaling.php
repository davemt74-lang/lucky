<?php
declare(strict_types=1);
require_once __DIR__.'/RecordingMulticam.php';
require_once __DIR__.'/PhoneLiveSignaling.php';

/** Private WebRTC signaling for a host's armed recording-session phones.
 * Never grants a public viewer access to an individual camera feed.
 */
final class RecordingLiveSignaling {
 private const LIVE_SECONDS=25;
 private static function phone(PDO $db,array $pair,string $session):void {
  RecordingSessions::id($session);
  if(ControllerPairing::status($pair)!=='paired')throw new DomainException('Approved phone pairing is required.',403);
  $q=$db->prepare("SELECT 1 FROM recording_sessions s
   JOIN recording_phone_permissions p ON p.recording_session_id=s.id
   JOIN recording_camera_arms a ON a.recording_session_id=s.id AND a.pair_id=?
   WHERE s.id=? AND s.owner_admin_id=? AND s.status='active' AND p.phone_enabled=1
    AND a.armed=1 AND a.capture_kind='video'
    AND a.last_seen_at>DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 15 SECOND)");
  $q->execute([$pair['id'],$session,(int)$pair['host_admin_id']]);
  if(!$q->fetchColumn())throw new DomainException('Arm this approved video camera for the active recording first.',403);
 }
 private static function host(PDO $db,string $session,int $admin):void {
  RecordingSessions::id($session);
  $q=$db->prepare("SELECT 1 FROM recording_sessions s
   JOIN recording_phone_permissions p ON p.recording_session_id=s.id
   WHERE s.id=? AND s.owner_admin_id=? AND s.status='active' AND p.phone_enabled=1");
  $q->execute([$session,$admin]);
  if(!$q->fetchColumn())throw new DomainException('Recording is not active or phone cameras are disabled.',403);
 }
 private static function expire(PDO $db):void {
  $db->exec('DELETE FROM recording_live_streams WHERE updated_at<UTC_TIMESTAMP(3)-INTERVAL 3 MINUTE');
 }
 public static function offer(PDO $db,array $pair,string $session,string $nonce,string $sdp):void {
  self::phone($db,$pair,$session);
  PhoneLiveSignaling::nonce($nonce);PhoneLiveSignaling::sdp($sdp,'offer');
  self::expire($db);
  // A paired phone can advertise one live production preview at a time.
  // Re-offering replaces its old nonce and immediately invalidates old answers.
  $q=$db->prepare('INSERT INTO recording_live_streams(pair_id,recording_session_id,nonce,offer_sdp,answer_sdp,updated_at)
   VALUES (?,?,?,?,NULL,UTC_TIMESTAMP(3)) ON DUPLICATE KEY UPDATE
    recording_session_id=VALUES(recording_session_id),nonce=VALUES(nonce),
    offer_sdp=VALUES(offer_sdp),answer_sdp=NULL,updated_at=UTC_TIMESTAMP(3)');
  $q->execute([$pair['id'],$session,$nonce,$sdp]);
 }
 public static function listOffers(PDO $db,string $session,int $admin):array {
  self::host($db,$session,$admin);
  $q=$db->prepare("SELECT l.pair_id,l.nonce,l.offer_sdp,p.device_name
   FROM recording_live_streams l JOIN controller_pairs p ON p.id=l.pair_id
    JOIN recording_camera_arms a ON a.pair_id=l.pair_id AND a.recording_session_id=l.recording_session_id
   WHERE l.recording_session_id=? AND l.updated_at>UTC_TIMESTAMP(3)-INTERVAL 25 SECOND
    AND p.host_admin_id=? AND p.status='paired' AND p.paired_expires_at>UTC_TIMESTAMP()
    AND a.armed=1 AND a.capture_kind='video'
    AND a.last_seen_at>DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 15 SECOND)
   ORDER BY l.created_at,l.pair_id LIMIT 8");
  $q->execute([$session,$admin]);
  return array_map(static fn(array $row):array=>['id'=>$row['pair_id'],'nonce'=>$row['nonce'],
   'name'=>$row['device_name'],'offer'=>$row['offer_sdp']],$q->fetchAll(PDO::FETCH_ASSOC));
 }
 public static function answer(PDO $db,string $session,int $admin,string $pair,string $nonce,string $sdp):void {
  self::host($db,$session,$admin);ControllerPairing::checkId($pair);
  PhoneLiveSignaling::nonce($nonce);PhoneLiveSignaling::sdp($sdp,'answer');
  $q=$db->prepare("UPDATE recording_live_streams l
   JOIN controller_pairs p ON p.id=l.pair_id
   JOIN recording_camera_arms a ON a.pair_id=l.pair_id AND a.recording_session_id=l.recording_session_id
   SET l.answer_sdp=?
   WHERE l.pair_id=? AND l.nonce=? AND l.recording_session_id=? AND l.answer_sdp IS NULL
    AND l.updated_at>UTC_TIMESTAMP(3)-INTERVAL 25 SECOND
    AND p.host_admin_id=? AND p.status='paired' AND p.paired_expires_at>UTC_TIMESTAMP()
    AND a.armed=1 AND a.capture_kind='video'
    AND a.last_seen_at>DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 15 SECOND)");
  $q->execute([$sdp,$pair,$nonce,$session,$admin]);
  if($q->rowCount()!==1)throw new DomainException('Phone stream was replaced, revoked or disconnected.',409);
 }
 public static function poll(PDO $db,array $pair,string $session,string $nonce):array {
  self::phone($db,$pair,$session);PhoneLiveSignaling::nonce($nonce);
  $q=$db->prepare('SELECT answer_sdp FROM recording_live_streams
   WHERE pair_id=? AND recording_session_id=? AND nonce=? AND updated_at>UTC_TIMESTAMP(3)-INTERVAL 25 SECOND');
  $q->execute([$pair['id'],$session,$nonce]);$row=$q->fetch(PDO::FETCH_ASSOC);
  if(!$row)throw new DomainException('Live preview expired or was replaced; reconnect.',409);
  $db->prepare('UPDATE recording_live_streams SET updated_at=UTC_TIMESTAMP(3) WHERE pair_id=? AND nonce=?')
   ->execute([$pair['id'],$nonce]);
  return ['answer'=>$row['answer_sdp']?:null];
 }
 public static function end(PDO $db,array $pair,string $nonce):void {
  PhoneLiveSignaling::nonce($nonce);
  $db->prepare('DELETE FROM recording_live_streams WHERE pair_id=? AND nonce=?')->execute([$pair['id'],$nonce]);
 }
}
