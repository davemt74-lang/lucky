<?php
declare(strict_types=1);

/** Universal media-production groups. Every method is scoped to the authenticated host. */
final class RecordingSessions {
 public const TYPES=['game','live_event','production','general'];
 public const SOURCE_KINDS=['desktop','phone','camera','upload'];
 private const TRANSITIONS=['draft'=>['active','archived'],'active'=>['completed'],'completed'=>['archived'],'archived'=>[]];

 public static function id(string $value):string {
  if(!preg_match('/^[a-f0-9]{32}$/D',$value))throw new DomainException('Invalid recording identifier.',422);
  return $value;
 }
 private static function nonce(string $value):string {
  if(!preg_match('/^[a-zA-Z0-9_-]{8,80}$/D',$value))throw new DomainException('A unique recording request token is required.',422);
  return $value;
 }
 private static function title(mixed $value,int $max=160):string {
  if(!is_string($value))throw new DomainException('Recording name is required.',422);
  $value=trim($value);
  if($value===''||(function_exists('mb_strlen')?mb_strlen($value,'UTF-8'):strlen($value))>$max||preg_match('/[\x00-\x1f\x7f]/',$value))throw new DomainException('Recording name is invalid.',422);
  return $value;
 }
 private static function description(mixed $value):string {
  if(!is_string($value))throw new DomainException('Invalid recording description.',422);
  $value=trim($value);
  if(strlen($value)>2000||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$value))throw new DomainException('Recording description is too long or contains control characters.',422);
  return $value;
 }
 private static function fingerprint(array $fields):string {return hash('sha256',json_encode($fields,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));}
 private static function newId():string {return bin2hex(random_bytes(16));}
 private static function owned(PDO $db,int $owner,string $id,bool $lock=false):array {
  self::id($id);$q=$db->prepare('SELECT * FROM recording_sessions WHERE id=? AND owner_admin_id=?'.($lock?' FOR UPDATE':''));$q->execute([$id,$owner]);
  $row=$q->fetch(PDO::FETCH_ASSOC);if(!$row)throw new DomainException('Recording session not found.',404);return $row;
 }
 private static function input(array $in):array {
  $type=(string)($in['type']??'');if(!in_array($type,self::TYPES,true))throw new DomainException('Invalid recording type.',422);
  $game=(string)($in['gameSessionId']??'');if($game!=='')self::id($game);
  if($type==='game'&&$game==='')throw new DomainException('Game recordings must reference a game session.',422);
  return [$type,self::title($in['title']??null),self::description($in['description']??''),$game];
 }
 public static function create(PDO $db,int $owner,array $in):array {
  if($owner<1)throw new DomainException('Administrator login required.',403);
  [$type,$title,$description,$game]=self::input($in);$nonce=self::nonce((string)($in['nonce']??''));
  if($game!==''){$q=$db->prepare('SELECT 1 FROM game_sessions WHERE id=?');$q->execute([$game]);if(!$q->fetchColumn())throw new DomainException('Game session not found.',404);}
  $hash=self::fingerprint([$type,$title,$description,$game]);$id=self::newId();
  try{
   $q=$db->prepare('INSERT INTO recording_sessions(id,owner_admin_id,recording_type,title,description,game_session_id,create_nonce,request_hash) VALUES (?,?,?,?,?,?,?,?)');
   $q->execute([$id,$owner,$type,$title,$description,$game!==''?$game:null,$nonce,$hash]);
  }catch(PDOException $e){
   if((string)$e->getCode()!=='23000')throw $e;
   $q=$db->prepare('SELECT id,request_hash FROM recording_sessions WHERE owner_admin_id=? AND create_nonce=?');$q->execute([$owner,$nonce]);$prior=$q->fetch(PDO::FETCH_ASSOC);
   if(!$prior)throw $e;
   if(!hash_equals((string)$prior['request_hash'],$hash))throw new DomainException('This request token was already used for different recording details.',409);
   $id=(string)$prior['id'];
  }
  return self::get($db,$owner,$id);
 }
 public static function list(PDO $db,int $owner):array {
  if($owner<1)throw new DomainException('Administrator login required.',403);
  $q=$db->prepare('SELECT id,recording_type,title,description,game_session_id,status,revision,started_at,ended_at,created_at,updated_at FROM recording_sessions WHERE owner_admin_id=? ORDER BY created_at DESC,id DESC LIMIT 100');$q->execute([$owner]);
  return array_map([self::class,'publicSession'],$q->fetchAll(PDO::FETCH_ASSOC));
 }
 private static function publicSession(array $row):array {
  return ['id'=>$row['id'],'type'=>$row['recording_type'],'title'=>$row['title'],'description'=>$row['description'],
   'gameSessionId'=>$row['game_session_id'],'status'=>$row['status'],'revision'=>(int)$row['revision'],
   'startedAt'=>$row['started_at'],'endedAt'=>$row['ended_at'],'createdAt'=>$row['created_at'],'updatedAt'=>$row['updated_at']];
 }
 public static function get(PDO $db,int $owner,string $id):array {return self::publicSession(self::owned($db,$owner,$id));}
 public static function detail(PDO $db,int $owner,string $id):array {
  $session=self::get($db,$owner,$id);
  $q=$db->prepare('SELECT id,take_index,title,status,started_at,ended_at FROM recording_takes WHERE recording_session_id=? ORDER BY take_index');$q->execute([$id]);$takes=$q->fetchAll(PDO::FETCH_ASSOC);
  $q=$db->prepare('SELECT id,source_key,source_kind,display_name FROM recording_sources WHERE recording_session_id=? ORDER BY created_at,id');$q->execute([$id]);$sources=$q->fetchAll(PDO::FETCH_ASSOC);
  $q=$db->prepare('SELECT id,take_id,source_id,claim_media_id,kind,start_offset_ms,sync_confidence FROM recording_assets WHERE recording_session_id=? ORDER BY created_at,id');$q->execute([$id]);$assets=$q->fetchAll(PDO::FETCH_ASSOC);
  return ['session'=>$session,'takes'=>$takes,'sources'=>$sources,'assets'=>$assets];
 }
 public static function setStatus(PDO $db,int $owner,string $id,string $next,int $revision):array {
  if($revision<1)throw new DomainException('Expected recording revision required.',422);
  $db->beginTransaction();try{
   $row=self::owned($db,$owner,$id,true);
   if((int)$row['revision']!==$revision)throw new DomainException('Recording changed. Reload before updating.',409);
   if(!in_array($next,self::TRANSITIONS[$row['status']]??[],true))throw new DomainException('Invalid recording status transition.',409);
   if($next==='completed'){
    $q=$db->prepare("SELECT 1 FROM recording_takes WHERE recording_session_id=? AND status='recording' LIMIT 1");$q->execute([$id]);
    if($q->fetchColumn())throw new DomainException('Stop all active takes before completing a session.',409);
   }
   $q=$db->prepare('UPDATE recording_sessions SET status=?,revision=revision+1,started_at=IF(?=\'active\',COALESCE(started_at,UTC_TIMESTAMP(3)),started_at),ended_at=IF(?=\'completed\',UTC_TIMESTAMP(3),ended_at) WHERE id=? AND owner_admin_id=? AND revision=?');
   $q->execute([$next,$next,$next,$id,$owner,$revision]);
   if($q->rowCount()!==1)throw new DomainException('Recording changed. Reload before updating.',409);
   $db->commit();return self::get($db,$owner,$id);
  }catch(Throwable $e){$db->rollBack();throw $e;}
 }
 public static function startTake(PDO $db,int $owner,string $id,array $in):array {
  $name=self::title($in['title']??'Take',120);$nonce=self::nonce((string)($in['nonce']??''));$hash=self::fingerprint([$name]);
  $db->beginTransaction();try{
   $session=self::owned($db,$owner,$id,true);
   $q=$db->prepare('SELECT * FROM recording_takes WHERE recording_session_id=? AND client_nonce=?');$q->execute([$id,$nonce]);$prior=$q->fetch(PDO::FETCH_ASSOC);
   if($prior){if(!hash_equals((string)$prior['request_hash'],$hash))throw new DomainException('Take request token already used for another take.',409);$db->commit();return self::publicTake($prior);}
   if(!in_array($session['status'],['draft','active'],true))throw new DomainException('This recording session is not open for new takes.',409);
   if($session['status']==='draft'){
    $q=$db->prepare("UPDATE recording_sessions SET status='active',revision=revision+1,started_at=UTC_TIMESTAMP(3) WHERE id=?");$q->execute([$id]);
   }
   $q=$db->prepare('SELECT COALESCE(MAX(take_index),0)+1 FROM recording_takes WHERE recording_session_id=?');$q->execute([$id]);$index=(int)$q->fetchColumn();
   $takeId=self::newId();$q=$db->prepare('INSERT INTO recording_takes(id,recording_session_id,take_index,title,client_nonce,request_hash) VALUES (?,?,?,?,?,?)');$q->execute([$takeId,$id,$index,$name,$nonce,$hash]);
   $db->commit();return ['id'=>$takeId,'index'=>$index,'title'=>$name,'status'=>'recording'];
  }catch(Throwable $e){$db->rollBack();throw $e;}
 }
 private static function publicTake(array $row):array {return ['id'=>$row['id'],'index'=>(int)$row['take_index'],'title'=>$row['title'],'status'=>$row['status']];}
 public static function finishTake(PDO $db,int $owner,string $sessionId,string $takeId):array {
  self::id($takeId);$db->beginTransaction();try{
   self::owned($db,$owner,$sessionId,true);
   $q=$db->prepare('SELECT * FROM recording_takes WHERE id=? AND recording_session_id=? FOR UPDATE');$q->execute([$takeId,$sessionId]);$row=$q->fetch(PDO::FETCH_ASSOC);
   if(!$row)throw new DomainException('Recording take not found.',404);
   if($row['status']==='recording'){$q=$db->prepare("UPDATE recording_takes SET status='completed',ended_at=UTC_TIMESTAMP(3) WHERE id=?");$q->execute([$takeId]);$row['status']='completed';}
   $db->commit();return self::publicTake($row);
  }catch(Throwable $e){$db->rollBack();throw $e;}
 }
 public static function source(PDO $db,int $owner,string $sessionId,array $in):array {
  $key=(string)($in['key']??'');if(!preg_match('/^[a-z0-9_-]{2,64}$/D',$key))throw new DomainException('Invalid camera source key.',422);
  $kind=(string)($in['kind']??'');if(!in_array($kind,self::SOURCE_KINDS,true))throw new DomainException('Invalid camera source kind.',422);
  $name=self::title($in['name']??null,120);self::owned($db,$owner,$sessionId);
  $id=self::newId();try{
   $q=$db->prepare('INSERT INTO recording_sources(id,recording_session_id,source_key,source_kind,display_name) VALUES (?,?,?,?,?)');$q->execute([$id,$sessionId,$key,$kind,$name]);
  }catch(PDOException $e){
   if((string)$e->getCode()!=='23000')throw $e;
   $q=$db->prepare('SELECT * FROM recording_sources WHERE recording_session_id=? AND source_key=?');$q->execute([$sessionId,$key]);$old=$q->fetch(PDO::FETCH_ASSOC);
   if(!$old)throw $e;
   if($old['source_kind']!==$kind||$old['display_name']!==$name)throw new DomainException('Camera source key belongs to a different device.',409);
   $id=$old['id'];
  }
  return ['id'=>$id,'key'=>$key,'kind'=>$kind,'name'=>$name];
 }
 public static function attachClaim(PDO $db,int $owner,string $sessionId,string $clipId,?string $takeId=null,?string $sourceId=null):array {
  self::id($clipId);if($takeId!==null)self::id($takeId);if($sourceId!==null)self::id($sourceId);
  $db->beginTransaction();try{
   $session=self::owned($db,$owner,$sessionId,true);
   if(!$session['game_session_id'])throw new DomainException('Only game-linked sessions may reference legacy prize media.',422);
   $q=$db->prepare('SELECT id,kind,session_id FROM claim_media WHERE id=? FOR UPDATE');$q->execute([$clipId]);$clip=$q->fetch(PDO::FETCH_ASSOC);
   if(!$clip||$clip['session_id']!==$session['game_session_id'])throw new DomainException('Clip is not part of this recording\'s linked game.',403);
   foreach([['id'=>$takeId,'table'=>'recording_takes'],['id'=>$sourceId,'table'=>'recording_sources']] as $link){
    if($link['id']===null)continue;
    $q=$db->prepare('SELECT 1 FROM '.$link['table'].' WHERE id=? AND recording_session_id=?');$q->execute([$link['id'],$sessionId]);
    if(!$q->fetchColumn())throw new DomainException('Camera or take belongs to another recording session.',403);
   }
   $q=$db->prepare('SELECT id,recording_session_id FROM recording_assets WHERE claim_media_id=?');$q->execute([$clipId]);$old=$q->fetch(PDO::FETCH_ASSOC);
   if($old){if($old['recording_session_id']!==$sessionId)throw new DomainException('Clip is already grouped under a different session.',409);$db->commit();return ['id'=>$old['id'],'existing'=>true];}
   $id=self::newId();$q=$db->prepare('INSERT INTO recording_assets(id,recording_session_id,take_id,source_id,claim_media_id,kind) VALUES (?,?,?,?,?,?)');$q->execute([$id,$sessionId,$takeId,$sourceId,$clipId,$clip['kind']]);
   $db->commit();return ['id'=>$id,'existing'=>false];
  }catch(Throwable $e){$db->rollBack();throw $e;}
 }
 /** Idempotent, non-destructive bridge from existing game proof media. */
 public static function importGame(PDO $db,int $owner,string $gameSessionId):array {
  self::id($gameSessionId);
  $q=$db->prepare('SELECT id FROM game_sessions WHERE id=?');$q->execute([$gameSessionId]);if(!$q->fetchColumn())throw new DomainException('Game session not found.',404);
  $recording=self::create($db,$owner,['type'=>'game','title'=>'Game footage · '.substr($gameSessionId,0,8),'description'=>'Imported from existing game proof media. Original clips and timelines remain unchanged.','gameSessionId'=>$gameSessionId,'nonce'=>'legacy-'.$gameSessionId]);
  $q=$db->prepare('SELECT id FROM claim_media WHERE session_id=? ORDER BY sort_order,created_at,id');$q->execute([$gameSessionId]);
  $added=0;foreach($q->fetchAll(PDO::FETCH_COLUMN) as $clipId){$linked=self::attachClaim($db,$owner,$recording['id'],(string)$clipId);if(!$linked['existing'])$added++;}
  return ['session'=>$recording,'added'=>$added];
 }
}
