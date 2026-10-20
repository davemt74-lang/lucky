<?php
declare(strict_types=1);
require_once __DIR__.'/CloudGameUpdates.php';
require_once __DIR__.'/GamePackageInstaller.php';

/**
 * Manual, authenticated cloud update download.
 *
 * URL and hash are ALWAYS retrieved again from a verified publisher feed,
 * never accepted from a phone/browser POST body. All HTTPS requests pin their
 * TLS-verified host to prevalidated public DNS addresses (anti-DNS-rebinding).
 */
final class PublisherTransport {
 private static function domain(string $url):array {
  CloudGameUpdates::endpoint($url);
  $parts=parse_url($url);$host=strtolower((string)$parts['host']);
  $records=@dns_get_record($host,DNS_A|DNS_AAAA);
  if(!is_array($records)||!$records)throw new DomainException('Publisher DNS lookup failed.',502);
  $chosen=null;
  foreach($records as $record){
   $ip=$record['ip']??$record['ipv6']??null;
   if(!is_string($ip)||!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))
    throw new DomainException('Publisher DNS address is private or unsafe.',403);
   if(isset($record['ip'])&&$chosen===null)$chosen=$ip;
  }
  if(!$chosen)throw new DomainException('Publisher requires a publicly routable IPv4 address.',422);
  return [$host,$chosen];
 }
 /**
  * Fetches only exact HTTPS URL, with no redirects, cookies or proxy routing.
  * Callers must independently validate publisher signature / release digest.
  */
 public static function fetch(string $url,string $token,int $maximum):string {
  [$host,$ip]=self::domain($url);
  if(!function_exists('curl_init'))throw new DomainException('Secure update transport unavailable. PHP cURL extension required.',503);
  if($maximum<1||$maximum>41943040)throw new DomainException('Invalid download size limit.',422);
  $curl=curl_init();if(!$curl)throw new RuntimeException('Unable to create HTTPS client.');
  $result='';$failed=false;
  try{
   curl_setopt_array($curl,[
    CURLOPT_URL=>$url,CURLOPT_RETURNTRANSFER=>false,CURLOPT_FOLLOWLOCATION=>false,
    CURLOPT_MAXREDIRS=>0,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
    CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
    CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>45,CURLOPT_PROXY=>'',
    CURLOPT_IPRESOLVE=>CURL_IPRESOLVE_V4,
    CURLOPT_RESOLVE=>[$host.':443:'.$ip],CURLOPT_USERAGENT=>'LuckyGameHub-Updates/1.0',
    CURLOPT_HTTPHEADER=>array_merge(['Accept: application/octet-stream','Connection: close'],
      $token!==''?['Authorization: Bearer '.$token]:[]),
    CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$result,&$failed,$maximum):int{
     if(strlen($result)+strlen($chunk)>$maximum){$failed=true;return 0;}
     $result.=$chunk;return strlen($chunk);
    }
   ]);
   $ok=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
   if($failed)throw new DomainException('Publisher file exceeds download limit.',413);
   if($ok===false||$status!==200)
    throw new DomainException('Publisher download failed or returned a non-200 response.',502);
   return $result;
  }finally{curl_close($curl);}
 }
}

final class CloudGamePackageDownload {
 public static function release(array $feed,string $id,string $version):array {
  GameCatalog::slug($id);GamePackageInstaller::version($version);
  foreach($feed['releases']??[] as $r){
   if(($r['game']??null)===$id&&($r['version']??null)===$version){
    if(!preg_match('/^[a-f0-9]{64}$/D',(string)($r['sha256']??''))||
      !is_int($r['bytes']??null)||$r['bytes']<1||$r['bytes']>40*1024*1024)
     throw new DomainException('This release exceeds the supported ZIP limits.',422);
    return $r;
   }
  }
  throw new DomainException('Requested release is not in the current signed publisher feed.',404);
 }
 public static function verifyDownload(string $data,array $release):void {
  if(strlen($data)!==($release['bytes']??null)||
    !hash_equals((string)($release['sha256']??''),hash('sha256',$data)))
   throw new DomainException('Downloaded game package failed release checksum verification.',403);
 }
 /** Called only from a CSRF-protected admin POST; never in server startup. */
 public static function stage(string $id,string $version):array {
  $configuration=CloudGameUpdates::settings();
  if(!$configuration)throw new DomainException('Connect a trusted publisher first.',409);
  $feed=CloudGameUpdates::check();
  $release=self::release($feed,$id,$version);
  $host=strtolower((string)parse_url($configuration['url'],PHP_URL_HOST));
  if(strtolower((string)parse_url($release['url'],PHP_URL_HOST))!==$host)
   throw new DomainException('Package download host differs from trusted publisher.',403);
  $bytes=PublisherTransport::fetch($release['url'],(string)($configuration['token']??''),40*1024*1024);
  self::verifyDownload($bytes,$release);
  $path=tempnam(sys_get_temp_dir(),'lucky-zip-');
  if($path===false)throw new RuntimeException('Cannot allocate private update temporary file.');
  try{
   if(file_put_contents($path,$bytes,LOCK_EX)!==strlen($bytes))
    throw new RuntimeException('Unable to stage downloaded ZIP.');
   @chmod($path,0600);
   $package=GamePackageInstaller::install($path,null,$configuration['publicKey']);
   if($package['id']!==$id||$package['version']!==$version)
    throw new DomainException('Downloaded package identity does not match signed release.',403);
   return $package;
  }finally{@unlink($path);}
 }
}
