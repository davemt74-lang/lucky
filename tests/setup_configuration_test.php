<?php
declare(strict_types=1);
/** Isolated real-filesystem behavior. No database, live installation or credential fixture is used. */
require_once __DIR__.'/../lib/SetupConfiguration.php';

$checks=0;
function setupConfigCheck(bool $ok,string $label):void {
 global $checks;
 if(!$ok)throw new RuntimeException('FAIL '.$label);
 ++$checks;echo 'PASS '.$label."\n";
}
function setupConfigThrows(callable $run,string $label):Throwable {
 try{$run();}catch(Throwable $error){setupConfigCheck(true,$label);return $error;}
 throw new RuntimeException('FAIL '.$label.' (no exception)');
}
function setupConfigEntries(string $directory):array {
 $names=array_values(array_diff(scandir($directory),['.','..']));sort($names,SORT_STRING);return $names;
}
/** Compare Windows spelling only; never rewrite the path passed to filesystem operations. */
function setupConfigPathKey(string $path):string {
 return PHP_OS_FAMILY==='Windows'?strtolower(str_replace('\\','/',$path)):$path;
}
/** Existing-file identity for read observations; never used to authorize cleanup. */
function setupConfigReadEvidence(string $expected,array $reads):array {
 $expectedReal=realpath($expected);
 $expectedRegular=$expectedReal!==false&&is_file($expected)&&!is_link($expected);
 $observations=[];$matched=false;
 foreach($reads as $read){
  $path=$read['path'];$resolved=realpath($path);
  $regular=$resolved!==false&&is_file($path)&&!is_link($path);
  $canonicalMatch=$expectedRegular&&$regular&&setupConfigPathKey($expectedReal)===setupConfigPathKey($resolved);
  $observations[]=['resolved'=>$resolved!==false,'regular'=>$regular,
   'same_spelling'=>setupConfigPathKey($expected)===setupConfigPathKey($path),'same_canonical_file'=>$canonicalMatch];
  $matched=$matched||$canonicalMatch;
 }
 return ['expected_resolved'=>$expectedReal!==false,'expected_regular'=>$expectedRegular,
  'read_count'=>count($reads),'matched'=>$matched,'observations'=>$observations];
}
function setupConfigRemove(string $path,string $root):void {
 $pathKey=setupConfigPathKey($path);$rootKey=setupConfigPathKey($root);
 if($pathKey!==$rootKey&&!str_starts_with($pathKey,$rootKey.'/'))throw new RuntimeException('Unsafe fixture cleanup.');
 if(is_link($path)||!is_dir($path)){if(file_exists($path)||is_link($path))unlink($path);return;}
 foreach(new DirectoryIterator($path) as $entry)if(!$entry->isDot())setupConfigRemove($entry->getPathname(),$root);
 rmdir($path);
}
function setupConfigSettings():array {
 return ['db_host'=>'127.0.0.1','db_port'=>3306,'db_name'=>'lucky_setup_fixture',
  'db_user'=>'fixture_user','db_pass'=>"config-fixture-secret-'quote'-\\slash-\n-second-line"];
}
function setupConfigPrime(string $directory,array $settings):array {
 return SetupConfiguration::withLock($directory,static function()use($directory,$settings):array{
  $staged=SetupConfiguration::prepare($directory,$settings);
  $identity=['token'=>$staged->token(),'hmac'=>$staged->configHmac()];$staged->discard();return $identity;
 });
}

/** Production filesystem methods are the only seam; actual files/links/locks are still used. */
final class SetupConfigFaults extends SetupConfiguration {
 public static string $fault='';
 public static array $configStreams=[];
 public static array $writes=[];
 public static array $permissions=[];
 public static array $reads=[];
 public static array $links=[];
 public static mixed $race=null;
 public static function reset(string $fault=''):void {
  self::$fault=$fault;self::$configStreams=[];self::$writes=[];self::$permissions=[];self::$reads=[];self::$links=[];self::$race=null;
 }
 private static function configStream($stream,string $data=''):bool {
  $id=(int)$stream;
  if(str_starts_with($data,'<?php'))self::$configStreams[$id]=true;
  return isset(self::$configStreams[$id]);
 }
 protected function writeChunk($stream,string $bytes):int|false {
  if(!self::configStream($stream,$bytes))return parent::writeChunk($stream,$bytes);
  $id=(int)$stream;$stat=fstat($stream);
  self::$writes[]=['stream'=>$id,'mode'=>$stat['mode']&0777,'length'=>strlen($bytes)];
  if(self::$fault==='write-zero')return 0;
  if(self::$fault==='write-false')return false;
  if(self::$fault==='write-overreport')return strlen($bytes)+1;
  if(self::$fault==='partial-then-false'){
   $calls=count(array_filter(self::$writes,static fn(array $call):bool=>$call['stream']===$id));
   return $calls===1?parent::writeChunk($stream,substr($bytes,0,7)):false;
  }
  if(self::$fault==='short-write')return parent::writeChunk($stream,substr($bytes,0,7));
  if(self::$fault==='write-throw')throw new RuntimeException('private-I/O-fault-secret-sentinel');
  return parent::writeChunk($stream,$bytes);
 }
 protected function flushStream($stream):bool {
  if(self::configStream($stream)&&self::$fault==='flush-false')return false;
  return parent::flushStream($stream);
 }
 protected function syncStream($stream):bool {
  if(self::configStream($stream)&&self::$fault==='sync-false')return false;
  return parent::syncStream($stream);
 }
 protected function readFile(string $path,int $limit):string|false {
  $bytes=parent::readFile($path,$limit);
  self::$reads[]=['path'=>$path,'length'=>is_string($bytes)?strlen($bytes):null];
  if(is_string($bytes)&&str_starts_with($bytes,'<?php')){
   if(self::$fault==='read-false')return false;
   if(self::$fault==='read-corrupt')return substr($bytes,0,-1).'!';
  }
  return $bytes;
 }
 protected function createLink(string $source,string $destination):bool {
  self::$links[]=['source'=>$source,'destination'=>$destination,'bytes'=>file_get_contents($source)];
  if(basename($destination)==='.setup-attempt.json'){
   if(self::$fault==='attempt-link-false')return false;
   if(self::$fault==='attempt-link-race'){file_put_contents($destination,'racing-owner-recovery-record');chmod($destination,0600);}
  }
  if(basename($destination)==='config.php'){
   if(is_callable(self::$race))(self::$race)($source,$destination);
   if(self::$fault==='link-false')return false;
   if(self::$fault==='link-throw')throw new RuntimeException('private-I/O-fault-secret-sentinel');
  }
  return parent::createLink($source,$destination);
 }
 protected function restrictPermissions(string $path):bool {
  self::$permissions[]=$path;
  if(self::$fault==='permissions-false')return false;
  if(self::$fault==='permissions-widened'){chmod($path,0644);return true;}
  return parent::restrictPermissions($path);
 }
}

$root=sys_get_temp_dir().'/lucky-config-tests-'.bin2hex(random_bytes(12));
if(!mkdir($root,0700))throw new RuntimeException('Cannot create private setup configuration fixture.');
$fixtureNumber=0;
$fixture=static function(string $label)use($root,&$fixtureNumber):string{
 $path=$root.'/'.(++$fixtureNumber).'-'.$label;
 if(!mkdir($path,0700))throw new RuntimeException('Cannot create isolated configuration case.');
 return $path;
};
$settings=setupConfigSettings();
$previousErrorLog=ini_get('error_log');ini_set('error_log',$root.'/fixture-errors.log');
try{
 $directory=$fixture('round trip with spaces');
 setupConfigCheck(!SetupConfiguration::exists($directory),'An empty private storage directory is not already configured');
 setupConfigThrows(static fn()=>SetupConfiguration::prepare($directory,$settings),'Preparing configuration without the actual directory lock is refused');
 setupConfigCheck(!SetupConfiguration::exists($directory),'An unlocked attempt creates no live configuration');
 $readControl=$directory.'/read-control';$differentControl=$directory.'/different-read-control';
 file_put_contents($readControl,'known-existing-file');file_put_contents($differentControl,'different-existing-file');
 setupConfigCheck(setupConfigReadEvidence($readControl,[['path'=>$directory.'/./read-control']])['matched'],
  'Parser-observation comparison resolves alternate spellings of the same existing regular file');
 setupConfigCheck(!setupConfigReadEvidence($readControl,[])['matched'],'An empty read log never proves parser execution');
 setupConfigCheck(!setupConfigReadEvidence($directory.'/missing',[['path'=>$directory.'/also-missing']])['matched'],
  'Two failed realpath lookups never compare as a matching read');
 setupConfigCheck(!setupConfigReadEvidence($readControl,[['path'=>$differentControl]])['matched'],
  'A read of a different existing file never satisfies the intended parser observation');
 unlink($readControl);unlink($differentControl);
 $callbackResult=SetupConfiguration::withLock($directory,static fn():string=>'lock-callback-return');
 setupConfigCheck($callbackResult==='lock-callback-return','The real directory lock forwards the callback result');
 $priorUmask=umask();
 SetupConfigFaults::reset();
 $roundTrip=SetupConfigFaults::withLock($directory,static function()use($directory,$settings):array{
  $staged=SetupConfigFaults::prepare($directory,$settings);
  setupConfigCheck($staged instanceof SetupConfigFaults,'The protected filesystem seam runs through the real publisher class');
  setupConfigCheck(!SetupConfiguration::exists($directory),'A complete staged file remains inactive before publication');
  setupConfigCheck((bool)preg_match('/\A[a-f0-9]{64}\z/D',$staged->token()),'Attempt token is a full random 256-bit hexadecimal identity');
  setupConfigCheck((bool)preg_match('/\A[a-f0-9]{64}\z/D',$staged->configHmac()),'Configuration binding is a full hexadecimal SHA-256 HMAC');
  $attempt=file_get_contents($directory.'/.setup-attempt.json');
  $decoded=json_decode($attempt,true,32,JSON_THROW_ON_ERROR);
  setupConfigCheck($decoded['version']===1&&$decoded['token']===$staged->token()&&$decoded['config_hmac']===$staged->configHmac(),
   'Private persisted attempt contains the exact version, token and configuration binding');
  setupConfigCheck(!str_contains($attempt,$settings['db_pass'])&&!str_contains($attempt,$settings['db_user']),
   'Attempt metadata does not contain plaintext database credentials');
  if(PHP_OS_FAMILY!=='Windows')setupConfigCheck((fileperms($directory.'/.setup-attempt.json')&0777)===0600,'Attempt metadata has owner-only POSIX permissions');
  $identity=['token'=>$staged->token(),'hmac'=>$staged->configHmac()];
  $staged->publish();
  setupConfigCheck(SetupConfiguration::exists($directory),'Successful no-replace publication activates the complete configuration');
  // This file was generated in this fixture from known scalar inputs, not supplied by a user.
  $loaded=require $directory.'/config.php';
  setupConfigCheck($loaded===SetupConfiguration::settings($settings),'Generated PHP configuration round-trips quotes, backslashes and multiline credentials without evaluation changes');
  $published=file_get_contents($directory.'/config.php');$staged->discard();$staged->discard();
  setupConfigCheck(file_get_contents($directory.'/config.php')===$published,'Repeated stage cleanup never deletes or changes the published configuration');
  setupConfigCheck(is_file($directory.'/.setup-attempt.json'),'Successful setup retains its private recovery identity');
  if(PHP_OS_FAMILY!=='Windows')setupConfigCheck((fileperms($directory.'/config.php')&0777)===0600,'Published credentials retain owner-only POSIX permissions');
  return $identity;
 });
 setupConfigCheck(umask()===$priorUmask,'Successful preparation restores the caller filesystem creation mask');
 setupConfigCheck(SetupConfigFaults::$writes!==[],'Successful preparation performed real observed configuration writes');
 if(PHP_OS_FAMILY!=='Windows')setupConfigCheck(array_filter(SetupConfigFaults::$writes,static fn(array $call):bool=>$call['mode']!==0600)===[],
  'Every observed credential write happened only after restrictive permissions were installed');
 setupConfigCheck(count(array_filter(SetupConfigFaults::$links,static fn(array $link):bool=>basename($link['destination'])==='config.php'))===1,
  'Publication uses exactly one no-replace hard-link operation for the live target');
 $published=file_get_contents($directory.'/config.php');
 setupConfigThrows(static fn()=>SetupConfiguration::withLock($directory,static fn()=>SetupConfiguration::prepare($directory,$settings)),
  'An already published configuration cannot be silently prepared for replacement');
 setupConfigCheck(file_get_contents($directory.'/config.php')===$published,'Existing live configuration remains byte-identical after attempted setup');

 $directory=$fixture('identity retry');$identity=setupConfigPrime($directory,$settings);$baseline=setupConfigEntries($directory);
 $reordered=array_reverse($settings,true);$retried=setupConfigPrime($directory,$reordered);
 setupConfigCheck($retried===$identity,'The same canonical settings reuse the exact attempt token and HMAC across retries');
 setupConfigCheck(setupConfigEntries($directory)===$baseline,'Discard and retry leave no abandoned configuration stage');
 $different=$settings;$different['db_pass'].='-different';$attempt=file_get_contents($directory.'/.setup-attempt.json');
 setupConfigThrows(static fn()=>SetupConfiguration::withLock($directory,static fn()=>SetupConfiguration::prepare($directory,$different)),
  'Different database settings cannot rebind an existing setup identity');
 setupConfigCheck(file_get_contents($directory.'/.setup-attempt.json')===$attempt&&!SetupConfiguration::exists($directory),
  'Rejected settings preserve the original attempt and leave the application unconfigured');

 // Model the exact private stage left by abrupt process termination. This is a
 // filesystem recovery boundary test, not a claim of physical power-loss durability.
 $directory=$fixture('abandoned same-attempt stage');$identity=setupConfigPrime($directory,$settings);
 $ownedStage=$directory.'/.setup-config-'.$identity['token'].'.tmp';
 $otherStage=$directory.'/.setup-config-'.str_repeat('f',64).'.tmp';$userFile=$directory.'/user-media.txt';
 file_put_contents($ownedStage,'incomplete-old-private-config');chmod($ownedStage,0600);
 file_put_contents($otherStage,'unrelated-private-stage');chmod($otherStage,0600);
 file_put_contents($userFile,'original-user-media');
 SetupConfiguration::withLock($directory,static function()use($directory,$settings,$ownedStage):void{
  $staged=SetupConfiguration::prepare($directory,$settings);
  setupConfigCheck(str_starts_with((string)file_get_contents($ownedStage),'<?php return '),
   'A modeled prepublication crash reclaims only the exact private same-attempt stage and verifies its replacement');
  $staged->discard();
 });
 setupConfigCheck(!file_exists($ownedStage)&&file_get_contents($otherStage)==='unrelated-private-stage'&&file_get_contents($userFile)==='original-user-media',
  'Abandoned-stage cleanup preserves other attempt identities and unrelated user data');
 setupConfigCheck(setupConfigPrime($directory,$settings)===$identity,'An abandoned-stage retry retains the original durable recovery binding');

 foreach(['directory','symlink','public-mode'] as $kind){
  if($kind==='public-mode'&&PHP_OS_FAMILY==='Windows')continue; // PHP mode bits do not describe NTFS ACLs.
  $directory=$fixture('unsafe-stage-'.$kind);$identity=setupConfigPrime($directory,$settings);
  $stage=$directory.'/.setup-config-'.$identity['token'].'.tmp';
  if($kind==='directory')mkdir($stage,0700);
  elseif($kind==='symlink'){
   if(!@symlink($directory.'/stage-referent-absent',$stage)){
    if(PHP_OS_FAMILY!=='Windows')throw new RuntimeException('Required Linux unsafe-stage symlink fixture unavailable.');
    echo "NOTE Windows runner cannot create stage symlink; Linux recovery-path tests remain required.\n";continue;
   }
  }else{file_put_contents($stage,'unowned-public-file');chmod($stage,0644);}
  setupConfigThrows(static fn()=>SetupConfiguration::withLock($directory,static fn()=>SetupConfiguration::prepare($directory,$settings)),
   $kind.' abandoned-stage path is rejected rather than adopted or deleted');
  setupConfigCheck(!SetupConfiguration::exists($directory)&&($kind==='directory'?is_dir($stage):($kind==='symlink'?is_link($stage):file_get_contents($stage)==='unowned-public-file')),
   $kind.' unsafe stage remains untouched while setup stays inactive');
 }

 $directory=$fixture('changed staged content');
 SetupConfiguration::withLock($directory,static function()use($directory,$settings):void{
  $staged=SetupConfiguration::prepare($directory,$settings);
  $stage=$directory.'/.setup-config-'.$staged->token().'.tmp';
  file_put_contents($stage,'modified-after-prepare');
  setupConfigThrows(static fn()=>$staged->publish(),'Changed staged bytes are rejected by the final prepublication readback');
  setupConfigCheck(!SetupConfiguration::exists($directory),'Changed private stage never becomes the active configuration');$staged->discard();
 });

 $directory=$fixture('escaped lock generation');$old=null;
 SetupConfiguration::withLock($directory,static function()use($directory,$settings,&$old):void{$old=SetupConfiguration::prepare($directory,$settings);});
 setupConfigThrows(static fn()=>$old->publish(),'An escaped staged object cannot publish after its original directory lock is released');
 SetupConfiguration::withLock($directory,static function()use($directory,$settings,$old):void{
  $current=SetupConfiguration::prepare($directory,$settings);
  $stage=$directory.'/.setup-config-'.$current->token().'.tmp';$bytes=file_get_contents($stage);
  setupConfigThrows(static fn()=>$old->publish(),'A stale object cannot publish during a later acquisition of the same directory lock');
  setupConfigThrows(static fn()=>$old->discard(),'A stale object cannot delete a newer attempt stage at the same deterministic filename');
  setupConfigCheck(file_get_contents($stage)===$bytes&&!SetupConfiguration::exists($directory),
   'Lock-generation rejection preserves the newer complete stage and inactive target');
  $current->publish();
  setupConfigCheck((require $directory.'/config.php')===SetupConfiguration::settings($settings),
   'The current lock generation can publish successfully after rejecting stale ownership');
 });
 unset($old);

 $faults=['write-zero','write-false','write-overreport','partial-then-false','flush-false','sync-false','read-false','read-corrupt','permissions-false','write-throw'];
 if(PHP_OS_FAMILY!=='Windows')$faults[]='permissions-widened';
 foreach($faults as $fault){
  $directory=$fixture($fault);$identity=setupConfigPrime($directory,$settings);$baseline=setupConfigEntries($directory);
  SetupConfigFaults::reset($fault);$before=umask();ob_start();
  try{
   $error=setupConfigThrows(static fn()=>SetupConfigFaults::withLock($directory,static fn()=>SetupConfigFaults::prepare($directory,$settings)),
    $fault.' fails before configuration activation');
   $captured=(string)ob_get_contents();
  }finally{ob_end_clean();}
  echo 'PASS '.$fault." failure is captured without unsafe output\n";
  setupConfigCheck(!str_contains($captured,'Warning:')&&!str_contains($captured,$settings['db_pass'])&&!str_contains($captured,'private-I/O-fault-secret-sentinel'),
   $fault.' does not leak warnings or credentials to output');
  setupConfigCheck(!str_contains($error->getMessage(),$settings['db_pass'])&&!str_contains($error->getMessage(),'private-I/O-fault-secret-sentinel'),
   $fault.' exposes only a safe public error');
  setupConfigCheck(!SetupConfiguration::exists($directory)&&setupConfigEntries($directory)===$baseline,
   $fault.' removes only its owned partial stage and preserves recovery metadata');
  if(str_starts_with($fault,'permissions-'))setupConfigCheck(SetupConfigFaults::$writes===[],
   $fault.' refuses to write any credential bytes before privacy is established');
  setupConfigCheck(umask()===$before,$fault.' restores the caller filesystem mask on failure');
  SetupConfigFaults::reset();
  setupConfigCheck(setupConfigPrime($directory,$settings)===$identity,$fault.' allows retry with the unchanged attempt identity');
 }

 foreach(['attempt-link-false','attempt-link-race'] as $fault){
  $directory=$fixture($fault);SetupConfigFaults::reset($fault);
  setupConfigThrows(static fn()=>SetupConfigFaults::withLock($directory,static fn()=>SetupConfigFaults::prepare($directory,$settings)),
   $fault.' fails closed while publishing the initial recovery identity');
  setupConfigCheck(!SetupConfiguration::exists($directory)&&glob($directory.'/.setup-attempt-*.tmp')===[],
   $fault.' cleans its private marker stage without activating configuration');
  if($fault==='attempt-link-race')setupConfigCheck(file_get_contents($directory.'/.setup-attempt.json')==='racing-owner-recovery-record',
   'An independently appearing recovery record is never overwritten or removed');
  else setupConfigCheck(!file_exists($directory.'/.setup-attempt.json'),'Unsupported hard links do not publish a partial recovery record');
 }

 $directory=$fixture('short writes');setupConfigPrime($directory,$settings);SetupConfigFaults::reset('short-write');
 SetupConfigFaults::withLock($directory,static function()use($directory,$settings):void{
  $staged=SetupConfigFaults::prepare($directory,$settings);$staged->publish();
 });
 setupConfigCheck(count(SetupConfigFaults::$writes)>1,'Partial positive writes are actually retried until every byte has been written');
 setupConfigCheck((require $directory.'/config.php')===SetupConfiguration::settings($settings),'Repeated short writes still publish the full exact configuration');

 foreach(['link-false','link-throw'] as $fault){
  $directory=$fixture($fault);$identity=setupConfigPrime($directory,$settings);$baseline=setupConfigEntries($directory);SetupConfigFaults::reset($fault);
  SetupConfigFaults::withLock($directory,static function()use($directory,$settings,$fault):void{
   $staged=SetupConfigFaults::prepare($directory,$settings);
   $error=setupConfigThrows(static fn()=>$staged->publish(),$fault.' cannot silently activate or overwrite configuration');
   setupConfigCheck(!str_contains($error->getMessage(),'private-I/O-fault-secret-sentinel'),$fault.' normalizes unexpected filesystem exception details');
   $staged->discard();
  });
  setupConfigCheck(!SetupConfiguration::exists($directory)&&setupConfigEntries($directory)===$baseline,
   $fault.' leaves no live or partial configuration after explicit cleanup');
  SetupConfigFaults::reset();
  setupConfigCheck(setupConfigPrime($directory,$settings)===$identity,$fault.' retains a stable identity for a later safe retry');
 }

 foreach(['regular','corrupt','directory','symlink-live','symlink-dangling'] as $kind){
  $directory=$fixture('existing-'.$kind);$target=$directory.'/config.php';$marker=$directory.'/must-not-execute';
  $evil='<?php file_put_contents('.var_export($marker,true).',"executed"); return ["intruder"=>true];';
  if($kind==='directory')mkdir($target,0700);
  elseif(str_starts_with($kind,'symlink-')){
   $referent=$directory.'/referent';if($kind==='symlink-live')file_put_contents($referent,$evil);
   if(!@symlink($referent,$target)){
    if(PHP_OS_FAMILY!=='Windows')throw new RuntimeException('Required Linux symlink fixture could not be created.');
    echo 'NOTE Windows runner cannot create '.$kind." fixture; Linux collision tests remain required.\n";continue;
   }
  }else file_put_contents($target,$kind==='regular'?$evil:'<?php syntactically broken and not executable');
  $before=is_link($target)?readlink($target):(is_file($target)?file_get_contents($target):'directory');
  setupConfigCheck(SetupConfiguration::exists($directory),$kind.' config entry blocks fresh-install detection');
  setupConfigThrows(static fn()=>SetupConfiguration::withLock($directory,static fn()=>SetupConfiguration::prepare($directory,$settings)),
   $kind.' configuration is never replaced by setup');
  $after=is_link($target)?readlink($target):(is_file($target)?file_get_contents($target):'directory');
  setupConfigCheck($before===$after&&!file_exists($marker),$kind.' remains unchanged and existing PHP is never executed');
 }

 foreach(['regular','directory','symlink-dangling'] as $kind){
  $directory=$fixture('race-'.$kind);setupConfigPrime($directory,$settings);SetupConfigFaults::reset();
  $raced='racing-owner-configuration-'.bin2hex(random_bytes(4));
  SetupConfigFaults::$race=static function(string $source,string $target)use($kind,$raced):void{
   if($kind==='directory')mkdir($target,0700);
   elseif($kind==='symlink-dangling'){
    if(!@symlink($target.'.absent',$target))throw new RuntimeException('Racing symlink fixture unavailable.');
   }else file_put_contents($target,$raced);
  };
  if($kind==='symlink-dangling'&&PHP_OS_FAMILY==='Windows'){
   $probe=$directory.'/symlink-probe';
   if(!@symlink($directory.'/absent',$probe)){echo "NOTE Windows runner cannot create racing symlink; Linux atomic-link test remains required.\n";continue;}
   unlink($probe);
  }
  SetupConfigFaults::withLock($directory,static function()use($directory,$settings,$kind):void{
   $staged=SetupConfigFaults::prepare($directory,$settings);
   setupConfigThrows(static fn()=>$staged->publish(),$kind.' target appearing during publication loses no existing data');$staged->discard();
  });
  $target=$directory.'/config.php';
  setupConfigCheck($kind==='regular'?file_get_contents($target)===$raced:($kind==='directory'?is_dir($target):is_link($target)),
   $kind.' racing target survives publication refusal and stage cleanup unchanged');
 }

 foreach(['corrupt-json','executable-php','directory','symlink'] as $kind){
  $directory=$fixture('attempt-'.$kind);$attempt=$directory.'/.setup-attempt.json';$marker=$directory.'/attempt-must-not-execute';
  if($kind==='directory')mkdir($attempt,0700);
  elseif($kind==='symlink'){
   if(!@symlink($directory.'/missing-attempt',$attempt)){
    if(PHP_OS_FAMILY!=='Windows')throw new RuntimeException('Required Linux attempt-symlink fixture unavailable.');
    echo "NOTE Windows runner cannot create attempt symlink; Linux identity-path tests remain required.\n";continue;
   }
  }else{file_put_contents($attempt,$kind==='corrupt-json'?'{broken':'<?php file_put_contents('.var_export($marker,true).',"executed"); return [];');chmod($attempt,0600);}
  $before=is_link($attempt)?readlink($attempt):(is_file($attempt)?file_get_contents($attempt):'directory');
  SetupConfigFaults::reset();
  $rejection=setupConfigThrows(static fn()=>SetupConfigFaults::withLock($directory,static fn()=>SetupConfigFaults::prepare($directory,$settings)),
   $kind.' attempt identity is rejected rather than executed, replaced or silently rebound');
  $after=is_link($attempt)?readlink($attempt):(is_file($attempt)?file_get_contents($attempt):'directory');
  setupConfigCheck($after===$before&&!file_exists($marker)&&!SetupConfiguration::exists($directory),
   $kind.' unsafe identity remains untouched and no live config is created');
  if(in_array($kind,['corrupt-json','executable-php'],true)){
   $readEvidence=setupConfigReadEvidence($attempt,SetupConfigFaults::$reads);
   if(!$readEvidence['matched'])echo 'SETUP_READ_DIAGNOSTIC '.json_encode(['case'=>$kind,'exception_class'=>get_class($rejection)]+
    $readEvidence,JSON_THROW_ON_ERROR)."\n";
   setupConfigCheck($readEvidence['expected_resolved']&&$readEvidence['expected_regular'],
    $kind.' parser fixture resolves to an existing non-symlink regular file');
   setupConfigCheck($readEvidence['matched'],
    $kind.' is actually read by the nonexecuting parser, not merely rejected by file permissions');
  }
 }

 $directory=$fixture('bounded interprocess lock');$ready=$directory.'/child-ready';$pipes=[];
 $script='require $argv[1]; SetupConfiguration::withLock($argv[2],static function()use($argv):void{file_put_contents($argv[3],"ready");usleep(12000000);});';
 $process=proc_open([PHP_BINARY,'-d','extension_dir='.ini_get('extension_dir'),'-d','display_errors=stderr','-d','log_errors=0',
  '-r',$script,__DIR__.'/../lib/SetupConfiguration.php',$directory,$ready],
  [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
 if(!is_resource($process))throw new RuntimeException('Cannot start isolated lock-holder process.');
 fclose($pipes[0]);stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);
 try{
  $deadline=microtime(true)+5;
  while(!is_file($ready)){
   if(!proc_get_status($process)['running']||microtime(true)>$deadline)throw new RuntimeException('Child did not acquire its actual filesystem lock.');
   clearstatcache(true,$ready);usleep(10000);
  }
  $started=microtime(true);
  setupConfigThrows(static fn()=>SetupConfiguration::withLock($directory,static fn():bool=>true),
   'A second process cannot enter while the real installer file lock is held');
  $elapsed=microtime(true)-$started;
  setupConfigCheck($elapsed>=4.5&&$elapsed<10,'Lock contention fails within the defined five-second budget instead of waiting indefinitely');
 }finally{
  if(proc_get_status($process)['running'])proc_terminate($process);
  $deadline=microtime(true)+3;
  while(proc_get_status($process)['running']&&microtime(true)<$deadline)usleep(10000);
  if(proc_get_status($process)['running'])proc_terminate($process,9);
  fclose($pipes[1]);fclose($pipes[2]);proc_close($process);
 }
 setupConfigCheck(SetupConfiguration::withLock($directory,static fn():bool=>true)===true,
  'Native process termination releases the installer lock for a safe later retry');

 $directory=$fixture('callback failure');
 setupConfigThrows(static fn()=>SetupConfiguration::withLock($directory,static function():never{throw new RuntimeException('controlled callback failure');}),
  'An application callback exception exits the actual lock safely');
 setupConfigCheck(SetupConfiguration::withLock($directory,static fn():bool=>true)===true,'The lock can be reacquired after callback failure');
 setupConfigCheck(umask()===$priorUmask,'All success and failure paths leave process umask unchanged');
 $errors=is_file($root.'/fixture-errors.log')?(string)file_get_contents($root.'/fixture-errors.log'):'';
 setupConfigCheck(!str_contains($errors,$settings['db_pass'])&&!str_contains($errors,'private-I/O-fault-secret-sentinel'),
  'Any abandoned-object cleanup diagnostics contain no credentials or injected private error details');
}finally{
 SetupConfigFaults::reset();ini_set('error_log',$previousErrorLog);setupConfigRemove($root,$root);
}
echo 'SETUP CONFIGURATION FILESYSTEM PASSED: '.$checks." checks.\n";
