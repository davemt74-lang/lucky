<?php
declare(strict_types=1);
require __DIR__.'/../lib/Bootstrap.php';
require_once __DIR__.'/../lib/RecordingSessions.php';
require_once __DIR__.'/../lib/RecordingPhone.php';
require_once __DIR__.'/../lib/RecordingMulticam.php';
headerSecurity();header('X-Robots-Tag: noindex, nofollow');
try {
 if(!installed())throw new DomainException('Install Lucky Game Hub first.',503);
 requireAdmin();$owner=(int)$_SESSION['admin_id'];$db=db();
 if(!$db->query("SHOW TABLES LIKE 'recording_sessions'")->fetch())throw new DomainException('Run upgrade.php to enable recording sessions.',409);
 if(in_array((string)($_GET['action']??''),['phone_permission','permission_status','media_list'],true)&&!$db->query("SHOW TABLES LIKE 'recording_phone_permissions'")->fetch())
  throw new DomainException('Run upgrade.php to enable phone recording.',409);
 $action=(string)($_GET['action']??'');$method=$_SERVER['REQUEST_METHOD']??'GET';
 if($method==='GET'){
  if($action==='list')success(['sessions'=>RecordingSessions::list($db,$owner)]);
  if($action==='detail')success(RecordingSessions::detail($db,$owner,(string)($_GET['id']??'')));
  if($action==='permission_status'){
   $id=RecordingSessions::id((string)($_GET['id']??''));RecordingSessions::get($db,$owner,$id);
   $q=$db->prepare('SELECT phone_enabled FROM recording_phone_permissions WHERE recording_session_id=?');$q->execute([$id]);
   success(['phoneEnabled'=>(bool)$q->fetchColumn()]);
  }
  if($action==='media_list'){
   $id=RecordingSessions::id((string)($_GET['id']??''));RecordingSessions::get($db,$owner,$id);
   $q=$db->prepare('SELECT a.id,a.kind,a.take_id,a.source_id,a.start_offset_ms,a.sync_confidence,m.mime,m.title,m.bytes,m.duration_ms,m.created_at FROM recording_assets a JOIN recording_media_files m ON m.asset_id=a.id WHERE a.recording_session_id=? ORDER BY m.created_at,a.id LIMIT 160');
   $q->execute([$id]);success(['media'=>$q->fetchAll(PDO::FETCH_ASSOC)]);
  }
  if($action==='camera_devices'){
   if(!$db->query("SHOW TABLES LIKE 'recording_camera_arms'")->fetch())throw new DomainException('Run upgrade.php to enable multicamera.',409);
   success(['devices'=>RecordingMulticam::devices($db,$owner,(string)($_GET['id']??''))]);
  }
  throw new DomainException('Unknown recording operation.',404);
 }
 if($method!=='POST')throw new DomainException('Unsupported recording method.',405);
 assertCsrf();$input=jsonInput();$id=(string)($input['id']??'');
 if($action==='phone_permission'){
  if(!is_bool($input['enabled']??null))throw new DomainException('Choose whether the phone camera is enabled.',422);
  $grant=RecordingPhone::grant($db,$owner,$id,$input['enabled']);
  audit($db,'recording.phone-permission',null,['id'=>$id,'enabled'=>$grant['phoneEnabled']]);success(['permission'=>$grant]);
 }
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
