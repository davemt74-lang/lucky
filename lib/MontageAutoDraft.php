<?php
declare(strict_types=1);
require_once __DIR__.'/MontagePlan.php';
/** Idempotent session-end highlight suggestions. Never renders or overwrites user edits. */
final class MontageAutoDraft {
 public static function decisions(array $source):array {
  if(!$source)return [];
  // Preserve the session's claim order, but favor reaction video within each prize.
  // Do not limit to the first 80 SQL rows: a later camera video may precede photos.
  $groups=[];$keys=[];
  foreach($source as $row){
   if(!is_array($row)||!in_array($row['kind']??null,['video','photo','audio'],true)
      ||!preg_match('/^[a-f0-9]{32}$/D',(string)($row['id']??'')))continue;
   $claim=(string)($row['claim_id']??$row['claimId']??'');
   if(!isset($groups[$claim])){$groups[$claim]=[];$keys[]=$claim;}
   $groups[$claim][]=$row;
  }
  $ordered=[];
  foreach($keys as $key){
   $group=$groups[$key];
   usort($group,static function($a,$b){
    $rank=['video'=>0,'photo'=>1,'audio'=>2];
    return ($rank[$a['kind']]<=>$rank[$b['kind']])
      ?:((int)($a['angle_index']??0)<=>(int)($b['angle_index']??0));
   });
   array_push($ordered,...$group);
  }
  $selected=[];$total=0;
  foreach($ordered as $row){
   if(count($selected)>=MontagePlan::MAX_RENDER_CLIPS)break;
   $start=max(0,(int)($row['trim_start_ms']??0));
   $end=max(0,(int)($row['trim_end_ms']??0));
   $full=max(0,(int)($row['duration_ms']??0));
   $photo=(int)($row['photo_duration_ms']??4000);
   if($row['kind']==='photo'){
    if($photo<1000||$photo>20000)continue;
    $length=$photo;
   }else{
    if($end>0 && $end<=$start)continue;
    if($full>0 && $start>=$full)continue;
    // Long recordings are trimmed non-destructively to the worker's segment limit.
    $end=$end>0?min($end,$start+120000):($full>0?min($full,$start+120000):0);
    $length=$end>0?$end-$start:120000; // Unknown durations use the worker's conservative estimate.
    if($length<1000||$length>120000)continue;
   }
   if($total+$length>MontagePlan::MAX_TOTAL_MS)continue; // Include later short clips instead of discarding the entire draft.
   $total+=$length;
   $row['trim_start_ms']=$start;$row['trim_end_ms']=$end;$row['photo_duration_ms']=$photo;
   $selected[]=$row;
  }
  if(!$selected)return [];
  // The selected set has already passed length limits; use shared validation too.
  $selected=MontagePlan::autoOrder($selected);
  $out=[];
  foreach($selected as $row){
   $out[]=['id'=>$row['id'],'instanceId'=>$row['id'],
    'title'=>(string)$row['title'],'trimStartMs'=>(int)$row['trim_start_ms'],
    'trimEndMs'=>(int)$row['trim_end_ms'],'photoDurationMs'=>(int)$row['photo_duration_ms'],
    'transition'=>'fade'];
  }
  return $out;
 }
 public static function ensure(PDO $db,string $session):bool {
  $q=$db->prepare('SELECT 1 FROM montage_projects WHERE session_id=?');$q->execute([$session]);
  if($q->fetchColumn())return false; // Manual work always takes precedence.
  $q=$db->prepare('SELECT * FROM claim_media WHERE session_id=? ORDER BY sort_order,created_at,id LIMIT 512');$q->execute([$session]);
  $project=self::decisions($q->fetchAll());if(!$project)return false;
  $q=$db->prepare('INSERT IGNORE INTO montage_projects (session_id,edit_json) VALUES (?,?)');
  $q->execute([$session,json_encode($project,JSON_THROW_ON_ERROR)]);
  return $q->rowCount()===1;
 }
}
