<?php
declare(strict_types=1);
require __DIR__.'/../lib/Bootstrap.php';
require_once __DIR__.'/../lib/RecordingSessions.php';
require_once __DIR__.'/../lib/RecordingPhone.php';
require_once __DIR__.'/../lib/RecordingMulticam.php';
headerSecurity();header('Referrer-Policy: no-referrer');header('X-Robots-Tag: noindex, nofollow');
try{
 if(!installed())throw new DomainException('Lucky Game Hub is not installed.',409);
 if(($_SERVER['REQUEST_METHOD']??'')!=='POST')throw new DomainException('POST required.',405);
 $host=(string)($_SERVER['HTTP_HOST']??'');$hostName=strtolower(preg_replace('/:\d+$/','',$host));
 $secure=(!empty($_SERVER['HTTPS'])&&strtolower((string)$_SERVER['HTTPS'])!=='off')||strtolower((string)($_SERVER['REQUEST_SCHEME']??''))==='https';
 if(!$secure&&!in_array($hostName,['localhost','127.0.0.1','[::1]'],true))throw new DomainException('Phone camera requires HTTPS.',403);
 $origin=(string)($_SERVER['HTTP_ORIGIN']??'');$scheme=$secure?'https':'http';
 $originHost=(string)parse_url($origin,PHP_URL_HOST);$originPort=parse_url($origin,PHP_URL_PORT);
 $expectedPort=preg_match('/:(\d+)$/',$host,$matches)?(int)$matches[1]:($secure?443:80);
 if($origin!==''&&(strcasecmp($originHost,$hostName)!==0||parse_url($origin,PHP_URL_SCHEME)!==$scheme||
    ($originPort??($secure?443:80))!==$expectedPort||parse_url($origin,PHP_URL_USER)!==null||parse_url($origin,PHP_URL_PASS)!==null))
  throw new DomainException('Phone camera origin is not permitted.',403);
 $db=db();$action=(string)($_GET['action']??'');$type=strtolower((string)($_SERVER['CONTENT_TYPE']??''));
 if(!$db->query("SHOW TABLES LIKE 'recording_phone_permissions'")->fetch()||!$db->query("SHOW TABLES LIKE 'recording_media_files'")->fetch())
  throw new DomainException('Ask the host to run upgrade.php before using Camera Studio.',409);
 if($action==='list'){
  if(!str_starts_with($type,'application/json'))throw new DomainException('JSON required.',415);
  $in=jsonInput();$pair=ControllerPairing::authenticate($db,(string)($in['id']??''),(string)($_SERVER['HTTP_X_CONTROLLER_TOKEN']??''),true);
  success(['sessions'=>RecordingPhone::sessions($db,$pair)]);
 }
 if(in_array($action,['arm','poll'],true)){
  if(!str_starts_with($type,'application/json'))throw new DomainException('JSON required.',415);
  if(!$db->query("SHOW TABLES LIKE 'recording_camera_arms'")->fetch())throw new DomainException('Run upgrade.php to enable multicamera.',409);
  $in=jsonInput();$pair=ControllerPairing::authenticate($db,(string)($in['id']??''),(string)($_SERVER['HTTP_X_CONTROLLER_TOKEN']??''),true);
  $sessionId=(string)($in['session']??'');
  if($action==='arm'){
   if(!is_bool($in['enabled']??null))throw new DomainException('Choose whether to arm your phone.',422);
   if($in['enabled']&&($in['consent']??'')!=='yes')throw new DomainException('Consent is required before arming a camera.',403);
   success(['camera'=>RecordingMulticam::arm($db,$pair,$sessionId,$in['enabled'],(string)($in['kind']??''))]);
  }
  success(['coordination'=>RecordingMulticam::poll($db,$pair,$sessionId,(string)($in['state']??'standby'))]);
 }
 if($action==='upload'){
  if(!str_starts_with($type,'multipart/form-data'))throw new DomainException('Media upload form required.',415);
  $pairId=(string)($_POST['id']??'');$token=(string)($_SERVER['HTTP_X_CONTROLLER_TOKEN']??'');
  ControllerPairing::authenticate($db,$pairId,$token,true);
  $asset=RecordingPhone::store($db,$pairId,$token,$_POST,$_FILES['media']??null);
  success(['asset'=>$asset],$asset['existing']?200:201);
 }
 throw new DomainException('Unknown camera operation.',404);
}catch(Throwable $e){error_log('Lucky recording phone: '.get_class($e).' '.$e->getMessage());errorOut($e);}
