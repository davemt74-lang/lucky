<?php
declare(strict_types=1);
require __DIR__.'/../lib/Bootstrap.php';
require_once __DIR__.'/../lib/RecordingSessions.php';
headerSecurity();header('X-Robots-Tag: noindex, nofollow');
try{
 if(!installed())throw new DomainException('Install first.',409);
 requireAdmin();if(($_SERVER['REQUEST_METHOD']??'')!=='GET')throw new DomainException('GET required.',405);
 $id=RecordingSessions::id((string)($_GET['id']??''));$db=db();
 $q=$db->prepare('SELECT f.mime,f.bytes,a.storage_key FROM recording_media_files f JOIN recording_assets a ON a.id=f.asset_id JOIN recording_sessions s ON s.id=a.recording_session_id WHERE a.id=? AND s.owner_admin_id=?');
 $q->execute([$id,(int)$_SESSION['admin_id']]);$file=$q->fetch(PDO::FETCH_ASSOC);
 if(!$file)throw new DomainException('Recording not found.',404);
 $key=RecordingSessions::id((string)$file['storage_key']);$path=LUCKY_ROOT.'/storage/recording-assets/'.$key.'.bin';
 if(!is_file($path))throw new DomainException('Recording file unavailable.',404);
 $size=filesize($path);if($size===false||$size!==(int)$file['bytes'])throw new RuntimeException('Recording file integrity mismatch.');
 $start=0;$end=$size-1;$range=(string)($_SERVER['HTTP_RANGE']??'');
 if($range!==''){
  if(!preg_match('/^bytes=(\d*)-(\d*)$/D',$range,$m)||($m[1]===''&&$m[2]===''))throw new DomainException('Invalid media range.',416);
  if($m[1]===''){$length=(int)$m[2];if($length<1)throw new DomainException('Invalid range.',416);$start=max(0,$size-$length);}
  else{$start=(int)$m[1];if($m[2]!=='')$end=min($end,(int)$m[2]);}
  if($start>$end||$start>=$size)throw new DomainException('Range unavailable.',416);
  http_response_code(206);header('Content-Range: bytes '.$start.'-'.$end.'/'.$size);
 }
 header('Content-Type: '.$file['mime']);header('Content-Length: '.($end-$start+1));header('Accept-Ranges: bytes');
 header('Content-Disposition: inline');header('Cache-Control: private, no-store');
 $handle=fopen($path,'rb');if(!$handle)throw new RuntimeException('Cannot read recording.');fseek($handle,$start);
 $remaining=$end-$start+1;while($remaining>0&&!feof($handle)){$chunk=fread($handle,min($remaining,65536));if($chunk===false)break;echo $chunk;$remaining-=strlen($chunk);if(connection_aborted())break;}
 fclose($handle);exit;
}catch(Throwable $e){error_log('Lucky recording stream: '.get_class($e).' '.$e->getMessage());errorOut($e);}
