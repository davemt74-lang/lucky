<?php
declare(strict_types=1);
require_once __DIR__.'/RecordingSessions.php';

/** Non-destructive multicamera project: take-relative media projected onto one session clock. */
final class RecordingTimeline {
 private static function initial():array {return ['offsets'=>[],'switches'=>[]];}
 public static function media(PDO $db,int $owner,string $sessionId):array {
  RecordingSessions::get($db,$owner,$sessionId);
  $q=$db->prepare("SELECT a.id,a.take_id,a.source_id,a.kind,a.start_offset_ms,a.sync_confidence,
    m.title,m.mime,m.bytes,m.duration_ms,m.created_at,src.display_name AS source_name,
    t.take_index,t.title AS take_title,
    ROUND(TIMESTAMPDIFF(MICROSECOND,COALESCE(s.started_at,s.created_at),COALESCE(t.started_at,m.created_at))/1000)+a.start_offset_ms AS initial_global_offset_ms
    FROM recording_assets a JOIN recording_sessions s ON s.id=a.recording_session_id
    JOIN recording_media_files m ON m.asset_id=a.id
    LEFT JOIN recording_takes t ON t.id=a.take_id
    LEFT JOIN recording_sources src ON src.id=a.source_id
    WHERE a.recording_session_id=? ORDER BY COALESCE(t.started_at,m.created_at),a.source_id,a.id LIMIT 160");
  $q->execute([$sessionId]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
  foreach($rows as &$row){$row['initial_global_offset_ms']=(int)$row['initial_global_offset_ms'];$row['duration_ms']=(int)$row['duration_ms'];$row['sync_confidence']=(int)$row['sync_confidence'];}
  unset($row);return $rows;
 }
 public static function get(PDO $db,int $owner,string $sessionId):array {
  $session=RecordingSessions::get($db,$owner,$sessionId);
  $q=$db->prepare('SELECT revision,timeline_json,updated_at FROM recording_timeline_edits WHERE recording_session_id=?');$q->execute([$sessionId]);$row=$q->fetch(PDO::FETCH_ASSOC);
  return ['session'=>$session,'revision'=>$row?(int)$row['revision']:0,'timeline'=>$row?json_decode((string)$row['timeline_json'],true,512,JSON_THROW_ON_ERROR):self::initial(),'updatedAt'=>$row['updated_at']??null];
 }
 private static function validate(PDO $db,string $sessionId,mixed $timeline):array {
  if(!is_array($timeline)||!is_array($timeline['offsets']??null)||!is_array($timeline['switches']??null)||!array_is_list($timeline['offsets'])||!array_is_list($timeline['switches']))
   throw new DomainException('Invalid multicamera timeline structure.',422);
  if(count($timeline['offsets'])>160||count($timeline['switches'])>400)throw new DomainException('Timeline edit limit exceeded.',422);
  $q=$db->prepare('SELECT a.id,a.kind FROM recording_assets a JOIN recording_media_files m ON m.asset_id=a.id WHERE a.recording_session_id=?');$q->execute([$sessionId]);
  $assets=[];foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row)$assets[$row['id']]=$row['kind'];
  $offsets=[];$used=[];
  foreach($timeline['offsets'] as $item){
   if(!is_array($item)||!is_string($item['assetId']??null)||!is_int($item['offsetMs']??null))throw new DomainException('Invalid angle offset.',422);
   $id=RecordingSessions::id($item['assetId']);$offset=$item['offsetMs'];
   if(!isset($assets[$id])||isset($used[$id])||$offset< -600000||$offset>86400000)throw new DomainException('Angle offset is out of range or belongs to another recording.',422);
   $used[$id]=true;$offsets[]=['assetId'=>$id,'offsetMs'=>$offset];
  }
  $switches=[];$previous=-1;
  foreach($timeline['switches'] as $item){
   if(!is_array($item)||!is_string($item['assetId']??null)||!is_int($item['atMs']??null))throw new DomainException('Invalid angle switch.',422);
   $id=RecordingSessions::id($item['assetId']);$at=$item['atMs'];
   if(!in_array($assets[$id]??'', ['video','photo'],true)||$at<0||$at>86400000||$at<=$previous)throw new DomainException('Switch must target a video/photo at a unique increasing timeline time.',422);
   $switches[]=['assetId'=>$id,'atMs'=>$at];$previous=$at;
  }
  return ['offsets'=>$offsets,'switches'=>$switches];
 }
 public static function save(PDO $db,int $owner,string $sessionId,int $revision,mixed $timeline):array {
  if($revision<0)throw new DomainException('Invalid timeline revision.',422);
  $db->beginTransaction();try{
   RecordingSessions::get($db,$owner,$sessionId);
   $q=$db->prepare('SELECT id FROM recording_sessions WHERE id=? AND owner_admin_id=? FOR UPDATE');$q->execute([$sessionId,$owner]);
   $q=$db->prepare('SELECT revision FROM recording_timeline_edits WHERE recording_session_id=? FOR UPDATE');$q->execute([$sessionId]);$current=$q->fetchColumn();
   $actual=$current===false?0:(int)$current;
   if($actual!==$revision)throw new DomainException('Another editor changed this timeline. Reload before saving.',409);
   $valid=self::validate($db,$sessionId,$timeline);
   $json=json_encode($valid,JSON_THROW_ON_ERROR);
   if($actual===0){$q=$db->prepare('INSERT INTO recording_timeline_edits(recording_session_id,revision,timeline_json) VALUES (?,1,?)');$q->execute([$sessionId,$json]);}
   else{$q=$db->prepare('UPDATE recording_timeline_edits SET revision=revision+1,timeline_json=? WHERE recording_session_id=? AND revision=?');$q->execute([$json,$sessionId,$revision]);if($q->rowCount()!==1)throw new DomainException('Timeline changed while saving.',409);}
   $db->commit();return ['revision'=>$actual+1,'timeline'=>$valid];
  }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
 }
}
