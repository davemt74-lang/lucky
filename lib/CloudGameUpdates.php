<?php
declare(strict_types=1);

/**
 * V5Q.22C: opt-in, read-only, publisher-signed game update discovery.
 *
 * The HomeServer does not contact a cloud host during boot/gameplay.
 * This module NEVER installs or executes downloaded code. A later verified
 * package installer consumes only separately approved packages.
 */
final class CloudGameUpdates {
 private const ROOT='game-updates';
 private const MAX_FEED=524288;
 private const MAX_RELEASES=100;
 private static function directory(?string $root=null):string {
  return $root??dirname(__DIR__).'/storage/'.self::ROOT;
 }
 public static function endpoint(mixed $value):string {
  if(!is_string($value)||strlen($value)<12||strlen($value)>2048||
      preg_match('/[\x00-\x20\x7f]/',$value))throw new DomainException('Invalid update feed URL.',422);
  $parts=parse_url($value);
  if(!is_array($parts)||strtolower((string)($parts['scheme']??''))!=='https'||
      empty($parts['host'])||isset($parts['user'])||isset($parts['pass'])||
      isset($parts['fragment'])||isset($parts['port'])&&$parts['port']!==443)
   throw new DomainException('Game updates require an HTTPS feed without credentials or redirects.',422);
  $host=strtolower((string)$parts['host']);
  if(!preg_match('/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9.-]*[a-z0-9])$/D',$host)||
     !str_contains($host,'.')||str_ends_with($host,'.local')||str_ends_with($host,'.internal')||
     filter_var($host,FILTER_VALIDATE_IP))
   throw new DomainException('Game update host must be a public DNS hostname.',422);
  return $value;
 }
 private static function verifyPublicKey(mixed $pem):string {
  if(!is_string($pem)||strlen($pem)>8192||!str_contains($pem,'-----BEGIN PUBLIC KEY-----'))
   throw new DomainException('Enter the publisher public signing key (PEM).',422);
  $key=openssl_pkey_get_public($pem);
  if($key===false)throw new DomainException('Invalid publisher signing key.',422);
  $info=openssl_pkey_get_details($key);
  $type=$info['type']??-1;$bits=$info['bits']??0;
  if(!(($type===OPENSSL_KEYTYPE_RSA&&$bits>=2048)||($type===OPENSSL_KEYTYPE_EC&&$bits>=256)))
   throw new DomainException('Signing key must be RSA 2048+ or EC 256+.',422);
  return (string)$info['key'];
 }
 private static function ensureDir(string $dir):void {
  if(is_link($dir))throw new RuntimeException('Update configuration directory is unsafe.');
  if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('Unable to create update configuration.');
  if(is_link($dir))throw new RuntimeException('Update configuration directory is unsafe.');
 }
 public static function settings(?string $root=null):?array {
  $dir=self::directory($root);$path=$dir.'/connection.json';
  if(!is_file($path))return null;
  if(is_link($path))throw new RuntimeException('Unsafe update configuration.');
  $settings=json_decode((string)file_get_contents($path),true,8,JSON_THROW_ON_ERROR);
  if(!is_array($settings)||($settings['schema']??null)!==1)throw new RuntimeException('Invalid update connection settings.');
  self::endpoint($settings['url']??null);
  self::verifyPublicKey($settings['publicKey']??null);
  return $settings;
 }
 public static function summary(?string $root=null):array {
  $cfg=self::settings($root);
  return ['connected'=>$cfg!==null,'url'=>$cfg['url']??null,
   'keyFingerprint'=>$cfg?hash('sha256',$cfg['publicKey']):null,'lastChecked'=>null,
   'automaticChecks'=>false,'automaticInstall'=>false];
 }
 public static function connect(array $data,?string $root=null):array {
  $url=self::endpoint($data['url']??null);
  $key=self::verifyPublicKey($data['publicKey']??null);
  $token=$data['token']??'';
  if(!is_string($token)||strlen($token)>1024||preg_match('/[\r\n\x00-\x1f\x7f]/',$token))
   throw new DomainException('Invalid update-channel access token.',422);
  $dir=self::directory($root);self::ensureDir($dir);
  $lock=fopen($dir.'/.lock','c');
  if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Unable to lock update settings.');
  try {
   $tmp=$dir.'/connection.'.bin2hex(random_bytes(12)).'.tmp';
   $bytes=json_encode(['schema'=>1,'url'=>$url,'publicKey'=>$key,'token'=>$token],JSON_THROW_ON_ERROR);
   if(file_put_contents($tmp,$bytes,LOCK_EX)===false)throw new RuntimeException('Unable to save cloud connection.');
   @chmod($tmp,0600);
   if(!rename($tmp,$dir.'/connection.json')){@unlink($tmp);throw new RuntimeException('Unable to enable cloud connection.');}
  }finally{flock($lock,LOCK_UN);fclose($lock);}
  return self::summary($root);
 }
 public static function disconnect(?string $root=null):array {
  $dir=self::directory($root);if(!is_dir($dir))return self::summary($root);
  self::ensureDir($dir);
  $lock=fopen($dir.'/.lock','c');
  if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Unable to lock update settings.');
  try{
   $path=$dir.'/connection.json';
   if(is_link($path))throw new RuntimeException('Unsafe update configuration.');
   if(is_file($path)&&!unlink($path))throw new RuntimeException('Unable to disconnect update channel.');
  }finally{flock($lock,LOCK_UN);fclose($lock);}
  return self::summary($root);
 }
 private static function hostname(string $url):string {
  return strtolower((string)parse_url(self::endpoint($url),PHP_URL_HOST));
 }
 public static function verifyEnvelope(string $raw,string $key,string $source):array {
  if(strlen($raw)>self::MAX_FEED)throw new DomainException('Game update feed is too large.',413);
  $feed=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
  if(!is_array($feed)||($feed['schema']??null)!=='lucky.game-update-feed.v1'||
     !is_string($feed['payload']??null)||!is_string($feed['signature']??null))
   throw new DomainException('Unknown game update feed format.',422);
  $payload=base64_decode($feed['payload'],true);$sig=base64_decode($feed['signature'],true);
  if($payload===false||$sig===false||strlen($payload)>400000||strlen($sig)>8192)
   throw new DomainException('Malformed signed game update feed.',422);
  $key=self::verifyPublicKey($key);
  if(openssl_verify($payload,$sig,$key,OPENSSL_ALGO_SHA256)!==1)
   throw new DomainException('Publisher signature verification failed; updates were rejected.',403);
  $data=json_decode($payload,true,16,JSON_THROW_ON_ERROR);
  if(!is_array($data)||!is_array($data['releases']??null)||count($data['releases'])>self::MAX_RELEASES)
   throw new DomainException('Invalid update release list.',422);
  $issued=self::date($data['issuedAt']??null);$expires=self::date($data['expiresAt']??null);
  $now=time();
  if($issued>$now+600||$issued<$now-31*86400||$expires<=$now||$expires>$issued+31*86400)
   throw new DomainException('Game update feed has expired or is not current.',422);
  $origin=self::hostname($source);$seen=[];$releases=[];
  foreach($data['releases'] as $r) {
   if(!is_array($r)||!is_string($r['game']??null)||
      !preg_match('/^[a-z0-9][a-z0-9-]{1,62}$/D',$r['game'])||
      !is_string($r['version']??null)||
      !preg_match('/^(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)$/D',$r['version'])||
      !is_string($r['sha256']??null)||!preg_match('/^[a-f0-9]{64}$/D',$r['sha256'])||
      !is_int($r['bytes']??null)||$r['bytes']<1||$r['bytes']>104857600||
      !is_string($r['url']??null)||self::hostname($r['url'])!==$origin)
    throw new DomainException('Invalid or cross-origin update package.',422);
   $keyPair=$r['game'].':'.$r['version'];
   if(isset($seen[$keyPair]))throw new DomainException('Duplicate game update release.',422);
   $seen[$keyPair]=true;
   $releases[]=['game'=>$r['game'],'version'=>$r['version'],'sha256'=>$r['sha256'],
    'bytes'=>$r['bytes'],'url'=>$r['url'],'notes'=>self::releaseNotes($r['notes']??'')];
  }
  return ['issuedAt'=>$data['issuedAt'],'expiresAt'=>$data['expiresAt'],'releases'=>$releases,
   'verified'=>true,'installSupported'=>false];
 }
 private static function date(mixed $value):int {
  if(!is_string($value)||!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D',$value))
   throw new DomainException('Update feed timestamp must be ISO UTC.',422);
  $date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z',$value,new DateTimeZone('UTC'));
  if(!$date||$date->format('Y-m-d\TH:i:s\Z')!==$value)
   throw new DomainException('Invalid feed timestamp.',422);
  return $date->getTimestamp();
 }
 private static function releaseNotes(mixed $notes):string {
  if(!is_string($notes)||strlen($notes)>1800||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$notes))
   throw new DomainException('Invalid release notes.',422);
  return $notes;
 }
 /** Network calls only from a deliberate authenticated admin POST. */
 public static function check(?string $root=null):array {
  $cfg=self::settings($root);
  if(!$cfg)throw new DomainException('Connect a trusted update channel first.',409);
  $host=self::hostname($cfg['url']);
  // Refuse hosts resolving to LAN, loopback, link-local or reserved addresses.
  $records=@dns_get_record($host,DNS_A|DNS_AAAA);
  if(!is_array($records)||!$records)throw new DomainException('Update feed host did not resolve.',502);
  foreach($records as $r) {
   $ip=$r['ip']??$r['ipv6']??'';
   if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))
    throw new DomainException('Game update feed resolved to a private or unsafe network address.',403);
  }
  $headers="Accept: application/json\r\nConnection: close\r\n";
  if(($cfg['token']??'')!=='')$headers.="Authorization: Bearer ".$cfg['token']."\r\n";
  $context=stream_context_create([
   'http'=>['method'=>'GET','timeout'=>8,'follow_location'=>0,'max_redirects'=>0,'ignore_errors'=>true,'header'=>$headers],
   'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false]
  ]);
  $bytes=@file_get_contents($cfg['url'],false,$context,0,self::MAX_FEED+1);
  if(!is_string($bytes))throw new DomainException('Unable to reach the configured game update feed.',502);
  $responseHeaders=$http_response_header??[];
  if(!preg_match('/^HTTP\/\S+ 200\b/',(string)($responseHeaders[0]??'')))
   throw new DomainException('Game update feed did not return HTTP 200.',502);
  return self::verifyEnvelope($bytes,$cfg['publicKey'],$cfg['url']);
 }
}
