<?php
declare(strict_types=1);
require __DIR__.'/../lib/Bootstrap.php';
require_once __DIR__.'/../lib/RecordingSessions.php';
headerSecurity();header('X-Robots-Tag: noindex, nofollow');
try {
 if(!installed())throw new DomainException('Install Lucky Game Hub first.',503);
 requireAdmin();$owner=(int)$_SESSION['admin_id'];$db=db();
 if(!$db->query("SHOW TABLES LIKE 'recording_sessions'")->fetch())throw new DomainException('Run upgrade.php to enable recording sessions.',409);
 $action=(string)($_GET['action']??'');$method=$_SERVER['REQUEST_METHOD']??'GET';
 if($method==='GET'){
  if($action==='list')success(['sessions'=>RecordingSessions::list($db,$owner)]);
  if($action==='detail')success(RecordingSessions::detail($db,$owner,(string)($_GET['id']??'')));
  throw new DomainException('Unknown recording operation.',404);
 }
 if($method!=='POST')throw new DomainException('Unsupported recording method.',405);
 assertCsrf();$input=jsonInput();$id=(string)($input['id']??'');
 if($action==='create'){
  $row=RecordingSessions::create($db,$owner,$input);audit($db,'recording.session-created',$row['gameSessionId'],['id'=>$row['id'],'type'=>$row['type']]);success(['session'=>$row],201);
 }
 if($action==='import_game'){
  $result=RecordingSessions::importGame($db,$owner,(string)($input['gameSessionId']??''));
  audit($db,'recording.legacy-game-imported',$result['session']['gameSessionId'],['id'=>$result['session']['id'],'added'=>$result['added']]);success($result);
 }
 if($action==='status'){
  $row=RecordingSessions::setStatus($db,$owner,$id,(string)($input['status']??''),(int)($input['revision']??0));
  audit($db,'recording.session-status',$row['gameSessionId'],['id'=>$id,'status'=>$row['status']]);success(['session'=>$row]);
 }
 if($action==='take_start'){
  $take=RecordingSessions::startTake($db,$owner,$id,$input);audit($db,'recording.take-started',null,['session'=>$id,'take'=>$take['id']]);success(['take'=>$take],201);
 }
 if($action==='take_stop'){
  $take=RecordingSessions::finishTake($db,$owner,$id,(string)($input['takeId']??''));audit($db,'recording.take-stopped',null,['session'=>$id,'take'=>$take['id']]);success(['take'=>$take]);
 }
 if($action==='source'){
  $source=RecordingSessions::source($db,$owner,$id,$input);audit($db,'recording.source-listed',null,['session'=>$id,'source'=>$source['id'],'kind'=>$source['kind']]);success(['source'=>$source],201);
 }
 if($action==='attach_claim'){
  $asset=RecordingSessions::attachClaim($db,$owner,$id,(string)($input['clipId']??''),isset($input['takeId'])?(string)$input['takeId']:null,isset($input['sourceId'])?(string)$input['sourceId']:null);
  audit($db,'recording.legacy-clip-linked',null,['session'=>$id,'asset'=>$asset['id'],'existing'=>$asset['existing']]);success(['asset'=>$asset],$asset['existing']?200:201);
 }
 throw new DomainException('Unknown recording operation.',404);
}catch(Throwable $e){error_log('Lucky recording sessions: '.get_class($e).' '.$e->getMessage());errorOut($e);}
