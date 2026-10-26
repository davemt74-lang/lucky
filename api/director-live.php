<?php
declare(strict_types=1);
require __DIR__.'/../lib/Bootstrap.php';
require_once __DIR__.'/../lib/DirectorBroadcast.php';
require_once __DIR__.'/../lib/SchemaGuard.php';
headerSecurity();header('Referrer-Policy: no-referrer');header('X-Robots-Tag: noindex, nofollow');
try{
 if(!installed())throw new DomainException('Install Lucky Game Hub first.',503);
 $db=db();SchemaGuard::require($db,['controller_proof_state','director_broadcasts','director_viewers'],'Camera Director Live View');
 $method=(string)($_SERVER['REQUEST_METHOD']??'GET');$action=(string)($_GET['action']??'');
 if(!in_array($method,['GET','POST'],true))throw new DomainException('Unsupported method.',405);
 $data=$method==='POST'?jsonInput():$_GET;
 if(in_array($action,['publish','host_list','answer','stop'],true)){
  requireAdmin();$host=(int)$_SESSION['admin_id'];
  if($method==='POST')assertCsrf();
  if($action==='publish'&&$method==='POST'){
   if(($data['consent']??false)!==true)throw new DomainException('Confirm explicit Program publishing consent.',403);
   $out=DirectorBroadcast::start($db,(string)($data['session']??''),(string)($data['claim']??''),$host);
   audit($db,'camera_director.publish',(string)$data['session'],['broadcast'=>$out['id']]);success($out,201);
  }
  if($action==='host_list'&&$method==='GET')success(['offers'=>DirectorBroadcast::offersForHost($db,(string)($data['id']??''),$host)]);
  if($action==='answer'&&$method==='POST'){
   DirectorBroadcast::answer($db,(string)($data['id']??''),$host,(string)($data['viewer']??''),(string)($data['sdp']??''));success(['answered'=>true]);
  }
  if($action==='stop'&&$method==='POST'){
   DirectorBroadcast::stop($db,(string)($data['id']??''),$host);audit($db,'camera_director.stop');success(['stopped'=>true]);
  }
 }else{
  $id=(string)($data['id']??'');$key=(string)($data['key']??'');
  if($action==='watch'&&$method==='GET')success(DirectorBroadcast::watch($db,$id,$key));
  if($action==='offer'&&$method==='POST'){
   DirectorBroadcast::offer($db,$id,$key,(string)($data['viewer']??''),(string)($data['sdp']??''));success(['queued'=>true],201);
  }
  if($action==='poll'&&$method==='GET')success(DirectorBroadcast::answerForViewer($db,$id,$key,(string)($data['viewer']??'')));
 }
 throw new DomainException('Unknown Program signaling action.',404);
}catch(Throwable $e){error_log('Lucky Director Live View: '.get_class($e).' '.$e->getMessage());errorOut($e);}
