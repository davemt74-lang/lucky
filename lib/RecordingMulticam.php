<?php
declare(strict_types=1);
require_once __DIR__.'/RecordingPhone.php';

/** Explicit, revocable coordination over approved QR phone pairings.
 * Commands are observed by polling, so timing is approximate—not frame-accurate.
 */
final class RecordingMulticam {
 public const KINDS=['video','audio'];
 public const STATES=['standby','recording','uploading','error'];
 private static function timestamp(?string $date):?int {
  if(!$date)return null;
  $value=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.v',$date,new DateTimeZone('UTC'));
  return $value?(int)round((float)$value->format('U.u')*1000):null;
 }
 private static function approved(PDO $db,array $pair,string $sessionId):void {
  RecordingSessions::id($sessionId);
  $q=$db->prepare('SELECT s.status,p.phone_enabled FROM recording_sessions s JOIN recording_phone_permissions p ON p.recording_session_id=s.id WHERE s.id=? AND s.owner_admin_id=?');
  $q->execute([$sessionId,(int)$pair['host_admin_id']]);$session=$q->fetch(PDO::FETCH_ASSOC);
  if(!$session||$session['status']!=='active'||(int)$session['phone_enabled']!==1)
   throw new DomainException('The host disabled or completed this recording.',403);
 }
 public static function arm(PDO $db,array $pair,string $sessionId,bool $enabled,string $kind):array {
  if(!in_array($kind,self::KINDS,true))throw new DomainException('Choose video or audio for multicamera.',422);
  self::approved($db,$pair,$sessionId);
  $db->beginTransaction();try{
   // Serialize competing phones on the parent recording row before counting
   // online armed peers, preventing two devices from taking the final slot.
   $q=$db->prepare('SELECT id FROM recording_sessions WHERE id=? AND owner_admin_id=? FOR UPDATE');
   $q->execute([$sessionId,(int)$pair['host_admin_id']]);
   if(!$q->fetchColumn())throw new DomainException('Recording session not found.',404);
   if($enabled){
    $capture=RecordingProfiles::kind($db,$sessionId,$kind);
    $q=$db->prepare("SELECT COUNT(*) FROM recording_camera_arms a JOIN controller_pairs p ON p.id=a.pair_id
      WHERE a.recording_session_id=? AND a.pair_id<>? AND a.armed=1
      AND a.last_seen_at >= DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 15 SECOND) AND p.status='paired'");
    $q->execute([$sessionId,$pair['id']]);
    if((int)$q->fetchColumn()>=$capture['maxCameras'])
     throw new DomainException('Recording camera limit reached. Disarm a phone or update the next recording profile.',409);
   }
   $q=$db->prepare("INSERT INTO recording_camera_arms(recording_session_id,pair_id,capture_kind,armed,last_state,last_seen_at) VALUES (?,?,?,?,'standby',UTC_TIMESTAMP(3)) ON DUPLICATE KEY UPDATE capture_kind=VALUES(capture_kind),armed=VALUES(armed),last_state='standby',last_seen_at=UTC_TIMESTAMP(3)");
   $q->execute([$sessionId,$pair['id'],$kind,(int)$enabled]);
   $db->commit();return ['armed'=>$enabled,'kind'=>$kind];
  }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
 }
 public static function poll(PDO $db,array $pair,string $sessionId,string $state):array {
  if(!in_array($state,self::STATES,true))throw new DomainException('Invalid phone camera state.',422);
  self::approved($db,$pair,$sessionId);
  $q=$db->prepare('SELECT armed,capture_kind FROM recording_camera_arms WHERE recording_session_id=? AND pair_id=?');
  $q->execute([$sessionId,$pair['id']]);$row=$q->fetch(PDO::FETCH_ASSOC);
  if(!$row||(int)$row['armed']!==1)return ['armed'=>false,'serverTimeMs'=>(int)round(microtime(true)*1000),'activeTake'=>null];
  $q=$db->prepare("UPDATE recording_camera_arms SET last_seen_at=UTC_TIMESTAMP(3),last_state=? WHERE recording_session_id=? AND pair_id=? AND armed=1");
  $q->execute([$state,$sessionId,$pair['id']]);
  $q=$db->prepare("SELECT id,take_index,title,started_at FROM recording_takes WHERE recording_session_id=? AND status='recording' ORDER BY take_index DESC LIMIT 1");
  $q->execute([$sessionId]);$take=$q->fetch(PDO::FETCH_ASSOC);
  if($take){$q=$db->prepare('UPDATE recording_camera_arms SET last_take_id=? WHERE recording_session_id=? AND pair_id=?');$q->execute([$take['id'],$sessionId,$pair['id']]);}
  return ['armed'=>true,'kind'=>$row['capture_kind'],'serverTimeMs'=>(int)round(microtime(true)*1000),
   'activeTake'=>$take?['id'=>$take['id'],'index'=>(int)$take['take_index'],'title'=>$take['title'],'startedAtMs'=>self::timestamp($take['started_at'])]:null];
 }
 public static function devices(PDO $db,int $owner,string $sessionId):array {
  RecordingSessions::get($db,$owner,$sessionId);
  $q=$db->prepare("SELECT c.pair_id,p.device_name,p.status AS pair_status,c.capture_kind,c.armed,c.last_state,c.last_take_id,c.last_seen_at,
    TIMESTAMPDIFF(SECOND,c.last_seen_at,UTC_TIMESTAMP(3)) AS age_seconds,p.paired_expires_at
    FROM recording_camera_arms c JOIN controller_pairs p ON p.id=c.pair_id WHERE c.recording_session_id=? ORDER BY c.updated_at DESC,c.pair_id LIMIT 40");
  $q->execute([$sessionId]);$result=[];
  foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){
   $online=(int)$r['armed']===1&&$r['pair_status']==='paired'&&(int)$r['age_seconds']<10&&strtotime($r['paired_expires_at'].' UTC')>time();
   $result[]=['id'=>$r['pair_id'],'name'=>$r['device_name'],'kind'=>$r['capture_kind'],'armed'=>(bool)$r['armed'],
    'state'=>$r['last_state'],'online'=>$online,'lastTakeId'=>$r['last_take_id'],'lastSeenAt'=>$r['last_seen_at']];
  }
  return $result;
 }
}
