<?php
declare(strict_types=1);
require __DIR__.'/../lib/Bootstrap.php';
require_once __DIR__.'/../lib/ControllerPairing.php';
require_once __DIR__.'/../lib/PhoneCapturePolicy.php';
require_once __DIR__.'/../lib/ClaimMediaUpload.php';
headerSecurity();header('Referrer-Policy: no-referrer');header('X-Robots-Tag: noindex, nofollow');
try {
 if(!installed())throw new DomainException('Install Lucky Game Hub first.',503);
 if(($_SERVER['REQUEST_METHOD']??'')!=='POST')throw new DomainException('POST required.',405);
 if(!str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE']??'')),'multipart/form-data'))throw new DomainException('Media upload form required.',415);
 $host=(string)($_SERVER['HTTP_HOST']??'');$hostName=strtolower(preg_replace('/:\d+$/','',$host));
 $secure=(!empty($_SERVER['HTTPS'])&&strtolower((string)$_SERVER['HTTPS'])!=='off')||strtolower((string)($_SERVER['REQUEST_SCHEME']??''))==='https';
 if(!$secure&&!in_array($hostName,['localhost','127.0.0.1','[::1]'],true))throw new DomainException('Phone media upload requires HTTPS.',403);
 $origin=(string)($_SERVER['HTTP_ORIGIN']??'');$scheme=$secure?'https':'http';
 $originHost=(string)parse_url($origin,PHP_URL_HOST);$originPort=parse_url($origin,PHP_URL_PORT);
 $expectedPort=preg_match('/:(\d+)$/',$host,$parts)?(int)$parts[1]:($secure?443:80);
 if($origin===''||strcasecmp($originHost,$hostName)!==0||parse_url($origin,PHP_URL_SCHEME)!==$scheme||
   ($originPort??($secure?443:80))!==$expectedPort||parse_url($origin,PHP_URL_USER)!==null||parse_url($origin,PHP_URL_PASS)!==null)
  throw new DomainException('Phone upload origin is not permitted.',403);
 $db=db();$pairId=(string)($_POST['id']??'');$token=(string)($_SERVER['HTTP_X_CONTROLLER_TOKEN']??'');
 $pair=ControllerPairing::authenticate($db,$pairId,$token,true);
 $nonce=(string)($_POST['nonce']??'');$id=PhoneCapturePolicy::mediaId($pairId,$nonce);
 $session=(string)($_POST['session']??'');$claim=(string)($_POST['claim']??'');
 ControllerPairing::checkId($session);ControllerPairing::checkId($claim);
 $form=$_POST;
 $form['sourceLabel']='Paired phone · '.substr((string)$pair['device_name'],0,46);
 $form['angleLabel']=substr('Phone · '.preg_replace('/[\x00-\x1f\x7f]/','',(string)$pair['device_name']),0,80);$form['angleIndex']=0;
 $form['replaceClipId']=''; // A phone upload cannot overwrite an existing proof recording.
 $authorize=static function(PDO $db,string $sessionId,string $claimId)use($pairId,$token):void{
  $q=$db->prepare('SELECT * FROM controller_pairs WHERE id=? FOR UPDATE');$q->execute([$pairId]);$locked=$q->fetch(PDO::FETCH_ASSOC);
  if(!$locked||!hash_equals((string)($locked['controller_hash']??''),ControllerPairing::tokenHash($token)))
   throw new DomainException('This phone pairing was revoked.',403);
  $q=$db->prepare('SELECT * FROM controller_proof_state WHERE session_id=? FOR UPDATE');$q->execute([$sessionId]);
  $proof=$q->fetch(PDO::FETCH_ASSOC);
  if(!$proof)throw new DomainException('No live proof is available for phone upload.',409);
  PhoneCapturePolicy::verify($locked,$proof,$sessionId,$claimId,time());
 };
 $clip=ClaimMediaUpload::store($db,$form,$_FILES['media']??null,$authorize,$id);
 success(['clip'=>['id'=>$clip['id'],'kind'=>$clip['kind'],'title'=>$clip['title'],'bytes'=>$clip['bytes'],'sessionId'=>$clip['sessionId'],'claimId'=>$clip['claimId']]],201);
}catch(Throwable $e){error_log('Lucky phone upload: '.get_class($e).' '.$e->getMessage());errorOut($e);}
