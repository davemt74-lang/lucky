<?php
declare(strict_types=1);

/** Read-only release identity. Missing build metadata never invents a commit. */
final class BuildVersion {
 private static function readJson(string $path):?array {
  if(!is_file($path)||!is_readable($path))return null;
  $raw=file_get_contents($path,false,null,0,8193);
  if($raw===false||strlen($raw)>8192)return null;
  try{$value=json_decode($raw,true,16,JSON_THROW_ON_ERROR);}
  catch(JsonException){return null;}
  return is_array($value)&&!array_is_list($value)?$value:null;
 }
 /** @return array{version:?string,commit:?string,tree:?string} */
 public static function info(?string $root=null):array {
  $root??=dirname(__DIR__);
  $release=self::readJson($root.'/release.json');
  $version=$release['version']??null;
  if(!is_string($version)||!preg_match('/^V[0-9A-Za-z]{1,31}$/D',$version))
   return ['version'=>null,'commit'=>null,'tree'=>null];
  $out=['version'=>$version,'commit'=>null,'tree'=>null];
  $build=self::readJson($root.'/build-info.json');
  if(($build['version']??null)!==$version)return $out;
  foreach(['commit','tree'] as $key)
   if(!is_string($build[$key]??null)||!preg_match('/^[a-f0-9]{40}$/D',$build[$key]))return $out;
  $out['commit']=$build['commit'];$out['tree']=$build['tree'];
  return $out;
 }
 public static function label(?string $root=null):string {
  $info=self::info($root);
  if($info['version']===null)return 'Version unavailable';
  return $info['version'].' · '.($info['commit']===null?'source build':substr($info['commit'],0,7));
 }
}
