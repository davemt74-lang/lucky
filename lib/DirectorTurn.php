<?php
declare(strict_types=1);

/**
 * Optional TURN REST credentials for Director Live View.
 * Shared secrets never leave the server. Every authorized publisher/viewer gets
 * a short-lived HMAC credential for its own WebRTC peer connection.
 */
final class DirectorTurn {
 private const STUN='stun:stun.l.google.com:19302';
 private const DEFAULT_TTL=21600;
 private static function directory():string {
  if(defined('LUCKY_TESTING')&&($test=getenv('LUCKY_TURN_TEST_DIR')))return $test;
  return dirname(__DIR__).'/storage';
 }
 private static function configPath():string{return self::directory().'/.director-turn-settings.php';}
 private static function keyPath():string{return self::directory().'/.director-turn-key.php';}
 private static function record():?array {
  $file=self::configPath();
  if(is_link($file))throw new RuntimeException('Unsafe TURN settings path.');
  if(!is_file($file))return null;
  $row=require $file;
  if(!is_array($row)||!isset($row['urls'],$row['ttl'],$row['enabled']))throw new RuntimeException('Invalid TURN settings.');
  return $row;
 }
 private static function key(bool $create):?string {
  $file=self::keyPath();
  if(is_link($file))throw new RuntimeException('Unsafe TURN key path.');
  if(is_file($file)){
   $hex=require $file;
   if(!is_string($hex)||!preg_match('/^[a-f0-9]{64}$/D',$hex))throw new RuntimeException('Invalid TURN encryption key.');
   return hex2bin($hex);
  }
  if(!$create)return null;
  $handle=@fopen($file,'x');
  if($handle===false)return self::key(false)??throw new RuntimeException('Unable to create TURN encryption key.');
  $hex=bin2hex(random_bytes(32));
  try{
   if(fwrite($handle,'<?php return '.var_export($hex,true).';')===false)throw new RuntimeException('Unable to save TURN encryption key.');
  }finally{fclose($handle);@chmod($file,0600);}
  return hex2bin($hex);
 }
 private static function write(array $row):void {
  $file=self::configPath();$dir=dirname($file);
  if(!is_dir($dir)||!is_writable($dir)||is_link($file))throw new RuntimeException('Secure TURN settings directory is unavailable.');
  $temp=$dir.'/.turn-tmp-'.bin2hex(random_bytes(12));
  if(file_put_contents($temp,'<?php return '.var_export($row,true).';',LOCK_EX)===false)throw new RuntimeException('Unable to save TURN settings.');
  @chmod($temp,0600);
  if(!rename($temp,$file)){@unlink($temp);throw new RuntimeException('Unable to activate TURN settings.');}
  if(function_exists('opcache_invalidate'))@opcache_invalidate($file,true);
 }
 private static function sharedSecret(array $row):string {
  if(empty($row['cipher'])||empty($row['iv'])||empty($row['tag']))throw new RuntimeException('TURN shared secret is not configured.');
  $key=self::key(false);
  $cipher=base64_decode((string)$row['cipher'],true);
  $iv=base64_decode((string)$row['iv'],true);
  $tag=base64_decode((string)$row['tag'],true);
  if(!$key||$cipher===false||$iv===false||strlen($iv)!==12||$tag===false||strlen($tag)!==16)throw new RuntimeException('Invalid encrypted TURN settings.');
  $secret=openssl_decrypt($cipher,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,'lucky-director-turn-v1');
  if(!is_string($secret)||$secret==='')throw new RuntimeException('Unable to decrypt TURN shared secret.');
  return $secret;
 }
 private static function urls(mixed $input):array {
  if(!is_string($input)||strlen($input)>1200)throw new DomainException('Provide up to four TURN relay URLs.',422);
  $urls=preg_split('/[\s,]+/',trim($input),-1,PREG_SPLIT_NO_EMPTY)?:[];
  if(count($urls)>4)throw new DomainException('At most four TURN URLs are supported.',422);
  foreach($urls as $url){
   if(strlen($url)>250||!preg_match('~^turns?:(?:[A-Za-z0-9][A-Za-z0-9.-]*|\[[0-9a-fA-F:]+\])(?::[0-9]{1,5})?(?:\?transport=(?:udp|tcp))?$~D',$url))
    throw new DomainException('Use a TURN URL such as turn:relay.example.com:3478?transport=udp.',422);
   if(preg_match('/:(\d{1,5})(?:\?transport=|$)/',$url,$match)&&(int)$match[1]>65535)
    throw new DomainException('TURN relay port is out of range.',422);
  }
  return array_values(array_unique($urls));
 }
 public static function status():array {
  $row=self::record();
  return ['enabled'=>(bool)($row['enabled']??false),'configured'=>$row!==null&&!empty($row['cipher']),
   'urls'=>$row['urls']??[],'ttl'=>(int)($row['ttl']??self::DEFAULT_TTL)];
 }
 public static function configure(array $input):array {
  if(!function_exists('openssl_encrypt')||!function_exists('openssl_decrypt'))throw new RuntimeException('PHP OpenSSL is required for TURN settings.');
  $enabled=($input['enabled']??false)===true;
  $urls=self::urls($input['urls']??'');
  $ttl=filter_var($input['ttl']??self::DEFAULT_TTL,FILTER_VALIDATE_INT);
  if($ttl===false||$ttl<600||$ttl>86400)throw new DomainException('TURN credential lifetime must be 600–86400 seconds.',422);
  $secret=$input['secret']??'';
  if(!is_string($secret))throw new DomainException('Invalid TURN secret.',422);
  $secret=trim($secret);
  if($secret!==''&&(strlen($secret)<16||strlen($secret)>255||preg_match('/[^\x21-\x7e]/',$secret)))
   throw new DomainException('The TURN REST shared secret must be 16–255 printable characters.',422);
  $existing=self::record();
  if($secret===''&&$existing&&!empty($existing['cipher']))$secret=self::sharedSecret($existing);
  if($enabled&&(!$urls||$secret===''))throw new DomainException('Configure TURN URL(s) and a shared secret before enabling relay.',422);
  if($secret===''){
   self::write(['enabled'=>false,'urls'=>$urls,'ttl'=>$ttl]);
   return self::status();
  }
  $key=self::key(true);$iv=random_bytes(12);$tag='';
  $cipher=openssl_encrypt($secret,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,'lucky-director-turn-v1');
  if($cipher===false)throw new RuntimeException('Unable to encrypt TURN shared secret.');
  self::write(['enabled'=>$enabled,'urls'=>$urls,'ttl'=>$ttl,'cipher'=>base64_encode($cipher),
   'iv'=>base64_encode($iv),'tag'=>base64_encode($tag)]);
  return self::status();
 }
 public static function ice():array {
  $servers=[['urls'=>self::STUN]];
  $row=self::record();
  if(!$row||!($row['enabled']??false))return ['iceServers'=>$servers,'relayEnabled'=>false];
  $secret=self::sharedSecret($row);
  $expires=time()+(int)$row['ttl'];
  $user=$expires.':'.bin2hex(random_bytes(8));
  $servers[]=['urls'=>$row['urls'],'username'=>$user,
   'credential'=>base64_encode(hash_hmac('sha1',$user,$secret,true))];
  return ['iceServers'=>$servers,'relayEnabled'=>true];
 }
}
