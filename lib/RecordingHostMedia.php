<?php
declare(strict_types=1);
require_once __DIR__.'/RecordingSessions.php';
require_once __DIR__.'/ClaimMedia.php';

/** Admin-only original captures from Camera Director, never prize-claim uploads. */
final class RecordingHostMedia {
 public static function assetId(int $owner,string $nonce):string {
  if(!preg_match('/^[a-zA-Z0-9_-]{12,80}$/D',$nonce))throw new DomainException('Invalid host recording upload token.',422);
  return substr(hash('sha256','host:'.$owner.':'.$nonce),0,32);
 }
 public static function store(PDO $db,int $owner,array $input,?array $file):array {
  $sessionId=RecordingSessions::id((string)($input['session']??''));
  $takeId=RecordingSessions::id((string)($input['takeId']??''));
  $nonce=(string)($input['nonce']??'');
  $assetId=self::assetId($owner,$nonce);
  $cameraId=(string)($input['sourceKey']??'');
  if(!preg_match('/^(program|cam_[a-zA-Z0-9_-]{1,58})$/D',$cameraId))
   throw new DomainException('Invalid Director source identifier.',422);
  $sourceKey=$cameraId==='program'?'director-program':'director-'.substr(hash('sha256',$cameraId),0,24);
  $sourceName=$cameraId==='program'?'Program Output':trim((string)($input['sourceName']??'Desktop Camera'));
  if($sourceName===''||mbSafeLen($sourceName)>120||preg_match('/[\x00-\x1f\x7f]/',$sourceName))
   $sourceName='Desktop Camera';
  $sourceKind=$cameraId==='program'?'camera':'desktop';
  if(!is_array($file)||($file['error']??-1)!==UPLOAD_ERR_OK||!is_uploaded_file((string)($file['tmp_name']??'')))
   throw new DomainException('Choose a valid Director video or check your server upload limit.',422);
  $bytes=(int)$file['size'];
  $mime=(new finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']);
  $kind=ClaimMedia::captureKind((string)$mime,$bytes,null,'video',(string)$file['tmp_name']);
  if($kind!=='video')throw new DomainException('Director originals must contain video.',422);
  $start=filter_var($input['recordedStartMs']??null,FILTER_VALIDATE_INT);
  $end=filter_var($input['recordedEndMs']??null,FILTER_VALIDATE_INT);
  $delta=filter_var($input['clockOffsetMs']??null,FILTER_VALIDATE_INT);
  if($start===false||$start===null||$end===false||$end===null||$delta===false||$delta===null||
   (int)$start<1000000000000||(int)$end<(int)$start||(int)$end-(int)$start>3600000||abs((int)$delta)>86400000)
   throw new DomainException('Invalid synchronized Director capture timing.',422);
  $duration=max(0,min(3600000,(int)($input['durationMs']??((int)$end-(int)$start))));
  $digest=hash_file('sha256',(string)$file['tmp_name']);
  $path=LUCKY_ROOT.'/storage/recording-assets/'.$assetId.'.bin';
  $moved=false;
  $db->beginTransaction();
  try {
   $q=$db->prepare('SELECT status FROM recording_sessions WHERE id=? AND owner_admin_id=? FOR UPDATE');
   $q->execute([$sessionId,$owner]);
   $status=$q->fetchColumn();
   if($status===false)throw new DomainException('Recording session is unavailable.',404);
   $q=$db->prepare('SELECT a.id,a.recording_session_id,m.mime,m.bytes,m.sha256 FROM recording_assets a JOIN recording_media_files m ON m.asset_id=a.id WHERE a.id=? FOR UPDATE');
   $q->execute([$assetId]);$prior=$q->fetch(PDO::FETCH_ASSOC);
   if($prior){
    if($prior['recording_session_id']!==$sessionId||$prior['mime']!==$mime||(int)$prior['bytes']!==$bytes||
       !hash_equals((string)$prior['sha256'],$digest)||!is_file($path))
     throw new DomainException('Upload token was already used for different content.',409);
    $db->commit();return ['id'=>$assetId,'kind'=>'video','bytes'=>$bytes,'existing'=>true];
   }
   // Upload may finish after Stop, but never into an archived session.
   if(!in_array($status,['active','completed'],true))
    throw new DomainException('Recording must be active or just completed for clip upload.',409);
   $q=$db->prepare('SELECT started_at FROM recording_takes WHERE id=? AND recording_session_id=?');
   $q->execute([$takeId,$sessionId]);$taken=$q->fetchColumn();
   if($taken===false)throw new DomainException('Director take does not belong to this recording session.',403);
   $takeStart=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.v',(string)$taken,new DateTimeZone('UTC'));
   if(!$takeStart)throw new DomainException('Invalid take timestamp.',422);
   $offset=(int)$start-(int)round((float)$takeStart->format('U.u')*1000);
   if($offset< -30000||$offset>3600000)throw new DomainException('Director capture timing does not match the take.',422);
   $q=$db->prepare('SELECT COUNT(*) AS total,COALESCE(SUM(m.bytes),0) AS used FROM recording_assets a JOIN recording_media_files m ON m.asset_id=a.id WHERE a.recording_session_id=?');
   $q->execute([$sessionId]);$usage=$q->fetch(PDO::FETCH_ASSOC);
   if((int)$usage['total']>=ClaimMedia::MAX_CLIPS_PER_SESSION||(int)$usage['used']+$bytes>ClaimMedia::MAX_SESSION_BYTES)
    throw new DomainException('Recording session storage limit reached.',413);
   $source=RecordingSessions::source($db,$owner,$sessionId,['key'=>$sourceKey,'kind'=>$sourceKind,'name'=>$sourceName]);
   if(!is_dir(dirname($path))&&!mkdir(dirname($path),0700,true))
    throw new RuntimeException('Could not create private recording folder.');
   if(!move_uploaded_file((string)$file['tmp_name'],$path))
    throw new RuntimeException('Failed to save Director original.');$moved=true;@chmod($path,0600);
   $q=$db->prepare('INSERT INTO recording_assets(id,recording_session_id,take_id,source_id,storage_key,kind,start_offset_ms,sync_confidence) VALUES (?,?,?,?,?,?,?,?)');
   $q->execute([$assetId,$sessionId,$takeId,$source['id'],$assetId,'video',$offset,2]);
   $q=$db->prepare('INSERT INTO recording_media_files(asset_id,mime,title,bytes,sha256,duration_ms) VALUES (?,?,?,?,?,?)');
   $q->execute([$assetId,$mime,$sourceName,$bytes,$digest,$duration]);
   $q=$db->prepare("INSERT INTO recording_capture_sync(asset_id,recorded_start_ms,recorded_end_ms,clock_offset_ms,sync_method) VALUES (?,?,?,?,'director_clock')");
   $q->execute([$assetId,(int)$start,(int)$end,(int)$delta]);
   $db->commit();return ['id'=>$assetId,'kind'=>'video','bytes'=>$bytes,'existing'=>false];
  }catch(Throwable $e){if($db->inTransaction())$db->rollBack();if($moved)@unlink($path);throw $e;}
 }
}
