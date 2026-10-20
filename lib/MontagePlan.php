<?php
declare(strict_types=1);
require_once __DIR__.'/MontageVisuals.php';
/** Validated, deterministic edit decisions used by both the UI and the renderer. */
final class MontagePlan {
 public const MAX_RENDER_CLIPS=80;
 public const MAX_TOTAL_MS=20*60*1000;
 public static function ms(array $clip):int {
  $kind=(string)($clip['kind']??'');
  if($kind==='photo')$duration=(int)($clip['photoDurationMs']??$clip['photo_duration_ms']??4000);
  else {
   $full=(int)($clip['durationMs']??$clip['duration_ms']??0);
   $start=(int)($clip['trimStartMs']??$clip['trim_start_ms']??0);
   $end=(int)($clip['trimEndMs']??$clip['trim_end_ms']??0);
   // Unknown uploaded clip durations are probed safely when the worker runs.
   $duration=$end>0?$end-$start:($full>0?$full-$start:0);
  }
  return max(0,$duration);
 }
 public static function validate(array $clips):array {
  if(!$clips||count($clips)>self::MAX_RENDER_CLIPS)throw new DomainException('Select 1–80 media clips for export.',422);
  $seen=[];$sum=0;$hasVideo=false;
  foreach($clips as $clip){
   if(!is_array($clip)||!preg_match('/^[a-f0-9]{32}$/D',(string)($clip['id']??'')))throw new DomainException('Invalid media clip.',422);
   if(isset($seen[$clip['instanceId']??$clip['id']]))throw new DomainException('Duplicate timeline segment in the edit.',422);
   $seen[$clip['instanceId']??$clip['id']]=1;
   if(!in_array($clip['kind']??null,['photo','video','audio'],true))throw new DomainException('Invalid clip type.',422);
   $mode=(string)($clip['audio_mode']??$clip['audioMode']??'normal');
   if(!in_array($mode,['normal','muted','detached'],true))throw new DomainException('Invalid segment audio mode.',422);
   if($mode==='detached'&&($clip['kind']??'')!=='video')throw new DomainException('Only video audio can be detached.',422);
   foreach(['detached_offset_ms','voiceover_offset_ms'] as $key){
    $camel=$key==='detached_offset_ms'?'detachedOffsetMs':'voiceoverOffsetMs';
    $value=$clip[$key]??$clip[$camel]??0;
    if(!is_numeric($value)||(float)$value<0||(float)$value>120000)throw new DomainException('Audio offset must be between 0 and 120 seconds.',422);
   }
   $voice=(string)($clip['voiceover_id']??$clip['voiceoverId']??'');
   if($voice!==''&&(!preg_match('/^[a-f0-9]{32}$/D',$voice)||($clip['kind']??'')==='audio'))
    throw new DomainException('Invalid voiceover reference.',422);
   MontageVisuals::validate((string)($clip['look_preset']??$clip['lookPreset']??'none'),(string)($clip['overlay_text']??$clip['overlayText']??''),(string)($clip['overlay_position']??$clip['overlayPosition']??'bottom'),(string)($clip['overlay_size']??$clip['overlaySize']??'medium'));
   $duration=self::ms($clip);
   if($clip['kind']==='photo'&&($duration<1000||$duration>20000))throw new DomainException('Photo duration out of range.',422);
   if($duration>120000)throw new DomainException('Individual clips are limited to 120 seconds for rendered videos.',422);
   $sum+=$duration?:120000;
   if($sum>self::MAX_TOTAL_MS)throw new DomainException('Montages are limited to 20 minutes.',422);
   $hasVideo=$hasVideo||$clip['kind']==='video';
  }
  return ['clipCount'=>count($clips),'estimatedMs'=>$sum,'containsVideo'=>$hasVideo];
 }
 /** Variety-first automatic sequencing, one camera angle per claim first, then the others. */
 public static function autoOrder(array $clips):array {
  $groups=[];$keys=[];
  foreach($clips as $clip){$claim=(string)($clip['claimId']??$clip['claim_id']??'');if(!isset($groups[$claim])){$groups[$claim]=[];$keys[]=$claim;}$groups[$claim][]=$clip;}
  $ordered=[];
  foreach($keys as $claim){
   $batch=$groups[$claim];usort($batch,static function($a,$b){
    $rank=['video'=>0,'photo'=>1,'audio'=>2];$r=($rank[$a['kind']]??3)<=>($rank[$b['kind']]??3);
    return $r!==0?$r:(($a['angleIndex']??$a['angle_index']??0)<=>($b['angleIndex']??$b['angle_index']??0));
   });
   foreach($batch as $clip)$ordered[]=$clip;
  }
  self::validate($ordered);return $ordered;
 }
 /** Resolve client editing decisions exclusively from authenticated session-owned source rows. */
 public static function materialize(array $source,array $edits):array {
  if(!$edits||count($edits)>self::MAX_RENDER_CLIPS)throw new DomainException('Invalid timeline.',422);
  $out=[];$seen=[];
  foreach($edits as $edit){
   if(!is_array($edit))throw new DomainException('Invalid timeline edit.',422);
   $id=(string)($edit['id']??'');$instance=(string)($edit['instanceId']??'');
   if(!preg_match('/^[a-f0-9]{32}$/D',$id)||!preg_match('/^[a-zA-Z0-9-]{1,80}$/D',$instance)||!isset($source[$id])||isset($seen[$instance]))throw new DomainException('Timeline source missing, duplicated or invalid. Reload the session.',409);
   $seen[$instance]=1;$item=$source[$id];$item['instanceId']=$instance;
   $start=$edit['trimStartMs']??$item['trim_start_ms'];$end=$edit['trimEndMs']??$item['trim_end_ms'];
   if(!is_numeric($start)||!is_numeric($end))throw new DomainException('Invalid segment trim.',422);
   $start=(int)$start;$end=(int)$end;
   if($start<0||$end<0||$start>3600000||$end>3600000||($end!==0&&$end<=$start))throw new DomainException('Invalid segment trim range.',422);
   $full=(int)$item['duration_ms'];
   if($full>0&&($start>=$full||($end>0&&$end>$full)))throw new DomainException('Trim exceeds source duration.',422);
   $photo=$edit['photoDurationMs']??$item['photo_duration_ms'];if(!is_numeric($photo)||$photo<1000||$photo>20000)throw new DomainException('Invalid photo timing.',422);
   $name=trim((string)($edit['title']??$item['title']));if($name===''||strlen($name)>120)throw new DomainException('Invalid clip title.',422);
    $transition=(string)($edit['transition']??'fade');
   if(!in_array($transition,['cut','fade'],true))throw new DomainException('Unsupported transition.',422);
   $audioMode=(string)($edit['audioMode']??'normal');
   if(!in_array($audioMode,['normal','muted','detached'],true)||($audioMode==='detached'&&$item['kind']!=='video'))
    throw new DomainException('Invalid segment audio mode.',422);
   $detachedOffset=$edit['detachedOffsetMs']??0;$voiceOffset=$edit['voiceoverOffsetMs']??0;
   foreach([$detachedOffset,$voiceOffset] as $offset)
    if(!is_numeric($offset)||(float)$offset<0||(float)$offset>120000)
     throw new DomainException('Audio offsets must be 0–120 seconds.',422);
   $voiceId=(string)($edit['voiceoverId']??'');
   if($voiceId!==''){
    if(!preg_match('/^[a-f0-9]{32}$/D',$voiceId)||$item['kind']==='audio'||!isset($source[$voiceId])||
       $source[$voiceId]['kind']!=='audio'||$source[$voiceId]['claim_id']!==$item['claim_id'])
     throw new DomainException('The recorded voiceover must belong to the same claimed prize.',403);
   }
   $item['audio_mode']=$audioMode;
   $item['detached_offset_ms']=(int)$detachedOffset;
   $item['voiceover_id']=$voiceId;
   $item['voiceover_offset_ms']=(int)$voiceOffset;
   $item['voiceover_muted']=($edit['voiceoverMuted']??false)===true;
   $look=(string)($edit['lookPreset']??'none');$overlay=(string)($edit['overlayText']??'');$position=(string)($edit['overlayPosition']??'bottom');$size=(string)($edit['overlaySize']??'medium');
   MontageVisuals::validate($look,$overlay,$position,$size);
   $item['look_preset']=$look;$item['overlay_text']=$overlay;$item['overlay_position']=$position;$item['overlay_size']=$size;
   $item['transition']=$transition;
   $item['trim_start_ms']=$start;$item['trim_end_ms']=$end;$item['photo_duration_ms']=(int)$photo;$item['title']=$name;
   $out[]=$item;
  }
  self::validate($out);return $out;
 }
 public static function profile(string $name):array {
  return match($name){'standard'=>['width'=>1280,'height'=>720,'crf'=>25],'high'=>['width'=>1920,'height'=>1080,'crf'=>23],default=>throw new DomainException('Choose a supported export quality.',422)};
 }
 public static function musicVolume($volume):float {
  if(!is_numeric($volume)||(float)$volume<0||(float)$volume>100)throw new DomainException('Invalid soundtrack volume.',422);
  return round(((float)$volume)/100,2);
 }
}
