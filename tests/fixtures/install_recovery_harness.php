<?php
declare(strict_types=1);
/** Shared, CI-only real HTTP/CLI transport; no application behavior is replaced. */
const G6_DB_PASSWORD='lucky-schema-matrix-fixture-password-2026';
const G6_APP_PASSWORD='lucky-local-application-fixture-password-2026';
const G6_ADMIN_PASSWORD='fixture-administrator-password-2026';
$g6Checks=0;$g6Processes=[];
function g6Check(bool $condition,string $message):void {
 global $g6Checks;
 if(!$condition)throw new RuntimeException('FAIL '.$message);
 ++$g6Checks;echo 'PASS '.$message."\n";
}
function g6Root():PDO {
 if(getenv('CI')!=='true')throw new RuntimeException('Dedicated CI schema-matrix MariaDB on 3307 is required; this suite does not skip.');
 $db=new PDO('mysql:host=127.0.0.1;port=3307;charset=utf8mb4','root',G6_DB_PASSWORD,
  [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>5]);
 g6Check((int)$db->query("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='lucky_schema_matrix'")->fetchColumn()===1,'Dedicated, isolated schema-matrix service is present');
 return $db;
}
function g6Fixture(string $label):string {
 if(!preg_match('/^[a-z-]+$/D',$label))throw new LogicException('Invalid fixture label.');
 $path=sys_get_temp_dir().'/lucky-g6-'.$label.'-'.bin2hex(random_bytes(8));
 if(!mkdir($path,0700))throw new RuntimeException('Cannot create isolated fixture.');
 return $path;
}
function g6Copy(string $source,string $target):void {
 if(!mkdir($target,0700,true)&&!is_dir($target))throw new RuntimeException('Cannot create fixture directory.');
 foreach(new DirectoryIterator($source) as $entry){
  if($entry->isDot())continue;
  if($entry->isLink())throw new RuntimeException('Source fixture must not contain symbolic links.');
  $to=$target.'/'.$entry->getFilename();
  if($entry->isDir())g6Copy($entry->getPathname(),$to);
  elseif(!copy($entry->getPathname(),$to))throw new RuntimeException('Cannot copy fixture source.');
 }
}
function g6App(string $fixture,string $name):string {
 if(!preg_match('/^[a-z0-9-]+$/D',$name))throw new LogicException('Invalid app label.');
 $app=$fixture.'/'.$name;if(!mkdir($app,0700))throw new RuntimeException('Cannot create app fixture.');
 $source=dirname(__DIR__,2);
 foreach(['lib','api','homeserver'] as $directory)g6Copy($source.'/'.$directory,$app.'/'.$directory);
 foreach(['install.php','login.php','first-admin.php','release.json'] as $file)
  if(!copy($source.'/'.$file,$app.'/'.$file))throw new RuntimeException('Cannot copy real '.$file);
 mkdir($app.'/storage',0700);mkdir($app.'/sessions',0700);file_put_contents($app.'/ready.txt','ready');
 foreach(['install.php','homeserver/initialize.php','lib/SchemaMigrations.php','lib/InstallationSetup.php','lib/SetupConfiguration.php'] as $file)
  g6Check(hash_file('sha256',$app.'/'.$file)===hash_file('sha256',$source.'/'.$file),$name.' begins with exact production '.$file);
 return $app;
}
function g6RemoveTree(string $fixture):void {
 $parent=realpath(dirname($fixture));
 if($parent!==realpath(sys_get_temp_dir())||!preg_match('/^lucky-g6-[a-z-]+-[a-f0-9]{16}$/D',basename($fixture))||is_link($fixture))
  throw new LogicException('Refusing cleanup outside owned G6 fixture.');
 $remove=static function(string $path)use(&$remove):void {
  if(is_link($path)||!is_dir($path)){if(file_exists($path)||is_link($path))unlink($path);return;}
  // Fault tests may have made only an owned leaf/directory read-only.
  chmod($path,0700);
  foreach(new DirectoryIterator($path) as $entry)if(!$entry->isDot())$remove($entry->getPathname());
  rmdir($path);
 };
 $remove($fixture);
}
function g6Db(PDO $root,array &$owned,string $name=''):PDO {
 if($name===''){
  $name='lucky_g6_'.bin2hex(random_bytes(8));
  $root->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$owned[]=$name;
 }
 if(!preg_match('/^(lucky_g6_[a-f0-9]{16}|lucky_games)$/D',$name))throw new LogicException('Unowned database.');
 return new PDO('mysql:host=127.0.0.1;port=3307;dbname='.$name.';charset=utf8mb4','root',G6_DB_PASSWORD,
  [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>5]);
}
function g6DropDbs(PDO $root,array $owned):void {
 foreach($owned as $name){
  if(!preg_match('/^lucky_g6_[a-f0-9]{16}$/D',$name))throw new LogicException('Refusing unowned database cleanup.');
  $root->exec('DROP DATABASE `'.$name.'`');
 }
}
function g6Php(string $app):array {
 // The portable local PHP wrapper may inject extension_dir/modules. CI uses
 // php.ini, but propagating its actual directory also makes child probes honest.
 $loader=$app.'/fixture-modules.php';
 if(!is_file($loader))file_put_contents($loader,<<<'PHP'
<?php
foreach(['ctype','fileinfo','pdo_mysql','tokenizer'] as $extension){
 if(!extension_loaded($extension)&&(!function_exists('dl')||!@dl($extension.'.'.PHP_SHLIB_SUFFIX))){fwrite(STDERR,'Required fixture module unavailable: '.$extension."\n");exit(90);}
}
PHP);
 return [PHP_BINARY,'-d','extension_dir='.ini_get('extension_dir'),'-d','enable_dl=1','-d','auto_prepend_file='.$loader,
  '-d','display_errors=0','-d','log_errors=1'];
}
function g6Spawn(string $app,array $command,array $environment,string $label):array {
 global $g6Processes;
 $log=$app.'/fixture-'.$label.'-'.bin2hex(random_bytes(4)).'.log';
 $process=proc_open($command,[0=>['file','/dev/null','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,$app,$environment,['bypass_shell'=>true]);
 if(!is_resource($process))throw new RuntimeException('Cannot start isolated '.$label.'.');
 $state=proc_get_status($process);$g6Processes[]=$process;
 return ['process'=>$process,'pid'=>$state['pid'],'log'=>$log,'app'=>$app];
}
function g6Stop(array $task,bool $crash=false):void {
 $process=$task['process'];if(!is_resource($process))return;
 $state=proc_get_status($process);
 if($state['running']){
  if($state['pid']!==$task['pid'])throw new LogicException('Fixture child identity changed.');
  proc_terminate($process,$crash?9:15);$until=microtime(true)+3;
  do{$state=proc_get_status($process);if(!$state['running'])break;usleep(20000);}while(microtime(true)<$until);
  if($state['running']){proc_terminate($process,9);$until=microtime(true)+3;do{$state=proc_get_status($process);if(!$state['running'])break;usleep(20000);}while(microtime(true)<$until);}
  if($state['running'])throw new RuntimeException('Owned fixture child did not stop.');
 }
 proc_close($process);
}
function g6CleanupProcesses():void {
 global $g6Processes;
 foreach($g6Processes as $process)if(is_resource($process)){
  $state=proc_get_status($process);g6Stop(['process'=>$process,'pid'=>$state['pid']]);
 }
}
function g6Server(string $app):array {
 $socket=stream_socket_server('tcp://127.0.0.1:0',$code,$message);
 if($socket===false)throw new RuntimeException('Cannot reserve fixture port.');
 $address=stream_socket_get_name($socket,false);fclose($socket);
 $environment=getenv();unset($environment['PHP_CLI_SERVER_WORKERS'],$environment['LUCKY_BOOTSTRAP']);
 $task=g6Spawn($app,[...g6Php($app),'-d','session.save_path='.$app.'/sessions','-S',$address,'-t',$app],$environment,'http');
 $task['base']='http://'.$address;
 for($i=0;$i<50;$i++){
  if(!proc_get_status($task['process'])['running'])throw new RuntimeException('Fixture HTTP child exited: '.file_get_contents($task['log']));
  if(@file_get_contents($task['base'].'/ready.txt',false,stream_context_create(['http'=>['timeout'=>0.2]]))==='ready')return $task;
  usleep(50000);
 }
 throw new RuntimeException('Fixture HTTP startup timed out.');
}
function g6Response(string $headers,string $body):array {
 $lines=explode("\r\n",$headers);
 if(!preg_match('~^HTTP/\S+ ([0-9]{3})~',$lines[0]??'',$match))throw new RuntimeException('Missing real HTTP response.');
 $result=['status'=>(int)$match[1],'body'=>$body,'headers'=>[]];
 foreach(array_slice($lines,1) as $line)if(str_contains($line,':')){
  [$key,$value]=explode(':',$line,2);$result['headers'][strtolower(trim($key))][]=trim($value);
 }
 return $result;
}
function g6Http(string $base,string $path,string $cookie='',?array $form=null):array {
 if(!preg_match('~^http://127\.0\.0\.1:[0-9]+$~D',$base)||!str_starts_with($path,'/'))throw new LogicException('Loopback fixture HTTP only.');
 $headers=['Connection: close'];if($cookie!=='')$headers[]='Cookie: '.$cookie;
 if($form!==null)$headers[]='Content-Type: application/x-www-form-urlencoded';
 $context=stream_context_create(['http'=>['method'=>$form===null?'GET':'POST','timeout'=>20,'ignore_errors'=>true,
  'follow_location'=>0,'header'=>$headers,'content'=>$form===null?'':http_build_query($form)]]);
 $body=@file_get_contents($base.$path,false,$context);$lines=$http_response_header??[];
 if(!is_string($body))throw new RuntimeException('Fixture HTTP request did not complete.');
 return g6Response(implode("\r\n",$lines),$body);
}
function g6Begin(string $base,string $path,string $cookie,array $form):mixed {
 if(!preg_match('~^http://(127\.0\.0\.1:[0-9]+)$~D',$base,$match)||!str_starts_with($path,'/'))throw new LogicException('Loopback asynchronous HTTP only.');
 $socket=stream_socket_client('tcp://'.$match[1],$code,$error,3);
 if($socket===false)throw new RuntimeException('Cannot connect fixture request.');
 stream_set_timeout($socket,3);$body=http_build_query($form);
 $request='POST '.$path." HTTP/1.1\r\nHost: ".$match[1]."\r\nConnection: close\r\nCookie: ".$cookie."\r\nContent-Type: application/x-www-form-urlencoded\r\nContent-Length: ".strlen($body)."\r\n\r\n".$body;
 for($offset=0;$offset<strlen($request);){$written=fwrite($socket,substr($request,$offset));if($written===false||$written===0)throw new RuntimeException('Incomplete fixture request write.');$offset+=$written;}
 stream_set_blocking($socket,false);return $socket;
}
function g6Finish(mixed $socket,int $seconds=20):array {
 $until=microtime(true)+$seconds;$response='';
 try{
  while(!feof($socket)&&microtime(true)<$until){
   $read=[$socket];$write=$except=[];$n=stream_select($read,$write,$except,0,100000);
   if($n===false)throw new RuntimeException('Fixture HTTP select failed.');
   if($n>0){$chunk=fread($socket,65536);if($chunk===false)throw new RuntimeException('Fixture HTTP read failed.');$response.=$chunk;}
  }
  if(!feof($socket))throw new RuntimeException('Fixture HTTP completion timed out.');
 }finally{fclose($socket);}
 if(!str_contains($response,"\r\n\r\n"))throw new RuntimeException('Fixture HTTP response was interrupted.');
 [$headers,$body]=explode("\r\n\r\n",$response,2);return g6Response($headers,$body);
}
function g6Cookie(array $response):string {
 foreach($response['headers']['set-cookie']??[] as $header)
  if(preg_match('/^(lucky_hub_sid=[A-Za-z0-9,-]+);/D',$header,$match))return $match[1];
 throw new RuntimeException('Real installer did not set its session cookie.');
}
function g6Csrf(array $response):string {
 if(!preg_match('/name="csrf" value="([a-f0-9]{64})"/D',$response['body'],$match))throw new RuntimeException('Real installer did not render its CSRF field.');
 return $match[1];
}
function g6Form(array $server,PDO $db,string $admin='recovery-admin'):array {
 $form=g6Http($server['base'],'/install.php');g6Check($form['status']===200,'Real setup form is reachable before installation');
 return [g6Cookie($form),['csrf'=>g6Csrf($form),'host'=>'127.0.0.1','port'=>3307,'database'=>$db->query('SELECT DATABASE()')->fetchColumn(),
  'db_user'=>'root','db_password'=>G6_DB_PASSWORD,'admin'=>$admin,'password'=>G6_ADMIN_PASSWORD]];
}
function g6CliStart(string $app,array $overrides=[]):array {
 $environment=getenv();$environment['LUCKY_BOOTSTRAP']='homeserver';$environment['LUCKY_DB_PORT']='3307';
 $environment['LUCKY_DB_ROOT_PASSWORD']=G6_DB_PASSWORD;$environment['LUCKY_DB_APP_PASSWORD']=G6_APP_PASSWORD;
 $environment=array_replace($environment,$overrides);
 return g6Spawn($app,[...g6Php($app),$app.'/homeserver/initialize.php'],$environment,'cli');
}
function g6Wait(array $task,int $seconds=25):array {
 $process=$task['process'];$until=microtime(true)+$seconds;
 do{$state=proc_get_status($process);if(!$state['running'])break;usleep(25000);}while(microtime(true)<$until);
 if($state['running']){g6Stop($task);throw new RuntimeException('Isolated HomeServer initialization timed out.');}
 $code=$state['exitcode'];$closed=proc_close($process);if($code<0)$code=$closed;
 return ['code'=>$code,'output'=>(string)file_get_contents($task['log'])];
}
function g6Cli(string $app,array $overrides=[]):array{return g6Wait(g6CliStart($app,$overrides));}
function g6Rows(PDO $db):array {
 $rows=[];
 foreach(['admins'=>'id','game_settings'=>'game_id','installation_setup_receipt'=>'id'] as $table=>$order)
  $rows[$table]=$db->query('SELECT * FROM `'.$table.'` ORDER BY `'.$order.'`')->fetchAll(PDO::FETCH_ASSOC);
 return $rows;
}
function g6Empty(PDO $db,string $label):void {
 foreach(g6Rows($db) as $table=>$rows)g6Check($rows===[],$label.' leaves no committed '.$table.' rows');
}
function g6SeedFailure(PDO $db):void {
 $db->exec("CREATE TRIGGER g6_seed_fault BEFORE INSERT ON game_settings FOR EACH ROW BEGIN IF NEW.game_id='game-2' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='isolated G6 second-seed fault'; END IF; END");
}
function g6SeedGate(PDO $db,PDO $root):string {
 $name='g6-seed-'.bin2hex(random_bytes(8));
 $q=$root->prepare('SELECT GET_LOCK(?,0)');$q->execute([$name]);if((int)$q->fetchColumn()!==1)throw new RuntimeException('Cannot own seed gate.');
 $literal=$db->quote($name);
 $db->exec("CREATE TRIGGER g6_seed_gate BEFORE INSERT ON game_settings FOR EACH ROW BEGIN IF NEW.game_id='spin-wheel' THEN SET @g6_gate=GET_LOCK(".$literal.",15); SET @g6_release=RELEASE_LOCK(".$literal."); END IF; END");
 return $name;
}
function g6Unlock(PDO $root,string $name):void {$q=$root->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$name]);}
function g6WaitDbBlocked(PDO $root,string $database,int $count=1):array {
 $q=$root->prepare("SELECT ID FROM information_schema.PROCESSLIST WHERE DB=? AND STATE='User lock'");
 $until=microtime(true)+8;
 do{$q->execute([$database]);$ids=$q->fetchAll(PDO::FETCH_COLUMN);if(count($ids)>=$count)return array_map('intval',$ids);usleep(25000);}while(microtime(true)<$until);
 throw new RuntimeException('Fixture never observed '.$count.' real blocked installer connection(s).');
}
function g6WaitDbGone(PDO $root,array $ids):void {
 if($ids===[]||array_filter($ids,static fn($id)=>!is_int($id)||$id<1)!==[])throw new LogicException('Exact owned connection IDs required.');
 $q=$root->prepare('SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID IN ('.implode(',',array_fill(0,count($ids),'?')).')');
 $until=microtime(true)+8;
 do{$q->execute($ids);if((int)$q->fetchColumn()===0)return;usleep(25000);}while(microtime(true)<$until);
 throw new RuntimeException('Killed fixture connections did not close and release their transaction.');
}
function g6Attempt(string $app):array {
 $path=$app.'/storage/.setup-attempt.json';$raw=file_get_contents($path);$value=json_decode((string)$raw,true,512,JSON_THROW_ON_ERROR);
 g6Check(is_array($value)&&($value['version']??null)===1&&preg_match('/^[a-f0-9]{64}$/D',(string)($value['token']??''))===1&&preg_match('/^[a-f0-9]{64}$/D',(string)($value['config_hmac']??''))===1,'Recovery metadata has the real private attempt identity and configuration binding');
 g6Check((fileperms($path)&0077)===0,'Recovery metadata is not group/world readable');
 g6Check(!str_contains((string)$raw,G6_DB_PASSWORD)&&!str_contains((string)$raw,G6_ADMIN_PASSWORD)&&!str_contains((string)$raw,G6_APP_PASSWORD),'Recovery metadata contains no database/admin plaintext passwords');
 return ['bytes'=>$raw,'value'=>$value];
}
function g6Receipt(PDO $db,string $state,string $deployment):array {
 $rows=$db->query('SELECT * FROM installation_setup_receipt')->fetchAll(PDO::FETCH_ASSOC);
 g6Check(count($rows)===1&&($rows[0]['state']??null)===$state&&($rows[0]['deployment']??null)===$deployment,'Exactly one '.$deployment.' recovery receipt is '.$state);
 return $rows[0];
}
function g6Login(array $server,string $admin):void {
 $form=g6Http($server['base'],'/login.php');$cookie=g6Cookie($form);
 $response=g6Http($server['base'],'/login.php',$cookie,['csrf'=>g6Csrf($form),'admin'=>$admin,'password'=>G6_ADMIN_PASSWORD]);
 g6Check($response['status']===303,'Recovered administrator signs in through unchanged login endpoint');
}
function g6Marker(string $path,int $seconds=10):void {
 $until=microtime(true)+$seconds;
 do{clearstatcache(true,$path);if(is_file($path))return;usleep(20000);}while(microtime(true)<$until);
 throw new RuntimeException('Bounded crash-window marker was never reached.');
}
function g6PausePublication(string $app,string $point):array {
 if(!in_array($point,['before','after'],true))throw new LogicException('Unknown crash boundary.');
 $path=$app.'/lib/InstallationSetup.php';$original=file_get_contents($path);$marker=$app.'/fixture-publish-'.$point.'.marker';
 if(!is_string($original)||substr_count($original,'$publish();')!==1)throw new RuntimeException('Production publish boundary changed; review the crash fixture.');
 // Only this throwaway copy gains a deterministic scheduling pause. Neither
 // the real entrypoint nor SQL/publisher behavior changes. The owning test
 // observes the real committed rows/config, kills this exact child, restores
 // the original byte-for-byte file, then retries the unchanged entrypoint.
 $pause='file_put_contents('.var_export($marker,true).',"paused",LOCK_EX); while(true){usleep(50000);}';
 $replacement=$point==='before'?$pause.' $publish();':'$publish(); '.$pause;
 file_put_contents($path,str_replace('$publish();',$replacement,$original));
 return ['path'=>$path,'original'=>$original,'marker'=>$marker];
}
function g6RestorePublication(array $pause):void {
 file_put_contents($pause['path'],$pause['original']);
 g6Check(hash_file('sha256',$pause['path'])===hash('sha256',$pause['original']),'Crash fixture restores byte-identical production setup service before retry');
}
