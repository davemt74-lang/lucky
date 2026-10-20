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
  $assets='';$size=0;
  $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS));
  foreach($it as $entryFile){
   if($entryFile->isLink())throw new DomainException('Unsafe package resource.',403);
   $path=substr($entryFile->getPathname(),strlen($base)+1);
   if(!preg_match('/\.(?:js|css)$/D',$path))continue;
   $resource=GamePackageActivation::file($game,$version,str_replace(DIRECTORY_SEPARATOR,'/',$path),$root,true);
   $bytes=file_get_contents($resource['path']);
   if(!is_string($bytes))throw new DomainException('Game resource unreadable.',422);
   $size+=strlen($bytes);
   if($size>2*1024*1024)throw new DomainException('Game scripts and styles exceed 2 MB sandbox budget.',422);
   if(str_ends_with($path,'.css'))$assets.='<style>'.str_replace('</style','<\/style',$bytes).'</style>';
   else $assets.='<script>'.str_replace('</script','<\/script',$bytes).'</script>';
  }
  // Remove external scripts and stylesheet links: package resources are
  // embedded above, while all network requests are denied by CSP.
  $raw=preg_replace('~<script\b(?=[^>]*\bsrc\s*=)[^>]*>\s*</script\s*>~is','',$raw);
  $raw=preg_replace('~<link\b(?=[^>]*\brel\s*=\s*["\']?stylesheet)[^>]*>~is','',$raw);
  $bridge='<script>document.addEventListener("click",function(e){const b=e.target.closest("[data-game-input]");if(!b)return;parent.postMessage({type:"lucky:input",name:b.dataset.gameInput,value:b.dataset.gameValue||null},"*");});</script>';
  $inject=$assets.$bridge;
  if(stripos($raw,'</body>')!==false)return preg_replace('~</body>~i',$inject.'</body>',$raw,1);
  return '<!doctype html><html><body>'.$raw.$inject.'</body></html>';
 }
}
