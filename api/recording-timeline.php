<?php
declare(strict_types=1);
require __DIR__.'/../lib/Bootstrap.php';
require_once __DIR__.'/../lib/RecordingTimeline.php';
headerSecurity();header('X-Robots-Tag: noindex, nofollow');
try{
 if(!installed())throw new DomainException('Install Lucky Game Hub first.',503);
 requireAdmin();$db=db();$owner=(int)$_SESSION['admin_id'];$action=(string)($_GET['action']??'');
 if(!$db->query("SHOW TABLES LIKE 'recording_timeline_edits'")->fetch())throw new DomainException('Run upgrade.php to enable multicamera timeline editing.',409);
 if(($_SERVER['REQUEST_METHOD']??'')==='GET'&&$action==='get'){
  $id=(string)($_GET['id']??'');$project=RecordingTimeline::get($db,$owner,$id);
  success(['project'=>$project,'media'=>RecordingTimeline::media($db,$owner,$id)]);
 }
 if(($_SERVER['REQUEST_METHOD']??'')!=='POST'||$action!=='save')throw new DomainException('Unknown timeline operation.',405);
 assertCsrf();$in=jsonInput();$id=(string)($in['id']??'');
 if(!is_int($in['revision']??null))throw new DomainException('Expected timeline revision required.',422);
 $edit=RecordingTimeline::save($db,$owner,$id,$in['revision'],$in['timeline']??null);
 audit($db,'recording.timeline-edited',null,['id'=>$id,'revision'=>$edit['revision']]);success(['edit'=>$edit]);
}catch(Throwable $e){error_log('Lucky recording timeline: '.get_class($e).' '.$e->getMessage());errorOut($e);}
