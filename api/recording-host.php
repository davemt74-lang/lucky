<?php
declare(strict_types=1);
require __DIR__.'/../lib/Bootstrap.php';
require_once __DIR__.'/../lib/RecordingHostMedia.php';
headerSecurity();header('X-Robots-Tag: noindex, nofollow');
try {
 if(!installed())throw new DomainException('Install Lucky Game Hub first.',503);
 requireAdmin();assertCsrf();
 if(($_SERVER['REQUEST_METHOD']??'')!=='POST')throw new DomainException('Unsupported method.',405);
 $db=db();
 if(!$db->query("SHOW TABLES LIKE 'recording_capture_sync'")->fetch())
  throw new DomainException('Run upgrade.php to enable synchronized recordings.',409);
 $result=RecordingHostMedia::store($db,(int)$_SESSION['admin_id'],$_POST,$_FILES['media']??null);
 audit($db,'recording.director-original-uploaded',null,['session'=>(string)($_POST['session']??''),'asset'=>$result['id'],'existing'=>$result['existing']]);
 success(['asset'=>$result],$result['existing']?200:201);
}catch(Throwable $e){error_log('Lucky recording host: '.get_class($e).' '.$e->getMessage());errorOut($e);}
