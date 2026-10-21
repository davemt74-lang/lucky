<?php
declare(strict_types=1);
require_once __DIR__.'/../lib/GamePublisherChannels.php';
require_once __DIR__.'/../lib/CloudGamePackageDownload.php';
function channelPass(bool $yes,string $message):void {
 if(!$yes)throw new RuntimeException('FAIL '.$message);
}
function channelReject(callable $fn,string $message,?int $code=null):void {
 try{$fn();}catch(DomainException $e){
  if($code!==null&&$e->getCode()!==$code)throw new RuntimeException('Wrong refusal '.$message.': '.$e->getMessage());
  return;
 }
 throw new RuntimeException('Expected rejection: '.$message);
}
function channelDelete(string $folder):void {
 if(!is_dir($folder))return;
 foreach(new FilesystemIterator($folder) as $entry){
  if($entry->isDir()&&!$entry->isLink())channelDelete($entry->getPathname());
  else unlink($entry->getPathname());
 }
 rmdir($folder);
}
$root=sys_get_temp_dir().'/lucky-channels-'.bin2hex(random_bytes(7));
mkdir($root,0700,true);
try{
 $settings=GamePublisherChannels::summary($root);
 channelPass($settings===[],'Offline by default: no publisher channels');
 $keyA=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>2048]);
 $keyB=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>2048]);
 if(!$keyA||!$keyB)throw new RuntimeException('Cannot create publisher keys.');
 $pubA=openssl_pkey_get_details($keyA)['key'];$pubB=openssl_pkey_get_details($keyB)['key'];
 $cA=['game'=>'spin-wheel','publisher'=>'Lucky Studio','url'=>'https://studio.example/updates.json',
  'publicKey'=>$pubA,'token'=>'private-feed-token'];
 $cB=['game'=>'table-trivia','publisher'=>'Quiz Studio','url'=>'https://quiz.example/feed.json',
  'publicKey'=>$pubB,'token'=>'trivia-token'];
 $first=GamePublisherChannels::connect($cA,$root);
 $second=GamePublisherChannels::connect($cB,$root);
 channelPass($first['game']==='spin-wheel'&&$second['game']==='table-trivia','Two independent publisher trust anchors');
 $list=GamePublisherChannels::summary($root);
 channelPass(count($list)===2&&$list[0]['game']==='spin-wheel'&&$list[1]['game']==='table-trivia','Sorted separate publisher connections');
 channelPass(!str_contains(json_encode($list),'private-feed-token')&&!str_contains(json_encode($list),'BEGIN PUBLIC KEY'),
  'Admin summary excludes private feed token and full verification key');
 channelPass(GamePublisherChannels::config('spin-wheel',$root)['token']==='private-feed-token','Private token retained only in local config');
 channelPass($first['keyFingerprint']!==$second['keyFingerprint'],'Publisher keys are distinct');
 channelReject(fn()=>GamePublisherChannels::connect([...$cA,'publicKey'=>$pubB],$root),'Key rotation requires explicit approval',409);
 channelReject(fn()=>GamePublisherChannels::connect([...$cA,'url'=>'https://other.example/feed.json'],$root),'Feed replacement requires explicit approval',409);
 channelReject(fn()=>GamePublisherChannels::connect([...$cA,'publisher'=>'Unknown'], $root),'Publisher identity change requires approval',409);
 channelPass(GamePublisherChannels::config('spin-wheel',$root)['publicKey']===$pubA,'Rejected rotation preserves existing key');
 channelReject(fn()=>GamePublisherChannels::connect([...$cA,'url'=>'http://studio.example/feed.json'],$root),'HTTP rejected');
 channelReject(fn()=>GamePublisherChannels::connect([...$cA,'url'=>'https://localhost/feed.json'],$root),'Localhost disallowed');
 channelReject(fn()=>GamePublisherChannels::connect([...$cA,'token'=>"line\nbreak"],$root),'Control characters in token disallowed');
 channelReject(fn()=>GamePublisherChannels::connect([...$cA,'game'=>'../../bad'],$root),'Path traversal game rejected');
 $changed=GamePublisherChannels::connect([...$cA,'publicKey'=>$pubB,'confirmReplace'=>true],$root);
 channelPass($changed['keyFingerprint']===hash('sha256',$pubB),'Explicit signing-key rotation persisted');
 $now=time();
 $payload=[
  'issuedAt'=>gmdate('Y-m-d\TH:i:s\Z',$now-50),
  'expiresAt'=>gmdate('Y-m-d\TH:i:s\Z',$now+86400),
  'releases'=>[
   ['game'=>'spin-wheel','version'=>'2.0.0','url'=>'https://studio.example/updates/wheel.zip',
    'sha256'=>str_repeat('1',64),'bytes'=>4096,'sdkMin'=>'1.0.0','notes'=>'Current SDK'],
   ['game'=>'table-trivia','version'=>'3.0.0','url'=>'https://studio.example/updates/trivia.zip',
    'sha256'=>str_repeat('2',64),'bytes'=>4096,'sdkMin'=>'1.0.0','notes'=>'Cross-title release'],
   ['game'=>'spin-wheel','version'=>'2.1.0','url'=>'https://studio.example/updates/wheel-next.zip',
    'sha256'=>str_repeat('3',64),'bytes'=>4096,'sdkMin'=>'9.0.0','notes'=>'Requires new SDK']
  ]];
 $sign=function(array $data,OpenSSLAsymmetricKey $key):string {
  $raw=json_encode($data,JSON_THROW_ON_ERROR);$signature='';
  if(!openssl_sign($raw,$signature,$key,OPENSSL_ALGO_SHA256))throw new RuntimeException('Could not sign feed');
  return json_encode(['schema'=>'lucky.game-update-feed.v1','payload'=>base64_encode($raw),
   'signature'=>base64_encode($signature)],JSON_THROW_ON_ERROR);
 };
 $bytes=$sign($payload,$keyB);
 $verified=CloudGameUpdates::verifyEnvelope($bytes,$pubB,$cA['url']);
 channelPass($verified['verified']===true,'Publisher feed signature verified');
 $onlyWheel=GamePublisherChannels::verifiedForGame($verified,'spin-wheel');
 channelPass(count($onlyWheel['releases'])===2,'Publisher cannot advertise another game through this game channel');
 channelPass($onlyWheel['releases'][0]['compatible']===true,'Compatible update displayed');
 channelPass($onlyWheel['releases'][1]['compatible']===false,'Newer SDK update visible but disabled');
 channelReject(fn()=>GamePublisherChannels::assertCompatible($onlyWheel['releases'][1]),'Newer SDK must not stage',409);
 channelPass(CloudGamePackageDownload::release($onlyWheel,'spin-wheel','2.0.0')['version']==='2.0.0','Matching signed release selectable');
 channelReject(fn()=>CloudGamePackageDownload::release($onlyWheel,'table-trivia','3.0.0'),'Cannot request cross-title release',404);
 channelReject(fn()=>CloudGameUpdates::verifyEnvelope($bytes,$pubA,$cA['url']),'Wrong publisher key rejects signed feed',403);
 $bad=$payload;$bad['releases'][0]['sdkMin']='latest';
 channelReject(fn()=>CloudGameUpdates::verifyEnvelope($sign($bad,$keyB),$pubB,$cA['url']),'Malformed signed compatibility declaration',422);
 $bad=$payload;$bad['releases'][0]['url']='https://quiz.example/wheel.zip';
 channelReject(fn()=>CloudGameUpdates::verifyEnvelope($sign($bad,$keyB),$pubB,$cA['url']),'Cross-host publisher injection',422);
 $stagedInfo=['game'=>'spin-wheel','version'=>'2.0.0','bytes'=>4096,'sha256'=>str_repeat('1',64),'sdkMin'=>'1.0.0'];
 GamePublisherChannels::assertCompatible($stagedInfo);
 $disconnected=GamePublisherChannels::disconnect('spin-wheel',$root);
 channelPass($disconnected['connected']===false,'Disconnect one game');
 channelPass(GamePublisherChannels::config('spin-wheel',$root)===null,'Revoked game trust removed');
 channelPass(GamePublisherChannels::config('table-trivia',$root)!==null,'Other game trusted source preserved');
 channelPass(count(GamePublisherChannels::summary($root))===1,'Remaining publisher stays connected');
 $archive=$root.'/dummy.zip';$zip=new ZipArchive();
 if($zip->open($archive,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)throw new RuntimeException('ZIP fixture failed');
 $host='<html><body>Test</body></html>';
 $manifest=['schema'=>'lucky.game-package.v1','id'=>'table-trivia','version'=>'1.0.0',
  'title'=>'Trivia','publisher'=>'Quiz Studio','host'=>'host.html','phone'=>null,
  'files'=>['host.html'=>hash('sha256',$host)]];
 $zip->addFromString('manifest.json',json_encode($manifest,JSON_THROW_ON_ERROR));
 $zip->addFromString('host.html',$host);$zip->close();
 channelPass(GamePackageInstaller::inspectIdentity($archive)==='table-trivia',
  'Upload inspects exact game ID before choosing pinned publisher key');
 echo "V5Q.23C publisher channels: isolated keys, manual trust, SDK gating, signed feed and revocation PASSED\n";
}finally{channelDelete($root);}
