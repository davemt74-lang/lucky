<?php
declare(strict_types=1);
require_once __DIR__.'/GamePackageInstaller.php';

/**
 * Atomic, owner-approved selection of an already validated *static* game ZIP.
 * Does not execute PHP or permit replacing a bundled game.
 * Active versions remain pinned while any live session exists (API gate).
 */
final class GamePackageActivation {
 public static function active(string $id,?string $root=null):?array {
  $id=GameCatalog::slug($id);
  if($id==='spin-wheel')return null;
  $dir=GamePackageInstaller::base($root).'/'.$id;
  if(is_link($dir))throw new DomainException('Unsafe game installation folder.',403);
  $file=$dir.'/active.json';
  if(!is_file($file))return null;
  if(is_link($file))throw new DomainException('Unsafe game activation file.',403);
  $row=json_decode((string)file_get_contents($file),true,12,JSON_THROW_ON_ERROR);
  if(!is_array($row)||($row['id']??null)!==$id)throw new RuntimeException('Invalid activation record.');
  $version=GamePackageInstaller::version($row['version']??null);
  $receipt=self::receipt($id,$version,$root);
  return ['id'=>$id,'version'=>$version,'receipt'=>$receipt,'epoch'=>$row['epoch']??''];
 }
 public static function receipt(string $id,string $version,?string $root=null):array {
  $id=GameCatalog::slug($id);$version=GamePackageInstaller::version($version);
  $folder=GamePackageInstaller::base($root).'/'.$id.'/versions/'.$version;
  if(!is_dir($folder)||is_link($folder))throw new DomainException('Game version not installed.',404);
  $path=$folder.'/receipt.json';
  if(!is_file($path)||is_link($path))throw new DomainException('Game package receipt missing.',404);
  $row=json_decode((string)file_get_contents($path),true,12,JSON_THROW_ON_ERROR);
  if(!is_array($row)||($row['id']??null)!==$id||($row['version']??null)!==$version
     ||($row['status']??null)!=='staged')throw new DomainException('Damaged package receipt.',409);
  return $row;
 }
 private static function mkdirSafe(string $folder):void {
  if(is_link($folder))throw new DomainException('Unsafe package activation path.',403);
  if(!is_dir($folder)&&!mkdir($folder,0700,true)&&!is_dir($folder))throw new RuntimeException('Cannot prepare activation.');
  if(is_link($folder))throw new DomainException('Unsafe package activation path.',403);
 }
 public static function activate(string $id,string $version,?string $expectedCurrent,bool $ownerApproved,?string $root=null):array {
  $id=GameCatalog::slug($id);$version=GamePackageInstaller::version($version);
  if($id==='spin-wheel')throw new DomainException('Spin the Wheel is a protected built-in game.',409);
  if(!$ownerApproved)throw new DomainException('Owner confirmation is required.',403);
  $base=GamePackageInstaller::base($root);self::mkdirSafe($base);
  $lock=fopen($base.'/.lock','c');
  if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Unable to lock game activation.');
  try {
   $dir=$base.'/'.$id;self::mkdirSafe($dir);
   $current=self::active($id,$root);
   if(($current['version']??null)!==$expectedCurrent)
    throw new DomainException('Active version changed. Refresh before switching games.',409);
   $receipt=self::receipt($id,$version,$root);
   if($current&&$current['version']===$version)return ['game'=>$id,'version'=>$version,'previous'=>$version,'updated'=>false];
   $row=['id'=>$id,'version'=>$version,'previous'=>$current['version']??null,
    'epoch'=>bin2hex(random_bytes(12)),'activatedAt'=>gmdate('Y-m-d\TH:i:s\Z')];
   $tmp=$dir.'/.activate-'.bin2hex(random_bytes(12));
   $bytes=json_encode($row,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
   if(file_put_contents($tmp,$bytes,LOCK_EX)===false)throw new RuntimeException('Unable to save game activation.');
   @chmod($tmp,0600);
   if(!rename($tmp,$dir.'/active.json')){@unlink($tmp);throw new RuntimeException('Unable to publish activated version.');}
   return ['game'=>$id,'version'=>$version,'previous'=>$row['previous'],'updated'=>true,
    'phone'=>$receipt['phone']!==null];
  }finally{flock($lock,LOCK_UN);fclose($lock);}
 }
 /**
  * Enumerate independent activation records, not package receipts. A corrupt
  * receipt must not hide its failure or prevent unrelated games from loading.
  * Never auto-activate an older release on corruption: fail closed and ask the
  * owner to inspect/restore the affected package.
  */
 public static function inspect(?string $root=null):array {
  $base=GamePackageInstaller::base($root);
  $active=[];$issues=[];
  if(is_link($base)){
   return ['active'=>[], 'issues'=>[['game'=>'storage','reason'=>'unsafe_directory']]];
  }
  if(!is_dir($base))return ['active'=>[], 'issues'=>[]];
  try {
   foreach(new DirectoryIterator($base) as $entry){
    if(!$entry->isDir()||$entry->isLink()||$entry->getFilename()==='spin-wheel')continue;
    $id=$entry->getFilename();
    if(!preg_match('/^[a-z0-9][a-z0-9-]{1,62}$/D',$id))continue;
    if(!file_exists($entry->getPathname().'/active.json')&&!is_link($entry->getPathname().'/active.json'))continue;
    try {
     $selection=self::active($id,$root);
     if(!$selection)continue;
     $receipt=$selection['receipt'];
     $active[$id]=['id'=>$id,'version'=>$selection['version'],
      'title'=>$receipt['title'],'phone'=>$receipt['phone']!==null,
      'publisher'=>$receipt['publisher']];
    } catch(Throwable $e) {
     // No filesystem paths or raw exception strings in admin API responses.
     error_log('Lucky game activation unavailable for '.$id.': '.get_class($e));
     $issues[]=['game'=>$id,'reason'=>'invalid_activation'];
    }
   }
  }catch(Throwable $e){
   error_log('Lucky package discovery unavailable: '.get_class($e));
   $issues[]=['game'=>'storage','reason'=>'unreadable_directory'];
  }
  ksort($active,SORT_STRING);
  return ['active'=>$active,'issues'=>$issues];
 }
 public static function all(?string $root=null):array {
  return self::inspect($root)['active'];
 }
 /**
  * Explicit owner recovery for a corrupt activation pointer. Preserve the
  * broken record as evidence; never silently select a different game release.
  * Call only behind the same live-session DB guard as normal activation.
  */
 public static function recover(string $id,string $version,bool $ownerApproved,?string $root=null):array {
  $id=GameCatalog::slug($id);$version=GamePackageInstaller::version($version);
  if($id==='spin-wheel')throw new DomainException('Built-in games cannot be recovered through package uploads.',409);
  if(!$ownerApproved)throw new DomainException('Owner approval required.',403);
  $base=GamePackageInstaller::base($root);self::mkdirSafe($base);
  $lock=fopen($base.'/.lock','c');
  if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Cannot lock game recovery.');
  try {
   $directory=$base.'/'.$id;self::mkdirSafe($directory);
   $file=$directory.'/active.json';
   if(!is_file($file)||is_link($file))throw new DomainException('No recoverable activation record exists.',409);
   try {
    self::active($id,$root);
   } catch(Throwable $damaged) {
    // Recovery can only target an already verified, staged package receipt.
    $receipt=self::receipt($id,$version,$root);
    $backup=$directory.'/activation-damaged-'.bin2hex(random_bytes(12)).'.json';
    if(!rename($file,$backup))throw new RuntimeException('Cannot preserve damaged activation record.');
    $row=['id'=>$id,'version'=>$version,'previous'=>null,
      'epoch'=>bin2hex(random_bytes(12)),'activatedAt'=>gmdate('Y-m-d\\TH:i:s\\Z'),
      'recovered'=>true];
    $tmp=$directory.'/.recover-'.bin2hex(random_bytes(12));
    try {
     if(file_put_contents($tmp,json_encode($row,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),LOCK_EX)===false)
      throw new RuntimeException('Cannot write recovered activation.');
     @chmod($tmp,0600);
     if(!rename($tmp,$file))throw new RuntimeException('Cannot publish recovered activation.');
    } catch(Throwable $e){
     @unlink($tmp);
     if(!file_exists($file))rename($backup,$file);
     throw $e;
    }
    return ['game'=>$id,'version'=>$version,'recovered'=>true,'phone'=>$receipt['phone']!==null];
   }
   throw new DomainException('Game activation is healthy; use normal version switching.',409);
  }finally{flock($lock,LOCK_UN);fclose($lock);}
 }
 /** Game packages are readable only via explicitly published asset routes. */
 public static function file(string $id,string $version,string $path,?string $root=null,bool $sessionPinned=false):array {
  $act=self::active($id,$root);
  if((!$act||$act['version']!==GamePackageInstaller::version($version))&&!$sessionPinned)
   throw new DomainException('Game version is not active.',404);
  self::receipt($id,$version,$root);
  $dir=GamePackageInstaller::base($root).'/'.$id.'/versions/'.$version;
  if(!is_string($path)||!preg_match('~^[a-zA-Z0-9][a-zA-Z0-9_./-]*\.(?:html|js|css|json|png|jpg|jpeg|webp|mp3|m4a|ogg|wav|webm|woff2)$~D',$path)
    ||str_contains($path,'..')||str_contains($path,'//')||str_contains($path,'/./')||count(explode('/',$path))>4)
    throw new DomainException('Unsafe game asset name.',404);
  $part=$dir;
  foreach(explode('/',$path) as $component){$part.='/'.$component;if(is_link($part))throw new DomainException('Unsafe game asset.',403);}
  if(!is_file($part))throw new DomainException('Game asset unavailable.',404);
  $size=filesize($part);
  if($size===false||$size>12*1024*1024)throw new DomainException('Game asset invalid.',422);
  return ['path'=>$part,'bytes'=>$size,'type'=>match(strtolower(pathinfo($path,PATHINFO_EXTENSION))){
    'html'=>'text/html; charset=utf-8','js'=>'text/javascript; charset=utf-8','css'=>'text/css; charset=utf-8',
    'json'=>'application/json','png'=>'image/png','jpg','jpeg'=>'image/jpeg','webp'=>'image/webp',
    'mp3'=>'audio/mpeg','m4a'=>'audio/mp4','ogg'=>'audio/ogg','wav'=>'audio/wav',
    'webm'=>'video/webm','woff2'=>'font/woff2',default=>'application/octet-stream'
  }];
 }
}
