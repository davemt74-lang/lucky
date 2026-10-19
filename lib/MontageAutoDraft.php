<?php
declare(strict_types=1);
require_once __DIR__.'/MontagePlan.php';
/** Idempotent session-end highlight suggestions. No FFmpeg execution or user edits overwritten. */
final class MontageAutoDraft {
 public static function decisions(array $source):array {
  if(!$source)return [];
  // Each video remains associated with its prize claim; small highlight plans stay renderable.
  $selected=array_slice($source,0,MontagePlan::MAX_RENDER_CLIPS);
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
  if($q->fetchColumn())return false; // Manual work takes precedence.
  $q=$db->prepare('SELECT * FROM claim_media WHERE session_id=? ORDER BY sort_order,created_at,id');$q->execute([$session]);
  $project=self::decisions($q->fetchAll());if(!$project)return false;
  $q=$db->prepare('INSERT IGNORE INTO montage_projects (session_id,edit_json) VALUES (?,?)');
  $q->execute([$session,json_encode($project,JSON_THROW_ON_ERROR)]);
  return $q->rowCount()===1;
 }
}
