<?php
declare(strict_types=1);
/** Private, claim-linked media validation and timeline metadata. */
final class ClaimMedia {
 public const MAX_BYTES=80*1024*1024;
 public const MAX_CLIPS_PER_SESSION=160;
 public const MAX_SESSION_BYTES=600*1024*1024;
 public const MIME_KIND=[
  'image/jpeg'=>'photo','image/png'=>'photo','image/webp'=>'photo',
  'video/webm'=>'video','video/mp4'=>'video','video/quicktime'=>'video',
  'audio/webm'=>'audio','audio/mpeg'=>'audio','audio/mp4'=>'audio','audio/ogg'=>'audio','audio/wav'=>'audio','audio/x-wav'=>'audio'
 ];
 public static function validateUpload(string $mime,int $bytes,?array $dimensions=null):string {
  if(!isset(self::MIME_KIND[$mime]))throw new DomainException('Unsupported media type. Upload a JPG, PNG, WebP, MP4, WebM, MOV, MP3, OGG or WAV file.',422);
  if($bytes<=0||$bytes>self::MAX_BYTES)throw new DomainException('Capture must be 80 MB or less.',413);
  $kind=self::MIME_KIND[$mime];
  if($kind==='photo'&&(!$dimensions||$dimensions[0]<1||$dimensions[1]<1||$dimensions[0]>8000||$dimensions[1]>8000))throw new DomainException('Invalid photo dimensions.',422);
  return $kind;
 }
 public static function validId(string $id):bool{return (bool)preg_match('/^[a-f0-9]{32}$/D',$id);}
 public static function edit(array $existing,array $in):array {
  $title=trim((string)($in['title']??$existing['title']));
  if($title===''||mbSafeLen($title)>120)throw new DomainException('Clip title must contain 1–120 characters.',422);
  $duration=max(0,(int)$existing['duration_ms']);
  $start=$in['trimStartMs']??$existing['trim_start_ms'];
  $end=$in['trimEndMs']??$existing['trim_end_ms'];
  if(!is_numeric($start)||!is_numeric($end))throw new DomainException('Invalid trim boundaries.',422);
  $start=(int)$start;$end=(int)$end;
  $photo=$in['photoDurationMs']??$existing['photo_duration_ms'];
  if(!is_numeric($photo))throw new DomainException('Invalid photo duration.',422);
  $photo=(int)$photo;
  if($photo<1000||$photo>20000)throw new DomainException('Photo duration must be 1–20 seconds.',422);
  if($start<0||$end<0||$start>3600000||$end>3600000)throw new DomainException('Invalid trim boundaries.',422);
  if($existing['kind']!=='photo' && $duration>0){
    if($start>=$duration)throw new DomainException('Start must be before clip end.',422);
    if($end!==0 && ($end<=$start || $end>$duration))throw new DomainException('End must follow start and stay within the clip.',422);
  }
  return ['title'=>$title,'trim_start_ms'=>$start,'trim_end_ms'=>$end,'photo_duration_ms'=>$photo];
 }
 public static function asPublic(array $r):array {
  return ['id'=>$r['id'],'sessionId'=>$r['session_id'],'claimId'=>$r['claim_id'],'kind'=>$r['kind'],
   'mime'=>$r['mime'],'title'=>$r['title'],'sourceLabel'=>$r['source_label']??'','angleLabel'=>$r['angle_label']??'','angleIndex'=>(int)($r['angle_index']??0),'bytes'=>(int)$r['bytes'],'durationMs'=>(int)$r['duration_ms'],
   'trimStartMs'=>(int)$r['trim_start_ms'],'trimEndMs'=>(int)$r['trim_end_ms'],
   'photoDurationMs'=>(int)$r['photo_duration_ms'],'order'=>(int)$r['sort_order'],
   'at'=>$r['created_at'],'src'=>'api/claim-media.php?action=stream&id='.$r['id'],
   'prize'=>$r['prize_name']??'', 'tier'=>$r['tier']??''];
 }
}
function mbSafeLen(string $v):int {return function_exists('mb_strlen')?mb_strlen($v,'UTF-8'):strlen($v);}
