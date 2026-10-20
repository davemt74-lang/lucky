<?php
declare(strict_types=1);
require_once __DIR__.'/GameCatalog.php';
require_once __DIR__.'/CloudGameUpdates.php';

/**
 * Offline, non-executing game package staging. No extracted file is web-served
 * or dynamically included. Later runtime certification governs activation.
 * The installer never writes in the application, module or database folders.
 */
final class GamePackageInstaller {
 private const MAX_ZIP=40*1024*1024;
 private const MAX_FILE=12*1024*1024;
 private const MAX_TOTAL=90*1024*1024;
 private const MAX_ENTRIES=150;
 private const FILE_EXT=['html','js','css','json','png','jpg','jpeg','webp','mp3','m4a','ogg','wav','webm','woff2'];
 public static function base(?string $root=null):string {
  if($root!==null)return $root;
  // Allow the certification harness to exercise the actual registry without
  // touching a user's installation. Ignored outside explicit test processes.
  if(defined('LUCKY_TESTING')&&LUCKY_TESTING===true){
   $test=getenv('LUCKY_TEST_GAME_PACKAGES');
   if(is_string($test)&&str_starts_with($test,sys_get_temp_dir().DIRECTORY_SEPARATOR))
    return $test;
  }
  return dirname(__DIR__).'/storage/game-packages';
 }
 private static function ensureDir(string $path):void {
  if(is_link($path))throw new DomainException('Package storage is unsafe.',403);
  if(!is_dir($path)&&!mkdir($path,0700,true)&&!is_dir($path))
   throw new RuntimeException('Unable to prepare game package storage.');
  if(is_link($path))throw new DomainException('Package storage is unsafe.',403);
 }
 private static function safeFile(mixed $path):string {
  if(!is_string($path)||strlen($path)>160||
   !preg_match('~^[a-zA-Z0-9][a-zA-Z0-9_./-]*\.(?:html|js|css|json|png|jpg|jpeg|webp|mp3|m4a|ogg|wav|webm|woff2)$~D',$path)||
   str_contains($path,'..')||str_contains($path,'//')||str_contains($path,'/./')||
   count(explode('/',$path))>4)
   throw new DomainException('Unsafe or unsupported game asset path.',422);
  return $path;
 }
 public static function version(mixed $value):string {
  if(!is_string($value)||!preg_match('/^(0|[1-9][0-9]{0,4})\.(0|[1-9][0-9]{0,4})\.(0|[1-9][0-9]{0,4})$/D',$value))
   throw new DomainException('Package version must be major.minor.patch.',422);
  return $value;
 }
 public static function validateManifest(mixed $data):array {
  if(!is_array($data)||($data['schema']??null)!=='lucky.game-package.v1')
   throw new DomainException('Unsupported Lucky game package format.',422);
  $id=GameCatalog::slug($data['id']??null);
  $version=self::version($data['version']??null);
  $title=$data['title']??null;$publisher=$data['publisher']??null;
  if(!is_string($title)||trim($title)===''||strlen($title)>120||
   !is_string($publisher)||trim($publisher)===''||strlen($publisher)>160)
   throw new DomainException('Package title and publisher are required.',422);
  $host=self::safeFile($data['host']??null);
  if(!str_ends_with($host,'.html'))throw new DomainException('Host entry must be HTML.',422);
  $phone=$data['phone']??null;
  if($phone!==null){$phone=self::safeFile($phone);if(!str_ends_with($phone,'.html'))throw new DomainException('Phone entry must be HTML.',422);}
  $files=$data['files']??null;
  if(!is_array($files)||count($files)<1||count($files)>self::MAX_ENTRIES-2||array_is_list($files))
   throw new DomainException('Package must specify its asset hashes.',422);
  $normalized=[];$seen=[];
  foreach($files as $path=>$hash){
   $path=self::safeFile($path);
   if(!is_string($hash)||!preg_match('/^[a-f0-9]{64}$/D',$hash))
    throw new DomainException('Every asset requires a SHA-256 digest.',422);
   $lower=strtolower($path);
   if(isset($seen[$lower]))throw new DomainException('Duplicate case-insensitive asset path.',422);
   $seen[$lower]=true;$normalized[$path]=$hash;
  }
  if(!isset($normalized[$host])||($phone!==null&&!isset($normalized[$phone])))
   throw new DomainException('Game entry points are missing from asset manifest.',422);
  return ['schema'=>'lucky.game-package.v1','id'=>$id,'version'=>$version,
   'title'=>trim($title),'publisher'=>trim($publisher),
   'host'=>$host,'phone'=>$phone,'files'=>$normalized];
 }
 private static function archive(string $path):ZipArchive {
  if(!class_exists(ZipArchive::class))throw new DomainException('ZIP support is missing from the HomeServer PHP runtime.',503);
  if(!is_file($path)||is_link($path)||filesize($path)<50||filesize($path)>self::MAX_ZIP)
   throw new DomainException('Choose a game package ZIP of 40 MB or less.',413);
  $zip=new ZipArchive();
  if($zip->open($path,ZipArchive::RDONLY)!==true)throw new DomainException('Unable to read game package ZIP.',422);
  return $zip;
 }
 private static function entries(ZipArchive $zip):array {
  if($zip->numFiles<2||$zip->numFiles>self::MAX_ENTRIES)
   throw new DomainException('Game ZIP contains too many or too few files.',422);
  $entries=[];$names=[];$total=0;
  for($i=0;$i<$zip->numFiles;$i++){
   $stat=$zip->statIndex($i);
   if(!$stat)throw new DomainException('Unable to inspect game ZIP entry.',422);
   $name=$stat['name']??'';
   if($name==='manifest.json'||$name==='manifest.sig'){}
   else self::safeFile($name);
   $lower=strtolower($name);
   if(isset($names[$lower]))throw new DomainException('Duplicate ZIP file path.',422);
   $names[$lower]=true;
   if(str_ends_with($name,'/'))throw new DomainException('Game ZIP must contain files, not explicit folders.',422);
   $ext=pathinfo($name,PATHINFO_EXTENSION);
   if($name!=='manifest.json'&&$name!=='manifest.sig'&&!in_array(strtolower($ext),self::FILE_EXT,true))
    throw new DomainException('Unsupported package file.',422);
   $size=$stat['size']??0;$compressed=$stat['comp_size']??0;
   if(!is_int($size)||$size<0||$size>self::MAX_FILE||!is_int($compressed)||
      ($size>1048576&&$compressed<($size/250)))
    throw new DomainException('Package entry exceeds safe decompression limits.',422);
   $total+=$size;
   if($total>self::MAX_TOTAL)throw new DomainException('Game ZIP expands beyond 90 MB.',422);
   // Unix archives can mark entries as symlinks, even with innocent names.
   $opsys=0;$attributes=0;
   if($zip->getExternalAttributesIndex($i,$opsys,$attributes)&&$opsys===ZipArchive::OPSYS_UNIX){
    $type=(($attributes>>16)&0170000);
    if($type===0120000||$type===0060000)throw new DomainException('Links and device nodes are forbidden in game packages.',422);
   }
   $entries[$name]=['index'=>$i,'size'=>$size];
  }
  if(!isset($entries['manifest.json']))throw new DomainException('Game ZIP lacks manifest.json.',422);
  return $entries;
 }
 private static function contents(ZipArchive $zip,int $index,int $limit):string {
  $stream=$zip->getStreamIndex($index);
  if(!$stream)throw new DomainException('Cannot read game package entry.',422);
  $bytes=stream_get_contents($stream,$limit+1);fclose($stream);
  if(!is_string($bytes)||strlen($bytes)>$limit)throw new DomainException('Game package entry too large.',422);
  return $bytes;
 }
 private static function verifySignature(ZipArchive $zip,array $entries,string $raw,?string $publicKey):bool {
  if(!isset($entries['manifest.sig']))return false;
  if(!$publicKey)throw new DomainException('Signed packages require a configured publisher signing key.',409);
  $signature=base64_decode(self::contents($zip,$entries['manifest.sig']['index'],8192),true);
  if($signature===false||strlen($signature)<48||
     openssl_verify($raw,$signature,$publicKey,OPENSSL_ALGO_SHA256)!==1)
   throw new DomainException('Game package publisher signature failed.',403);
  return true;
 }
 /**
  * Locally uploaded bundles may be staged unsigned, but no staged game runs.
  * Signed bundles must verify against the owner's explicitly pinned publisher.
  */
 public static function install(string $upload,?string $root=null,?string $publisherKey=null,
  ?string $expectedGame=null,?string $expectedVersion=null):array {
  $zip=self::archive($upload);
  try{
   $entries=self::entries($zip);
   $raw=self::contents($zip,$entries['manifest.json']['index'],131072);
   $data=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
   $manifest=self::validateManifest($data);
   $id=$manifest['id'];$version=$manifest['version'];
   // Reject a substituted archive before any private version folder is created.
   if(($expectedGame!==null&&$id!==$expectedGame)||($expectedVersion!==null&&$version!==$expectedVersion))
    throw new DomainException('Game package identity does not match the selected release.',403);
   $builtIn=GameRegistry::isBuiltIn($id);
   $expected=['manifest.json'=>true];
   if(isset($entries['manifest.sig']))$expected['manifest.sig']=true;
   foreach($manifest['files'] as $file=>$hash)$expected[$file]=true;
   if(count($expected)!==count($entries)||array_diff_key($expected,$entries))
    throw new DomainException('Game ZIP content does not match its file manifest.',422);
   $verified=self::verifySignature($zip,$entries,$raw,$publisherKey);
   // Built-in games may receive signed *staged* updates, but can never be
   // replaced or deleted by uploaded ZIPs. Runtime activation is separate.
   if($builtIn&&!$verified)
    throw new DomainException('Updates to built-in games require a pinned publisher signature.',403);
   $base=self::base($root);self::ensureDir($base);
   $lock=fopen($base.'/.lock','c');
   if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Cannot lock game package installation.');
   try{
    $gameDir=$base.'/'.$id;self::ensureDir($gameDir);
    $versions=$gameDir.'/versions';self::ensureDir($versions);
    $target=$versions.'/'.$version;$digest=hash('sha256',$raw);
    if(is_dir($target)){
     if(is_link($target))throw new DomainException('Unsafe installed game version.',403);
     $prior=self::receipt($target);
     if($prior['manifestSha256']!==$digest)
      throw new DomainException('Package version already exists with different content.',409);
     return $prior;
    }
    $stage=$gameDir.'/.import-'.bin2hex(random_bytes(12));
    self::ensureDir($stage);
    try{
     foreach($manifest['files'] as $file=>$expectedHash){
      $parts=explode('/',$file);array_pop($parts);
      $dir=$stage;
      foreach($parts as $part){$dir.='/'.$part;self::ensureDir($dir);}
      $stream=$zip->getStreamIndex($entries[$file]['index']);
      if(!$stream)throw new DomainException('Game ZIP asset stream failed.',422);
      $dest=$stage.'/'.$file;$out=fopen($dest,'xb');
      if(!$out){fclose($stream);throw new RuntimeException('Unable to stage game file.');}
      $ctx=hash_init('sha256');$actualSize=0;
      try{
       while(!feof($stream)){
        $bytes=fread($stream,65536);
        if($bytes===false)throw new DomainException('Failed to read ZIP contents.',422);
        $actualSize+=strlen($bytes);
        if($actualSize>self::MAX_FILE)throw new DomainException('Game asset is too large.',422);
        hash_update($ctx,$bytes);
        if($bytes!==''&&fwrite($out,$bytes)!==strlen($bytes))throw new RuntimeException('Unable to write game asset.');
       }
      }finally{fclose($stream);fclose($out);}
      if($actualSize!==$entries[$file]['size']||!hash_equals($expectedHash,hash_final($ctx)))
       throw new DomainException('Package asset checksum mismatch.',422);
      @chmod($dest,0600);
     }
     $receipt=['id'=>$id,'version'=>$version,'title'=>$manifest['title'],
      'publisher'=>$manifest['publisher'],'host'=>$manifest['host'],
      'phone'=>$manifest['phone'],'manifestSha256'=>$digest,'signatureVerified'=>$verified,
      'builtIn'=>$builtIn,
      'status'=>'staged','fileCount'=>count($manifest['files']),
      'installedAt'=>gmdate('Y-m-d\TH:i:s\Z')];
     $json=json_encode($receipt,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
     if(file_put_contents($stage.'/receipt.json',$json,LOCK_EX)===false)throw new RuntimeException('Cannot write game installation receipt.');
     @chmod($stage.'/receipt.json',0600);
     if(!rename($stage,$target))throw new RuntimeException('Unable to atomically publish staged game version.');
    }finally{if(is_dir($stage))self::erase($stage);}
    return $receipt;
   }finally{flock($lock,LOCK_UN);fclose($lock);}
  }finally{$zip->close();}
 }
 private static function erase(string $root):void {
  if(is_link($root))throw new RuntimeException('Refusing to erase a linked game directory.');
  foreach(new FilesystemIterator($root) as $file){
   if($file->isLink())throw new RuntimeException('Refusing to erase a linked game file.');
   if($file->isDir())self::erase($file->getPathname());
   else unlink($file->getPathname());
  }
  rmdir($root);
 }
 private static function receipt(string $directory):array {
  $path=$directory.'/receipt.json';
  if(!is_file($path)||is_link($path))throw new RuntimeException('Damaged game package receipt.');
  $data=json_decode((string)file_get_contents($path),true,8,JSON_THROW_ON_ERROR);
  if(!is_array($data)||($data['status']??null)!=='staged')throw new RuntimeException('Invalid game package receipt.');
  return $data;
 }
 public static function listing(?string $root=null):array {
  $base=self::base($root);if(!is_dir($base))return [];
  $rows=[];
  foreach(new DirectoryIterator($base) as $game){
   if(!$game->isDir()||$game->isLink()||!preg_match('/^[a-z0-9][a-z0-9-]{1,62}$/D',$game->getFilename()))continue;
   $directory=$game->getPathname().'/versions';
   if(!is_dir($directory)||is_link($directory))continue;
   foreach(new DirectoryIterator($directory) as $release)if($release->isDir()&&!$release->isLink()){
    try{
     self::version($release->getFilename());
     $receipt=self::receipt($release->getPathname());
     if(($receipt['id']??null)!==$game->getFilename()||($receipt['version']??null)!==$release->getFilename())continue;
     $rows[]=$receipt;
    }catch(Throwable $e){/* damaged records are never advertised */}
   }
  }
  usort($rows,fn($a,$b)=>strcmp($a['id'],$b['id'])?:version_compare($b['version'],$a['version']));
  return $rows;
 }
}
