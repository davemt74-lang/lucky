<?php
declare(strict_types=1);
require_once __DIR__.'/../lib/CloudGamePackageDownload.php';
function check22f(bool $value,string $label):void {if(!$value)throw new RuntimeException($label);}
function reject22f(callable $callback,string $label):void {
 try{$callback();}catch(DomainException $error){return;}
 throw new RuntimeException('Expected refusal: '.$label);
}
$archive="Not a real ZIP; this is an exact byte-level fixture";
$release=['game'=>'table-trivia','version'=>'1.2.3','url'=>'https://publisher.example/download/trivia.zip',
 'sha256'=>hash('sha256',$archive),'bytes'=>strlen($archive),'notes'=>'Test game update'];
$feed=['verified'=>true,'releases'=>[$release]];
$choice=CloudGamePackageDownload::release($feed,'table-trivia','1.2.3');
check22f($choice['sha256']===$release['sha256'],'Release is selected by server-side game and version');
CloudGamePackageDownload::verifyDownload($archive,$choice);
reject22f(fn()=>CloudGamePackageDownload::release($feed,'table-trivia','9.9.9'),'Unadvertised game version');
reject22f(fn()=>CloudGamePackageDownload::release($feed,'../admin','1.2.3'),'Invalid game id');
reject22f(fn()=>CloudGamePackageDownload::release($feed,'table-trivia','bad-version'),'Invalid semantic version');
reject22f(fn()=>CloudGamePackageDownload::release(['releases'=>[
 ['game'=>'table-trivia','version'=>'1.2.3','sha256'=>$release['sha256'],
  'bytes'=>40*1024*1024+1]],'table-trivia','1.2.3'),'Oversized release');
reject22f(fn()=>CloudGamePackageDownload::verifyDownload($archive.'x',$choice),'Appended bytes');
reject22f(fn()=>CloudGamePackageDownload::verifyDownload(str_repeat('x',strlen($archive)),$choice),'Tampered same-length bytes');
reject22f(fn()=>PublisherTransport::fetch('http://publisher.example/trivia.zip','',1000),'HTTP downgrade');
reject22f(fn()=>PublisherTransport::fetch('https://127.0.0.1/trivia.zip','',1000),'Loopback SSRF');
reject22f(fn()=>PublisherTransport::fetch('https://host.local/trivia.zip','',1000),'Local DNS SSRF');
echo "V5Q.22F: verified release selection, exact digest/length and HTTPS SSRF safeguards passed\n";
