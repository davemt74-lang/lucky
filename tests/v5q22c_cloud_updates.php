<?php
declare(strict_types=1);
require_once __DIR__.'/../lib/CloudGameUpdates.php';
function certifyUpdate(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
function updateReject(callable $fn,string $message):void {
 try{$fn();}catch(DomainException $e){return;}
 throw new RuntimeException('Expected rejection: '.$message);
}
$root=sys_get_temp_dir().'/lucky-updates-'.bin2hex(random_bytes(8));
if(!mkdir($root,0700,true))throw new RuntimeException('Cannot prepare temporary update configuration.');
try{
 certifyUpdate(CloudGameUpdates::summary($root)['connected']===false,'Default must be offline');
 updateReject(fn()=>CloudGameUpdates::endpoint('http://publisher.example/updates.json'),'HTTP update feed');
 updateReject(fn()=>CloudGameUpdates::endpoint('https://127.0.0.1/updates.json'),'IP update host');
 updateReject(fn()=>CloudGameUpdates::endpoint('https://login:pass@publisher.example/feed'),'Credentials in update URL');
 $key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>2048]);
 if($key===false)throw new RuntimeException('OpenSSL RSA unavailable.');
 $public=openssl_pkey_get_details($key)['key'];
 $state=CloudGameUpdates::connect(['url'=>'https://publisher.example/releases/feed.json','publicKey'=>$public,'token'=>'example-token'],$root);
 certifyUpdate($state['connected']===true&&str_starts_with($state['keyFingerprint'],hash('sha256',$public)),'Trusted feed connection');
 certifyUpdate(!str_contains(json_encode($state),'example-token'),'Cloud token must not appear in settings response.');
 $settings=CloudGameUpdates::settings($root);
 certifyUpdate(($settings['token']??null)==='example-token','Token should persist locally only.');
 $now=time();$origin='https://publisher.example/releases/feed.json';
 $data=['issuedAt'=>gmdate('Y-m-d\TH:i:s\Z',$now-60),'expiresAt'=>gmdate('Y-m-d\TH:i:s\Z',$now+86400),
  'releases'=>[['game'=>'spin-wheel','version'=>'5.22.1','url'=>'https://publisher.example/releases/wheel-5.22.1.zip',
    'sha256'=>str_repeat('a',64),'bytes'=>12345,'notes'=>'Wheel update release']]];
 $sign=function(array $payload)use($key):string {
  $raw=json_encode($payload,JSON_THROW_ON_ERROR);
  $signature='';if(!openssl_sign($raw,$signature,$key,OPENSSL_ALGO_SHA256))throw new RuntimeException('Unable to sign test feed.');
  return json_encode(['schema'=>'lucky.game-update-feed.v1','payload'=>base64_encode($raw),'signature'=>base64_encode($signature)],JSON_THROW_ON_ERROR);
 };
 $verified=CloudGameUpdates::verifyEnvelope($sign($data),$public,$origin);
 certifyUpdate($verified['verified']===true&&$verified['installSupported']===false,'Metadata verified but not executable.');
 certifyUpdate($verified['releases'][0]['game']==='spin-wheel','Spin the Wheel release discoverable.');
 $tampered=json_decode($sign($data),true);
 $payload=json_decode(base64_decode($tampered['payload']),true);$payload['releases'][0]['version']='99.99.99';
 $tampered['payload']=base64_encode(json_encode($payload));
 updateReject(fn()=>CloudGameUpdates::verifyEnvelope(json_encode($tampered),$public,$origin),'Forged release payload');
 $cross=$data;$cross['releases'][0]['url']='https://untrusted.example/wheel.zip';
 updateReject(fn()=>CloudGameUpdates::verifyEnvelope($sign($cross),$public,$origin),'Cross-host package substitution');
 $expired=$data;$expired['expiresAt']=gmdate('Y-m-d\TH:i:s\Z',$now-30);
 updateReject(fn()=>CloudGameUpdates::verifyEnvelope($sign($expired),$public,$origin),'Expired feed');
 $badDigest=$data;$badDigest['releases'][0]['sha256']='not-a-hash';
 updateReject(fn()=>CloudGameUpdates::verifyEnvelope($sign($badDigest),$public,$origin),'Invalid package digest');
 $disconnect=CloudGameUpdates::disconnect($root);
 certifyUpdate($disconnect['connected']===false,'Cloud disconnect must preserve offline state');
 echo "V5Q.22C optional cloud updates, signature verification, trust boundaries and offline default passed\n";
}finally{
 $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
 foreach($it as $entry){if($entry->isDir())rmdir($entry->getPathname());else unlink($entry->getPathname());}
 rmdir($root);
}
