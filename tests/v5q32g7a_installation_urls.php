<?php
declare(strict_types=1);
require_once __DIR__.'/../lib/InstallationUrls.php';
require_once __DIR__.'/../lib/GameEngine.php';
require_once __DIR__.'/../lib/ControllerPairing.php';

$checks=0;
function lanCheck(bool $pass,string $label):void {
 global $checks;
 if(!$pass)throw new RuntimeException('FAIL '.$label);
 ++$checks;echo 'PASS '.$label."\n";
}
function lanReject(callable $call,string $label,int $status=422):void {
 try{$call();}catch(DomainException $error){lanCheck($error->getCode()===$status,$label);return;}
 throw new RuntimeException('FAIL accepted '.$label);
}
$fixture=sys_get_temp_dir().'/lucky-lan-g7a-'.bin2hex(random_bytes(12));
if(!mkdir($fixture,0700))throw new RuntimeException('Cannot create isolated LAN fixture.');
$path=$fixture.'/'.InstallationUrls::NETWORK_FILE;$now=time();
function lanWrite(string $path,mixed $value,int $mtime):void {
 if(file_put_contents($path,is_string($value)?$value:json_encode($value,JSON_THROW_ON_ERROR))===false||!touch($path,$mtime))
  throw new RuntimeException('Cannot write isolated LAN fixture.');
 clearstatcache(true,$path);
}
$home=['deployment'=>'homeserver'];$cloud=['deployment'=>'cloud'];
$valid=['version'=>1,'origin'=>'https://192.168.1.20:8843'];
$unavailable=['deployment'=>'homeserver','lanOrigin'=>'','leaseSeconds'=>0];
try{
 lanCheck(InstallationUrls::metadata($home,$fixture,$now)===$unavailable,'Missing LAN does not prevent local application metadata');
 foreach(['https://10.0.0.8:8843','https://172.16.0.1:8843','https://172.31.255.254:8843','https://192.168.1.20:8843'] as $origin){
  lanCheck(InstallationUrls::validLanOrigin($origin),'Canonical private LAN origin accepted: '.$origin);
 }
 foreach([
  null,0,[],true,'','http://192.168.1.20:8843','HTTPS://192.168.1.20:8843',
  'https://192.168.1.20','https://192.168.1.20:443','https://192.168.1.20:8843/',
  'https://192.168.1.20:8843?to=evil.test','https://192.168.1.20:8843#ignored',
  'https://user@192.168.1.20:8843','https://192.168.1.20:8843@evil.test',
  'https://192.168.1.20.evil.test:8843','https://evil.test:8843',
  'https://127.0.0.1:8843','https://localhost:8843','https://[::1]:8843',
  'https://169.254.10.5:8843','https://100.64.0.1:8843','https://8.8.8.8:8843',
  'https://172.15.1.1:8843','https://172.32.1.1:8843','https://192.169.1.1:8843',
  'https://192.168.001.020:8843','https://3232235796:8843','https://0xc0a80114:8843',
  'https://10.0.0.256:8843','https://10.0.0:8843',"https://10.0.0.8:8843\n"
 ] as $origin)lanCheck(!InstallationUrls::validLanOrigin($origin),'Unsafe/noncanonical origin rejected: '.json_encode($origin));
 lanWrite($path,$valid,$now);
 $expected=['deployment'=>'homeserver','lanOrigin'=>$valid['origin'],'leaseSeconds'=>30];
 lanCheck(InstallationUrls::metadata($home,$fixture,$now)===$expected,'Fresh launcher-owned network state is readable');
 lanCheck(InstallationUrls::metadata($cloud,$fixture,$now)===['deployment'=>'cloud','lanOrigin'=>'','leaseSeconds'=>0],'Educational deployment ignores HomeServer metadata');
 lanCheck(InstallationUrls::metadata([],$fixture,$now)===['deployment'=>'cloud','lanOrigin'=>'','leaseSeconds'=>0],'Existing configuration keeps hosted default');
 foreach([-29,0,5] as $offset){lanWrite($path,$valid,$now+$offset);$aged=$expected;$aged['leaseSeconds']=min(30,30+$offset);lanCheck(InstallationUrls::metadata($home,$fixture,$now)===$aged,'Lease boundary/remaining time accepted '.$offset);}
 foreach([-30,-31,6] as $offset){lanWrite($path,$valid,$now+$offset);lanCheck(InstallationUrls::metadata($home,$fixture,$now)===$unavailable,'Stale/future network lease rejected '.$offset);}
 foreach([
  '{}','{broken','[]',str_repeat(' ',8193),'null',
  ['version'=>'1','origin'=>$valid['origin']],['version'=>2,'origin'=>$valid['origin']],
  ['version'=>1],['origin'=>$valid['origin']],
  ['version'=>1,'origin'=>$valid['origin'],'extra'=>'not the supported schema'],
  ['version'=>1,'origin'=>'https://evil.test:8843'],['version'=>1,'origin'=>[]],
 ] as $value){lanWrite($path,$value,$now);lanCheck(InstallationUrls::metadata($home,$fixture,$now)===$unavailable,'Malformed network state is ignored safely');}
 unlink($path);mkdir($path,0700);
 lanCheck(InstallationUrls::metadata($home,$fixture,$now)===$unavailable,'Directory cannot act as network state');
 rmdir($path);
 if(PHP_OS_FAMILY!=='Windows'){
  lanWrite($fixture.'/other.json',$valid,$now);symlink($fixture.'/other.json',$path);
  lanCheck(InstallationUrls::metadata($home,$fixture,$now)===$unavailable,'Network file cannot be a symbolic link');
  unlink($path);unlink($fixture.'/other.json');
 }
 lanWrite($path,$valid,$now);
 $_SERVER['HTTP_HOST']='evil.test';$_SERVER['HTTP_X_FORWARDED_HOST']='evil.test';
 $_SERVER['HTTP_X_FORWARDED_PROTO']='https';
 lanCheck(InstallationUrls::metadata($home,$fixture,$now)===$expected,'Request Host and forwarding headers cannot choose LAN authority');
 $relative=ControllerPairing::inviteUrl(str_repeat('a',32),str_repeat('b',64));
 lanCheck(InstallationUrls::share($relative,$expected)===$valid['origin'].'/'.$relative,'Phone invitation reaches LAN origin with capability unchanged');
 lanCheck(InstallationUrls::share($relative,['deployment'=>'cloud','lanOrigin'=>'https://evil.test'])===$relative,'Hosted invitation remains installation-relative');
 lanReject(fn()=>InstallationUrls::share($relative,$unavailable),'No LAN cannot produce localhost invitation',409);
 lanReject(fn()=>InstallationUrls::share($relative,['deployment'=>'homeserver','lanOrigin'=>'https://evil.test:8843']),'Invalid LAN authority fails closed',409);
 foreach(['display.php?session='.str_repeat('c',32).'&token='.str_repeat('d',48),
  'live-view.php?show='.str_repeat('e',32),'package-controller.php?session='.str_repeat('c',32)] as $relative)
  lanCheck(InstallationUrls::share($relative,$expected)===$valid['origin'].'/'.$relative,'Supported route uses canonical LAN origin');
 foreach(['https://evil.test/controller.php','//evil.test/controller.php','/controller.php','../controller.php',
  'controller.php/evil','controller.php#secret','controller.php?x=1\\@evil.test',"controller.php?x=1\n",
  'login.php','package-view.php','api/index.php?action=logout','controller.php?key='.str_repeat('a',2048)] as $relative)
  lanReject(fn()=>InstallationUrls::share($relative,$expected),'Unapproved share route rejected');
 $head=InstallationUrls::head($expected);
 lanCheck(str_contains($head,'name="lucky-deployment" content="homeserver"')&&str_contains($head,'content="'.$valid['origin'].'"'),'Rendered metadata has the trusted deployment and origin');
 lanCheck(str_contains($head,'public/installation-urls.js?v=5q32g7a" defer'),'Shared URL helper loads before deferred consumers');
 lanCheck(str_contains($head,'name="lucky-lan-lease-seconds" content="30"'),'Rendered LAN cache has a bounded remaining lifetime');
 lanCheck(!str_contains(InstallationUrls::head(['deployment'=>'homeserver','lanOrigin'=>'"><script>bad()</script>']),'bad()'),'Malformed metadata cannot inject HTML');
 lanCheck(!str_contains(InstallationUrls::head(['deployment'=>'cloud','lanOrigin'=>$valid['origin']]),$valid['origin']),'Hosted HTML never adopts a stale local origin');
 $id=str_repeat('a',32);
 foreach(['api/index.php','/api/index.php','/school/games/api/index.php'] as $base){
  $image=$base.'?action=prize_image&id='.$id;$video=$base.'?action=prize_video&id='.$id;
  lanCheck(GameEngine::validImage($image)&&GameEngine::validPrizeVideo($video),'Portable local uploaded image/video paths are accepted');
  lanCheck(!GameEngine::validUrl($image),'External prize links do not inherit local-media exceptions');
  $cfg=GameEngine::defaults();$cfg['pools']['tier1']['prizes'][0]['image']=$image;
  $cfg['pools']['tier1']['prizes'][0]['videoUrl']=$video;
  $saved=GameEngine::validateConfig($cfg);
  lanCheck($saved['pools']['tier1']['prizes'][0]['image']===$image&&$saved['pools']['tier1']['prizes'][0]['videoUrl']===$video,'Validated game configuration preserves portable media');
 }
 foreach(['//evil.test/api/index.php','/../api/index.php','/%2e%2e/api/index.php','/a/../api/index.php','/api/other.php'] as $base){
  lanCheck(!GameEngine::validImage($base.'?action=prize_image&id='.$id),'Unsafe relative image route rejected');
 }
 foreach(['?action=logout&id='.$id,'?action=prize_video&id='.$id,'?action=prize_image&id='.$id.'&extra=1',
  '?action=prize_image&id=bad','?action=prize_image&id='.$id.'#fragment'] as $query)
  lanCheck(!GameEngine::validImage('/api/index.php'.$query),'Only exact local image action and ID accepted');
 lanCheck(GameEngine::validImage('https://cdn.example.test/photo.jpg')&&GameEngine::validUrl('https://shop.example.test/prize'),'Existing hosted external HTTP(S) references retain validation');
 $api=(string)file_get_contents(__DIR__.'/../api/controller.php');
 $invite=substr($api,strpos($api,"if(\$action==='invite'){"));
 lanCheck(strpos($invite,'InstallationUrls::share(')<strpos($invite,'$db->beginTransaction()'),'Actual invite endpoint resolves LAN before database mutation');
 $layout=(string)file_get_contents(__DIR__.'/../lib/Layout.php');
 lanCheck(str_contains($layout,".installationUrlHead().'</head>"),'Shared shell emits address metadata inside head');
 foreach(['display.php','controller.php','intro.php','live-view.php','package-controller.php','camera.php'] as $file){
  $source=(string)file_get_contents(__DIR__.'/../'.$file);
  lanCheck(str_contains($source,'<?=installationUrlHead()?></head>'),'Standalone page includes URL helper: '.$file);
 }
 echo 'G7A INSTALLATION URL CHECKS PASSED: '.$checks."\n";
}finally{
 foreach([$path,$fixture.'/other.json'] as $entry){
  if(is_link($entry)||is_file($entry))unlink($entry);
  elseif(is_dir($entry))rmdir($entry);
 }
 rmdir($fixture);
}
