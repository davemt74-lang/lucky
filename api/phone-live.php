<?php
declare(strict_types=1);
require __DIR__.'/../lib/Bootstrap.php';
require_once __DIR__.'/../lib/PhoneLiveSignaling.php';
require_once __DIR__.'/../lib/SchemaGuard.php';
headerSecurity();header('Referrer-Policy: no-referrer');header('X-Robots-Tag: noindex, nofollow');
try {
 if(!installed())throw new DomainException('Install Lucky Game Hub first.',503);
 $role=(string)($_GET['role']??'');$action=(string)($_GET['action']??'');
 $method=(string)($_SERVER['REQUEST_METHOD']??'GET');
 if(!in_array($role,['phone','host'],true))throw new DomainException('Invalid live camera role.',400);
 if(!in_array($method,['GET','POST'],true))throw new DomainException('Invalid live camera method.',405);
 $db=db();SchemaGuard::require($db,['controller_live_streams','controller_proof_state'],'Live phone camera');
 $data=$method==='POST'?jsonInput():$_GET;
 $session=(string)($data['session']??'');$claim=(string)($data['claim']??'');
 if($role==='phone'){
  $id=(string)($data['id']??'');$token=(string)($_SERVER['HTTP_X_CONTROLLER_TOKEN']??'');
  $pair=ControllerPairing::authenticate($db,$id,$token,true);
  // Every phone write is constrained to its same-origin HTTPS controller.
  if($method==='POST'){
   $host=(string)($_SERVER['HTTP_HOST']??'');$hostName=strtolower(preg_replace('/:\d+$/','',$host));
   $secure=(!empty($_SERVER['HTTPS'])&&strtolower((string)$_SERVER['HTTPS'])!=='off')||
    strtolower((string)($_SERVER['REQUEST_SCHEME']??''))==='https';
   if(!$secure&&!in_array($hostName,['localhost','127.0.0.1','[::1]'],true))
    throw new DomainException('Phone live camera requires HTTPS.',403);
   $origin=(string)($_SERVER['HTTP_ORIGIN']??'');$originHost=(string)parse_url($origin,PHP_URL_HOST);
   $originPort=parse_url($origin,PHP_URL_PORT);
   $expectedPort=preg_match('/:(\d+)$/',$host,$parts)?(int)$parts[1]:($secure?443:80);
   if($origin===''||strcasecmp($originHost,$hostName)!==0||
     parse_url($origin,PHP_URL_SCHEME)!==($secure?'https':'http')||
     ($originPort??($secure?443:80))!==$expectedPort||
     parse_url($origin,PHP_URL_USER)!==null||parse_url($origin,PHP_URL_PASS)!==null)
    throw new DomainException('Phone live camera origin is not permitted.',403);
  }
  $nonce=(string)($data['nonce']??'');
  if($method==='POST'&&$action==='end'){
   PhoneLiveSignaling::end($db,$pair,$nonce);success(['ended'=>true]);
  }
  if($method==='POST'&&$action==='offer'){
   PhoneLiveSignaling::offer($db,$pair,$session,$claim,$nonce,(string)($data['sdp']??''));
   success(['accepted'=>true],201);
  }
  if($method==='GET'&&$action==='poll')
   success(PhoneLiveSignaling::poll($db,$pair,$session,$claim,$nonce));
 }else {
  requireAdmin();$host=(int)$_SESSION['admin_id'];
  if($method==='GET'&&$action==='list')
   success(['phones'=>PhoneLiveSignaling::listOffers($db,$session,$claim,$host)]);
  if($method==='POST'&&$action==='answer'){
   assertCsrf();
   PhoneLiveSignaling::answer($db,$session,$claim,$host,(string)($data['id']??''),
    (string)($data['nonce']??''),(string)($data['sdp']??''));
   success(['accepted'=>true]);
  }
 }
 throw new DomainException('Unknown live camera action.',404);
}catch(Throwable $e){error_log('Lucky phone live signaling: '.get_class($e).' '.$e->getMessage());errorOut($e);}
