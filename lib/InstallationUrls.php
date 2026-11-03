<?php
declare(strict_types=1);

/** Installation-owned addresses only; request Host/forwarding headers are not authority. */
final class InstallationUrls {
 public const NETWORK_FILE='homeserver-network.json';
 public const LEASE_SECONDS=30;

 public static function validLanOrigin(mixed $origin):bool {
  if(!is_string($origin)||!preg_match('/^https:\/\/([0-9.]+):8843$/D',$origin,$match))return false;
  $ip=$match[1];
  if(filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)===false)return false;
  $parts=array_map('intval',explode('.',$ip));
  return $parts[0]===10||($parts[0]===172&&$parts[1]>=16&&$parts[1]<=31)||
   ($parts[0]===192&&$parts[1]===168);
 }

 /** Portable, tightly scoped uploaded-media paths; not arbitrary relative links. */
 public static function validMediaPath(string $path,string $kind):bool {
  if(!in_array($kind,['prize_image','prize_video','track'],true)||strlen($path)>2048)return false;
  return preg_match('#^(?:/(?:[A-Za-z0-9_~-][A-Za-z0-9_.~-]*/)*)?api/index\\.php\\?action='.
   preg_quote($kind,'#').'&id=[a-f0-9]{32}$#D',$path)===1;
 }

 /** Missing/stale/invalid LAN state disables sharing, not local gameplay. */
 public static function metadata(array $config,string $storage,?int $now=null):array {
  $deployment=($config['deployment']??'cloud')==='homeserver'?'homeserver':'cloud';
  $out=['deployment'=>$deployment,'lanOrigin'=>'','leaseSeconds'=>0];
  if($deployment!=='homeserver')return $out;
  $path=$storage.'/'.self::NETWORK_FILE;
  clearstatcache(true,$path);
  if(is_link($path)||!is_file($path)||!is_readable($path))return $out;
  $handle=@fopen($path,'rb');
  if($handle===false)return $out;
  try{
   $stat=fstat($handle);$now??=time();
   if(!is_array($stat)||($stat['mode']&0170000)!==0100000||$stat['size']<1||$stat['size']>8192||
      $stat['mtime']<=$now-self::LEASE_SECONDS||$stat['mtime']>$now+5)return $out;
   $raw=stream_get_contents($handle,8193);
   if(!is_string($raw)||strlen($raw)>8192)return $out;
   try{$value=json_decode($raw,true,8,JSON_THROW_ON_ERROR);}
   catch(JsonException){return $out;}
   if(!is_array($value)||count($value)!==2||($value['version']??null)!==1||
      !self::validLanOrigin($value['origin']??null))return $out;
   $out['lanOrigin']=$value['origin'];
   $out['leaseSeconds']=min(self::LEASE_SECONDS,$stat['mtime']+self::LEASE_SECONDS-$now);
   return $out;
  }finally{fclose($handle);}
 }

 /** Only explicit application share routes may carry an invitation capability. */
 public static function share(string $relative,array $metadata):string {
  if(strlen($relative)>2048||!preg_match('/^(?:controller|display|package-controller|live-view)\.php(?:\?[A-Za-z0-9%&=_.~-]+)?$/D',$relative))
   throw new DomainException('Invalid installation sharing path.',422);
  if(($metadata['deployment']??'cloud')!=='homeserver')return $relative;
  $origin=$metadata['lanOrigin']??'';
  if(!self::validLanOrigin($origin))
   throw new DomainException('HomeServer phone sharing is unavailable. Connect this computer to your home network, start HomeServer, then reload this page.',409);
  return $origin.'/'.$relative;
 }

 public static function head(array $metadata):string {
  $deployment=($metadata['deployment']??'cloud')==='homeserver'?'homeserver':'cloud';
  $origin=$deployment==='homeserver'&&self::validLanOrigin($metadata['lanOrigin']??null)?$metadata['lanOrigin']:'';
  $lease=$origin!==''&&is_int($metadata['leaseSeconds']??null)?max(0,min(self::LEASE_SECONDS,$metadata['leaseSeconds'])):0;
  $escape=static fn(string $value):string=>htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
  return '<meta name="lucky-deployment" content="'.$escape($deployment).'">'.
   '<meta name="lucky-lan-origin" content="'.$escape($origin).'">'.
   '<meta name="lucky-lan-lease-seconds" content="'.$lease.'">'.
   '<script src="public/installation-urls.js?v=5q32g7a" defer></script>';
 }
}
