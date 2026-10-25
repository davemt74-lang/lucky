<?php
declare(strict_types=1);
require_once __DIR__.'/RecordingTimeline.php';
require_once __DIR__.'/RecordingExportPlan.php';

/** Admin-only immutable multicamera renders, queued from a saved timeline revision. */
final class RecordingExports {
 public static function publicRow(array $row):array {
  $ready=$row['status']==='ready';
  return ['id'=>$row['id'],'sessionId'=>$row['recording_session_id'],'status'=>$row['status'],
   'revision'=>(int)$row['timeline_revision'],'durationMs'=>(int)$row['duration_ms'],
   'bytes'=>(int)$row['output_bytes'],'error'=>$row['error_message']??'',
   'createdAt'=>$row['created_at'],'startedAt'=>$row['started_at'],'finishedAt'=>$row['finished_at'],
   'download'=>$ready?'api/recording-export.php?action=download&id='.$row['id']:null,
   'play'=>$ready?'api/recording-export.php?action=play&id='.$row['id']:null];
 }
 public static function get(PDO $db,int $owner,string $id):array {
  RecordingSessions::id($id);
  $q=$db->prepare('SELECT * FROM recording_exports WHERE id=? AND owner_admin_id=?');$q->execute([$id,$owner]);
  $row=$q->fetch(PDO::FETCH_ASSOC);if(!$row)throw new DomainException('Multicamera export not found.',404);
  return $row;
 }
 public static function list(PDO $db,int $owner,string $sessionId):array {
  RecordingSessions::get($db,$owner,$sessionId);
  $q=$db->prepare('SELECT * FROM recording_exports WHERE recording_session_id=? AND owner_admin_id=? ORDER BY created_at DESC,id DESC LIMIT 25');
  $q->execute([$sessionId,$owner]);return array_map([self::class,'publicRow'],$q->fetchAll(PDO::FETCH_ASSOC));
 }
 public static function queue(PDO $db,int $owner,string $sessionId,int $revision,?string $masterAudio,string $nonce):array {
  RecordingSessions::id($sessionId);
  if($owner<1)throw new DomainException('Administrator login required.',403);
  if($revision<0||$revision>2147483647)throw new DomainException('Invalid edit revision.',422);
  if($masterAudio!==null&&$masterAudio!=='')RecordingSessions::id($masterAudio);
  if(!preg_match('/^[a-zA-Z0-9_-]{8,80}$/D',$nonce))throw new DomainException('Unique export request token required.',422);
  $hash=hash('sha256',json_encode([$revision,$masterAudio],JSON_THROW_ON_ERROR));
  $db->beginTransaction();try{
   $q=$db->prepare('SELECT id FROM recording_sessions WHERE id=? AND owner_admin_id=? FOR UPDATE');$q->execute([$sessionId,$owner]);
   if(!$q->fetchColumn())throw new DomainException('Recording session not found.',404);
   $q=$db->prepare('SELECT * FROM recording_exports WHERE recording_session_id=? AND client_nonce=? FOR UPDATE');$q->execute([$sessionId,$nonce]);
   $previous=$q->fetch(PDO::FETCH_ASSOC);
   if($previous){
    if(!hash_equals((string)$previous['request_hash'],$hash))throw new DomainException('Export request token already used for different options.',409);
    $db->commit();return self::publicRow($previous);
   }
   $project=RecordingTimeline::get($db,$owner,$sessionId);
   if((int)$project['revision']!==$revision)throw new DomainException('Timeline changed. Reload before exporting.',409);
   $q=$db->prepare("SELECT id FROM recording_exports WHERE recording_session_id=? AND status IN ('queued','running') LIMIT 1 FOR UPDATE");$q->execute([$sessionId]);
   if($q->fetch())throw new DomainException('An export is already in progress for this recording.',409);
   $plan=RecordingExportPlan::build(RecordingTimeline::media($db,$owner,$sessionId),$project['timeline'],$masterAudio);
   $id=bin2hex(random_bytes(16));$json=json_encode($plan,JSON_THROW_ON_ERROR);
   $q=$db->prepare('INSERT INTO recording_exports(id,recording_session_id,owner_admin_id,client_nonce,request_hash,timeline_revision,audio_asset_id,profile,snapshot_json,duration_ms) VALUES (?,?,?,?,?,?,?,?,?,?)');
   $q->execute([$id,$sessionId,$owner,$nonce,$hash,$revision,$plan['audioAssetId'],'standard',$json,$plan['durationMs']]);
   $db->commit();
   return self::getPublic($db,$owner,$id);
  }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
 }
 private static function getPublic(PDO $db,int $owner,string $id):array {return self::publicRow(self::get($db,$owner,$id));}
 public static function cancel(PDO $db,int $owner,string $id):array {
  $row=self::get($db,$owner,$id);
  $q=$db->prepare("UPDATE recording_exports SET status='cancelled',finished_at=UTC_TIMESTAMP(3) WHERE id=? AND owner_admin_id=? AND status='queued'");
  $q->execute([$id,$owner]);
  if($q->rowCount()!==1)throw new DomainException('Only a queued export can be cancelled.',409);
  return self::getPublic($db,$owner,$id);
 }
}
