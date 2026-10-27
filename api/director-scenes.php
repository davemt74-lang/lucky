<?php
declare(strict_types=1);
require __DIR__.'/../lib/Bootstrap.php';
require_once __DIR__.'/../lib/DirectorScenes.php';
require_once __DIR__.'/../lib/DirectorRecordingScenes.php';
require_once __DIR__.'/../lib/SchemaGuard.php';
headerSecurity();
try{
 if(!installed())throw new DomainException('Install Lucky Game Hub first.',503);
 requireAdmin();$host=(int)$_SESSION['admin_id'];$db=db();
 SchemaGuard::require($db,['game_sessions','controller_proof_state','claim_media','director_scene_presets'],'Camera Director Scenes');
 $method=(string)($_SERVER['REQUEST_METHOD']??'GET');$action=(string)($_GET['action']??'');
 $recording=$method==='GET'?(string)($_GET['recordingSession']??''):'';
 if($method==='GET'&&$recording!==''){
  SchemaGuard::require($db,['recording_sessions','director_recording_scenes'],'Recording Session Director Scenes');
  if($action==='list')success(['scenes'=>DirectorRecordingScenes::listing($db,$recording,$host)]);
  throw new DomainException('Unsupported recording scene operation.',405);
 }
 if($method==='GET'&&$action==='list')
  success(['scenes'=>DirectorScenes::listing($db,(string)($_GET['session']??''),(string)($_GET['claim']??''),$host)]);
 if($method!=='POST')throw new DomainException('Unsupported scene operation.',405);
 assertCsrf();$in=jsonInput();
 $recording=(string)($in['recordingSession']??'');
 if($recording!==''){
  SchemaGuard::require($db,['recording_sessions','director_recording_scenes'],'Recording Session Director Scenes');
  if($action==='save'){
   $scene=DirectorRecordingScenes::save($db,$recording,$host,$in);
   audit($db,'camera_director.recording_scene.save',$recording,['scene'=>$scene['id']]);success(['scene'=>$scene]);
  }
  if($action==='remove'){
   DirectorRecordingScenes::remove($db,$recording,$host,(string)($in['id']??''));
   audit($db,'camera_director.recording_scene.remove',$recording,['scene'=>(string)($in['id']??'')]);success(['removed'=>true]);
  }
  throw new DomainException('Unknown recording scene operation.',404);
 }
 $session=(string)($in['session']??'');$claim=(string)($in['claim']??'');
 if($action==='save'){
  $scene=DirectorScenes::save($db,$session,$claim,$host,$in);
  audit($db,'camera_director.scene.save',$session,['scene'=>$scene['id']]);
  success(['scene'=>$scene]);
 }
 if($action==='remove'){
  DirectorScenes::remove($db,$session,$claim,$host,(string)($in['id']??''));
  audit($db,'camera_director.scene.remove',$session,['scene'=>(string)$in['id']]);
  success(['removed'=>true]);
 }
 throw new DomainException('Unknown scene operation.',404);
}catch(Throwable $e){error_log('Lucky Director Scenes: '.get_class($e).' '.$e->getMessage());errorOut($e);}
