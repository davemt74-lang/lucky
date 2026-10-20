<?php
declare(strict_types=1);
require_once __DIR__.'/../lib/PackageGameSandbox.php';
function sdkCheck(bool $ok,string $reason):void {
 if(!$ok)throw new RuntimeException($reason);
}
function sdkReject(callable $call,string $reason):void {
 try{$call();}catch(DomainException $e){return;}
 throw new RuntimeException('Expected rejection: '.$reason);
}
function sdkClean(string $root):void {
 if(!is_dir($root))return;
 foreach(new FilesystemIterator($root) as $file){
  if($file->isDir()&&!$file->isLink())sdkClean($file->getPathname());
  else unlink($file->getPathname());
 }
 rmdir($root);
}
$base=sys_get_temp_dir().'/lucky-sdk-media-'.bin2hex(random_bytes(7));
mkdir($base,0700,true);
try{
 $root=$base.'/installed';$zipFile=$base.'/game.zip';
 // Valid 1x1 opaque pixel PNG; audio/video/font bodies are structurally
 // checked by the container tests and MIME rules, not media decoders.
 $picture=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGP4z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC',true);
 $wave="RIFF".pack('V',36)."WAVEfmt ".pack('VvvVVvv',16,1,1,8000,8000,1,8)."data".pack('V',0);
 $video="\x1a\x45\xdf\xa3webm-ci-video";
 $font="wOF2sdk-ci-font-fixture";
 $host='<!doctype html><html><head><link rel="stylesheet" href="styles/game.css"></head><body>'.
  '<img id="cover" src="lucky-asset:media/cover.png" alt="Cover">'.
  '<audio id="sound" src="lucky-asset:media/ding.wav"></audio>'.
  '<video id="movie" poster="lucky-asset:media/cover.png" src="lucky-asset:media/clip.webm"></video>'.
  '<script src="scripts/lucky-game.js"></script>'.
  '<script src="scripts/main.js"></script></body></html>';
 $css='@font-face{font-family:Local;src:url("lucky-asset:media/game.woff2") format("woff2")}'.
  'body{font-family:Local}';
 $sdk=file_get_contents(__DIR__.'/../sdk/lucky-game.js');
 $main='LuckyGameSDK.onState(s=>document.body.dataset.status=s.status);';
 $files=['host.html'=>$host,'scripts/lucky-game.js'=>$sdk,'scripts/main.js'=>$main,
  'styles/game.css'=>$css,'media/cover.png'=>$picture,'media/ding.wav'=>$wave,
  'media/clip.webm'=>$video,'media/game.woff2'=>$font];
 $manifest=['schema'=>'lucky.game-package.v1','id'=>'sdk-fixture','version'=>'1.0.0',
  'title'=>'SDK Fixture','publisher'=>'Internal Test Only','host'=>'host.html','phone'=>null,
  'files'=>array_map(fn($x)=>hash('sha256',$x),$files)];
 $z=new ZipArchive();
 sdkCheck($z->open($zipFile,ZipArchive::CREATE|ZipArchive::OVERWRITE)===true,'Unable to build media fixture ZIP');
 $z->addFromString('manifest.json',json_encode($manifest,JSON_THROW_ON_ERROR));
 foreach($files as $name=>$body)$z->addFromString($name,$body);
 $z->close();
 $staged=GamePackageInstaller::install($zipFile,$root);
 sdkCheck($staged['fileHashes']===$manifest['files'],'Installer stores trusted hash map.');
 GamePackageActivation::activate('sdk-fixture','1.0.0',null,true,$root);
 $doc=PackageGameSandbox::document('sdk-fixture','1.0.0','host',$root);
 if(getenv('LUCKY_SDK_BROWSER')==='1'){echo $doc;exit;}
 sdkCheck(!str_contains($doc,'lucky-asset:'),'All offline references must resolve.');
 foreach([
  'data:image/png;base64,'.base64_encode($picture),
  'data:audio/wav;base64,'.base64_encode($wave),
  'data:video/webm;base64,'.base64_encode($video),
  'data:font/woff2;base64,'.base64_encode($font)
 ] as $resource)sdkCheck(str_contains($doc,$resource),'Offline media URI embedded.');
 sdkCheck(substr_count($doc,'data:image/png;base64,')===2,'Cover reused in image and video poster without extra reads.');
 sdkCheck(strpos($doc,'LuckyGameSDK=')!==false&&strpos($doc,'LuckyGameSDK=')<strpos($doc,$main),
  'SDK script must precede dependent app script.');
 sdkCheck(!str_contains($doc,'<script src=')&&!str_contains($doc,'<link rel="stylesheet"'),
  'No external resource tags in standalone game.');
 file_put_contents($root.'/sdk-fixture/versions/1.0.0/media/ding.wav','changed');
 sdkReject(fn()=>PackageGameSandbox::document('sdk-fixture','1.0.0','host',$root),'Media tampering rejected after installation.');
 file_put_contents($root.'/sdk-fixture/versions/1.0.0/media/ding.wav',$wave);
 file_put_contents($root.'/sdk-fixture/versions/1.0.0/scripts/main.js','tampered');
 sdkReject(fn()=>PackageGameSandbox::document('sdk-fixture','1.0.0','host',$root),'Script tampering rejected after installation.');
 file_put_contents($root.'/sdk-fixture/versions/1.0.0/scripts/main.js',$main);
 $invalid=str_replace('media/clip.webm','media/hidden.webm',$host);
 file_put_contents($root.'/sdk-fixture/versions/1.0.0/host.html',$invalid);
 sdkReject(fn()=>PackageGameSandbox::document('sdk-fixture','1.0.0','host',$root),'Missing media rejected.');
 echo "V5Q.23B offline media, runtime file hashes, script order and missing assets passed\n";
}finally{sdkClean($base);}
