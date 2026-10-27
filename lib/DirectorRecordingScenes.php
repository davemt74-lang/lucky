<?php
declare(strict_types=1);
/** Recording-session scene presets, separate from prize-proof scenes. */
final class DirectorRecordingScenes {
 private const LIMIT=16;
 private const STICKERS=['','⭐','🏆','🎉','🔥','🎁'];
 private static function id(string $value):string {
  if(!preg_match('/^[a-f0-9]{32}$/D',$value))throw new DomainException('Invalid recording or scene identifier.',422);
  return $value;
 }
 private static function owned(PDO $db,string $id,int $host,bool $write=false):void {
  self::id($id);
  $q=$db->prepare('SELECT status FROM recording_sessions WHERE id=? AND owner_admin_id=?');$q->execute([$id,$host]);
  $status=$q->fetchColumn();if(!$status)throw new DomainException('Recording session unavailable.',403);
  if($write&&!in_array($status,['draft','active'],true))throw new DomainException('This recording session has been completed.',409);
 }
 private static function output(array $row):array {
  return ['id'=>$row['id'],'name'=>$row['name'],'source'=>$row['source_id'],'transition'=>$row['transition_kind'],
   'title'=>$row['overlay_text'],'sticker'=>$row['overlay_sticker']];
 }
 public static function listing(PDO $db,string $id,int $host):array {
  self::owned($db,$id,$host);
  $q=$db->prepare('SELECT * FROM director_recording_scenes WHERE recording_session_id=? AND host_admin_id=? ORDER BY name,id LIMIT 16');
  $q->execute([$id,$host]);return array_map([self::class,'output'],$q->fetchAll(PDO::FETCH_ASSOC));
 }
 public static function save(PDO $db,string $id,int $host,array $data):array {
  self::owned($db,$id,$host,true);
  $name=trim((string)($data['name']??''));
  if($name===''||(function_exists('mb_strlen')?mb_strlen($name,'UTF-8'):strlen($name))>60)throw new DomainException('Scene name must contain 1–60 characters.',422);
  $source=(string)($data['source']??'');
  if(!preg_match('/^(camera|phone):[A-Za-z0-9_-]{1,64}$|^clip:[a-f0-9]{32}$/D',$source))throw new DomainException('Invalid Director source.',422);
  if(str_starts_with($source,'clip:')){
   $q=$db->prepare("SELECT 1 FROM recording_assets WHERE id=? AND recording_session_id=? AND kind='video'");
   $q->execute([substr($source,5),$id]);if(!$q->fetchColumn())throw new DomainException('Scene clip must belong to this recording session.',403);
  }
  $transition=(string)($data['transition']??'cut');if(!in_array($transition,['cut','fade','wipe','dip'],true))throw new DomainException('Invalid transition.',422);
  $title=trim((string)($data['title']??''));
  if((function_exists('mb_strlen')?mb_strlen($title,'UTF-8'):strlen($title))>100)throw new DomainException('Scene title too long.',422);
  $sticker=(string)($data['sticker']??'');if(!in_array($sticker,self::STICKERS,true))throw new DomainException('Invalid sticker.',422);
  $db->beginTransaction();
  try{
   $q=$db->prepare("SELECT id FROM recording_sessions WHERE id=? AND owner_admin_id=? AND status IN ('draft','active') FOR UPDATE");
   $q->execute([$id,$host]);if(!$q->fetchColumn())throw new DomainException('Recording is no longer editable.',409);
   $q=$db->prepare('SELECT id FROM director_recording_scenes WHERE recording_session_id=? AND host_admin_id=? AND name=?');
   $q->execute([$id,$host,$name]);$scene=$q->fetchColumn();
   if($scene){
    $db->prepare('UPDATE director_recording_scenes SET source_id=?,transition_kind=?,overlay_text=?,overlay_sticker=?,updated_at=UTC_TIMESTAMP() WHERE id=? AND host_admin_id=?')
     ->execute([$source,$transition,$title,$sticker,$scene,$host]);
   }else{
    $q=$db->prepare('SELECT COUNT(*) FROM director_recording_scenes WHERE recording_session_id=? AND host_admin_id=?');$q->execute([$id,$host]);
    if((int)$q->fetchColumn()>=self::LIMIT)throw new DomainException('Limit of 16 scenes reached.',409);
    $scene=bin2hex(random_bytes(16));
    $db->prepare('INSERT INTO director_recording_scenes(id,recording_session_id,host_admin_id,name,source_id,transition_kind,overlay_text,overlay_sticker) VALUES (?,?,?,?,?,?,?,?)')
     ->execute([$scene,$id,$host,$name,$source,$transition,$title,$sticker]);
   }
   $db->commit();
  }catch(Throwable $e){$db->rollBack();throw $e;}
  return ['id'=>$scene,'name'=>$name,'source'=>$source,'transition'=>$transition,'title'=>$title,'sticker'=>$sticker];
 }
 public static function remove(PDO $db,string $session,int $host,string $scene):void {
  self::owned($db,$session,$host,true);self::id($scene);
  $q=$db->prepare('DELETE FROM director_recording_scenes WHERE id=? AND recording_session_id=? AND host_admin_id=?');
  $q->execute([$scene,$session,$host]);if($q->rowCount()!==1)throw new DomainException('Scene not found.',404);
 }
}
