<?php
declare(strict_types=1);
require __DIR__.'/../lib/Bootstrap.php';
require_once __DIR__.'/../lib/RecordingLiveSignaling.php';
require_once __DIR__.'/../lib/SchemaGuard.php';
headerSecurity();header('Referrer-Policy: no-referrer');header('X-Robots-Tag: noindex, nofollow');
try{
 if(!installed())throw new DomainException('Install Lucky Game Hub first.',503);
 $role=(string)($_GET['role']??'');$action=(string)($_GET['action']??'');
 $method=(string)($_SERVER['REQUEST_METHOD']??'GET');
 if(!in_array($role,['phone','host'],true)||!in_array($method,['GET','POST'],true))
  throw new DomainException('Invalid live recording operation.',405);
 $db=db();SchemaGuard::require($db,['recording_sessions','recording_phone_permissions','recording_camera_arms','recording_live_streams'],'Recording Camera Live');
 $data=$method==='POST'?jsonInput():$_GET;$session=(string)($data['session']??'');
 if($role==='phone'){
  $pair=ControllerPairing::authenticate($db,(string)($data['id']??''),(string)($_SERVER['HTTP_X_CONTROLLER_TOKEN']??''),true);
  if($method==='POST'){
   $host=(string)($_SERVER['HTTP_HOST']??'');$hostname=strtolower(preg_replace('/:\d+$/','',$host));
   $secure=(!empty($_SERVER['HTTPS'])&&strtolower((string)$_SERVER['HTTPS'])!=='off')||strtolower((string)($_SERVER['REQUEST_SCHEME']??''))==='https';
   if(!$secure&&!in_array($hostname,['localhost','127.0.0.1','[::1]'],true))throw new DomainException('Live phone video requires HTTPS.',403);
   $origin=(string)($_SERVER['HTTP_ORIGIN']??'');$port=parse_url($origin,PHP_URL_PORT);
   $expected=preg_match('/:(\d+)$/',$host,$matches)?(int)$matches[1]:($secure?443:80);
   if($origin===''||strcasecmp((string)parse_url($origin,PHP_URL_HOST),$hostname)!==0||
     parse_url($origin,PHP_URL_SCHEME)!==($secure?'https':'http')||($port??($secure?443:80))!==$expected||
     parse_url($origin,PHP_URL_USER)!==null||parse_url($origin,PHP_URL_PASS)!==null)
    throw new DomainException('Live phone video origin is not permitted.',403);
  }
  $nonce=(string)($data['nonce']??'');
  if($action==='offer'&&$method==='POST'){
   RecordingLiveSignaling::offer($db,$pair,$session,$nonce,(string)($data['sdp']??''));success(['accepted'=>true],201);
  }
  if($action==='poll'&&$method==='GET')success(RecordingLiveSignaling::poll($db,$pair,$session,$nonce));
  if($action==='end'&&$method==='POST'){
   RecordingLiveSignaling::end($db,$pair,$nonce);success(['ended'=>true]);
  }
 }else{
  requireAdmin();$admin=(int)$_SESSION['admin_id'];
  if($action==='list'&&$method==='GET')success(['phones'=>RecordingLiveSignaling::listOffers($db,$session,$admin)]);
  if($action==='answer'&&$method==='POST'){
   assertCsrf();RecordingLiveSignaling::answer($db,$session,$admin,(string)($data['id']??''),
    (string)($data['nonce']??''),(string)($data['sdp']??''));success(['accepted'=>true]);
  }
 }
 throw new DomainException('Unknown live recording action.',404);
}catch(Throwable $e){error_log('Lucky recording live: '.get_class($e).' '.$e->getMessage());errorOut($e);}
