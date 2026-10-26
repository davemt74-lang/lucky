<?php
declare(strict_types=1);

/** E4C: bounded production metadata and immutable per-session capture preferences. */
final class RecordingProfiles {
 public const DEFAULT_CAPTURE=[
  'multicameraEnabled'=>true,'maxCameras'=>4,'phoneCameraEnabled'=>true,
  'video'=>true,'photo'=>true,'audio'=>true,'countdownSeconds'=>3
 ];

 public static function capture(mixed $value):array {
  if(!is_array($value))throw new DomainException('Invalid recording capture settings.',422);
  $allowed=array_keys(self::DEFAULT_CAPTURE);
  foreach($value as $key=>$unused)if(!in_array($key,$allowed,true))throw new DomainException('Unsupported recording capture setting.',422);
  $out=array_replace(self::DEFAULT_CAPTURE,$value);
  foreach(['multicameraEnabled','phoneCameraEnabled','video','photo','audio'] as $key)
   if(!is_bool($out[$key]))throw new DomainException('Invalid recording permission: '.$key,422);
  if(!is_int($out['maxCameras'])||$out['maxCameras']<1||$out['maxCameras']>8)
   throw new DomainException('Choose between 1 and 8 cameras.',422);
  if(!is_int($out['countdownSeconds'])||$out['countdownSeconds']<0||$out['countdownSeconds']>30)
   throw new DomainException('Countdown must be between 0 and 30 seconds.',422);
  if(!$out['video']&&!$out['photo']&&!$out['audio'])
   throw new DomainException('At least one recording format must remain enabled.',422);
  if(!$out['multicameraEnabled'])$out['maxCameras']=1;
  return $out;
 }

 private static function short(mixed $value,int $max,string $label):string {
  if(!is_string($value)||strlen($value)>$max||preg_match('/[\x00-\x1f\x7f]/',$value))
   throw new DomainException('Invalid recording '.$label.'.',422);
  return trim($value);
 }
 public static function metadata(array $input):array {
  $tags=$input['tags']??[];$shots=$input['shotList']??[];
  if(!is_array($tags)||count($tags)>12||!is_array($shots)||count($shots)>16)
   throw new DomainException('Too many recording tags or shot-list items.',422);
  $tags=array_values(array_unique(array_map(fn($s)=>self::short($s,32,'tag'),$tags)));
  $shots=array_values(array_map(fn($s)=>self::short($s,160,'shot-list item'),$shots));
  if(in_array('',$tags,true)||in_array('',$shots,true))throw new DomainException('Empty tags and shot-list items are not allowed.',422);
  $date=self::short($input['eventDate']??'',10,'date');
  if($date!==''&&(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date)||!checkdate((int)substr($date,5,2),(int)substr($date,8,2),(int)substr($date,0,4))))
   throw new DomainException('Choose a valid event date.',422);
  return ['tags'=>$tags,'location'=>self::short($input['location']??'',120,'location'),
   'eventDate'=>$date,'brief'=>self::short($input['brief']??'',1200,'production brief'),'shotList'=>$shots];
 }
 public static function make(array $input,?string $gameId):array {
  $metadata=self::metadata($input);
  // A linked game always uses the authoritative saved policy; the client cannot override it.
  $capture=$gameId!==null?self::capture(GameCatalog::load(GameCatalog::slug($gameId))['recording']??[]):self::capture($input['capture']??[]);
  return $metadata+['capture'=>$capture];
 }
 public static function ready(PDO $db):bool {
  return (bool)$db->query("SHOW TABLES LIKE 'recording_session_profiles'")->fetchColumn();
 }
 public static function save(PDO $db,string $sessionId,?string $gameId,array $profile):void {
  if(!self::ready($db))return; // E1–E4B compatibility for independently run legacy tests.
  $q=$db->prepare('INSERT INTO recording_session_profiles(recording_session_id,game_id,profile_json) VALUES (?,?,?)');
  $q->execute([$sessionId,$gameId,json_encode($profile,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);
 }
 public static function get(PDO $db,string $sessionId):array {
  if(!self::ready($db))return self::metadata([])+['capture'=>self::DEFAULT_CAPTURE];
  $q=$db->prepare('SELECT profile_json FROM recording_session_profiles WHERE recording_session_id=?');$q->execute([$sessionId]);
  $json=$q->fetchColumn();
  if($json===false)return self::metadata([])+['capture'=>self::DEFAULT_CAPTURE];
  $saved=json_decode((string)$json,true,64,JSON_THROW_ON_ERROR);
  if(!is_array($saved))throw new RuntimeException('Corrupted recording profile.');
  return self::metadata($saved)+['capture'=>self::capture($saved['capture']??[])];
 }
 public static function kind(PDO $db,string $sessionId,string $kind):array {
  $capture=self::get($db,$sessionId)['capture'];
  if(!in_array($kind,['video','photo','audio'],true)||!$capture[$kind])
   throw new DomainException('This recording profile does not allow '.$kind.' capture.',403);
  return $capture;
 }
}
