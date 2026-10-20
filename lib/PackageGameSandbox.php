<?php
declare(strict_types=1);
require_once __DIR__.'/GamePackageActivation.php';

/**
 * Build a self-contained isolated game document. No server-side package code,
 * no external URLs, no cookies, and no remote network access in the sandbox.
 */
final class PackageGameSandbox {
 public static function document(string $game,string $version,string $role,?string $root=null):string {
  if(!in_array($role,['host','phone'],true))throw new DomainException('Invalid game view.',400);
  $receipt=GamePackageActivation::receipt($game,$version,$root);
  $entry=$role==='host'?$receipt['host']:$receipt['phone'];
  if(!is_string($entry)||$entry==='')throw new DomainException('Game has no phone controller.',404);
  $file=GamePackageActivation::file($game,$version,$entry,$root,true);
  $raw=file_get_contents($file['path']);
  if(!is_string($raw)||strlen($raw)>1048576)throw new DomainException('Game HTML must be under 1 MB.',422);
  // HTML can contain its own inline game code; browser CSP+iframe sandbox
  // constrains capabilities. Static module JS/CSS are embedded inline.
  $base=GamePackageInstaller::base($root).'/'.$game.'/versions/'.$version;
  $assets='';$size=0;$localScripts=[];$localStyles=[];
  $hashes=$receipt['fileHashes']??null;
  // Packages staged before V5Q.23B have no stored hash map, but remain
  // readable; newer ones must validate every asset against its signed or
  // manually approved manifest even after a HomeServer restart.
  if($hashes!==null&&!is_array($hashes))throw new DomainException('Invalid package asset manifest.',409);
  $verify=function(string $assetPath,string $bytes)use($hashes):void {
   if($hashes===null)return; // legacy staged package
   $expected=$hashes[$assetPath]??null;
   if(!is_string($expected)||!preg_match('/^[a-f0-9]{64}$/D',$expected)||
      !hash_equals($expected,hash('sha256',$bytes)))
    throw new DomainException('A game asset failed runtime integrity verification.',409);
  };
  $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS));
  foreach($it as $entryFile){
   if($entryFile->isLink())throw new DomainException('Unsafe package resource.',403);
   $path=substr($entryFile->getPathname(),strlen($base)+1);
   if(!preg_match('/\.(?:js|css)$/D',$path))continue;
   $resource=GamePackageActivation::file($game,$version,str_replace(DIRECTORY_SEPARATOR,'/',$path),$root,true);
   $bytes=file_get_contents($resource['path']);
   if(!is_string($bytes))throw new DomainException('Game resource unreadable.',422);
   $verify(str_replace(DIRECTORY_SEPARATOR,'/',$path),$bytes);
   $size+=strlen($bytes);
   if($size>2*1024*1024)throw new DomainException('Game scripts and styles exceed 2 MB sandbox budget.',422);
   $safe=str_replace(DIRECTORY_SEPARATOR,'/',$path);
   if(str_ends_with($path,'.css'))$localStyles[$safe]='<style>'.str_ireplace('</style','<\\/style',$bytes).'</style>';
   else $localScripts[$safe]='<script>'.str_ireplace('</script','<\\/script',$bytes).'</script>';
  }
  // Local JS/CSS references are expanded in document order; remote sources
  // stay forbidden. Unreferenced legacy assets still load at the end.
  $used=[];
  $raw=preg_replace_callback('~<script\s+src=["\x27]([^"\x27]+)["\x27]\s*>\s*</script\s*>~i',
    static function(array $m)use(&$used,$localScripts):string {
     if(!isset($localScripts[$m[1]]))return '';
     $used[$m[1]]=true;return $localScripts[$m[1]];
    },$raw);
  $raw=preg_replace_callback('~<link\s+rel=["\x27]stylesheet["\x27]\s+href=["\x27]([^"\x27]+)["\x27]\s*/?>~i',
    static function(array $m)use(&$used,$localStyles):string {
     if(!isset($localStyles[$m[1]]))return '';
     $used[$m[1]]=true;return $localStyles[$m[1]];
    },$raw);
  $raw=preg_replace('~<script\\b(?=[^>]*\\bsrc\\s*=)[^>]*>\\s*</script\\s*>~is','',$raw);
  $raw=preg_replace('~<link\b(?=[^>]*\brel\s*=\s*["\x27]?stylesheet)[^>]*>~is','',$raw);
  foreach($localStyles as $asset=>$style)if(!isset($used[$asset]))$assets.=$style;
  foreach($localScripts as $asset=>$script)if(!isset($used[$asset]))$assets.=$script;
  $bridge='<script>document.addEventListener("click",function(e){const b=e.target.closest("[data-game-input]");if(!b)return;parent.postMessage({type:"lucky:input",name:b.dataset.gameInput,value:b.dataset.gameValue||null},"*");});</script>';
  $inject=$assets.$bridge;
  $document=stripos($raw,'</body>')!==false
   ?preg_replace('~</body>~i',$inject.'</body>',$raw,1)
   :'<!doctype html><html><body>'.$raw.$inject.'</body></html>';
  if(!is_string($document))throw new DomainException('Invalid game document.',422);
  $embedded=[];$totalMedia=0;
  // Explicit offline references work in HTML src/poster, CSS url(), and JS
  // string literals. Media is transformed to data: URIs: an opaque-origin
  // iframe has no access to the HomeServer's session cookies or a URL loader.
  $document=preg_replace_callback(
   '~lucky-asset:([A-Za-z0-9][A-Za-z0-9_./-]{0,155}\\.(?:png|jpg|jpeg|webp|mp3|m4a|ogg|wav|webm|woff2))~',
   function(array $found)use($game,$version,$root,$verify,&$embedded,&$totalMedia):string {
    $path=$found[1];
    if(isset($embedded[$path]))return $embedded[$path];
    $file=GamePackageActivation::file($game,$version,$path,$root,true);
    $raw=file_get_contents($file['path']);
    if(!is_string($raw)||strlen($raw)!==$file['bytes'])
     throw new DomainException('Unable to read offline media asset.',422);
    $verify($path,$raw);
    $totalMedia+=strlen($raw);
    if($totalMedia>24*1024*1024)
     throw new DomainException('This game view exceeds the 24 MB offline media budget.',413);
    return $embedded[$path]='data:'.explode(';',$file['type'])[0].';base64,'.base64_encode($raw);
   },$document);
  if(!is_string($document)||str_contains($document,'lucky-asset:'))
   throw new DomainException('Game contains an invalid or missing offline media reference.',422);
  return $document;
 }
}
