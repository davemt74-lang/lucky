<?php
declare(strict_types=1);
/**
 * Real MariaDB, HTTP login/CSRF, native file sessions, and unchanged production endpoints.
 * Only the isolated copy of ClaudeBridge redirects its transport to a controlled local
 * provider. The trusted FFprobe executable fixture exercises the real proc_open path;
 * actual codec/FFmpeg behavior remains covered by the dedicated video suites.
 * No LUCKY_TESTING flag, production test bypass, paid API, or deployed storage is used.
 */
if(getenv('CI')!=='true')throw new RuntimeException('CI MariaDB only.');
require_once __DIR__.'/../lib/MigrationSql.php';

$checks=0;
function g3Check(bool $pass,string $message):void {
 global $checks;
 if(!$pass)throw new RuntimeException('FAIL '.$message);
 ++$checks;echo 'PASS '.$message."\n";
}
function g3Copy(string $source,string $target):void {
 if(!mkdir($target,0700,true)&&!is_dir($target))throw new RuntimeException('Cannot create isolated fixture directory.');
 foreach(new DirectoryIterator($source) as $entry){
  if($entry->isDot())continue;
  if($entry->isLink())throw new RuntimeException('Fixture source contains a symlink.');
  $dest=$target.'/'.$entry->getFilename();
  if($entry->isDir())g3Copy($entry->getPathname(),$dest);
  elseif(!copy($entry->getPathname(),$dest))throw new RuntimeException('Cannot copy production fixture source.');
 }
}
function g3Remove(string $path):void {
 if(is_link($path)||!is_dir($path)){if(file_exists($path)||is_link($path))unlink($path);return;}
 foreach(new DirectoryIterator($path) as $entry)if(!$entry->isDot())g3Remove($entry->getPathname());
 rmdir($path);
}
function g3Stop(mixed $process):void {
 if(!is_resource($process))return;
 if(proc_get_status($process)['running']){
  proc_terminate($process);
  for($i=0;$i<20&&proc_get_status($process)['running'];$i++)usleep(50000);
  if(proc_get_status($process)['running'])proc_terminate($process,9);
 }
 proc_close($process);
}
/** Raw sockets let one real request remain in flight while another shares its cookie. */
function g3Open(string $base,string $path,string $method='GET',string $cookie='',?array $json=null,array $headers=[],?string $body=null):mixed {
 $address=parse_url($base,PHP_URL_HOST).':'.parse_url($base,PHP_URL_PORT);
 if(!preg_match('/^127\.0\.0\.1:[0-9]+$/D',$address)||!str_starts_with($path,'/')||preg_match('/[\r\n]/',$path))throw new LogicException('Non-loopback fixture request.');
 $stream=stream_socket_client('tcp://'.$address,$code,$message,3);
 if($stream===false)throw new RuntimeException('Fixture connection failed: '.$message);
 stream_set_timeout($stream,4);
 $request=$method.' '.$path." HTTP/1.1\r\nHost: ".$address."\r\nConnection: close\r\n";
 if($cookie!=='')$headers['Cookie']=$cookie;
 if($json!==null){$body=json_encode($json,JSON_THROW_ON_ERROR);$headers['Content-Type']='application/json';}
 if($body!==null)$headers['Content-Length']=(string)strlen($body);
 foreach($headers as $name=>$value){
  if(preg_match('/[\r\n]/',(string)$name.(string)$value))throw new LogicException('Invalid fixture header.');
  $request.=$name.': '.$value."\r\n";
 }
 $request.="\r\n".($body??'');$offset=0;
 while($offset<strlen($request)){
  $written=fwrite($stream,substr($request,$offset));
  if($written===false||$written===0){fclose($stream);throw new RuntimeException('Fixture request write failed.');}
  $offset+=$written;
 }
 return $stream;
}
function g3Headers(mixed $stream):array {
 $line=fgets($stream,8192);
 if(!is_string($line)||!preg_match('~^HTTP/\S+ ([0-9]{3})~',$line,$match))throw new RuntimeException('Missing fixture HTTP status (possible retained session lock).');
 $response=['status'=>(int)$match[1],'headers'=>[]];$length=strlen($line);
 while(($line=fgets($stream,8192))!==false){
  $length+=strlen($line);if($length>32768)throw new RuntimeException('Oversized fixture headers.');
  if($line==="\r\n"||$line==="\n")return $response;
  if(!str_contains($line,':'))throw new RuntimeException('Malformed fixture response header.');
  [$name,$value]=explode(':',$line,2);$response['headers'][strtolower(trim($name))][]=trim($value);
 }
 throw new RuntimeException('Incomplete fixture headers.');
}
function g3Finish(mixed $stream,?array $response=null):array {
 try{
  $response??=g3Headers($stream);$body=stream_get_contents($stream,1048577);$meta=stream_get_meta_data($stream);
  if(!is_string($body)||strlen($body)>1048576||($meta['timed_out']??false))throw new RuntimeException('Fixture response is oversized or timed out.');
  return $response+['body'=>$body];
 }finally{fclose($stream);}
}
function g3Request(string $base,string $path,string $method='GET',string $cookie='',?array $json=null,array $headers=[],?string $body=null):array {
 $start=microtime(true);$response=g3Finish(g3Open($base,$path,$method,$cookie,$json,$headers,$body));
 return $response+['elapsed'=>microtime(true)-$start];
}
function g3Json(array $response):array {
 try{$json=json_decode($response['body'],true,32,JSON_THROW_ON_ERROR);}
 catch(JsonException $e){throw new RuntimeException('Non-JSON HTTP '.$response['status'].': '.substr($response['body'],0,300),0,$e);}
 if(!is_array($json))throw new RuntimeException('Invalid fixture JSON response.');
 return $json;
}
function g3Header(array $response,string $name):string{return implode(', ',$response['headers'][strtolower($name)]??[]);}
function g3Cookie(array $response):string {
 foreach($response['headers']['set-cookie']??[] as $value)
  if(preg_match('/^(lucky_hub_sid=[a-zA-Z0-9,-]+);/D',$value,$match))return $match[1];
 throw new RuntimeException('Real login did not return a session cookie.');
}
function g3Login(string $base,string $username,string $password):array {
 $form=g3Request($base,'/login.php');$anonymous=g3Cookie($form);
 if(!preg_match('/name="csrf" value="([a-f0-9]{64})"/D',$form['body'],$match))throw new RuntimeException('Real login form is missing CSRF.');
 $login=g3Request($base,'/login.php','POST',$anonymous,null,['Content-Type'=>'application/x-www-form-urlencoded'],
  http_build_query(['admin'=>$username,'password'=>$password,'csrf'=>$match[1]]));
 g3Check($login['status']===303&&g3Header($login,'Location')==='admin.php','Real login authenticates and redirects');
 $cookie=g3Cookie($login);
 g3Check($cookie!==$anonymous,'Successful login regenerates the real session ID');
 g3Check(str_contains(strtolower(g3Header($login,'Set-Cookie')),'httponly')&&str_contains(strtolower(g3Header($login,'Set-Cookie')),'samesite=lax'),'Login cookie keeps HttpOnly and SameSite protection');
 $me=g3Request($base,'/api/index.php?action=me','GET',$cookie);$json=g3Json($me);
 g3Check($me['status']===200&&($json['admin']??false)===true&&is_string($json['csrf']??null)&&strlen($json['csrf'])===64,'Authenticated identity and CSRF persist across HTTP requests');
 return ['cookie'=>$cookie,'csrf'=>$json['csrf']];
}
function g3WaitFile(string $path,float $seconds=5):void {
 $until=microtime(true)+$seconds;
 do{clearstatcache(true,$path);if(is_file($path))return;usleep(10000);}while(microtime(true)<$until);
 throw new RuntimeException('Slow fixture never started: '.basename($path));
}
function g3Start(string $root,string $label,string $fixture,array &$processes,array $extra=[],array $environment=[]):string {
 $socket=stream_socket_server('tcp://127.0.0.1:0',$errorCode,$errorMessage);
 if($socket===false)throw new RuntimeException('Cannot reserve loopback port: '.$errorMessage);
 $address=stream_socket_get_name($socket,false);fclose($socket);
 if(!is_string($address)||!preg_match('/^127\.0\.0\.1:[0-9]+$/D',$address))throw new RuntimeException('Invalid loopback address.');
 $args=[PHP_BINARY,'-d','display_errors=0','-d','log_errors=1','-d','output_buffering=0','-d','implicit_flush=1','-d','zlib.output_compression=0',
  '-d','session.save_handler=files','-d','session.serialize_handler=php','-d','session.save_path='.$fixture.'/sessions',
  '-d','session.gc_probability=0','-d','max_execution_time=40'];
 foreach($extra as $key=>$value){$args[]='-d';$args[]=$key.'='.$value;}
 array_push($args,'-S',$address,'-t',$root);
 $env=getenv();if(!is_array($env))$env=[];unset($env['PHP_CLI_SERVER_WORKERS']);$env=array_replace($env,$environment);
 $process=proc_open($args,[0=>['file','/dev/null','r'],1=>['file',$fixture.'/'.$label.'.log','a'],2=>['file',$fixture.'/'.$label.'.log','a']],$pipes,$root,$env,['bypass_shell'=>true]);
 if(!is_resource($process))throw new RuntimeException('Cannot start '.$label.' fixture server.');
 $processes[]=$process;$base='http://'.$address;$ready=(string)file_get_contents($root.'/ready.txt');
 for($i=0;$i<50;$i++){
  if(!proc_get_status($process)['running'])throw new RuntimeException('Fixture server exited: '.file_get_contents($fixture.'/'.$label.'.log'));
  if(@file_get_contents($base.'/ready.txt',false,stream_context_create(['http'=>['timeout'=>0.2,'ignore_errors'=>true]]))===$ready)return $base;
  usleep(100000);
 }
 throw new RuntimeException('Fixture server did not start: '.$label);
}
function g3FastMe(string $base,array $login,string $during):void {
 $response=g3Request($base,'/api/index.php?action=me','GET',$login['cookie']);$json=g3Json($response);
 g3Check($response['status']===200&&($json['admin']??false)===true&&($json['csrf']??null)===$login['csrf']&&$response['elapsed']<4,
  'Same-cookie identity/CSRF completes in under four seconds during '.$during);
}

$db=new PDO('mysql:host=127.0.0.1;port=3306;dbname=lucky_test;charset=utf8mb4','root','luckytestpassword',[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$db->exec("SET time_zone='+00:00'");
foreach(MigrationSql::statements((string)file_get_contents(__DIR__.'/../lib/schema.sql')) as $sql)$db->exec($sql);
foreach(['v5q_claim_media.sql','v5q3_renders.sql','v5q29e1_recording_sessions.sql','v5q29e2_phone_camera.sql','v5q29e4b_multicamera_exports.sql'] as $migration)
 foreach(MigrationSql::statements((string)file_get_contents(__DIR__.'/../lib/migrations/'.$migration)) as $sql)$db->exec($sql);
$fixture=rtrim(sys_get_temp_dir(),DIRECTORY_SEPARATOR).'/lucky-session-g3-'.bin2hex(random_bytes(8));
$processes=[];$inflight=[];$owners=[];$game=null;$track=null;
try{
 mkdir($fixture,0700);mkdir($fixture.'/sessions',0700);mkdir($fixture.'/provider',0700);
 $app=$fixture.'/app';g3Copy(__DIR__.'/../lib',$app.'/lib');g3Copy(__DIR__.'/../api',$app.'/api');
 if(!copy(__DIR__.'/../login.php',$app.'/login.php'))throw new RuntimeException('Cannot copy real login endpoint.');
 foreach(['storage','storage/recording-assets','storage/recording-exports','storage/claim-media','storage/renders','storage/audio'] as $directory)mkdir($app.'/'.$directory,0700);
 $config=['deployment'=>'cloud','db_host'=>'127.0.0.1','db_port'=>3306,'db_name'=>'lucky_test','db_user'=>'root','db_pass'=>'luckytestpassword'];
 file_put_contents($app.'/storage/config.php','<?php return '.var_export($config,true).';');
 $ready=bin2hex(random_bytes(12));file_put_contents($app.'/ready.txt',$ready);file_put_contents($fixture.'/provider/ready.txt',$ready);
 foreach(['lib/Bootstrap.php','api/video-ai.php','api/index.php','api/recording-media.php','api/recording-export.php','api/claim-media.php','api/montage.php','login.php'] as $file)
  g3Check(hash_file('sha256',$app.'/'.$file)===hash_file('sha256',__DIR__.'/../'.$file),'Fixture uses exact production '.$file);
 // A separate, temporary old-behavior mutant proves TCP backpressure has not merely
 // buffered the entire PHP response and silently released its session at shutdown.
 $mediaSource=(string)file_get_contents($app.'/api/recording-media.php');
 if(substr_count($mediaSource,'releaseSessionLock();')!==1)throw new RuntimeException('Update the explicit media-lock mutation fixture.');
 file_put_contents($app.'/api/g3-locked-recording-media.php',str_replace('releaseSessionLock();','/* Test-only old-behavior negative control: retain session. */',$mediaSource));

 // Harness negative control only: deliberately retain the actual native file lock.
 file_put_contents($app.'/g3-lock-control.php',<<<'PHP'
<?php
declare(strict_types=1);
require __DIR__.'/lib/Bootstrap.php';
requireAdmin();$root=dirname(__DIR__);$start=microtime(true);
file_put_contents($root.'/lock.started','locked');
do{usleep(10000);clearstatcache(true,$root.'/lock.release');}
while(!is_file($root.'/lock.release')&&microtime(true)-$start<3);
success(['heldNativeSession'=>true]);
PHP);

 // Controlled real loopback HTTP transport; its minimum hold is deliberately >15s.
 file_put_contents($fixture.'/provider/index.php',<<<'PHP'
<?php
declare(strict_types=1);
$root=dirname(__DIR__);$start=microtime(true);
file_put_contents($root.'/provider.calls',"call\n",FILE_APPEND|LOCK_EX);
$request=json_decode((string)file_get_contents('php://input'),true);
if(!is_array($request)||!is_string($request['system']??null)||!is_string($request['prompt']??null)){http_response_code(400);exit;}
file_put_contents($root.'/provider.started',(string)$start);
do{usleep(20000);clearstatcache(true,$root.'/provider.release');$elapsed=microtime(true)-$start;}
while($elapsed<16||(!is_file($root.'/provider.release')&&$elapsed<23));
header('Content-Type: application/json');
echo json_encode(['text'=>json_encode(['summary'=>'Local fixture plan','operations'=>[]],JSON_THROW_ON_ERROR)],JSON_THROW_ON_ERROR);
PHP);
 $provider=g3Start($fixture.'/provider','provider',$fixture,$processes);
 $bridge=<<<'PHP'
<?php
declare(strict_types=1);
/** Test copy only: production endpoint/auth/session code is unchanged. */
final class ClaudeBridge {
 public static function ask(string $system,string $prompt,int $tokens=1200):string {
  $body=json_encode(['system'=>$system,'prompt'=>$prompt,'tokens'=>$tokens],JSON_THROW_ON_ERROR);
  $context=stream_context_create(['http'=>['method'=>'POST','timeout'=>27,'ignore_errors'=>true,'follow_location'=>0,
   'header'=>['Content-Type: application/json','Connection: close'],'content'=>$body]]);
  $raw=file_get_contents(PROVIDER_URL.'/index.php',false,$context);
  if(!is_string($raw))throw new RuntimeException('Local provider unavailable.');
  $response=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
  if(!is_string($response['text']??null))throw new RuntimeException('Invalid local provider response.');
  return $response['text'];
 }
}
PHP;
 file_put_contents($app.'/lib/ClaudeBridge.php',str_replace('PROVIDER_URL',var_export($provider,true),$bridge));

 // A trusted executable, not an application bypass: real MediaIntelligence invokes it.
 $probe=<<<'PHP'
<?php
declare(strict_types=1);
$root=__DIR__;$start=microtime(true);
file_put_contents($root.'/probe.started',json_encode($argv,JSON_THROW_ON_ERROR));
do{
 usleep(20000);clearstatcache(true,$root.'/probe.release');$elapsed=microtime(true)-$start;
 if(is_file($root.'/probe.release')&&file_get_contents($root.'/probe.release')==='cleanup')exit(1);
}
while($elapsed<5||(!is_file($root.'/probe.release')&&$elapsed<10));
echo json_encode(['format'=>['duration'=>'1.0'],'streams'=>[]],JSON_THROW_ON_ERROR);
PHP;
 file_put_contents($fixture.'/ffprobe-fixture', '#!'.PHP_BINARY."\n".$probe);chmod($fixture.'/ffprobe-fixture',0700);
 $environment=['LUCKY_FFPROBE_BIN'=>$fixture.'/ffprobe-fixture'];
 $first=g3Start($app,'first',$fixture,$processes,[],$environment);
 $second=g3Start($app,'second',$fixture,$processes,[],$environment);

 // Fault injection is confined to a third test server. Reads use the native handler;
 // only writes for these real API requests fail, making PHP emit its write warning.
 file_put_contents($fixture.'/session-failure.php',<<<'PHP'
<?php
declare(strict_types=1);
final class G3FailingSessionWrite extends SessionHandler {
 public function write(string $id,string $data):bool {
  $path=parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH);
  if(in_array($path,['/api/video-ai.php','/api/recording-media.php','/api/recording-export.php','/api/claim-media.php','/api/montage.php'],true)){
   if(($_GET['sessionFailure']??'')==='domain'){
    // Release this fixture handler's delegated file descriptor before deliberately
    // throwing; otherwise the custom handler itself leaks a lock beyond its request.
    parent::close();throw new DomainException('PRIVATE SESSION WRITER DETAIL',403);
   }
   return false;
  }
  return parent::write($id,$data);
 }
}
session_set_save_handler(new G3FailingSessionWrite(),true);
PHP);
 $fault=g3Start($app,'write-failure',$fixture,$processes,['auto_prepend_file'=>$fixture.'/session-failure.php','session.lazy_write'=>'0'],$environment);
 $password='fixture-only-'.bin2hex(random_bytes(12));$users=[];
 foreach(['owner','other'] as $label){
  $username='session-g3-'.$label.'-'.bin2hex(random_bytes(5));
  $db->prepare('INSERT INTO admins(username,password_hash) VALUES (?,?)')->execute([$username,password_hash($password,PASSWORD_DEFAULT)]);
  $owners[]=(int)$db->lastInsertId();$users[]=$username;
 }
 [$owner,$other]=$owners;$game=bin2hex(random_bytes(16));$claim=bin2hex(random_bytes(16));$clip=bin2hex(random_bytes(16));
 $recording=bin2hex(random_bytes(16));$asset=bin2hex(random_bytes(16));$export=bin2hex(random_bytes(16));$render=bin2hex(random_bytes(16));$track=bin2hex(random_bytes(16));
 $db->prepare("INSERT INTO game_sessions(id,game_id,status,version,state_json,viewer_token) VALUES (?,'spin-wheel','active',1,'{}',?)")->execute([$game,bin2hex(random_bytes(24))]);
 $db->prepare("INSERT INTO game_claims(id,session_id,prize_id,tier,prize_name,cents,created_at) VALUES (?,?,'fixture-prize','gold','Fixture prize',100,UTC_TIMESTAMP())")->execute([$claim,$game]);
 $sampleBytes=1600;$wav='RIFF'.pack('V',36+$sampleBytes).'WAVEfmt '.pack('VvvVVvv',16,1,1,8000,16000,2,16).'data'.pack('V',$sampleBytes).str_repeat("\0",$sampleBytes);
 file_put_contents($app.'/storage/claim-media/'.$clip.'.bin',$wav);
 $db->prepare("INSERT INTO claim_media(id,session_id,claim_id,kind,mime,title,bytes,duration_ms,sort_order) VALUES (?,?,?,'audio','audio/wav','Session fixture',?,1000,1)")->execute([$clip,$game,$claim,strlen($wav)]);
 $db->prepare("INSERT INTO recording_sessions(id,owner_admin_id,recording_type,title,create_nonce,request_hash) VALUES (?,?,'production','Session lock fixture','session-lock-fixture',?)")->execute([$recording,$owner,hash('sha256','session fixture')]);
 $db->prepare("INSERT INTO recording_assets(id,recording_session_id,storage_key,kind) VALUES (?,?,?,'audio')")->execute([$asset,$recording,$asset]);
 // Valid 32 MiB PCM WAV: larger than socket buffers, without allocating a 32 MiB PHP string.
 $pcmBytes=32*1024*1024;$mediaPath=$app.'/storage/recording-assets/'.$asset.'.bin';$file=fopen($mediaPath,'wb');
 if($file===false)throw new RuntimeException('Cannot create stream fixture.');
 fwrite($file,'RIFF'.pack('V',36+$pcmBytes).'WAVEfmt '.pack('VvvVVvv',16,1,2,192000,768000,4,16).'data'.pack('V',$pcmBytes));
 $chunk=str_repeat("\0",65536);for($i=0;$i<$pcmBytes/65536;$i++)fwrite($file,$chunk);fclose($file);
 $mediaSize=$pcmBytes+44;
 $db->prepare("INSERT INTO recording_media_files(asset_id,mime,title,bytes,sha256,duration_ms) VALUES (?,'audio/wav','Large PCM fixture',?,?,43691)")->execute([$asset,$mediaSize,hash_file('sha256',$mediaPath)]);
 $db->prepare("INSERT INTO recording_exports(id,recording_session_id,owner_admin_id,client_nonce,request_hash,timeline_revision,snapshot_json,status,output_bytes) VALUES (?,?,?,'session-export-fixture',?,1,'{}','ready',?)")->execute([$export,$recording,$owner,hash('sha256','export fixture'),strlen($wav)]);
 $db->prepare("INSERT INTO montage_renders(id,session_id,admin_id,status,snapshot_json,output_bytes) VALUES (?,?,?,'ready','[]',?)")->execute([$render,$game,$owner,strlen($wav)]);
 // Streaming tests assert byte/Range contracts, not codec validity of completed render containers.
 file_put_contents($app.'/storage/recording-exports/'.$export.'.mp4',$wav);file_put_contents($app.'/storage/renders/'.$render.'.mp4',$wav);
 $poster="\xFF\xD8fixture-poster\xFF\xD9";file_put_contents($app.'/storage/renders/'.$render.'.jpg',$poster);
 file_put_contents($app.'/storage/audio/'.$track.'.audio',$wav);
 $db->prepare("INSERT INTO media_tracks(id,title,kind,source,mime,bytes) VALUES (?,'Fixture soundtrack','upload','','audio/wav',?)")->execute([$track,strlen($wav)]);

 $login=g3Login($first,$users[0],$password);$auth=['X-CSRF-Token'=>$login['csrf']];
 $control=g3Open($first,'/g3-lock-control.php','GET',$login['cookie']);$inflight[]=$control;
 g3WaitFile($fixture.'/lock.started');
 $blocked=g3Open($second,'/api/index.php?action=me','GET',$login['cookie']);$inflight[]=$blocked;
 $read=[$blocked];$write=null;$except=null;
 g3Check(stream_select($read,$write,$except,0,250000)===0,'Negative control proves both real servers contend on the same native session lock');
 file_put_contents($fixture.'/lock.release','release');
 $controlResult=g3Finish($control);$unblocked=g3Finish($blocked);$inflight=[];
 g3Check($controlResult['status']===200&&(g3Json($controlResult)['heldNativeSession']??false)===true&&$unblocked['status']===200&&(g3Json($unblocked)['admin']??false)===true,'Native-session control unblocks only after the first request finishes');
 $plan=['session'=>$game,'skill'=>'highlight','instruction'=>'No edits needed','edits'=>[['id'=>$clip,'instanceId'=>'fixture-clip']]];
 $rejected=g3Request($second,'/api/video-ai.php?action=plan','POST','',$plan,$auth);
 g3Check($rejected['status']===403&&g3Json($rejected)['ok']===false,'Unauthenticated AI requests are rejected before provider work');
 $rejected=g3Request($second,'/api/video-ai.php?action=plan','POST',$login['cookie'],$plan,['X-CSRF-Token'=>'wrong']);
 g3Check($rejected['status']===403&&g3Json($rejected)['ok']===false,'Invalid AI CSRF is rejected before provider work');
 g3Check(!is_file($fixture.'/provider.calls'),'Rejected requests perform no local provider call');

 $started=microtime(true);$pending=g3Open($first,'/api/video-ai.php?action=plan','POST',$login['cookie'],$plan,$auth);$inflight[]=$pending;
 g3WaitFile($fixture.'/provider.started');
 // First after the provider marker so the persisted integer-second throttle is still fresh.
 $repeat=g3Request($second,'/api/video-ai.php?action=plan','POST',$login['cookie'],$plan,$auth);
 g3Check($repeat['status']===429&&g3Json($repeat)['ok']===false&&$repeat['elapsed']<4,'Concurrent AI plan promptly sees the persisted four-second reservation (429)');
 g3FastMe($second,$login,'the slow external AI request');
 $update=['session'=>$game,'id'=>$clip,'title'=>'Changed while AI is pending'];
 $changed=g3Request($second,'/api/claim-media.php?action=update','POST',$login['cookie'],$update,$auth);
 g3Check($changed['status']===200&&g3Json($changed)['clip']['title']===$update['title']&&$changed['elapsed']<4,'Same-cookie authenticated CSRF control succeeds while AI is pending');
 $badLogout=g3Request($second,'/api/index.php?action=logout','POST',$login['cookie'],[],['X-CSRF-Token'=>'wrong']);
 g3Check($badLogout['status']===403&&g3Json($badLogout)['ok']===false,'Unlocking AI does not weaken logout CSRF validation');
 $logout=g3Request($second,'/api/index.php?action=logout','POST',$login['cookie'],[],$auth);
 g3Check($logout['status']===200&&(g3Json($logout)['loggedOut']??false)===true&&$logout['elapsed']<4,'Same-cookie logout completes promptly before the AI response');
 $oldSession=$fixture.'/sessions/sess_'.substr($login['cookie'],strlen('lucky_hub_sid='));clearstatcache(true,$oldSession);
 g3Check(!is_file($oldSession),'Logout destroys the original native session file while AI is still running');
 $loggedOut=g3Request($second,'/api/index.php?action=me','GET',$login['cookie']);
 g3Check((g3Json($loggedOut)['admin']??true)===false,'Old cookie is unauthenticated before the delayed response');
 file_put_contents($fixture.'/provider.release','release');stream_set_timeout($pending,25);$completed=g3Finish($pending);$inflight=[];
 g3Check($completed['status']===200&&(g3Json($completed)['proposal']['operations']??null)===[],'Actual AI endpoint returns its validated proposal after the delayed HTTP transport');
 g3Check(microtime(true)-$started>=15,'The real AI request was in flight for at least fifteen seconds');
 g3Check(g3Header($completed,'Set-Cookie')==='','Delayed authenticated response does not issue a replacement session cookie');
 clearstatcache(true,$oldSession);g3Check(!is_file($oldSession),'Late AI completion does not recreate the destroyed session');
 $loggedOut=g3Request($second,'/api/index.php?action=me','GET',$login['cookie']);
 g3Check((g3Json($loggedOut)['admin']??true)===false,'Old cookie remains unauthenticated after the delayed response');
 $q=$db->prepare("SELECT admin_id FROM audit_log WHERE event='video_ai.proposed' AND session_id=?");$q->execute([$game]);
 g3Check((int)$q->fetchColumn()===$owner,'Released in-request identity still attributes the completed proposal audit to its owner');
 g3Check(file_get_contents($fixture.'/provider.calls')==="call\n",'Concurrent/rejected requests did not duplicate provider work');

 $login=g3Login($second,$users[0],$password);$auth=['X-CSRF-Token'=>$login['csrf']];
 $analysis=g3Open($first,'/api/video-ai.php?action=analyze','POST',$login['cookie'],['session'=>$game,'id'=>$clip],$auth);$inflight[]=$analysis;
 g3WaitFile($fixture.'/probe.started');g3FastMe($second,$login,'the real local analysis subprocess');
 file_put_contents($fixture.'/probe.release','release');stream_set_timeout($analysis,15);$analyzed=g3Finish($analysis);$inflight=[];
 g3Check($analyzed['status']===200&&(g3Json($analyzed)['localOnly']??false)===true,'Actual analysis endpoint completes its trusted local probe path');
 $argv=json_decode((string)file_get_contents($fixture.'/probe.started'),true,32,JSON_THROW_ON_ERROR);
 g3Check(in_array('-protocol_whitelist',$argv,true)&&in_array('file,pipe',$argv,true)&&realpath((string)end($argv))===realpath($app.'/storage/claim-media/'.$clip.'.bin'),'Probe keeps local-only protocol restriction and the authorized original path');
 g3Check(is_file($app.'/storage/media-analysis/'.$clip.'.json'),'Analysis cache is created by the real production service');
 $q=$db->prepare("SELECT admin_id FROM audit_log WHERE event='video_ai.media_analyzed' AND session_id=?");$q->execute([$game]);
 g3Check((int)$q->fetchColumn()===$owner,'Local analysis audit keeps the authenticated identity after releasing its lock');

 // The mutant must fail the concurrency requirement, not merely occupy its HTTP worker.
 $lockedMedia=g3Open($first,'/api/g3-locked-recording-media.php?id='.$asset,'GET',$login['cookie']);$inflight[]=$lockedMedia;
 $lockedHeaders=g3Headers($lockedMedia);
 g3Check($lockedHeaders['status']===200&&g3Header($lockedHeaders,'Content-Length')===(string)$mediaSize,'Old-behavior media mutation starts the same large body');
 $blockedMe=g3Open($second,'/api/index.php?action=me','GET',$login['cookie']);$inflight[]=$blockedMe;
 $read=[$blockedMe];$write=null;$except=null;
 g3Check(stream_select($read,$write,$except,0,250000)===0,'Without the media unlock, the same-cookie request demonstrably remains blocked on the native session');
 fclose($lockedMedia);$unblockedMe=g3Finish($blockedMe);$inflight=[];
 g3Check($unblockedMe['status']===200&&(g3Json($unblockedMe)['admin']??false)===true,'Old-behavior session lock clears when the media client disconnects');

 // Unchanged production endpoint: same bytes/backpressure, but no retained session lock.
 $stream=g3Open($first,'/api/recording-media.php?id='.$asset,'GET',$login['cookie']);$inflight[]=$stream;
 $headers=g3Headers($stream);
 g3Check($headers['status']===200&&g3Header($headers,'Content-Length')===(string)$mediaSize,'Actual recording stream starts with the complete validated byte length');
 $probeRequest=g3Open($first,'/ready.txt');$inflight[]=$probeRequest;$read=[$probeRequest];$write=null;$except=null;
 g3Check(stream_select($read,$write,$except,0,250000)===0,'Unconsumed body demonstrably occupies the first single-worker HTTP server');
 g3FastMe($second,$login,'the backpressured original-media response');
 $changed=g3Request($second,'/api/claim-media.php?action=update','POST',$login['cookie'],array_replace($update,['title'=>'Changed while media streams']),$auth);
 g3Check($changed['status']===200&&$changed['elapsed']<4,'Same-cookie CSRF control succeeds during the blocked media transfer');
 fclose($probeRequest);fclose($stream);$inflight=[];
 $readyAgain=g3Request($first,'/ready.txt');g3Check($readyAgain['body']===$ready,'Closing the media client releases the fixture HTTP worker');

 $routes=[
  'recording original'=>['/api/recording-media.php?id='.$asset,$mediaSize,$mediaPath,false],
  'claim original'=>['/api/claim-media.php?action=stream&id='.$clip,strlen($wav),$app.'/storage/claim-media/'.$clip.'.bin',true],
  'multicamera export'=>['/api/recording-export.php?action=play&id='.$export,strlen($wav),$app.'/storage/recording-exports/'.$export.'.mp4',true],
  'montage export'=>['/api/montage.php?action=play&id='.$render,strlen($wav),$app.'/storage/renders/'.$render.'.mp4',true],
  'soundtrack'=>['/api/index.php?action=track&id='.$track,strlen($wav),$app.'/storage/audio/'.$track.'.audio',true]
 ];
 foreach($routes as $name=>[$route,$size,$path,$headAllowed]){
  $range=g3Request($second,$route,'GET',$login['cookie'],null,['Range'=>'bytes=4-19']);
  g3Check($range['status']===206&&g3Header($range,'Content-Range')==='bytes 4-19/'.$size&&g3Header($range,'Content-Length')==='16'&&$range['body']===file_get_contents($path,false,null,4,16),$name.' preserves exact bounded Range bytes and headers');
  $suffix=g3Request($second,$route,'GET',$login['cookie'],null,['Range'=>'bytes=-8']);
  g3Check($suffix['status']===206&&g3Header($suffix,'Content-Range')==='bytes '.($size-8).'-'.($size-1).'/'.$size&&$suffix['body']===file_get_contents($path,false,null,$size-8,8),$name.' preserves suffix Range bytes');
  $invalid=g3Request($second,$route,'GET',$login['cookie'],null,['Range'=>'bytes='.$size.'-']);
  g3Check($invalid['status']===416,$name.' rejects out-of-bounds ranges');
  $head=g3Request($second,$route,'HEAD',$login['cookie']);
  g3Check($head['status']===($headAllowed?200:405)&&$head['body']===''&&(!$headAllowed||g3Header($head,'Content-Length')===(string)$size),$name.' preserves its HEAD status, headers and empty body');
 }
 foreach(['/api/recording-export.php?action=download&id='.$export,'/api/montage.php?action=download&id='.$render] as $route){
  $head=g3Request($second,$route,'HEAD',$login['cookie']);
  g3Check($head['status']===200&&str_starts_with(g3Header($head,'Content-Disposition'),'attachment;')&&$head['body']==='','Download keeps attachment disposition and bodyless HEAD');
 }
 $posterRoute='/api/montage.php?action=poster&id='.$render;
 $posterResponse=g3Request($second,$posterRoute,'GET',$login['cookie']);
 g3Check($posterResponse['status']===200&&$posterResponse['body']===$poster&&g3Header($posterResponse,'Content-Type')==='image/jpeg','Poster readfile path preserves its private response bytes and MIME');
 $head=g3Request($second,$posterRoute,'HEAD',$login['cookie']);
 g3Check($head['status']===200&&$head['body']===''&&g3Header($head,'Content-Length')===(string)strlen($poster),'Poster HEAD remains bodyless with the correct length');
 foreach(array_slice($routes,0,4) as [$route]){
  $denied=g3Request($second,$route,'GET','',null,['Range'=>'bytes=0-3']);
  g3Check($denied['status']===403&&g3Json($denied)['ok']===false&&g3Header($denied,'Content-Range')==='','Private media authentication still precedes media framing');
 }
 $otherLogin=g3Login($second,$users[1],$password);
 foreach([$routes['recording original'][0],$routes['multicamera export'][0]] as $route){
  $denied=g3Request($second,$route,'GET',$otherLogin['cookie'],null,['Range'=>'bytes=0-3']);
  g3Check($denied['status']===404&&g3Json($denied)['ok']===false&&g3Header($denied,'Content-Range')==='','Owner-scoped media does not become available to another administrator');
 }
 foreach([$routes['claim original'][0],$routes['montage export'][0]] as $route){
  $shared=g3Request($second,$route,'GET',$otherLogin['cookie'],null,['Range'=>'bytes=0-3']);
  g3Check($shared['status']===206,'Existing legacy shared-administrator media scope is preserved');
 }

 // Closing a session can return true despite the native handler's write warning.
 // The fault server must fail closed before remote work and before media headers.
 $callsBefore=file_get_contents($fixture.'/provider.calls');
 $failure=g3Request($fault,'/api/video-ai.php?action=plan','POST',$login['cookie'],$plan,$auth);
 g3Check($failure['status']===500&&g3Json($failure)['error']==='Server error; see server logs.','Actual AI endpoint fails closed when session persistence warns');
 $failure=g3Request($fault,'/api/video-ai.php?action=plan&sessionFailure=domain','POST',$login['cookie'],$plan,$auth);
 g3Check($failure['status']===500&&g3Json($failure)['error']==='Server error; see server logs.'&&!str_contains($failure['body'],'PRIVATE SESSION WRITER DETAIL'),'A throwing session writer cannot leak its DomainException or turn persistence failure into a client error');
 g3Check(file_get_contents($fixture.'/provider.calls')===$callsBefore,'Failed session persistence prevents any provider invocation');
 foreach(array_merge(array_column(array_slice($routes,0,4),0),[$posterRoute]) as $route){
  $failure=g3Request($fault,$route,'GET',$login['cookie'],null,['Range'=>'bytes=0-3']);
  g3Check($failure['status']===500&&g3Json($failure)['error']==='Server error; see server logs.','Media endpoint fails closed on a real session-write warning');
  g3Check(str_starts_with(g3Header($failure,'Content-Type'),'application/json')&&g3Header($failure,'Content-Range')===''&&g3Header($failure,'Content-Disposition')===''&&g3Header($failure,'Accept-Ranges')===''&&
   (g3Header($failure,'Content-Length')===''||g3Header($failure,'Content-Length')===(string)strlen($failure['body'])),'Session-write failure has clean JSON error framing, not stale media headers');
 }
 g3FastMe($second,$login,'recovery from the failing session writer');
 $changed=g3Request($second,'/api/claim-media.php?action=update','POST',$login['cookie'],array_replace($update,['title'=>'Session still usable']),$auth);
 g3Check($changed['status']===200&&g3Json($changed)['clip']['title']==='Session still usable','Failed slow-operation persistence does not corrupt the prior authenticated session or CSRF');
 echo 'Session concurrency HTTP/MariaDB: '.$checks.'/'.$checks." passed\n";
}catch(Throwable $error){
 foreach(['first','second','write-failure','provider'] as $label){$log=$fixture.'/'.$label.'.log';if(is_file($log))fwrite(STDERR,$label.' log tail: '.substr((string)file_get_contents($log),-4000)."\n");}
 throw $error;
}finally{
 // Unblock controlled workers before termination, then remove only this test's rows/tree.
 if(is_dir($fixture)){file_put_contents($fixture.'/provider.release','cleanup');file_put_contents($fixture.'/probe.release','cleanup');file_put_contents($fixture.'/lock.release','cleanup');}
 foreach($inflight as $stream)if(is_resource($stream))fclose($stream);
 foreach(array_reverse($processes) as $process)g3Stop($process);
 foreach($owners as $id){$db->prepare('DELETE FROM audit_log WHERE admin_id=?')->execute([$id]);$db->prepare('DELETE FROM admins WHERE id=?')->execute([$id]);}
 if($game!==null)$db->prepare('DELETE FROM game_sessions WHERE id=?')->execute([$game]);
 if($track!==null)$db->prepare('DELETE FROM media_tracks WHERE id=?')->execute([$track]);
 if(is_dir($fixture)&&preg_match('/^lucky-session-g3-[a-f0-9]{16}$/D',basename($fixture)))g3Remove($fixture);
}
