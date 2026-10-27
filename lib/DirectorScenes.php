<?php
declare(strict_types=1);
/** Private, host-owned scene presets for the current proof. */
final class DirectorScenes {
 private const LIMIT=16;
 private const TRANSITIONS=['cut','fade','wipe','dip'];
 private const STICKERS=['','⭐','🏆','🎉','🔥','🎁'];
 private static function id(string $id):void {
  if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new DomainException('Invalid scene or proof identifier.',422);
 }
 private static function clean(string $value,int $max,string $label,bool $empty=false):string {
  $value=trim($value);$length=function_exists('mb_strlen')?mb_strlen($value,'UTF-8'):strlen($value);
  if($length>$max||(!$empty&&$length===0))throw new DomainException('Invalid '.$label.' length.',422);
  return $value;
 }
 private static function check(PDO $db,string $session,string $claim,int $host):void {
  self::id($session);self::id($claim);
  $q=$db->prepare('SELECT 1 FROM controller_proof_state WHERE session_id=? AND claim_id=? AND host_admin_id=? AND is_open=1 AND updated_at>UTC_TIMESTAMP()-INTERVAL 12 SECOND');
  $q->execute([$session,$claim,$host]);
  if(!$q->fetchColumn())throw new DomainException('Open the current proof to manage director scenes.',403);
 }
 private static function output(array $row):array {
  return ['id'=>$row['id'],'name'=>$row['name'],'source'=>$row['source_id'],
   'transition'=>$row['transition_kind'],'title'=>$row['overlay_text'],'sticker'=>$row['overlay_sticker']];
 }
 public static function listing(PDO $db,string $session,string $claim,int $host):array {
  self::check($db,$session,$claim,$host);
  $q=$db->prepare('SELECT * FROM director_scene_presets WHERE session_id=? AND claim_id=? AND host_admin_id=? ORDER BY name,id LIMIT 16');
  $q->execute([$session,$claim,$host]);return array_map([self::class,'output'],$q->fetchAll(PDO::FETCH_ASSOC));
 }
 public static function save(PDO $db,string $session,string $claim,int $host,array $data):array {
  self::check($db,$session,$claim,$host);
  $name=self::clean((string)($data['name']??''),60,'scene name');
  $source=(string)($data['source']??'');
  if(!preg_match('/^(camera|phone):[A-Za-z0-9_-]{1,64}$|^clip:[a-f0-9]{32}$/D',$source))throw new DomainException('Invalid scene source.',422);
  if(str_starts_with($source,'clip:')){
   $q=$db->prepare("SELECT 1 FROM claim_media WHERE id=? AND session_id=? AND claim_id=? AND kind='video'");
   $q->execute([substr($source,5),$session,$claim]);
   if(!$q->fetchColumn())throw new DomainException('Clip is not in this proof.',403);
  }
  $transition=(string)($data['transition']??'cut');
  if(!in_array($transition,self::TRANSITIONS,true))throw new DomainException('Invalid scene transition.',422);
  $title=self::clean((string)($data['title']??''),100,'scene title',true);
  $sticker=(string)($data['sticker']??'');
  if(!in_array($sticker,self::STICKERS,true))throw new DomainException('Invalid sticker.',422);
  $db->beginTransaction();
  try{
   $lock=$db->prepare('SELECT id FROM game_sessions WHERE id=? FOR UPDATE');$lock->execute([$session]);
   if(!$lock->fetchColumn())throw new DomainException('Game session not found.',404);
   $q=$db->prepare('SELECT id FROM director_scene_presets WHERE session_id=? AND claim_id=? AND host_admin_id=? AND name=?');
   $q->execute([$session,$claim,$host,$name]);$id=$q->fetchColumn();
   if($id){
    $db->prepare('UPDATE director_scene_presets SET source_id=?,transition_kind=?,overlay_text=?,overlay_sticker=?,updated_at=UTC_TIMESTAMP() WHERE id=? AND host_admin_id=?')
     ->execute([$source,$transition,$title,$sticker,$id,$host]);
   }else{
    $q=$db->prepare('SELECT COUNT(*) FROM director_scene_presets WHERE session_id=? AND claim_id=? AND host_admin_id=?');$q->execute([$session,$claim,$host]);
    if((int)$q->fetchColumn()>=self::LIMIT)throw new DomainException('Maximum 16 scenes per proof.',409);
    $id=bin2hex(random_bytes(16));
    $db->prepare('INSERT INTO director_scene_presets(id,session_id,claim_id,host_admin_id,name,source_id,transition_kind,overlay_text,overlay_sticker) VALUES (?,?,?,?,?,?,?,?,?)')
     ->execute([$id,$session,$claim,$host,$name,$source,$transition,$title,$sticker]);
   }
   $db->commit();
  }catch(Throwable $e){$db->rollBack();throw $e;}
  return ['id'=>$id,'name'=>$name,'source'=>$source,'transition'=>$transition,'title'=>$title,'sticker'=>$sticker];
 }
 public static function remove(PDO $db,string $session,string $claim,int $host,string $id):void {
  self::check($db,$session,$claim,$host);self::id($id);
  $q=$db->prepare('DELETE FROM director_scene_presets WHERE id=? AND session_id=? AND claim_id=? AND host_admin_id=?');
  $q->execute([$id,$session,$claim,$host]);
  if($q->rowCount()!==1)throw new DomainException('Scene not found in this proof.',404);
 }
}
