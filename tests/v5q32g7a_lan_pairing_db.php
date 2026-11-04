<?php
declare(strict_types=1);
/**
 * Real HTTP login, native file sessions, production pairing endpoints, and MariaDB.
 * The app/config/network files live only in a unique temporary fixture. No production
 * test bypass, proxy/TLS emulation, external request, or live storage is used. Caddy
 * listener isolation and private-file denial are separate native Windows gates.
 */
if(getenv('CI')!=='true')throw new RuntimeException('CI MariaDB only.');
require_once __DIR__.'/../lib/SchemaMigrations.php';
require_once __DIR__.'/../lib/PrizePackages.php';
require_once __DIR__.'/../lib/ControllerPairing.php';

$checks=0;
function lanCheck(bool $pass,string $message):void {
 global $checks;
 if(!$pass)throw new RuntimeException('FAIL '.$message);
 ++$checks;echo 'PASS '.$message."\n";
}
function lanWrite(string $path,string $bytes):void {
 if(file_put_contents($path,$bytes,LOCK_EX)!==strlen($bytes))throw new RuntimeException('Fixture write failed.');
}
function lanCopy(string $source,string $target):void {
 if(!mkdir($target,0700,true)&&!is_dir($target))throw new RuntimeException('Cannot create isolated fixture directory.');
 foreach(new DirectoryIterator($source) as $entry){
  if($entry->isDot())continue;
  if($entry->isLink())throw new RuntimeException('Fixture source contains a symlink.');
  $dest=$target.'/'.$entry->getFilename();
  if($entry->isDir())lanCopy($entry->getPathname(),$dest);
  elseif(!copy($entry->getPathname(),$dest))throw new RuntimeException('Cannot copy production fixture source.');
 }
}
function lanRemove(string $path):void {
 if(is_link($path)||!is_dir($path)){if(file_exists($path)||is_link($path))unlink($path);return;}
 foreach(new DirectoryIterator($path) as $entry)if(!$entry->isDot())lanRemove($entry->getPathname());
 rmdir($path);
}
function lanStop(mixed $process):void {
 if(!is_resource($process))return;
 if(proc_get_status($process)['running']){
  proc_terminate($process);
  for($i=0;$i<20&&proc_get_status($process)['running'];$i++)usleep(50000);
  if(proc_get_status($process)['running'])proc_terminate($process,9);
 }
 proc_close($process);
}
function lanRequest(string $base,string $path,string $method='GET',string $cookie='',?array $json=null,array $headers=[],?string $body=null):array {
 if(!preg_match('~^http://127\\.0\\.0\\.1:[0-9]+/school/lucky$~D',$base)||
    !str_starts_with($path,'/')||preg_match('/[\\r\\n]/',$path))throw new LogicException('Non-loopback fixture request.');
 $headers+=['Connection'=>'close'];
 if($cookie!=='')$headers['Cookie']=$cookie;
 if($json!==null){$body=json_encode($json,JSON_THROW_ON_ERROR);$headers['Content-Type']='application/json';}
 if($body!==null)$headers['Content-Length']=(string)strlen($body);
 $lines=[];
 foreach($headers as $name=>$value){
  if(preg_match('/[\\r\\n]/',(string)$name.(string)$value))throw new LogicException('Invalid fixture header.');
  $lines[]=$name.': '.$value;
 }
 $context=stream_context_create(['http'=>['method'=>$method,'protocol_version'=>1.1,'timeout'=>8,
  'ignore_errors'=>true,'follow_location'=>0,'header'=>$lines,'content'=>$body??'']]);
 $stream=fopen($base.$path,'rb',false,$context);
 if($stream===false)throw new RuntimeException('Cannot contact fixture HTTP server.');
 try{$raw=stream_get_contents($stream,1048577);$meta=stream_get_meta_data($stream);}finally{fclose($stream);}
 if(!is_string($raw)||strlen($raw)>1048576||($meta['timed_out']??false))throw new RuntimeException('Fixture HTTP response exceeded bounds.');
 $response=['status'=>0,'headers'=>[],'body'=>$raw];
 foreach($meta['wrapper_data']??[] as $header){
  if(preg_match('~^HTTP/\\S+ ([0-9]{3})~',$header,$match))$response['status']=(int)$match[1];
  elseif(str_contains($header,':')){[$name,$value]=explode(':',$header,2);$response['headers'][strtolower(trim($name))][]=trim($value);}
 }
 if($response['status']===0)throw new RuntimeException('Fixture response has no HTTP status.');
 return $response;
}
function lanJson(array $response):array {
 try{$json=json_decode($response['body'],true,32,JSON_THROW_ON_ERROR);}
 catch(JsonException $e){throw new RuntimeException('Invalid JSON HTTP response with status '.$response['status'],0,$e);}
 if(!is_array($json))throw new RuntimeException('Fixture response is not a JSON object.');
 return $json;
}
function lanHeader(array $response,string $name):string{return implode(', ',$response['headers'][strtolower($name)]??[]);}
function lanCookie(array $response):string {
 foreach($response['headers']['set-cookie']??[] as $header)
  if(preg_match('/^(lucky_hub_sid=[A-Za-z0-9,-]+);/D',$header,$match))return $match[1];
 throw new RuntimeException('Real login did not return a session cookie.');
}
function lanLogin(string $base,string $name,string $password):array {
 $form=lanRequest($base,'/login.php');$anonymous=lanCookie($form);
 if(!preg_match('/name="csrf" value="([a-f0-9]{64})"/D',$form['body'],$match))throw new RuntimeException('Real login form has no CSRF token.');
 $login=lanRequest($base,'/login.php','POST',$anonymous,null,['Content-Type'=>'application/x-www-form-urlencoded'],
  http_build_query(['admin'=>$name,'password'=>$password,'csrf'=>$match[1]]));
 lanCheck($login['status']===303&&lanHeader($login,'Location')==='admin.php','Real nested-install login authenticates and redirects');
 $cookie=lanCookie($login);
 lanCheck($cookie!==$anonymous&&str_contains(strtolower(lanHeader($login,'Set-Cookie')),'httponly'),
  'Login regenerates its native session and retains HttpOnly protection');
 $me=lanRequest($base,'/api/index.php?action=me','GET',$cookie);$identity=lanJson($me);
 lanCheck($me['status']===200&&($identity['admin']??null)===true&&
  is_string($identity['csrf']??null)&&preg_match('/^[a-f0-9]{64}$/D',$identity['csrf'])===1,
  'Real HTTP identity and CSRF persist across requests');
 return ['cookie'=>$cookie,'csrf'=>$identity['csrf']];
}
function lanSnapshot(PDO $db,array $owners,int $auditStart):array {
 $marks=implode(',',array_fill(0,count($owners),'?'));
 $q=$db->prepare('SELECT * FROM controller_pairs WHERE host_admin_id IN ('.$marks.') ORDER BY id');$q->execute($owners);
 $snapshot=['pairs'=>$q->fetchAll(),'pairTotal'=>(int)$db->query('SELECT COUNT(*) FROM controller_pairs')->fetchColumn()];
 $q=$db->prepare('SELECT * FROM audit_log WHERE id>? ORDER BY id');$q->execute([$auditStart]);$snapshot['audit']=$q->fetchAll();
 $q=$db->prepare('SELECT c.* FROM controller_commands c JOIN controller_pairs p ON p.id=c.pair_id WHERE p.host_admin_id IN ('.$marks.') ORDER BY c.id');
 $q->execute($owners);$snapshot['commands']=$q->fetchAll();return $snapshot;
}
function lanReject(PDO $db,array $owners,int $auditStart,callable $request,int $status,string $message):void {
 $before=lanSnapshot($db,$owners,$auditStart);$response=$request();$json=lanJson($response);
 lanCheck($response['status']===$status&&($json['ok']??null)===false&&is_string($json['error']??null),
  $message.' (HTTP '.$response['status'].')');
 lanCheck(lanSnapshot($db,$owners,$auditStart)===$before,$message.' changes no pairing, command, or audit rows');
}
function lanStart(string $documentRoot,string $fixture,array &$processes):string {
 $socket=stream_socket_server('tcp://127.0.0.1:0',$code,$message);
 if($socket===false)throw new RuntimeException('Cannot reserve loopback fixture port.');
 $address=stream_socket_get_name($socket,false);fclose($socket);
 if(!is_string($address)||!preg_match('/^127\\.0\\.0\\.1:[0-9]+$/D',$address))throw new RuntimeException('Invalid fixture address.');
 $env=getenv();if(!is_array($env))$env=[];unset($env['PHP_CLI_SERVER_WORKERS']);
 $process=proc_open([PHP_BINARY,'-d','display_errors=0','-d','log_errors=1','-d','opcache.enable_cli=0',
  '-d','session.save_handler=files','-d','session.save_path='.$fixture.'/sessions','-d','session.gc_probability=0',
  '-S',$address,'-t',$documentRoot],[0=>['file','/dev/null','r'],1=>['file',$fixture.'/server.log','a'],
  2=>['file',$fixture.'/server.log','a']],$pipes,$documentRoot,$env,['bypass_shell'=>true]);
 if(!is_resource($process))throw new RuntimeException('Cannot launch real PHP fixture server.');
 $processes[]=$process;$base='http://'.$address.'/school/lucky';
 for($i=0;$i<50;$i++){
  if(!proc_get_status($process)['running'])throw new RuntimeException('Fixture server stopped during startup.');
  $context=stream_context_create(['http'=>['timeout'=>0.2,'ignore_errors'=>true,'follow_location'=>0]]);
  if(@file_get_contents($base.'/ready.txt',false,$context)==='lan-fixture-ready')return $base;
  usleep(100000);
 }
 throw new RuntimeException('Fixture server did not become ready.');
}
function lanConfig(string $app,string $deployment,array $override=[]):void {
 $config=['deployment'=>$deployment,'db_host'=>'127.0.0.1','db_port'=>3306,'db_name'=>'lucky_test',
  'db_user'=>'root','db_pass'=>'luckytestpassword'];
 $config=array_replace($config,$override);
 lanWrite($app.'/storage/config.php','<?php return '.var_export($config,true).';');
}
function lanNetwork(string $app,?string $raw,?int $mtime=null):void {
 $path=$app.'/storage/'.InstallationUrls::NETWORK_FILE;
 if(is_dir($path)&&!is_link($path))rmdir($path);
 elseif(file_exists($path)||is_link($path))unlink($path);
 if($raw!==null){lanWrite($path,$raw);if($mtime!==null&&!touch($path,$mtime))throw new RuntimeException('Cannot age fixture metadata.');}
 clearstatcache(true,$path);
}

$db=new PDO('mysql:host=127.0.0.1;port=3306;dbname=lucky_test;charset=utf8mb4','root','luckytestpassword',[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$db->exec("SET time_zone='+00:00'");SchemaMigrations::ensure($db);
$auditStart=(int)$db->query('SELECT COALESCE(MAX(id),0) FROM audit_log')->fetchColumn();
$fixture=rtrim(sys_get_temp_dir(),DIRECTORY_SEPARATOR).'/lucky-lan-g7a-'.bin2hex(random_bytes(8));
$processes=[];$owners=[];$pairs=[];$package=null;$createdGame=false;$createdSelection=false;
try{
 mkdir($fixture,0700);mkdir($fixture.'/sessions',0700);$app=$fixture.'/www/school/lucky';
 lanCopy(__DIR__.'/../lib',$app.'/lib');lanCopy(__DIR__.'/../api',$app.'/api');
 if(!copy(__DIR__.'/../login.php',$app.'/login.php'))throw new RuntimeException('Cannot copy real login endpoint.');
 mkdir($app.'/storage',0700);lanConfig($app,'cloud');lanWrite($app.'/ready.txt','lan-fixture-ready');
 foreach(['login.php','lib/Bootstrap.php','lib/InstallationUrls.php','lib/ControllerPairing.php','api/controller.php','api/index.php','api/installation-urls.php'] as $file)
  lanCheck(hash_file('sha256',$app.'/'.$file)===hash_file('sha256',__DIR__.'/../'.$file),'HTTP fixture uses exact production '.$file);

 $password='fixture-only-'.bin2hex(random_bytes(12));$names=[];
 foreach(['owner','other'] as $kind){
  $name='lan-g7a-'.$kind.'-'.bin2hex(random_bytes(6));$names[]=$name;
  $db->prepare('INSERT INTO admins(username,password_hash) VALUES (?,?)')->execute([$name,password_hash($password,PASSWORD_DEFAULT)]);
  $owners[]=(int)$db->lastInsertId();
 }
 // Only insert fixture-owned rows. Existing settings/packages are never overwritten.
 if($db->query("SELECT game_id FROM game_settings WHERE game_id='spin-wheel'")->fetchColumn()===false){
  $db->prepare('INSERT INTO game_settings(game_id,config_json,intro_json,theme_json) VALUES (?,?,?,?)')
   ->execute(['spin-wheel',json_encode(GameEngine::defaults(),JSON_THROW_ON_ERROR),'{}','{}']);$createdGame=true;
 }
 $package=bin2hex(random_bytes(16));$packageName='LAN regression '.bin2hex(random_bytes(6));
 $db->prepare('INSERT INTO prize_packages(id,game_id,name,config_json) VALUES (?,?,?,?)')
  ->execute([$package,'spin-wheel',$packageName,json_encode(GameEngine::defaults(),JSON_THROW_ON_ERROR)]);
 if($db->query("SELECT game_id FROM prize_package_selection WHERE game_id='spin-wheel'")->fetchColumn()===false){
  $db->prepare('INSERT INTO prize_package_selection(game_id,package_id) VALUES (?,?)')->execute(['spin-wheel',$package]);$createdSelection=true;
 }
 $base=lanStart($fixture.'/www',$fixture,$processes);$host=lanLogin($base,$names[0],$password);$other=lanLogin($base,$names[1],$password);
 $origin=parse_url($base,PHP_URL_SCHEME).'://'.parse_url($base,PHP_URL_HOST).':'.parse_url($base,PHP_URL_PORT);
 $hostHeaders=['X-CSRF-Token'=>$host['csrf'],'Origin'=>$origin];
 $spoof=['X-Forwarded-Host'=>'attacker.example','X-Forwarded-Proto'=>'https','Forwarded'=>'for=192.168.9.9;host=attacker.example;proto=https'];
 $invite=static fn(array $headers=[]):array=>lanRequest($base,'/api/controller.php?action=invite','POST',$host['cookie'],[],$headers+$hostHeaders);
 $reject=static function(callable $request,int $status,string $label)use($db,$owners,$auditStart):void{
  lanReject($db,$owners,$auditStart,$request,$status,$label);
 };
 $fresh=json_encode(['version'=>1,'origin'=>'https://192.168.10.42:8843'],JSON_THROW_ON_ERROR);
 lanNetwork($app,$fresh);
 $metadata=lanRequest($base,'/api/installation-urls.php','GET','',null,$spoof);$publicMeta=lanJson($metadata);
 lanCheck($metadata['status']===200&&$publicMeta===['ok'=>true,'deployment'=>'cloud','lanOrigin'=>'','leaseSeconds'=>0],
  'Public cloud metadata ignores HomeServer files and forged forwarding headers');
 lanCheck(str_contains(strtolower(lanHeader($metadata,'Cache-Control')),'no-store')&&lanHeader($metadata,'Access-Control-Allow-Origin')==='',
  'Metadata response is not cached and grants no cross-origin browser access');
 $reject(fn()=>lanRequest($base,'/api/installation-urls.php','POST','',[]),405,'Metadata endpoint refuses mutation methods');
 $cloud=$invite($spoof);$cloudJson=lanJson($cloud);
 lanCheck($cloud['status']===201&&($cloudJson['ok']??null)===true,'Cloud invitation succeeds with spoofed forwarding headers and a stale HomeServer file');
 lanCheck(preg_match('/^controller\\.php\\?invite=([a-f0-9]{32})&key=([a-f0-9]{64})$/D',$cloudJson['url']??'',$match)===1,
  'Cloud URL remains installation-relative and ignores forwarding headers and LAN metadata');
 $pairs[]=$cloudJson['id'];
 lanCheck($cloudJson['id']===$match[1]&&!str_contains($cloudJson['url'],'attacker.example')&&!str_contains($cloudJson['url'],'192.168.'),
  'Nested hosted installation keeps its own domain/path when resolving the relative invitation');

 lanConfig($app,'homeserver');
 $metadata=lanRequest($base,'/api/installation-urls.php','GET','',null,$spoof);$publicMeta=lanJson($metadata);
 $keys=array_keys($publicMeta);sort($keys);
 $expectedKeys=$keys===['deployment','lanOrigin','leaseSeconds','ok'];
 $deployment=$publicMeta['deployment']??null;
 $originMatches=($publicMeta['lanOrigin']??null)==='https://192.168.10.42:8843';
 $lease=$publicMeta['leaseSeconds']??null;
 $validMetadata=$metadata['status']===200&&$expectedKeys&&$publicMeta['ok']===true&&
  $deployment==='homeserver'&&$originMatches&&is_int($lease)&&$lease>0&&$lease<=30;
 if(!$validMetadata){
  $networkPath=$app.'/storage/'.InstallationUrls::NETWORK_FILE;
  clearstatcache(true,$networkPath);
  $mtime=filemtime($networkPath);
  echo 'LAN fixture diagnostics '.json_encode([
   'status'=>$metadata['status'],'expectedKeys'=>$expectedKeys,
   'deployment'=>in_array($deployment,['cloud','homeserver'],true)?$deployment:'unexpected',
   'originMatches'=>$originMatches,'leaseSeconds'=>is_int($lease)?$lease:null,
   'fileAgeSeconds'=>$mtime===false?null:time()-$mtime,
  ],JSON_THROW_ON_ERROR)."\n";
 }
 lanCheck($validMetadata,
  'Unauthenticated refresh returns only the canonical LAN origin and bounded remaining lease, never credentials or account data');
 $beforeMetadata=lanSnapshot($db,$owners,$auditStart);
 lanConfig($app,'homeserver',['db_name'=>'missing_lan_fixture_'.bin2hex(random_bytes(8)),
  'db_user'=>'missing_lan_fixture_'.bin2hex(random_bytes(8)),'db_pass'=>'fixture-invalid']);
 try{
  $noDatabase=lanRequest($base,'/api/installation-urls.php');$noDatabaseMeta=lanJson($noDatabase);
  lanCheck($noDatabase['status']===200&&($noDatabaseMeta['lanOrigin']??'')==='https://192.168.10.42:8843',
   'Metadata refresh works with intentionally unusable DB credentials and never connects to the database');
 }finally{lanConfig($app,'homeserver');}
 lanCheck(lanSnapshot($db,$owners,$auditStart)===$beforeMetadata,'Public metadata reads do not mutate pairing, command, or audit state');
 foreach([
  'missing'=>[null,null],
  'expired'=>[$fresh,time()-InstallationUrls::LEASE_SECONDS-2],
  'future'=>[$fresh,time()+60],
  'malformed JSON'=>['{',null],
  'oversized'=>[str_repeat(' ',8193),null],
  'string version'=>[json_encode(['version'=>'1','origin'=>'https://192.168.10.42:8843'],JSON_THROW_ON_ERROR),null],
  'unknown field'=>[json_encode(['version'=>1,'origin'=>'https://192.168.10.42:8843','extra'=>true],JSON_THROW_ON_ERROR),null],
  'external HTTPS'=>[json_encode(['version'=>1,'origin'=>'https://attacker.example:8843'],JSON_THROW_ON_ERROR),null],
  'cleartext LAN'=>[json_encode(['version'=>1,'origin'=>'http://192.168.10.42:8843'],JSON_THROW_ON_ERROR),null],
  'public IP'=>[json_encode(['version'=>1,'origin'=>'https://8.8.8.8:8843'],JSON_THROW_ON_ERROR),null],
  'loopback IP'=>[json_encode(['version'=>1,'origin'=>'https://127.0.0.1:8843'],JSON_THROW_ON_ERROR),null],
  'credential-bearing origin'=>[json_encode(['version'=>1,'origin'=>'https://admin@192.168.10.42:8843'],JSON_THROW_ON_ERROR),null],
 ] as $label=>[$raw,$mtime]){
  lanNetwork($app,$raw,$mtime);$reject(fn()=>$invite($spoof),409,$label.' LAN metadata fails closed despite forged forwarding headers');
 }
 lanNetwork($app,null);mkdir($app.'/storage/'.InstallationUrls::NETWORK_FILE,0700);
 $reject(fn()=>$invite(),409,'A directory cannot impersonate launcher network metadata');
 lanNetwork($app,null);lanWrite($fixture.'/network-target.json',$fresh);
 if(!symlink($fixture.'/network-target.json',$app.'/storage/'.InstallationUrls::NETWORK_FILE))throw new RuntimeException('Cannot create required symlink negative control.');
 $reject(fn()=>$invite(),409,'A symlink cannot impersonate launcher network metadata');
 lanNetwork($app,$fresh);
 $reject(fn()=>$invite(['X-CSRF-Token'=>str_repeat('0',64)]),403,'Wrong CSRF cannot mint a LAN capability');
 $reject(fn()=>lanRequest($base,'/api/controller.php?action=invite','POST','',[],['Origin'=>$origin]),403,'Anonymous request cannot mint a LAN capability');
 $reject(fn()=>$invite(['Origin'=>'https://attacker.example']),403,'Cross-origin host request remains forbidden');
 $reject(fn()=>$invite(['Host'=>'attacker.example']+$spoof),403,'An arbitrary Host cannot become a trusted cleartext pairing address');

 $home=$invite($spoof);$homeJson=lanJson($home);
 lanCheck($home['status']===201&&($homeJson['ok']??null)===true,'A fresh launcher-owned LAN origin enables the HomeServer invitation');
 lanCheck(preg_match('~^https://192\\.168\\.10\\.42:8843/controller\\.php\\?invite=([a-f0-9]{32})&key=([a-f0-9]{64})$~D',$homeJson['url']??'',$match)===1,
  'HomeServer invitation targets only the canonical HTTPS LAN controller route');
 $id=$match[1];$secret=$match[2];$pairs[]=$id;
 lanCheck($homeJson['id']===$id&&!str_contains($homeJson['url'],$host['csrf'])&&!str_contains($homeJson['url'],$host['cookie']),
  'Share URL contains only the intended invitation capability, never host CSRF or session credentials');
 $q=$db->prepare('SELECT * FROM controller_pairs WHERE id=?');$q->execute([$id]);$row=$q->fetch();
 lanCheck($row!==false&&(int)$row['host_admin_id']===$owners[0]&&$row['invite_hash']===ControllerPairing::tokenHash($secret)&&
  $row['controller_hash']===null&&$row['status']==='inviting','MariaDB stores the owner-bound invitation hash, not the raw secret');
 $reject(fn()=>lanRequest($base,'/api/controller.php?action=claim','POST','',['id'=>$id,'key'=>str_repeat('0',64),'name'=>'LAN phone'],['Origin'=>$origin]),403,
  'An incorrect invitation secret cannot claim the phone');
 $claim=lanRequest($base,'/api/controller.php?action=claim','POST','',['id'=>$id,'key'=>$secret,'name'=>'LAN regression phone'],['Origin'=>$origin]);$claimed=lanJson($claim);
 lanCheck($claim['status']===200&&($claimed['status']??'')==='pending'&&preg_match('/^[a-f0-9]{64}$/D',$claimed['controllerToken']??'')===1,
  'Phone claims the real invitation without a host login and remains pending approval');
 $controllerToken=$claimed['controllerToken'];$phoneHeaders=['Origin'=>$origin,'X-Controller-Token'=>$controllerToken];
 $q->execute([$id]);$claimedRow=$q->fetch();
 lanCheck($claimedRow['controller_hash']===ControllerPairing::tokenHash($controllerToken)&&$claimedRow['status']==='pending',
  'Phone bearer is hashed and pending state is persisted before host approval');
 $reject(fn()=>lanRequest($base,'/api/controller.php?action=claim','POST','',['id'=>$id,'key'=>$secret,'name'=>'Replay'],['Origin'=>$origin]),403,
  'An already-used invitation cannot be replayed');
 $pending=lanRequest($base,'/api/controller.php?action=status','POST','',['id'=>$id],$phoneHeaders);
 lanCheck($pending['status']===200&&(lanJson($pending)['status']??'')==='pending','Bearer status reports pending without granting host access');
 $reject(fn()=>lanRequest($base,'/api/controller.php?action=approve','POST',$other['cookie'],['id'=>$id],['Origin'=>$origin,'X-CSRF-Token'=>$other['csrf']]),404,
  'A different authenticated host cannot approve this phone');
 $reject(fn()=>lanRequest($base,'/api/controller.php?action=approve','POST','',['id'=>$id],$phoneHeaders),403,
  'Phone bearer cannot substitute for an administrator approval session');
 $approved=lanRequest($base,'/api/controller.php?action=approve','POST',$host['cookie'],['id'=>$id],$hostHeaders);
 lanCheck($approved['status']===200&&(lanJson($approved)['status']??'')==='paired','Owning host approves through the unchanged same-origin admin endpoint');
 // Expired advertising state must stop NEW sharing, not disconnect an established pair.
 lanNetwork($app,$fresh,time()-InstallationUrls::LEASE_SECONDS-2);
 $expiredMeta=lanRequest($base,'/api/installation-urls.php','GET','',null,$spoof);
 lanCheck($expiredMeta['status']===200&&lanJson($expiredMeta)===['ok'=>true,'deployment'=>'homeserver','lanOrigin'=>'','leaseSeconds'=>0],
  'An already-open client can refresh expired metadata to an unavailable origin without a database mutation');
 $paired=lanRequest($base,'/api/controller.php?action=status','POST','',['id'=>$id],$phoneHeaders);$pairedJson=lanJson($paired);
 lanCheck($paired['status']===200&&($pairedJson['status']??'')==='paired'&&($pairedJson['deviceName']??'')==='LAN regression phone',
  'Existing approved pairing survives metadata expiry; lease controls only new share creation');
 $reject(fn()=>$invite(),409,'Expired advertising lease cannot mint another invitation after pairing');
 $revoked=lanRequest($base,'/api/controller.php?action=revoke','POST',$host['cookie'],['id'=>$id],$hostHeaders);
 lanCheck($revoked['status']===200&&(lanJson($revoked)['status']??'')==='revoked','Host revocation still works when LAN advertising is unavailable');
 $reject(fn()=>lanRequest($base,'/api/controller.php?action=status','POST','',['id'=>$id],$phoneHeaders),403,'Revoked bearer cannot regain phone access');

 // Persist portable image/video/cover paths through the real versioned service.
 $mediaId=bin2hex(random_bytes(16));$image='api/index.php?action=prize_image&id='.$mediaId;
 $video='/school/lucky/api/index.php?action=prize_video&id='.$mediaId;
 $config=GameEngine::defaults();$config['pools']['tier1']['prizes'][0]['image']=$image;
 $config['pools']['tier1']['prizes'][0]['videoUrl']=$video;
 $config['pools']['tier1']['prizes'][0]['url']='https://example.invalid/product';
 $db->beginTransaction();$saved=PrizePackages::save($db,$package,1,$packageName,$config,true,true,null,$image);$db->commit();
 lanCheck($saved['version']===2&&$saved['image']===$image,'Versioned prize-package service accepts and persists a portable cover path');
 $reader=new PDO('mysql:host=127.0.0.1;port=3306;dbname=lucky_test;charset=utf8mb4','root','luckytestpassword',[
  PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
 $q=$reader->prepare('SELECT config_json,image_url,version FROM prize_packages WHERE id=?');$q->execute([$package]);$stored=$q->fetch();
 $storedConfig=json_decode($stored['config_json'],true,512,JSON_THROW_ON_ERROR);
 lanCheck((int)$stored['version']===2&&$stored['image_url']===$image&&$storedConfig['pools']['tier1']['prizes'][0]['image']===$image&&
  $storedConfig['pools']['tier1']['prizes'][0]['videoUrl']===$video,'A separate MariaDB connection reloads exact portable media paths with no desktop-origin prefix');
 lanCheck($storedConfig['pools']['tier1']['prizes'][0]['url']==='https://example.invalid/product','Ordinary third-party product URLs remain unchanged');
 $rowBefore=$stored;
 foreach([
  ['image','api/index.php?action=logout&id='.$mediaId,$image],
  ['image','../api/index.php?action=prize_image&id='.$mediaId,$image],
  ['image',$image.'&token=foreign',$image],
  ['videoUrl',$image,$image],
  ['url',$image,$image],
  ['image',$image,'api/index.php?action=track&id='.$mediaId],
 ] as [$field,$value,$cover]){
  $bad=$config;$bad['pools']['tier1']['prizes'][0][$field]=$value;$caught=false;
  $db->beginTransaction();
  try{PrizePackages::save($db,$package,2,$packageName,$bad,true,true,null,$cover);}
  catch(DomainException){$caught=true;}
  finally{if($db->inTransaction())$db->rollBack();}
  lanCheck($caught,'Portable-media exception does not relax '.$field.' or cover route/type validation');
  $q->execute([$package]);lanCheck($q->fetch()===$rowBefore,'Rejected media edit leaves the committed package bytes and version unchanged');
 }
 echo 'V5Q32G7A real HTTP/MariaDB LAN pairing and media persistence PASSED '.$checks.'/'.$checks."\n";
}finally{
 foreach($processes as $process)lanStop($process);
 if($db->inTransaction())$db->rollBack();
 // Identify anonymous claim audit rows by this fixture's random pair IDs only.
 if($owners){
  $marks=implode(',',array_fill(0,count($owners),'?'));
  $q=$db->prepare('SELECT id FROM controller_pairs WHERE host_admin_id IN ('.$marks.')');$q->execute($owners);
  $ownedPairs=array_merge($pairs,$q->fetchAll(PDO::FETCH_COLUMN));
  $q=$db->prepare('SELECT id,admin_id,details FROM audit_log WHERE id>?');$q->execute([$auditStart]);$delete=$db->prepare('DELETE FROM audit_log WHERE id=?');
  foreach($q->fetchAll() as $audit){
   $details=json_decode($audit['details'],true);
   if(in_array((int)$audit['admin_id'],$owners,true)||in_array($details['pairId']??null,$ownedPairs,true))$delete->execute([$audit['id']]);
  }
  $db->prepare('DELETE FROM admins WHERE id IN ('.$marks.')')->execute($owners);
 }
 if($createdSelection)$db->prepare('DELETE FROM prize_package_selection WHERE game_id=? AND package_id=?')->execute(['spin-wheel',$package]);
 if($package!==null)$db->prepare('DELETE FROM prize_packages WHERE id=?')->execute([$package]);
 if($createdGame)$db->prepare('DELETE FROM game_settings WHERE game_id=?')->execute(['spin-wheel']);
 if(is_dir($fixture))lanRemove($fixture);
}
