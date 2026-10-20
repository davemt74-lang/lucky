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
 public static function all(?string $root=null):array {
  $ids=[];
  foreach(GamePackageInstaller::listing($root) as $row)
   if($row['id']!=='spin-wheel')$ids[$row['id']]=true;
  $result=[];
  foreach(array_keys($ids) as $id){
   $act=self::active($id,$root);
   if($act)$result[$id]=['id'=>$id,'version'=>$act['version'],
     'title'=>$act['receipt']['title'],'phone'=>$act['receipt']['phone']!==null,
     'publisher'=>$act['receipt']['publisher']];
  }
  return $result;
 }
 /** Game packages are readable only via explicitly published asset routes. */
 public static function file(string $id,string $version,string $path,?string $root=null):array {
  $act=self::active($id,$root);
  if(!$act||$act['version']!==GamePackageInstaller::version($version))
   throw new DomainException('Game version is not active.',404);
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
