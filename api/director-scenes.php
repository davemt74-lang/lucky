<?php
declare(strict_types=1);
require __DIR__.'/../lib/Bootstrap.php';
require_once __DIR__.'/../lib/DirectorScenes.php';
require_once __DIR__.'/../lib/SchemaGuard.php';
headerSecurity();
try{
 if(!installed())throw new DomainException('Install Lucky Game Hub first.',503);
 requireAdmin();$host=(int)$_SESSION['admin_id'];$db=db();
 SchemaGuard::require($db,['game_sessions','controller_proof_state','claim_media','director_scene_presets'],'Camera Director Scenes');
 $method=(string)($_SERVER['REQUEST_METHOD']??'GET');$action=(string)($_GET['action']??'');
 if($method==='GET'&&$action==='list')
  success(['scenes'=>DirectorScenes::listing($db,(string)($_GET['session']??''),(string)($_GET['claim']??''),$host)]);
 if($method!=='POST')throw new DomainException('Unsupported scene operation.',405);
 assertCsrf();$in=jsonInput();
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
