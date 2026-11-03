<?php
declare(strict_types=1);
/** Real file/save-handler behavior; HTTP concurrency is certified by the separate database suite. */
define('LUCKY_TESTING',true); // Only suppress Bootstrap's automatic start; every session below is real.
require_once __DIR__.'/../lib/Bootstrap.php';

$checks=0;
function sessionReleaseCheck(bool $ok,string $label):void {
 global $checks;
 if(!$ok)throw new RuntimeException('FAIL '.$label);
 ++$checks;echo "PASS $label\n";
}
function sessionReleaseSource(string $relative):string {
 $out='';
 foreach(token_get_all((string)file_get_contents(__DIR__.'/../'.$relative)) as $token){
  if(is_array($token)){
   if(!in_array($token[0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true))$out.=$token[1];
  }else $out.=$token;
 }
 return $out;
}
/** Extract a compact code block without counting braces inside quoted PHP strings. */
function sessionReleaseBlock(string $source,string $marker):string {
 $start=strpos($source,$marker);
 if($start===false)throw new RuntimeException('Missing source boundary '.$marker);
 $open=strpos($source,'{',$start+strlen($marker));
 if($open===false)throw new RuntimeException('Missing source block '.$marker);
 $depth=0;$quote=null;
 for($i=$open,$length=strlen($source);$i<$length;++$i){
  $char=$source[$i];
  if($quote!==null){
   if($char==='\\'){++$i;continue;}
   if($char===$quote)$quote=null;
   continue;
  }
  if($char==='"'||$char==="'"){$quote=$char;continue;}
  if($char==='{')++$depth;
  if($char==='}'&&--$depth===0)return substr($source,$open,$i-$open+1);
 }
 throw new RuntimeException('Unclosed source block '.$marker);
}
function sessionReleaseOrdered(string $source,array $needles):bool {
 $offset=0;
 foreach($needles as $needle){
  $found=strpos($source,$needle,$offset);
  if($found===false)return false;
  $offset=$found+strlen($needle);
 }
 return true;
}

final class SessionReleaseAuditPDO extends PDO {
 public array $executions=[];
 public function __construct(){} // Never opens a database connection.
 public function prepare(string $query,array $options=[]):PDOStatement|false {
  return new SessionReleaseAuditStatement($this,$query);
 }
}
final class SessionReleaseAuditStatement extends PDOStatement {
 public function __construct(private SessionReleaseAuditPDO $connection,private string $query){}
 public function execute(?array $params=null):bool {
  $this->connection->executions[]=['query'=>$this->query,'params'=>$params];return true;
 }
}
final class SessionReleaseBrokenHandler implements SessionHandlerInterface {
 public int $writes=0;
 public function __construct(private ?Throwable $failure=null){}
 public function open(string $path,string $name):bool{return true;}
 public function close():bool{return true;}
 public function read(string $id):string|false{return '';}
 public function write(string $id,string $data):bool {
  ++$this->writes;
  if($this->failure!==null)throw $this->failure;
  return false;
 }
 public function destroy(string $id):bool{return true;}
 public function gc(int $max_lifetime):int|false{return 0;}
}

// Buffer PASS output so explicit session starts/handler changes cannot be masked by sent headers.
ob_start();
$fixture=sys_get_temp_dir().'/lucky-session-release-'.bin2hex(random_bytes(12));
if(!mkdir($fixture,0700))throw new RuntimeException('Cannot create session release fixture.');
$priorPath=session_save_path();$priorModule=session_module_name();$priorName=session_name();
$ini=[];
foreach(['session.use_cookies'=>'0','session.use_strict_mode'=>'0','session.cache_limiter'=>'',
 'session.gc_probability'=>'0','session.serialize_handler'=>'php_serialize'] as $name=>$value){
 $ini[$name]=ini_get($name);ini_set($name,$value);
}
session_save_path($fixture);session_name('lucky_release_test');
try {
 sessionReleaseCheck(session_status()===PHP_SESSION_NONE,'Bootstrap test guard does not create a fake active session');
 $beforeId=session_id();releaseSessionLock();releaseSessionLock();
 sessionReleaseCheck(session_status()===PHP_SESSION_NONE&&session_id()===$beforeId&&count(scandir($fixture))===2,
  'Inactive and repeated release never starts a session or creates storage');

 $id='release-'.bin2hex(random_bytes(12));session_id($id);
 sessionReleaseCheck(session_start(),'Explicit native file session starts');
 $expected=['admin_id'=>7317,'csrf'=>str_repeat('a',64),'video_ai_last_request'=>time(),'consent'=>true];
 $_SESSION=$expected;
 releaseSessionLock();
 $file=$fixture.'/sess_'.$id;$persisted=(string)file_get_contents($file);
 sessionReleaseCheck(session_status()===PHP_SESSION_NONE,'Active session is actually closed');
 sessionReleaseCheck(unserialize($persisted,['allowed_classes'=>false])===$expected,
  'Administrator identity, CSRF, rate reservation and other session fields are persisted together');
 sessionReleaseCheck($_SESSION===$expected&&isAdmin(),'Closed session snapshot still supports authenticated reads');
 requireAdmin();$_SERVER['HTTP_X_CSRF_TOKEN']=$expected['csrf'];assertCsrf();
 sessionReleaseCheck(csrf()===$expected['csrf']&&session_status()===PHP_SESSION_NONE,
  'Existing auth and CSRF reads do not reopen the session');
 $audit=new SessionReleaseAuditPDO();audit($audit,'session.release-test',null,['probe'=>true]);
 sessionReleaseCheck(count($audit->executions)===1&&$audit->executions[0]['params'][0]===$expected['admin_id']&&
  session_status()===PHP_SESSION_NONE,'Actual audit helper retains the original actor without reopening');

 $_SESSION['admin_id']=9999;$_SESSION['video_ai_last_request']=0;$_SESSION['late_write']='must-not-persist';
 releaseSessionLock();
 sessionReleaseCheck(file_get_contents($file)===$persisted&&session_status()===PHP_SESSION_NONE,
  'Repeated release does not persist mutations made to the closed snapshot');
 session_id($id);sessionReleaseCheck(session_start(),'Independent later request reopens the stored session');
 sessionReleaseCheck($_SESSION===$expected,'Later request reads saved identity and throttle, not stale snapshot mutations');
 releaseSessionLock();$staleSnapshot=$_SESSION;

 // Model an intervening request's logout. The HTTP suite separately exercises concurrent processes.
 session_id($id);sessionReleaseCheck(session_start(),'Intervening logout request obtains the real stored session');
 $_SESSION=[];sessionReleaseCheck(session_destroy(),'Intervening logout destroys native session storage');
 clearstatcache(true,$file);
 sessionReleaseCheck(!is_file($file)&&session_status()===PHP_SESSION_NONE,'Logout leaves no active session or native file');
 $_SESSION=$staleSnapshot;releaseSessionLock();clearstatcache(true,$file);
 sessionReleaseCheck(!is_file($file)&&session_status()===PHP_SESSION_NONE,
  'Completion of an old authenticated snapshot cannot recreate a destroyed session');

 // Control: a false save-handler write emits E_WARNING even when PHP returns true from close.
 $rawHandler=new SessionReleaseBrokenHandler();session_set_save_handler($rawHandler,false);
 session_id('raw-failure');sessionReleaseCheck(session_start(),'False-writer control starts a real custom-handler session');
 $_SESSION=['admin_id'=>7317];$warnings=0;
 set_error_handler(static function()use(&$warnings):bool{++$warnings;return true;},E_WARNING);
 ob_start();
 try{$rawClosed=session_write_close();$rawOutput=(string)ob_get_contents();}
 finally{ob_end_clean();restore_error_handler();}
 sessionReleaseCheck($rawClosed===true&&$rawHandler->writes===1&&$warnings>0&&$rawOutput==='',
  'Control reproduces PHP true-return plus failed-write warning without leaking diagnostics');

 foreach([
  'false-return'=>null,
  'runtime'=>new RuntimeException('private-handler-storage-sentinel'),
  'domain'=>new DomainException('private-handler-credential-sentinel',403),
 ] as $case=>$handlerFailure){
  $label=$handlerFailure===null?'False-returning':get_class($handlerFailure);
  $handler=new SessionReleaseBrokenHandler($handlerFailure);session_set_save_handler($handler,false);
  session_id('failure-'.$case);sessionReleaseCheck(session_start(),
   $label.' save handler starts an actual session');
  $_SESSION=['admin_id'=>7317,'csrf'=>str_repeat('b',64),'video_ai_last_request'=>time()];
  $priorWarnings=[];
  set_error_handler(static function(int $number,string $message)use(&$priorWarnings):bool{
   $priorWarnings[]=$message;return true;
  });
  $failure=null;$slowOperationStarted=false;ob_start();
  try {
   try{releaseSessionLock();$slowOperationStarted=true;}catch(Throwable $e){$failure=$e;}
   $helperOutput=(string)ob_get_contents();
   sessionReleaseCheck($failure instanceof RuntimeException&&!($failure instanceof DomainException)&&!$slowOperationStarted,
    $label.' persistence produces a generic server exception before any slow or paid operation');
   sessionReleaseCheck($helperOutput===''&&$priorWarnings===[],
    $label.' persistence emits no warning, path or credential-bearing diagnostic');
   sessionReleaseCheck($failure->getMessage()==='Unable to save and close the browser session.'&&$failure->getCode()===0,
    $label.' failure cannot expose handler messages or client-facing status codes');
   sessionReleaseCheck($failure->getPrevious()===$handlerFailure,
    $label.' preserves only the original exception cause for internal diagnosis');
   trigger_error('previous-handler-restored',E_USER_WARNING);
   sessionReleaseCheck($priorWarnings===['previous-handler-restored'],
    'Previous error handler is restored after '.$label.' failure');
   sessionReleaseCheck($handler->writes===1,'Failed close never retries the save handler implicitly');
  }finally{
   $caseOutput=(string)ob_get_contents();ob_end_clean();restore_error_handler();echo $caseOutput;
   if(session_status()===PHP_SESSION_ACTIVE)session_abort();
  }
 }

 // These are explicit source-boundary guards, not substitutes for behavioral HTTP assertions.
 $bootstrap=sessionReleaseSource('lib/Bootstrap.php');
 $helper=sessionReleaseBlock($bootstrap,'functionreleaseSessionLock(');
 sessionReleaseCheck(str_contains($helper,'session_write_close()')&&!str_contains($helper,'session_start(')&&
  !str_contains($helper,'session_destroy(')&&!str_contains($helper,'session_unset('),
  'Release helper closes rather than restarts, destroys or clears session identity');
 $ai=sessionReleaseSource('api/video-ai.php');
 sessionReleaseCheck(substr_count($ai,'releaseSessionLock();')===3,'AI releases only connection test, analysis and plan paths');
 foreach(['test'=>'ClaudeBridge::ask(','analyze'=>'MediaIntelligence::analyze(','plan'=>'ClaudeBridge::ask('] as $action=>$slow){
  $body=sessionReleaseBlock($ai,"if(\$action==='$action')");
  sessionReleaseCheck(sessionReleaseOrdered($body,['releaseSessionLock();',$slow]),$action.' releases before its slow operation');
  sessionReleaseCheck(sessionReleaseOrdered($ai,['requireAdmin();','assertCsrf();',"if(\$action==='$action')"]),
   $action.' authenticates and validates CSRF before the release branch');
  [, $after]=explode('releaseSessionLock();',$body,2);
  sessionReleaseCheck(!str_contains($after,'$_SESSION')&&!preg_match('/\b(?:session_start|csrf|assertCsrf)\(/',$after),
   $action.' never writes or reopens session state after release');
 }
 $plan=sessionReleaseBlock($ai,"if(\$action==='plan')");
 sessionReleaseCheck(sessionReleaseOrdered($plan,["\$_SESSION['video_ai_last_request']??0",'429',
  "\$_SESSION['video_ai_last_request']=\$now",'releaseSessionLock();','ClaudeBridge::ask(']),
  'Four-second plan throttle is checked and reserved under lock before external work');
 $analyze=sessionReleaseBlock($ai,"if(\$action==='analyze')");
 sessionReleaseCheck(sessionReleaseOrdered($analyze,['sessionRow($db,$session)','session_id=?',
  'if(!$clip)','releaseSessionLock();','MediaIntelligence::analyze(']),
  'Local analysis validates game and clip membership before releasing');
 foreach(['settings_save','settings_remove'] as $action)sessionReleaseCheck(
  !str_contains(sessionReleaseBlock($ai,"if(\$action==='$action')"),'releaseSessionLock('),
  'AI '.$action.' mutation retains its existing session lifecycle');

 $index=sessionReleaseSource('api/index.php');
 sessionReleaseCheck(substr_count($index,'releaseSessionLock();')===6,
  'Main API releases exactly four media reads and two read-only publisher checks');
 foreach(['streamTrack','streamPrizeVideo','streamPrizeImage'] as $function){
  $body=sessionReleaseBlock($index,'function'.$function.'(');
  sessionReleaseCheck(sessionReleaseOrdered($body,['is_file(','releaseSessionLock();',"header('Content-Type:"]),
   $function.' validates the file and closes before media response framing');
  [, $after]=explode('releaseSessionLock();',$body,2);
  sessionReleaseCheck(!str_contains($after,'$_SESSION')&&!str_contains($after,'session_start('),
   $function.' never reopens or mutates the session after release');
 }
 sessionReleaseCheck(sessionReleaseOrdered(sessionReleaseBlock($index,"if(\$action==='game_art'"),
  ['GameCatalog::artwork(','releaseSessionLock();',"header('Content-Type:",'readfile(']),
  'Game artwork validates its source and closes before framing/output');
 sessionReleaseCheck(sessionReleaseOrdered(sessionReleaseBlock($index,'functionstreamPrizeVideo('),
  ['requireAdmin();','releaseSessionLock();']), 'Private prize video preserves its administrator gate');
 foreach(['game_publisher_check'=>'GamePublisherChannels::check(','game_update_check'=>'CloudGameUpdates::check('] as $action=>$slow){
  sessionReleaseCheck(sessionReleaseOrdered($index,['assertCsrf();','requireAdmin();',"if(\$action==='$action')"]),
   $action.' retains authenticated CSRF-protected explicit POST entry');
  sessionReleaseCheck(sessionReleaseOrdered(sessionReleaseBlock($index,"if(\$action==='$action')"),
   ['releaseSessionLock();',$slow,'audit(']),$action.' releases before read-only network work and retains auditing');
 }
 foreach(['game_update_stage','game_package_upload','game_package_activate','game_package_recover','logout'] as $action){
  $body=sessionReleaseBlock($index,"if(\$action==='$action')");
  sessionReleaseCheck(!str_contains($body,'releaseSessionLock('),$action.' remains outside the read-only release scope');
 }
 $logout=sessionReleaseBlock($index,"if(\$action==='logout')");
 sessionReleaseCheck(sessionReleaseOrdered($logout,['session_unset();','session_destroy();','success(']),
  'API logout still clears and destroys its active session before responding');
 $login=sessionReleaseSource('login.php');
 sessionReleaseCheck(!str_contains($login,'releaseSessionLock(')&&str_contains($login,'session_regenerate_id(true)')&&
  str_contains($login,"\$_SESSION['login_attempts']")&&str_contains($login,"\$_SESSION['admin_id']="),
  'Login retains throttle writes and identifier regeneration in its active session');
 $account=sessionReleaseSource('account.php');
 $avatar=sessionReleaseBlock($account,"if((\$_GET['action']??'')==='avatar')");
 sessionReleaseCheck(substr_count($account,'releaseSessionLock();')===1&&sessionReleaseOrdered($avatar,
  ['is_file(','FILEINFO_MIME_TYPE','releaseSessionLock();',"header('Content-Type:",'readfile(']),
  'Account releases only validated avatar output, not logout/profile mutations');
 sessionReleaseCheck(str_contains($avatar,'catch(Throwable$e){errorOut($e);}')&&
  sessionReleaseOrdered($account,['if(!isAdmin())','accountIdentity();',"if((\$_GET['action']??'')==='avatar')"]),
  'Avatar release failures use generic errors after current account authorization');
 $accountLogout=sessionReleaseBlock($account,"if((\$_POST['action']??'')==='logout')");
 sessionReleaseCheck(!str_contains($accountLogout,'releaseSessionLock(')&&
  sessionReleaseOrdered($accountLogout,['$_SESSION=[];','session_regenerate_id(true);']),
  'Account logout still clears and regenerates its active session');
 $view=sessionReleaseSource('package-view.php');
 sessionReleaseCheck(substr_count($view,'releaseSessionLock();')===1&&sessionReleaseOrdered($view,
  ['hash_equals(',"\$state['packageVersion']", "\$state['status']",'releaseSessionLock();','PackageGameSandbox::document(','echo$markup']),
  'Packaged game document validates viewer, pinned version and lifecycle before releasing expensive output');
 echo "V5Q32G3 SESSION RELEASE: $checks/$checks\n";
}finally{
 if(session_status()===PHP_SESSION_ACTIVE)session_abort();
 session_module_name($priorModule);session_save_path($priorPath);session_name($priorName);session_id('');
 foreach($ini as $name=>$value)ini_set($name,(string)$value);
 $_SESSION=[];
 foreach(new DirectoryIterator($fixture) as $entry){
  if($entry->isDot())continue;
  if(!$entry->isFile()||!str_starts_with($entry->getFilename(),'sess_'))
   throw new RuntimeException('Unexpected test fixture entry; refusing cleanup.');
  unlink($entry->getPathname());
 }
 rmdir($fixture);ob_end_flush();
}
