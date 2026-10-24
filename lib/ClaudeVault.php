<?php
declare(strict_types=1);

/** Per-installation encryption for the optional, server-only Claude credential. */
final class ClaudeVault {
 public const MODELS=['claude-haiku-5-5','claude-sonnet-5-5','claude-opus-5-5'];
 private static function directory():string {
  if(defined('LUCKY_TESTING') && ($test=getenv('LUCKY_CLAUDE_TEST_DIR')))return $test;
  return dirname(__DIR__).'/storage';
 }
 private static function keyPath():string{return self::directory().'/.claude-key.php';}
 private static function configPath():string{return self::directory().'/.claude-settings.php';}
 private static function sealed(string $file,array $data):void {
  $dir=dirname($file);
  if(!is_dir($dir)||!is_writable($dir)||is_link($file))throw new RuntimeException('Secure settings directory is unavailable.');
  $temp=$dir.'/.claude-tmp-'.bin2hex(random_bytes(12));
  $bytes='<?php return '.var_export($data,true).';';
  if(file_put_contents($temp,$bytes,LOCK_EX)===false)throw new RuntimeException('Unable to save Claude settings.');
  @chmod($temp,0600);
  if(!rename($temp,$file)){@unlink($temp);throw new RuntimeException('Unable to activate Claude settings.');}
  if(function_exists('opcache_invalidate'))@opcache_invalidate($file,true);
 }
 private static function secret(bool $create):?string {
  $file=self::keyPath();if(is_link($file))throw new RuntimeException('Invalid Claude key storage.');
  if(is_file($file)){
   $hex=require $file;
   if(!is_string($hex)||!preg_match('/^[a-f0-9]{64}$/D',$hex))throw new RuntimeException('Invalid local encryption key.');
   return hex2bin($hex);
  }
  if(!$create)return null;
  // Exclusive creation prevents two initial saves from silently using different keys.
  $handle=@fopen($file,'x');
  if($handle===false)return self::secret(false)??throw new RuntimeException('Unable to create encryption key.');
  $hex=bin2hex(random_bytes(32));
  try{if(fwrite($handle,'<?php return '.var_export($hex,true).';')===false)throw new RuntimeException('Unable to save encryption key.');}
  finally{fclose($handle);@chmod($file,0600);}
  return hex2bin($hex);
 }
 private static function record():?array {
  $file=self::configPath();if(is_link($file))throw new RuntimeException('Invalid Claude settings storage.');
  if(!is_file($file))return null;
  $r=require $file;
  if(!is_array($r)||!isset($r['cipher'],$r['iv'],$r['tag'],$r['model']))throw new RuntimeException('Invalid Claude settings.');
  return $r;
 }
 public static function status():array {
  $r=self::record();return ['configured'=>$r!==null,'model'=>in_array($r['model']??'',self::MODELS,true)?$r['model']:self::MODELS[0]];
 }
 public static function store(string $apiKey,string $model):array {
  $apiKey=trim($apiKey);
  if(!in_array($model,self::MODELS,true))throw new DomainException('Choose a supported Claude model.',422);
  if(strlen($apiKey)<20||strlen($apiKey)>400||!preg_match('/^[\x21-\x7e]+$/D',$apiKey))throw new DomainException('Enter a valid Claude API key.',422);
  if(!function_exists('openssl_encrypt'))throw new RuntimeException('PHP OpenSSL is required for secure AI settings.');
  $secret=self::secret(true);$iv=random_bytes(12);$tag='';
  $cipher=openssl_encrypt($apiKey,'aes-256-gcm',$secret,OPENSSL_RAW_DATA,$iv,$tag,'lucky-video-ai-v1');
  if($cipher===false)throw new RuntimeException('Could not encrypt the API key.');
  self::sealed(self::configPath(),['model'=>$model,'cipher'=>base64_encode($cipher),'iv'=>base64_encode($iv),'tag'=>base64_encode($tag)]);
  return self::status();
 }
 public static function apiKey():string {
  $r=self::record();if(!$r)throw new DomainException('Configure Claude in Admin → AI Studio first.',409);
  $secret=self::secret(false);
  $cipher=base64_decode((string)$r['cipher'],true);$iv=base64_decode((string)$r['iv'],true);$tag=base64_decode((string)$r['tag'],true);
  if(!$secret||$cipher===false||$iv===false||strlen($iv)!==12||$tag===false||strlen($tag)!==16)throw new RuntimeException('Stored Claude credential is invalid.');
  $key=openssl_decrypt($cipher,'aes-256-gcm',$secret,OPENSSL_RAW_DATA,$iv,$tag,'lucky-video-ai-v1');
  if(!is_string($key)||$key==='')throw new RuntimeException('Stored Claude credential could not be decrypted.');
  return $key;
 }
 public static function disconnect():array {
  $file=self::configPath();if(is_link($file))throw new RuntimeException('Invalid Claude settings storage.');
  if(is_file($file)&&!unlink($file))throw new RuntimeException('Could not remove Claude credential.');
  if(function_exists('opcache_invalidate'))@opcache_invalidate($file,true);
  return self::status();
 }
}
