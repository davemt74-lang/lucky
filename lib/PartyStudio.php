<?php
declare(strict_types=1);

/** Reusable party, theme and costume records. Game starts copy their data into an immutable roster. */
final class PartyStudio {
 private static function id(mixed $value,bool $optional=false):string {
  if($optional&&($value===null||$value===''))return '';
  if(!is_string($value)||!preg_match('/^[a-f0-9]{32}$/D',$value))throw new DomainException('Invalid library record ID.',422);
  return $value;
 }
 private static function label(mixed $value,int $max=120,bool $required=true):string {
  if(!is_string($value))throw new DomainException('Names and descriptions must be text.',422);
  $value=trim($value);
  $length=function_exists('mb_strlen')?mb_strlen($value,'UTF-8'):strlen($value);
  if(($required&&$value==='')||$length>$max||preg_match('/[\x00-\x1f\x7f]/',$value))
   throw new DomainException('A name or description is missing or invalid.',422);
  return $value;
 }
 private static function decode(string $json):array {
  $value=json_decode($json,true,512,JSON_THROW_ON_ERROR);
  return is_array($value)?$value:[];
 }
 public static function approvedPhone(PDO $db,string $id,int $adminId):void {
  if($id==='')return;
  self::id($id);
  $q=$db->prepare("SELECT 1 FROM controller_pairs WHERE id=? AND host_admin_id=? AND status='paired' AND paired_expires_at>UTC_TIMESTAMP()");
  $q->execute([$id,$adminId]);
  if(!$q->fetchColumn())throw new DomainException('That phone must be connected and approved by this host.',422);
 }
 private static function rows(PDO $db,string $table):array {
  $sql=match($table){
   'costumes'=>'SELECT id,name,description,items_json FROM lucky_costumes ORDER BY name,id',
   'themes'=>'SELECT id,name,description,accent,costume_ids_json FROM lucky_themes ORDER BY name,id',
   'parties'=>'SELECT id,name,description,theme_id,attendees_json FROM lucky_parties ORDER BY updated_at DESC,id DESC',
   default=>throw new DomainException('Unknown library section.',422)
  };
  $rows=$db->query($sql)->fetchAll();
  foreach($rows as &$row){
   foreach(['items_json'=>'items','costume_ids_json'=>'costumeIds','attendees_json'=>'attendees'] as $column=>$key)
    if(array_key_exists($column,$row)){$row[$key]=self::decode($row[$column]);unset($row[$column]);}
   if(array_key_exists('theme_id',$row)){$row['themeId']=$row['theme_id']??'';unset($row['theme_id']);}
  }
  return $rows;
 }
 public static function catalog(PDO $db):array {
  return ['costumes'=>self::rows($db,'costumes'),'themes'=>self::rows($db,'themes'),'parties'=>self::rows($db,'parties')];
 }
 private static function record(PDO $db,string $table,string $id):?array {
  foreach(self::rows($db,$table) as $row)if($row['id']===$id)return $row;
  return null;
 }
 private static function costumeItems(mixed $raw):array {
  if(!is_array($raw)||count($raw)>32)throw new DomainException('A costume supports up to 32 individual items.',422);
  $items=[];
  foreach($raw as $i=>$item){
   if(!is_array($item))throw new DomainException('Invalid costume item.',422);
   $items[]=[
    'id'=>is_string($item['id']??null)&&preg_match('/^[a-zA-Z0-9_-]{1,40}$/D',$item['id'])?$item['id']:'item-'.($i+1),
    'name'=>self::label($item['name']??null,100),
    'category'=>self::label($item['category']??'Accessory',50),
    'layer'=>$i+1,
    'notes'=>self::label($item['notes']??'',300,false)
   ];
  }
  return $items;
 }
 private static function attendees(mixed $raw):array {
  if(!is_array($raw)||count($raw)>100)throw new DomainException('A party supports up to 100 attendees.',422);
  $result=[];
  foreach($raw as $i=>$person){
   if(!is_array($person)||!in_array($person['role']??'', ['player','host','guest'],true))throw new DomainException('Invalid attendee role.',422);
   $result[]=['id'=>'attendee-'.($i+1),'name'=>self::label($person['name']??'',100,false),
    'role'=>$person['role'],'costumeId'=>self::id($person['costumeId']??'',true)];
  }
  return $result;
 }
 public static function save(PDO $db,string $section,array $input):array {
  $id=self::id($input['id']??'',true);if($id==='')$id=bin2hex(random_bytes(16));
  $name=self::label($input['name']??null);$description=self::label($input['description']??'',500,false);
  if($section==='costumes'){
   $items=self::costumeItems($input['items']??[]);
   $q=$db->prepare('INSERT INTO lucky_costumes(id,name,description,items_json) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),items_json=VALUES(items_json)');
   $q->execute([$id,$name,$description,json_encode($items,JSON_THROW_ON_ERROR)]);
  }elseif($section==='themes'){
   $accent=(string)($input['accent']??'#f6cb74');if(!preg_match('/^#[a-fA-F0-9]{6}$/D',$accent))throw new DomainException('Invalid theme color.',422);
   $ids=$input['costumeIds']??[];if(!is_array($ids)||count($ids)>100)throw new DomainException('Too many theme costumes.',422);
   $unique=[];foreach($ids as $costume){$key=self::id($costume);if(!self::record($db,'costumes',$key))throw new DomainException('A costume no longer exists.',422);$unique[$key]=true;}
   $q=$db->prepare('INSERT INTO lucky_themes(id,name,description,accent,costume_ids_json) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),accent=VALUES(accent),costume_ids_json=VALUES(costume_ids_json)');
   $q->execute([$id,$name,$description,$accent,json_encode(array_keys($unique),JSON_THROW_ON_ERROR)]);
  }elseif($section==='parties'){
   $themeId=self::id($input['themeId']??'',true);if($themeId!==''&&!self::record($db,'themes',$themeId))throw new DomainException('Selected theme does not exist.',422);
   $attendees=self::attendees($input['attendees']??[]);
   foreach($attendees as $person)if($person['costumeId']!==''&&!self::record($db,'costumes',$person['costumeId']))throw new DomainException('An attendee costume no longer exists.',422);
   $q=$db->prepare('INSERT INTO lucky_parties(id,name,description,theme_id,attendees_json) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),theme_id=VALUES(theme_id),attendees_json=VALUES(attendees_json)');
   $q->execute([$id,$name,$description,$themeId?:null,json_encode($attendees,JSON_THROW_ON_ERROR)]);
  }else throw new DomainException('Unknown library section.',422);
  return ['id'=>$id];
 }
 public static function delete(PDO $db,string $section,mixed $key):void {
  $id=self::id($key);
  $table=match($section){'costumes'=>'lucky_costumes','themes'=>'lucky_themes','parties'=>'lucky_parties',default=>throw new DomainException('Unknown library section.',422)};
  if($section==='costumes'){
   foreach(self::rows($db,'themes') as $theme)if(in_array($id,$theme['costumeIds'],true))throw new DomainException('Remove this costume from its themes first.',409);
   foreach(self::rows($db,'parties') as $party)foreach($party['attendees'] as $person)
    if(($person['costumeId']??'')===$id)throw new DomainException('Remove this costume from party attendees first.',409);
  }
  $q=$db->prepare('DELETE FROM '.$table.' WHERE id=?');$q->execute([$id]);
  if($q->rowCount()!==1)throw new DomainException('Record not found.',404);
 }
 /** All attached pieces are copied into the session; subsequent library edits cannot change past turns. */
 public static function roster(PDO $db,array $input):array {
  $partyId=self::id($input['partyId']??'',true);
  $party=$partyId!==''?self::record($db,'parties',$partyId):null;
  if($partyId!==''&&!$party)throw new DomainException('Selected party was not found.',422);
  $themeId=self::id($input['themeId']??($party['themeId']??''),true);
  $theme=$themeId!==''?self::record($db,'themes',$themeId):null;
  if($themeId!==''&&!$theme)throw new DomainException('Selected theme was not found.',422);
  $usedPhones=[];
  $make=function(string $role,int $limit)use($db,$input,$party,&$usedPhones):array {
   $source=$input[$role.'s']??null;
   if($source===null&&$party)$source=array_values(array_filter($party['attendees'],fn($p)=>$p['role']===$role));
   if($source===null||$source===[])$source=[['name'=>'','costumeId'=>'']];
   if(!is_array($source)||count($source)<1||count($source)>$limit)throw new DomainException('Invalid number of '.$role.'s.',422);
   $people=[];
   foreach(array_values($source) as $i=>$person){
    if(!is_array($person))throw new DomainException('Invalid participant.',422);
    $costumeId=self::id($person['costumeId']??'',true);
    $costume=$costumeId!==''?self::record($db,'costumes',$costumeId):null;
    if($costumeId!==''&&!$costume)throw new DomainException('Selected costume was not found.',422);
    $phone=$role==='player'?self::id($person['phonePairId']??'',true):'';
    if($phone!==''){
     self::approvedPhone($db,$phone,(int)($_SESSION['admin_id']??0));
     if(isset($usedPhones[$phone]))throw new DomainException('One phone cannot belong to two players.',422);
     $usedPhones[$phone]=true;
    }
    $name=self::label($person['name']??'',100,false);
    $people[]=['id'=>$role.'-'.($i+1),'name'=>$name!==''?$name:ucfirst($role).' '.($i+1),'role'=>$role,
     'costume'=>$costume?['id'=>$costume['id'],'name'=>$costume['name'],'items'=>$costume['items']]:null,
     'phonePairId'=>$phone];
   }
   return $people;
  };
  return ['party'=>$party?['id'=>$party['id'],'name'=>$party['name']]:null,
   'theme'=>$theme?['id'=>$theme['id'],'name'=>$theme['name'],'accent'=>$theme['accent'],'costumeIds'=>$theme['costumeIds']]:null,
   'players'=>$make('player',64),'hosts'=>$make('host',16),'turnIndex'=>0,'turnHistory'=>[]];
 }
 public static function defaultRoster():array {
  return ['party'=>null,'theme'=>null,'players'=>[['id'=>'player-1','name'=>'Player 1','role'=>'player','costume'=>null,'phonePairId'=>'']],
   'hosts'=>[['id'=>'host-1','name'=>'Host 1','role'=>'host','costume'=>null]],'turnIndex'=>0,'turnHistory'=>[]];
 }
 public static function assignPhone(PDO $db,array &$state,string $playerId,string $pairId,int $adminId):void {
  $pairId=self::id($pairId,true);self::approvedPhone($db,$pairId,$adminId);
  if(!isset($state['multiplayer']['players'])||!is_array($state['multiplayer']['players']))throw new DomainException('This session has no player roster.',409);
  $found=false;
  foreach($state['multiplayer']['players'] as &$person){
   if($person['id']===$playerId){$found=true;continue;}
   if($pairId!==''&&($person['phonePairId']??'')===$pairId)throw new DomainException('That phone already belongs to another player.',409);
  }unset($person);
  if(!$found)throw new DomainException('Player not found.',404);
  foreach($state['multiplayer']['players'] as &$person)
   if($person['id']===$playerId)$person['phonePairId']=$pairId;
  unset($person);
  $state['version']++;
 }
 /** A phone assigned to a player may be identified by name in live preview and saved proof clips. */
 public static function cameraOperator(PDO $db,string $sessionId,string $pairId):?array {
  $q=$db->prepare('SELECT state_json FROM game_sessions WHERE id=?');$q->execute([$sessionId]);
  $raw=$q->fetchColumn();if(!$raw)return null;
  $state=json_decode((string)$raw,true);if(!is_array($state))return null;
  foreach($state['multiplayer']['players']??[] as $player)
   if(($player['phonePairId']??'')!==''&&$player['phonePairId']===$pairId)
    return ['id'=>$player['id'],'name'=>$player['name'],'costume'=>$player['costume']??null];
  return null;
 }
}
