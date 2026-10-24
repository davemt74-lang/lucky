<?php
declare(strict_types=1);
require_once __DIR__.'/ClaudeVault.php';

/** Explicit, bounded Claude Messages request. Never accepts a user-supplied URL. */
final class ClaudeBridge {
 public static function ask(string $system,string $prompt,int $maxTokens=1100):string {
  if(!extension_loaded('curl'))throw new RuntimeException('PHP cURL is required for Claude.');
  $status=ClaudeVault::status();
  $payload=json_encode(['model'=>$status['model'],'max_tokens'=>max(32,min(1500,$maxTokens)),
   'system'=>$system,'messages'=>[['role'=>'user','content'=>$prompt]]],JSON_THROW_ON_ERROR);
  if(strlen($payload)>28000)throw new DomainException('Editor context is too large for AI planning.',413);
  $handle=curl_init('https://api.anthropic.com/v1/messages');
  if($handle===false)throw new RuntimeException('Could not initialize Claude request.');
  try {
   curl_setopt_array($handle,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$payload,CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_HTTPHEADER=>['Content-Type: application/json','anthropic-version: 2023-06-01','x-api-key: '.ClaudeVault::apiKey()],
    CURLOPT_CONNECTTIMEOUT=>6,CURLOPT_TIMEOUT=>25,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
    CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS]);
   $body=curl_exec($handle);$code=(int)curl_getinfo($handle,CURLINFO_RESPONSE_CODE);
   if($body===false)throw new DomainException('Claude is temporarily unreachable. Try again later.',503);
   if($code===401||$code===403)throw new DomainException('Claude rejected the configured API key. Check Admin → AI Studio.',422);
   if($code===429)throw new DomainException('Claude rate limit reached. Try again later.',429);
   if($code<200||$code>=300)throw new DomainException('Claude could not complete this request (HTTP '.$code.').',502);
   if(strlen($body)>120000)throw new DomainException('Claude response was too large.',502);
   $data=json_decode($body,true,512,JSON_THROW_ON_ERROR);$text='';
   foreach(($data['content']??[]) as $part)if(($part['type']??'')==='text')$text.=(string)($part['text']??'');
   if(trim($text)==='')throw new DomainException('Claude returned no usable suggestion.',502);
   return $text;
  } finally {curl_close($handle);}
 }
}
