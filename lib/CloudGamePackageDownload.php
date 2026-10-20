<?php
declare(strict_types=1);
require_once __DIR__.'/CloudGameUpdates.php';
require_once __DIR__.'/GamePackageInstaller.php';
require_once __DIR__.'/PublisherTransport.php';

/** Admin-requested, signed publisher game package download and offline staging. */
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
   $package=GamePackageInstaller::install($path,null,$configuration['publicKey'],$id,$version);
   if($package['id']!==$id||$package['version']!==$version)
    throw new DomainException('Downloaded package identity does not match signed release.',403);
   return $package;
  }finally{@unlink($path);}
 }
}
