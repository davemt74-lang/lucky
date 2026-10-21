<?php
declare(strict_types=1);
require_once __DIR__.'/GamePackageActivation.php';

/**
 * HomeServer-owned capabilities for static game/plugin packages.
 * No package may execute PHP or submit SQL, and every method is both
 * package-declared and authorized by the existing owner session.
 */
final class GamePluginCapabilities {
 public const SUPPORTED=[
  'storage.read','storage.write','players.read','players.write',
  'scores.read','scores.write','rounds.read','rounds.write',
  'turns.read','turns.write','timers.read','timers.write',
  'teams.read','teams.write','achievements.read','achievements.write',
  'events.read','events.write','session.write'
 ];
 private const OPERATIONS=[
  'storage.get'=>'storage.read','storage.set'=>'storage.write',
  'players.list'=>'players.read','players.add'=>'players.write',
  'scores.list'=>'scores.read','scores.add'=>'scores.write',
  'rounds.get'=>'rounds.read','rounds.next'=>'rounds.write',
  'turns.get'=>'turns.read','turns.set'=>'turns.write',
  'timers.get'=>'timers.read','timers.start'=>'timers.write','timers.stop'=>'timers.write',
  'teams.list'=>'teams.read','teams.add'=>'teams.write','teams.assign'=>'teams.write',
  'achievements.list'=>'achievements.read','achievements.unlock'=>'achievements.write',
  'events.list'=>'events.read','events.emit'=>'events.write',
  'storage.delete'=>'storage.write','session.save'=>'session.write'
 ];
 public static function validateDeclarations(mixed $value):array {
  if(!is_array($value)||!array_is_list($value)||count($value)>count(self::SUPPORTED))
   throw new DomainException('Game capabilities must be a short list.',422);
  $set=[];
  foreach($value as $cap) {
   if(!is_string($cap)||!in_array($cap,self::SUPPORTED,true)||isset($set[$cap]))
    throw new DomainException('Unsupported or duplicate game capability.',422);
   $set[$cap]=true;
  }
  return array_keys($set);
 }
 public static function available(string $game,string $version,?string $root=null):array {
  $receipt=GamePackageActivation::receipt($game,$version,$root);
  return self::validateDeclarations($receipt['capabilities']??[]);
 }
 public static function authorize(string $game,string $version,string $operation,?string $root=null):void {
  $required=self::OPERATIONS[$operation]??null;
  if(!$required)throw new DomainException('Unsupported SDK operation.',400);
  if(!in_array($required,self::available($game,$version,$root),true))
   throw new DomainException('This game package was not granted the requested capability.',403);
 }
 public static function changesState(string $operation):bool {
  return str_ends_with($operation,'.set')||str_ends_with($operation,'.add')||
   str_ends_with($operation,'.next')||str_ends_with($operation,'.start')||
   str_ends_with($operation,'.stop')||str_ends_with($operation,'.assign')||
   str_ends_with($operation,'.unlock')||str_ends_with($operation,'.emit')||
   str_ends_with($operation,'.delete');
 }
 public static function validateArgs(string $operation,mixed $value):array {
  if(!isset(self::OPERATIONS[$operation]))throw new DomainException('Unsupported SDK operation.',400);
  if(!is_array($value)||array_is_list($value)&&$value!==[]||count($value)>8)
   throw new DomainException('SDK arguments must be an object.',422);
  $json=json_encode($value,JSON_THROW_ON_ERROR);
  if(strlen($json)>8192)throw new DomainException('SDK request exceeds 8 KB.',413);
  return $value;
 }
 private static function slug(mixed $value,string $name):string {
  if(!is_string($value)||!preg_match('/^[a-z][a-z0-9_-]{0,39}$/D',$value))
   throw new DomainException('Invalid game '.$name.'.',422);
  return $value;
 }
 private static function label(mixed $value):string {
  if(!is_string($value)||trim($value)===''||strlen($value)>48||
     preg_match('/[\x00-\x1f\x7f]/',$value))
   throw new DomainException('Invalid player name.',422);
  return trim($value);
 }
 private static function plugin(array &$state):array {
  if(!isset($state['plugin']))$state['plugin']=[
   'players'=>[],'scores'=>[],'round'=>0,'turn'=>null,'timers'=>[],
   'teams'=>[],'achievements'=>[],'events'=>[]
  ];
  return $state['plugin'];
 }
 /** Called before starting a DB transaction; DDL never runs during gameplay. */
 public static function ensureSchema(PDO $db):void {
  $db->exec("CREATE TABLE IF NOT EXISTS game_plugin_storage (
   game_id VARCHAR(64) NOT NULL,
   scope_id CHAR(32) NOT NULL,
   storage_key VARCHAR(64) NOT NULL,
   value_json LONGTEXT NOT NULL,
   revision INT UNSIGNED NOT NULL DEFAULT 1,
   updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
   PRIMARY KEY (game_id,scope_id,storage_key),
   INDEX idx_plugin_storage_game (game_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
 }
 private static function storage(PDO $db,string $game,string $session,string $operation,array $args):array {
  $key=self::slug($args['key']??null,'storage key');
  $scope=$args['scope']??'game';
  if(!in_array($scope,['game','session'],true))throw new DomainException('Invalid storage scope.',422);
  $id=$scope==='game'?str_repeat('0',32):$session;
  $query=$db->prepare('SELECT value_json,revision FROM game_plugin_storage WHERE game_id=? AND scope_id=? AND storage_key=? FOR UPDATE');
  $query->execute([$game,$id,$key]);$found=$query->fetch(PDO::FETCH_ASSOC);
  if($operation==='storage.get')
   return ['key'=>$key,'scope'=>$scope,'value'=>$found?json_decode($found['value_json'],true,64,JSON_THROW_ON_ERROR):null,
    'revision'=>$found?(int)$found['revision']:0];
  if($operation==='storage.delete'){
   $expected=$args['expectedRevision']??null;$current=$found?(int)$found['revision']:0;
   if(!is_int($expected)||$expected!==$current)throw new DomainException('Game storage changed.',409);
   if($found){$del=$db->prepare('DELETE FROM game_plugin_storage WHERE game_id=? AND scope_id=? AND storage_key=?');
     $del->execute([$game,$id,$key]);}
   return ['key'=>$key,'scope'=>$scope,'deleted'=>$found!==false];
  }
  if(!array_key_exists('value',$args))throw new DomainException('Storage value is required.',422);
  $raw=json_encode($args['value'],JSON_THROW_ON_ERROR);
  if(strlen($raw)>8192)throw new DomainException('Game storage values must be 8 KB or less.',413);
  $expected=$args['expectedRevision']??null;
  if(!is_int($expected)||$expected<0||$expected>4294967294)
   throw new DomainException('Storage writes require a revision.',409);
  $current=$found?(int)$found['revision']:0;
  if($current!==$expected)throw new DomainException('Game storage changed. Refresh before writing.',409);
  if($current===0){
   $q=$db->prepare('SELECT COUNT(*) FROM game_plugin_storage WHERE game_id=? AND scope_id=?');
   $q->execute([$game,$id]);
   if((int)$q->fetchColumn()>=128)throw new DomainException('Game storage has reached the 128-key limit.',413);
   $query=$db->prepare('INSERT INTO game_plugin_storage(game_id,scope_id,storage_key,value_json,revision) VALUES (?,?,?,?,1)');
   $query->execute([$game,$id,$key,$raw]);
  } else {
   $query=$db->prepare('UPDATE game_plugin_storage SET value_json=?,revision=revision+1 WHERE game_id=? AND scope_id=? AND storage_key=?');
   $query->execute([$raw,$game,$id,$key]);
  }
  return ['key'=>$key,'scope'=>$scope,'value'=>$args['value'],'revision'=>$current+1];
 }
 /** Call only while the caller holds a game_settings lock and session row lock. */
 public static function execute(PDO $db,array &$state,string $operation,array $args):array {
  $args=self::validateArgs($operation,$args);
  $game=(string)($state['game']??'');$session=(string)($state['id']??'');
  if(!preg_match('/^[a-f0-9]{32}$/D',$session))throw new DomainException('Invalid SDK session.',400);
  self::authorize($game,(string)($state['packageVersion']??''),$operation);
  if(!in_array($state['status']??'',['active','paused'],true))
   throw new DomainException('This game session is not running.',409);
  $isWrite=self::changesState($operation);
  if($isWrite&&$state['status']!=='active')throw new DomainException('SDK changes require an active game.',409);
  if(str_starts_with($operation,'storage.'))$result=self::storage($db,$game,$session,$operation,$args);
  else{
   $p=self::plugin($state);
   foreach(['teams','achievements','events'] as $field)if(!isset($p[$field]))$p[$field]=[];
   switch($operation){
    case 'players.list':$result=$p['players'];break;
    case 'players.add':
     $id=self::slug($args['id']??null,'player ID');
     $name=self::label($args['name']??null);
     if(isset($p['players'][$id]))throw new DomainException('Player already exists.',409);
     if(count($p['players'])>=16)throw new DomainException('Player limit reached.',413);
     $p['players'][$id]=['id'=>$id,'name'=>$name];$p['scores'][$id]=0;
     $result=$p['players'][$id];break;
    case 'teams.list':$result=$p['teams'];break;
    case 'teams.add':
     $id=self::slug($args['id']??null,'team ID');$name=self::label($args['name']??null);
     if(isset($p['teams'][$id]))throw new DomainException('Team already exists.',409);
     if(count($p['teams'])>=8)throw new DomainException('Team limit reached.',413);
     $p['teams'][$id]=['id'=>$id,'name'=>$name];$result=$p['teams'][$id];break;
    case 'teams.assign':
     $team=self::slug($args['teamId']??null,'team ID');
     $player=self::slug($args['playerId']??null,'player ID');
     if(!isset($p['teams'][$team])||!isset($p['players'][$player]))
      throw new DomainException('Unknown team or player.',404);
     $p['players'][$player]['teamId']=$team;
     $result=['teamId'=>$team,'playerId'=>$player];break;
    case 'achievements.list':$result=$p['achievements'];break;
    case 'achievements.unlock':
     $code=self::slug($args['id']??null,'achievement ID');
     if(isset($p['achievements'][$code]))throw new DomainException('Achievement already unlocked.',409);
     if(count($p['achievements'])>=64)throw new DomainException('Achievement limit reached.',413);
     $p['achievements'][$code]=['id'=>$code,'unlockedAt'=>gmdate('c')];
     $result=$p['achievements'][$code];break;
    case 'events.list':$result=$p['events'];break;
    case 'events.emit':
     $name=self::slug($args['name']??null,'event name');
     $value=$args['value']??null;
     if(!(is_bool($value)||is_int($value)||is_string($value)||$value===null)||
        is_string($value)&&strlen($value)>240)
      throw new DomainException('Event values must be small primitives.',422);
     $event=['name'=>$name,'value'=>$value,'at'=>gmdate('c')];
     $p['events'][]=$event;$p['events']=array_slice($p['events'],-32);
     $result=$event;break;
    case 'scores.list':$result=$p['scores'];break;
    case 'scores.add':
     $id=self::slug($args['playerId']??null,'player ID');
     $delta=$args['delta']??null;
     if(!isset($p['players'][$id]))throw new DomainException('Unknown game player.',404);
     if(!is_int($delta)||abs($delta)>10000)throw new DomainException('Score adjustment must be within 10,000 points.',422);
     $total=($p['scores'][$id]??0)+$delta;
     if(abs($total)>1000000000)throw new DomainException('Score limit reached.',413);
     $p['scores'][$id]=$total;$result=['playerId'=>$id,'score'=>$total];break;
    case 'rounds.get':$result=['round'=>$p['round']];break;
    case 'rounds.next':
     if($p['round']>=1000)throw new DomainException('Round limit reached.',413);
     $p['round']++;$p['turn']=null;
     $result=['round'=>$p['round']];break;
    case 'turns.get':$result=['playerId'=>$p['turn']];break;
    case 'turns.set':
     $id=self::slug($args['playerId']??null,'player ID');
     if(!isset($p['players'][$id]))throw new DomainException('Unknown game player.',404);
     $p['turn']=$id;$result=['playerId'=>$id];break;
    case 'timers.get':
     $name=self::slug($args['name']??null,'timer name');
     $time=$p['timers'][$name]??null;
     $result=['name'=>$name,'active'=>$time!==null,
      'remainingSeconds'=>$time===null?0:max(0,((int)$time['expiresAt'])-time())];break;
    case 'timers.start':
     $name=self::slug($args['name']??null,'timer name');
     $seconds=$args['seconds']??null;
     if(!is_int($seconds)||$seconds<1||$seconds>3600)
      throw new DomainException('Timer must be between 1 and 3600 seconds.',422);
     if(!isset($p['timers'][$name])&&count($p['timers'])>=16)
      throw new DomainException('Timer limit reached.',413);
     $p['timers'][$name]=['expiresAt'=>time()+$seconds];
     $result=['name'=>$name,'remainingSeconds'=>$seconds];break;
    case 'timers.stop':
     $name=self::slug($args['name']??null,'timer name');
     unset($p['timers'][$name]);$result=['name'=>$name,'active'=>false];break;
    default:throw new DomainException('Unsupported SDK operation.',400);
   }
   if($isWrite)$state['plugin']=$p;
  }
  if($isWrite)$state['version']++;
  return ['operation'=>$operation,'value'=>$result,'version'=>$state['version']];
 }
 public static function publicView(array $state):array {
  $p=$state['plugin']??null;
  if(!is_array($p))$p=['players'=>[],'scores'=>[],'round'=>0,'turn'=>null,'timers'=>[],
   'teams'=>[],'achievements'=>[],'events'=>[]];
  $timers=[];
  foreach($p['timers'] as $name=>$timer)
   $timers[$name]=max(0,((int)($timer['expiresAt']??0))-time());
  return ['players'=>$p['players'],'scores'=>$p['scores'],'round'=>$p['round'],
   'turn'=>$p['turn'],'timers'=>$timers,'teams'=>$p['teams']??[],
   'achievements'=>$p['achievements']??[],'events'=>$p['events']??[]];
 }
}
