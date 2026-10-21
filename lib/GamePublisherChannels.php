<?php
declare(strict_types=1);
require_once __DIR__.'/CloudGameUpdates.php';
require_once __DIR__.'/GameCatalog.php';

/**
 * Per-game publisher trust anchors; deliberately separate from the legacy
 * global feed. No network access on boot, session reads or registry discovery.
 * Each protected channel is bound to exactly one game slug.
 */
final class GamePublisherChannels {
 public const SDK_VERSION='1.0.0';
 private static function root(?string $root=null):string {
  return ($root??dirname(__DIR__).'/storage/game-updates').'/channels';
 }
 private static function path(string $id,?string $root=null):string {
  return self::root($root).'/'.GameCatalog::slug($id).'.json';
 }
 private static function ensure(string $dir):void {
  if(is_link($dir))throw new DomainException('Unsafe publisher channel directory.',403);
  if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))
   throw new RuntimeException('Unable to prepare publisher channel directory.');
  if(is_link($dir))throw new DomainException('Unsafe publisher channel directory.',403);
 }
 private static function token(mixed $value):string {
  if(!is_string($value)||strlen($value)>1024||preg_match('/[\r\n\x00-\x1f\x7f]/',$value))
   throw new DomainException('Invalid publisher channel access token.',422);
  return $value;
 }
 private static function label(mixed $name):string {
  if(!is_string($name)||strlen($name)<1||strlen($name)>160||
     trim($name)===''||preg_match('/[\x00-\x1f\x7f]/',$name))
   throw new DomainException('Enter a valid publisher name.',422);
  return trim($name);
 }
 private static function publicKey(mixed $key):string {
  if(!is_string($key)||strlen($key)>8192||!str_contains($key,'-----BEGIN PUBLIC KEY-----'))
   throw new DomainException('Enter a publisher public signing key.',422);
  $resource=openssl_pkey_get_public($key);
  if($resource===false)throw new DomainException('Invalid publisher verification key.',422);
  $details=openssl_pkey_get_details($resource);
  if(!$details||!(($details['type']===OPENSSL_KEYTYPE_RSA&&$details['bits']>=2048)||
    ($details['type']===OPENSSL_KEYTYPE_EC&&$details['bits']>=256)))
   throw new DomainException('Publisher keys must be RSA 2048+ or EC 256+.',422);
  return $details['key'];
 }
 public static function config(string $game,?string $root=null):?array {
  $path=self::path($game,$root);
  if(is_link($path))throw new DomainException('Unsafe publisher trust file.',403);
  if(!is_file($path))return null;
  $data=json_decode((string)file_get_contents($path),true,12,JSON_THROW_ON_ERROR);
  if(!is_array($data)||($data['schema']??null)!==1||
     ($data['game']??null)!==$game)throw new DomainException('Damaged publisher configuration.',409);
  self::label($data['publisher']??null);
  CloudGameUpdates::endpoint($data['url']??null);
  self::publicKey($data['publicKey']??null);
  self::token($data['token']??null);
  return $data;
 }
 private static function exposed(array $data):array {
  return ['game'=>$data['game'],'publisher'=>$data['publisher'],'url'=>$data['url'],
   'keyFingerprint'=>hash('sha256',$data['publicKey']),'connected'=>true,
   'automaticChecks'=>false,'automaticInstall'=>false];
 }
 public static function summary(?string $root=null):array {
  $dir=self::root($root);if(is_link($dir))throw new DomainException('Unsafe channels directory.',403);
  if(!is_dir($dir))return [];
  $rows=[];
  foreach(new DirectoryIterator($dir) as $item){
   if(!$item->isFile()||$item->isLink()||!preg_match('/^[a-z0-9][a-z0-9-]{1,62}\.json$/D',$item->getFilename()))continue;
   $game=substr($item->getFilename(),0,-5);
   try{$row=self::config($game,$root);if($row)$rows[]=self::exposed($row);}
   catch(Throwable $e){error_log('Publisher connection is damaged for '.$game.': '.get_class($e));$rows[]=['game'=>$game,'connected'=>false,'error'=>'invalid_configuration'];}
  }
  usort($rows,fn($a,$b)=>strcmp($a['game'],$b['game']));
  return $rows;
 }
 /** Explicitly trust a game publisher. No automatic trust/key rotation. */
 public static function connect(array $data,?string $root=null):array {
  $game=GameCatalog::slug($data['game']??null);
  $publisher=self::label($data['publisher']??null);
  $url=CloudGameUpdates::endpoint($data['url']??null);
  $key=self::publicKey($data['publicKey']??null);
  $token=self::token($data['token']??'');
  $dir=self::root($root);self::ensure($dir);
  $lock=fopen($dir.'/.lock','c');
  if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Could not lock publisher trust settings.');
  try{
   $prior=self::config($game,$root);
   if($prior&&($prior['url']!==$url||$prior['publicKey']!==$key||$prior['publisher']!==$publisher)&&
       ($data['confirmReplace']??null)!==true)
    throw new DomainException('Explicitly confirm the publisher feed or signing key change.',409);
   $current=['schema'=>1,'game'=>$game,'publisher'=>$publisher,'url'=>$url,
    'publicKey'=>$key,'token'=>$token];
   $tmp=$dir.'/.channel-'.bin2hex(random_bytes(12));
   try{
    if(file_put_contents($tmp,json_encode($current,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),LOCK_EX)===false)
     throw new RuntimeException('Unable to save publisher connection.');
    @chmod($tmp,0600);
    if(!rename($tmp,self::path($game,$root)))throw new RuntimeException('Unable to publish publisher trust settings.');
   }finally{@unlink($tmp);}
   return self::exposed($current);
  }finally{flock($lock,LOCK_UN);fclose($lock);}
 }
 public static function disconnect(string $game,?string $root=null):array {
  $game=GameCatalog::slug($game);
  $dir=self::root($root);
  if(!is_dir($dir))return ['game'=>$game,'connected'=>false];
  if(is_link($dir))throw new DomainException('Unsafe publisher channel directory.',403);
  $lock=fopen($dir.'/.lock','c');
  if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Could not lock publisher trust settings.');
  try{
   $path=self::path($game,$root);
   if(is_link($path))throw new DomainException('Unsafe publisher trust file.',403);
   if(is_file($path)&&!unlink($path))throw new RuntimeException('Unable to disconnect publisher.');
   return ['game'=>$game,'connected'=>false];
  }finally{flock($lock,LOCK_UN);fclose($lock);}
 }
 /**
  * Check a signed feed only after explicit admin request. All cross-game
  * release advertisements are excluded, even when correctly signed.
  */
 public static function verifiedForGame(array $feed,string $game):array {
  $game=GameCatalog::slug($game);
  if(($feed['verified']??false)!==true)throw new DomainException('Publisher feed is not verified.',403);
  $releases=[];
  foreach(($feed['releases']??[]) as $release){
   if(($release['game']??null)!==$game)continue;
   self::assertCompatible($release);
   $releases[]=$release;
  }
  return ['game'=>$game,'verified'=>true,'sdkVersion'=>self::SDK_VERSION,
   'releases'=>$releases,'automaticInstall'=>false];
 }
 public static function assertCompatible(array $release):void {
  $min=$release['sdkMin']??'0.0.0';
  if(!is_string($min)||!preg_match('/^(0|[1-9][0-9]{0,4})\.(0|[1-9][0-9]{0,4})\.(0|[1-9][0-9]{0,4})$/D',$min))
   throw new DomainException('Invalid publisher SDK compatibility declaration.',422);
  if(version_compare($min,self::SDK_VERSION,'>'))
   throw new DomainException('This game requires a newer HomeServer game SDK.',409);
 }
 public static function check(string $game,?string $root=null):array {
  $game=GameCatalog::slug($game);
  $cfg=self::config($game,$root);
  if(!$cfg)throw new DomainException('Configure the publisher for this game before checking updates.',409);
  require_once __DIR__.'/PublisherTransport.php';
  $raw=PublisherTransport::fetch($cfg['url'],$cfg['token'],524288);
  $feed=CloudGameUpdates::verifyEnvelope($raw,$cfg['publicKey'],$cfg['url']);
  return self::verifiedForGame($feed,$game);
 }
}
