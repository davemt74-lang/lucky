<?php
declare(strict_types=1);

/**
 * Private, verified, no-replace publication of first-install configuration.
 * Filesystem operations are overridable only for isolated behavioral tests;
 * production callers use this implementation and have no fault-injection flags.
 */
class SetupConfiguration {
 private const LOCK_SECONDS=5;
 private const ATTEMPT_FILE='.setup-attempt.json';
 private static array $heldLocks=[];
 private string $storage;
 private string $lockGeneration;
 private string $attemptToken='';
 private string $hmac='';
 private string $stage='';
 private string $contents='';
 /** @var array<string,array> Only exact files created/adopted by this attempt. */
 private array $owned=[];

 protected function __construct(string $storage){
  $this->storage=$storage;$this->lockGeneration=self::$heldLocks[$storage]??'';
 }

 /** Resolve a legitimate persistent storage mount/junction, not individual files. */
 private static function directory(string $storage):string {
  $path=realpath($storage);
  if(!is_string($path)||!is_dir($path))throw new RuntimeException('Installation storage is unavailable. Restore its directory and retry.');
  return rtrim($path,'/\\');
 }
 private static function entry(string $path):array|false {clearstatcache(true,$path);return @lstat($path);}
 private static function regular(string $path,array $stat):bool {
  return ($stat['mode']&0170000)===0100000&&!is_link($path);
 }
 private static function sameFile(array $a,array $b):bool {
  return $a['dev']===$b['dev']&&$a['ino']===$b['ino'];
 }
 /** Includes corrupt files, directories, special files and dangling symlinks. */
 public static function exists(string $storage):bool {return self::entry(self::directory($storage).'/config.php')!==false;}

 /** Serialize all cooperating setup paths, with a finite wait and no secret data. */
 public static function withLock(string $storage,callable $operation):mixed {
  $directory=self::directory($storage);
  if(isset(self::$heldLocks[$directory]))throw new LogicException('Installation lock is already held in this process.');
  $path=$directory.'/installer.lock';$new=false;$handle=false;
  $mask=umask(0077);
  try{$handle=@fopen($path,'x+b');$new=is_resource($handle);}
  finally{umask($mask);}
  if(!$new){
   $stat=self::entry($path);
   if($stat===false||!self::regular($path,$stat))throw new RuntimeException('Installation lock is unavailable or unsafe.');
   $handle=@fopen($path,'r+b');
  }
  if(!is_resource($handle))throw new RuntimeException('Cannot open the installation lock. Check storage permissions.');
  $locked=false;
  try{
   $opened=fstat($handle);$stat=self::entry($path);
   if(!$opened||!$stat||!self::regular($path,$stat)||!self::sameFile($opened,$stat))
    throw new RuntimeException('Installation lock changed while opening it.');
   if($new&&!@chmod($path,0600))throw new RuntimeException('Cannot protect the installation lock.');
   $deadline=hrtime(true)+self::LOCK_SECONDS*1000000000;
   do{
    $wouldBlock=0;
    if(flock($handle,LOCK_EX|LOCK_NB,$wouldBlock)){$locked=true;break;}
    if(!$wouldBlock)throw new RuntimeException('Installation locking is unsupported by this storage.');
    if(hrtime(true)>=$deadline)throw new RuntimeException('Another setup is running. Wait a few seconds and retry.');
    usleep(50000);
   }while(true);
   self::$heldLocks[$directory]=bin2hex(random_bytes(16));
   return $operation();
  }finally{
   unset(self::$heldLocks[$directory]);
   if($locked)flock($handle,LOCK_UN);
   fclose($handle);
  }
 }
 private function assertLocked():void {
  if($this->lockGeneration===''||!isset(self::$heldLocks[$this->storage])||
     !hash_equals($this->lockGeneration,self::$heldLocks[$this->storage]))
   throw new LogicException('Configuration publication and cleanup require their original installation lock.');
 }
 private function assertTargetAbsent():void {
  if(self::entry($this->storage.'/config.php')!==false)
   throw new RuntimeException('Configuration already exists. It was not replaced; restore a backup if it is damaged.');
 }

 /** The fixed application configuration is serialized without executing PHP. */
 public static function settings(array $settings):array {
  $keys=['deployment','db_host','db_port','db_name','db_user','db_pass'];
  if(array_diff(array_keys($settings),$keys))throw new InvalidArgumentException('Unknown installation configuration field.');
  $deployment=$settings['deployment']??'cloud';
  if(!is_string($deployment)||!in_array($deployment,['cloud','homeserver'],true))throw new InvalidArgumentException('Invalid deployment mode.');
  foreach(['db_host','db_name','db_user','db_pass'] as $key)
   if(!isset($settings[$key])||!is_string($settings[$key])||str_contains($settings[$key],"\0"))
    throw new InvalidArgumentException('Invalid installation configuration field.');
  if(!preg_match('/^[A-Za-z0-9._-]{1,150}$/D',$settings['db_host'])&&$settings['db_host']!=='::1')
   throw new InvalidArgumentException('Invalid database host.');
  if(!is_int($settings['db_port']??null)||$settings['db_port']<1||$settings['db_port']>65535||
     !preg_match('/^[A-Za-z0-9_]{1,64}$/D',$settings['db_name'])||
     !preg_match('/^[A-Za-z0-9_@.%-]{1,100}$/D',$settings['db_user'])||strlen($settings['db_pass'])>4096)
   throw new InvalidArgumentException('Invalid database configuration.');
  if($deployment==='homeserver'&&!in_array(strtolower($settings['db_host']),['127.0.0.1','localhost','::1'],true))
   throw new InvalidArgumentException('HomeServer database must remain local.');
  return ['deployment'=>$deployment,'db_host'=>$settings['db_host'],'db_port'=>$settings['db_port'],
   'db_name'=>$settings['db_name'],'db_user'=>$settings['db_user'],'db_pass'=>$settings['db_pass']];
 }

 /** Prepare before DB setup DML; retain only a bound, private attempt identity. */
 public static function prepare(string $storage,array $settings):static {
  $self=new static(self::directory($storage));
  $self->assertLocked();$self->assertTargetAbsent();
  $self->contents='<?php return '.var_export(self::settings($settings),true).';';
  try{
   $self->loadAttempt();
   $self->stage=$self->storage.'/.setup-config-'.$self->attemptToken.'.tmp';
   // A process killed during the preceding attempt can leave only this exact
   // protocol-owned stage. Never sweep arbitrary temporary files or user media.
   if(($stat=self::entry($self->stage))!==false){
    $self->assertPrivate($self->stage,$stat);
    $self->owned[$self->stage]=$stat;
    $self->removeOwned($self->stage);
   }
   $self->writePrivate($self->stage,$self->contents);
   return $self;
  }catch(Throwable $error){
   try{$self->discard();}catch(Throwable $cleanup){error_log('Lucky setup: private staging cleanup could not be completed.');}
   throw new RuntimeException('Could not prepare private installation configuration. Preserve the recovery identity and retry with the same database settings after checking storage permissions.',0,$error);
  }
 }
 private function loadAttempt():void {
  $path=$this->storage.'/'.self::ATTEMPT_FILE;
  $stat=self::entry($path);
  if($stat!==false){
   $this->assertPrivate($path,$stat);
   $bytes=$this->readFile($path,2049);
   if(!is_string($bytes)||strlen($bytes)>2048)throw new RuntimeException('Installation recovery record is unreadable. Restore it from backup.');
   try{$record=json_decode($bytes,true,8,JSON_THROW_ON_ERROR);}
   catch(Throwable $error){throw new RuntimeException('Installation recovery record is invalid. Restore it from backup.',0,$error);}
   if(!is_array($record)||count($record)!==3||($record['version']??null)!==1||
      !is_string($record['token']??null)||!preg_match('/^[a-f0-9]{64}$/D',$record['token'])||
      !is_string($record['config_hmac']??null)||!preg_match('/^[a-f0-9]{64}$/D',$record['config_hmac']))
    throw new RuntimeException('Installation recovery record is invalid. Restore it from backup.');
   $this->attemptToken=$record['token'];
   $this->hmac=hash_hmac('sha256',$this->contents,$this->attemptToken);
   if(!hash_equals($record['config_hmac'],$this->hmac))
    throw new RuntimeException('A pending installation uses different database settings. Resume it with the same settings; no data was reset.');
   return;
  }
  $this->attemptToken=bin2hex(random_bytes(32));
  $this->hmac=hash_hmac('sha256',$this->contents,$this->attemptToken);
  $bytes=json_encode(['version'=>1,'token'=>$this->attemptToken,'config_hmac'=>$this->hmac],JSON_THROW_ON_ERROR)."\n";
  $temporary=$this->storage.'/.setup-attempt-'.bin2hex(random_bytes(16)).'.tmp';
  $this->writePrivate($temporary,$bytes);
  if(!$this->createLink($temporary,$path))
   throw new RuntimeException('Cannot publish the private recovery record without replacing existing files. Check storage hard-link support and permissions.');
  $this->assertLinked($temporary,$path);
  $this->removeOwned($temporary);
 }
 public function token():string {return $this->attemptToken;}
 public function configHmac():string {return $this->hmac;}

 /** Called only after durable DB setup; link fails atomically if target exists. */
 public function publish():void {
  $this->assertLocked();
  try{
  $this->assertTargetAbsent();
  if($this->stage===''||!isset($this->owned[$this->stage]))throw new LogicException('Configuration has not been prepared.');
  $this->assertOwned($this->stage);
  if($this->readFile($this->stage,strlen($this->contents)+1)!==$this->contents)
   throw new RuntimeException('Prepared configuration changed; it was not activated.');
  $target=$this->storage.'/config.php';
  if(!$this->createLink($this->stage,$target))
   throw new RuntimeException('Could not activate configuration without replacing an existing path. Setup data is saved; correct storage permissions and retry with the same settings.');
  $this->assertLinked($this->stage,$target);
  // Once the no-replace link succeeds the valid target belongs to the installed
  // application, never to cleanup. A later failure must not remove it.
  $this->removeOwned($this->stage);
  }catch(Throwable $error){
   throw new RuntimeException('Configuration publication could not be confirmed. Preserve the saved setup data and recovery identity. No existing configuration was removed or replaced.',0,$error);
  }
 }
 /** Remove only this object's exact stage identities, never the active config. */
 public function discard():void {
  if($this->owned!==[])$this->assertLocked();
  foreach(array_keys($this->owned) as $path)$this->removeOwned($path);
 }
 public function __destruct(){
  try{$this->discard();}catch(Throwable $error){error_log('Lucky setup: a private temporary file requires manual cleanup.');}
 }
 private function assertPrivate(string $path,array $stat):void {
  if(!self::regular($path,$stat))throw new RuntimeException('Unsafe installation recovery file. No existing file was replaced.');
  if(PHP_OS_FAMILY!=='Windows'){
   if(($stat['mode']&0777)!==0600)throw new RuntimeException('Installation recovery files require owner-only permissions.');
   if(function_exists('posix_geteuid')&&(int)$stat['uid']!==posix_geteuid())
    throw new RuntimeException('Installation recovery file belongs to another account.');
  }
 }
 private function assertOwned(string $path,bool $private=true):array {
  $stat=self::entry($path);
  if($stat===false||!isset($this->owned[$path])||!self::sameFile($stat,$this->owned[$path]))
   throw new RuntimeException('Installation temporary file changed identity.');
  if($private)$this->assertPrivate($path,$stat);
  elseif(!self::regular($path,$stat))throw new RuntimeException('Installation temporary file is no longer a regular file.');
  return $stat;
 }
 private function assertLinked(string $source,string $target):void {
  $sourceStat=$this->assertOwned($source);$targetStat=self::entry($target);
  if($targetStat===false||!self::regular($target,$targetStat)||!self::sameFile($sourceStat,$targetStat))
   throw new RuntimeException('Published configuration identity could not be verified. No existing target will be removed.');
 }
 private function removeOwned(string $path):void {
  if(!isset($this->owned[$path]))return;
  if(self::entry($path)===false){unset($this->owned[$path]);return;}
  $this->assertOwned($path,false);
  if(!@unlink($path))throw new RuntimeException('Cannot remove this installation attempt\'s private temporary file.');
  unset($this->owned[$path]);
 }
 private function writePrivate(string $path,string $contents):void {
  $mask=umask(0077);
  try{$handle=@fopen($path,'x+b');}finally{umask($mask);}
  if(!is_resource($handle))throw new RuntimeException('Cannot create a private installation staging file.');
  $opened=fstat($handle);
  if(!$opened){fclose($handle);throw new RuntimeException('Cannot inspect the installation staging file.');}
  $this->owned[$path]=$opened;
  try{
   if(!$this->restrictPermissions($path))throw new RuntimeException('Cannot restrict installation staging permissions.');
   $this->assertOwned($path);
   $offset=0;$length=strlen($contents);
   while($offset<$length){
    $written=$this->writeChunk($handle,substr($contents,$offset));
    if(!is_int($written)||$written<1||$written>$length-$offset)
     throw new RuntimeException('Incomplete installation configuration write. Nothing was activated.');
    $offset+=$written;
   }
   if(!$this->flushStream($handle)||!$this->syncStream($handle))
    throw new RuntimeException('Cannot flush the installation configuration. Nothing was activated.');
   $completed=fstat($handle);
   if(!$completed||!self::sameFile($opened,$completed)||(int)$completed['size']!==$length)
    throw new RuntimeException('Incomplete installation configuration write. Nothing was activated.');
  }finally{fclose($handle);}
  $this->assertOwned($path);
  if($this->readFile($path,strlen($contents)+1)!==$contents)
   throw new RuntimeException('Installation configuration verification failed. Nothing was activated.');
 }
 protected function writeChunk($stream,string $contents):int|false {return @fwrite($stream,$contents);}
 protected function flushStream($stream):bool {return @fflush($stream);}
 protected function syncStream($stream):bool {return !function_exists('fsync')||@fsync($stream);}
 protected function readFile(string $path,int $limit):string|false {return @file_get_contents($path,false,null,0,$limit);}
 protected function createLink(string $source,string $destination):bool {return @link($source,$destination);}
 protected function restrictPermissions(string $path):bool {return @chmod($path,0600);}
}
