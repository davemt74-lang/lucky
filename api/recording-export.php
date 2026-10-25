<?php
declare(strict_types=1);
require __DIR__.'/../lib/Bootstrap.php';
require_once __DIR__.'/../lib/RecordingExports.php';
headerSecurity();header('X-Robots-Tag: noindex, nofollow');
function recordingExportFile(array $row,bool $head,bool $inline):never {
 if($row['status']!=='ready')throw new DomainException('Multicamera video is not ready.',409);
 $path=LUCKY_ROOT.'/storage/recording-exports/'.$row['id'].'.mp4';
 if(!is_file($path)||($bytes=filesize($path))===false||$bytes<1024)throw new DomainException('Completed export is unavailable.',404);
 $start=0;$end=$bytes-1;
 header('Content-Type: video/mp4');header('Content-Disposition: '.($inline?'inline':'attachment').'; filename="lucky-multicamera-'.$row['id'].'.mp4"');
 header('Accept-Ranges: bytes');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
 if(isset($_SERVER['HTTP_RANGE'])){
  if(!preg_match('/^bytes=(\d*)-(\d*)$/D',(string)$_SERVER['HTTP_RANGE'],$m)||($m[1]===''&&$m[2]==='')){
   http_response_code(416);header('Content-Range: bytes */'.$bytes);exit;
  }
  if($m[1]===''){$suffix=(int)$m[2];if($suffix<1){http_response_code(416);exit;}$start=max(0,$bytes-$suffix);}
  else{$start=(int)$m[1];if($m[2]!=='')$end=min($end,(int)$m[2]);}
  if($start>=$bytes||$end<$start){http_response_code(416);header('Content-Range: bytes */'.$bytes);exit;}
  http_response_code(206);header("Content-Range: bytes $start-$end/$bytes");
 }
 header('Content-Length: '.($end-$start+1));if($head)exit;
 $file=fopen($path,'rb');fseek($file,$start);$left=$end-$start+1;
 while($left>0&&!feof($file)){$chunk=fread($file,min(65536,$left));if($chunk===false||$chunk==='')break;echo $chunk;$left-=strlen($chunk);}
 fclose($file);exit;
}
try{
 if(!installed())throw new DomainException('Install Lucky Game Hub first.',503);
 requireAdmin();$db=db();$owner=(int)$_SESSION['admin_id'];$action=(string)($_GET['action']??'');$method=(string)($_SERVER['REQUEST_METHOD']??'GET');
 if(!$db->query("SHOW TABLES LIKE 'recording_exports'")->fetch())throw new DomainException('Run upgrade.php for multicamera video export.',409);
 if(($method==='GET'||$method==='HEAD')&&in_array($action,['download','play'],true)){
  recordingExportFile(RecordingExports::get($db,$owner,(string)($_GET['id']??'')),$method==='HEAD',$action==='play');
 }
 if($method==='GET'&&$action==='list'){
  success(['exports'=>RecordingExports::list($db,$owner,(string)($_GET['session']??''))]);
 }
 if($method==='GET'&&$action==='status'){
  success(['export'=>RecordingExports::publicRow(RecordingExports::get($db,$owner,(string)($_GET['id']??'')))]);
 }
 if($method!=='POST')throw new DomainException('Unsupported export operation.',405);
 assertCsrf();$data=jsonInput();
 if($action==='queue'){
  if(!is_int($data['revision']??null))throw new DomainException('Current edit revision is required.',422);
  $audio=$data['masterAudio']??null;
  if($audio!==null&&!is_string($audio))throw new DomainException('Unknown audio selection.',422);
  $queued=RecordingExports::queue($db,$owner,(string)($data['session']??''),$data['revision'],$audio,(string)($data['nonce']??''));
  audit($db,'recording.export-queued',null,['session'=>$queued['sessionId'],'export'=>$queued['id']]);
  success(['export'=>$queued],201);
 }
 if($action==='cancel'){
  $cancelled=RecordingExports::cancel($db,$owner,(string)($data['id']??''));
  audit($db,'recording.export-cancelled',null,['export'=>$cancelled['id']]);success(['export'=>$cancelled]);
 }
 throw new DomainException('Unknown export action.',404);
}catch(Throwable $e){error_log('Lucky recording export API: '.get_class($e).' '.$e->getMessage());errorOut($e);}
