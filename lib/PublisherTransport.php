<?php
declare(strict_types=1);
require_once __DIR__.'/CloudGameUpdates.php';

/** TLS-verified public DNS pinned HTTP fetch (no redirects, no LAN/proxy). */
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

