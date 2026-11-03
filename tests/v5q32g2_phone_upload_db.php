<?php
declare(strict_types=1);
/** Real multipart HTTP uploads, real MariaDB, and an isolated application/storage copy. */
if(getenv('CI')!=='true')throw new RuntimeException('CI MariaDB only.');
define('LUCKY_TESTING',true);
require_once __DIR__.'/../lib/Bootstrap.php';
require_once __DIR__.'/../lib/MigrationSql.php';
require_once __DIR__.'/../lib/RecordingSessions.php';
require_once __DIR__.'/../lib/RecordingPhone.php';
require_once __DIR__.'/../lib/RecordingMulticam.php';

$checks=0;
function uploadCheck(bool $pass,string $message):void {
 global $checks;
 if(!$pass)throw new RuntimeException('FAIL '.$message);
 ++$checks;echo 'PASS '.$message."\n";
}
function uploadCopyTree(string $source,string $destination):void {
 if(!mkdir($destination,0700,true)&&!is_dir($destination))throw new RuntimeException('Could not create fixture directory.');
 foreach(new DirectoryIterator($source) as $entry){
  if($entry->isDot())continue;
  if($entry->isLink())throw new RuntimeException('Fixture source must not contain symlinks.');
  $target=$destination.'/'.$entry->getFilename();
  if($entry->isDir())uploadCopyTree($entry->getPathname(),$target);
  elseif(!copy($entry->getPathname(),$target))throw new RuntimeException('Could not copy fixture source.');
 }
}
function uploadRemoveTree(string $path):void {
 if(is_link($path)||!is_dir($path)){if(file_exists($path)||is_link($path))unlink($path);return;}
 foreach(new DirectoryIterator($path) as $entry)if(!$entry->isDot())uploadRemoveTree($entry->getPathname());
 rmdir($path);
}
function uploadStopServer(mixed $server):void {
 if(!is_resource($server))return;
 if(proc_get_status($server)['running']){
  proc_terminate($server);
  for($i=0;$i<20&&proc_get_status($server)['running'];$i++)usleep(50000);
  if(proc_get_status($server)['running'])proc_terminate($server,9);
 }
 proc_close($server);
}
/** HTTP parsing, is_uploaded_file(), move_uploaded_file(), and the API are not mocked. */
function uploadRequest(string $base,string $token,array $fields,?string $media,string $mime='audio/wav',?string $origin=null):array {
 $boundary='LuckyUpload'.bin2hex(random_bytes(12));$body='';
 foreach($fields as $name=>$value){
  if(!preg_match('/^[a-zA-Z][a-zA-Z0-9]*$/D',(string)$name))throw new LogicException('Invalid fixture field.');
  $body.='--'.$boundary."\r\nContent-Disposition: form-data; name=\"".$name."\"\r\n\r\n".(string)$value."\r\n";
 }
 if($media!==null)$body.='--'.$boundary."\r\nContent-Disposition: form-data; name=\"media\"; filename=\"capture.bin\"\r\nContent-Type: ".$mime."\r\n\r\n".$media."\r\n";
 $body.='--'.$boundary."--\r\n";
 $context=stream_context_create(['http'=>[
  'method'=>'POST','protocol_version'=>1.1,'timeout'=>10,'ignore_errors'=>true,'follow_location'=>0,
  'header'=>['Content-Type: multipart/form-data; boundary='.$boundary,'Content-Length: '.strlen($body),
   'Connection: close','Origin: '.($origin??$base),'X-Controller-Token: '.$token],
  'content'=>$body
 ]]);
 $stream=fopen($base.'/api/recording-phone.php?action=upload','rb',false,$context);
 if($stream===false)throw new RuntimeException('Could not contact fixture server.');
 try{$raw=stream_get_contents($stream);$meta=stream_get_meta_data($stream);}finally{fclose($stream);}
 if(!is_string($raw)||($meta['timed_out']??false))throw new RuntimeException('Fixture HTTP response timed out.');
 $status=0;
 foreach($meta['wrapper_data']??[] as $header)if(preg_match('~^HTTP/\S+ ([0-9]{3})~',$header,$match))$status=(int)$match[1];
 try{$json=json_decode($raw,true,32,JSON_THROW_ON_ERROR);}
 catch(JsonException $e){throw new RuntimeException('Non-JSON upload response (HTTP '.$status.'): '.substr($raw,0,500),0,$e);}
 if(!is_array($json))throw new RuntimeException('Invalid upload response.');
 return ['status'=>$status,'json'=>$json];
}
/** Capture every upload-owned database/file mutation, not just the returned error code. */
function uploadSnapshot(PDO $db,array $owners,string $fixture):array {
 $scope=implode(',',array_fill(0,count($owners),'?'));$snapshot=[];
 foreach([
  'assets'=>'SELECT a.* FROM recording_assets a JOIN recording_sessions s ON s.id=a.recording_session_id WHERE s.owner_admin_id IN ('.$scope.') ORDER BY a.id',
  'media'=>'SELECT m.* FROM recording_media_files m JOIN recording_assets a ON a.id=m.asset_id JOIN recording_sessions s ON s.id=a.recording_session_id WHERE s.owner_admin_id IN ('.$scope.') ORDER BY m.asset_id',
  'sources'=>'SELECT r.* FROM recording_sources r JOIN recording_sessions s ON s.id=r.recording_session_id WHERE s.owner_admin_id IN ('.$scope.') ORDER BY r.id',
  'sync'=>'SELECT c.* FROM recording_capture_sync c JOIN recording_assets a ON a.id=c.asset_id JOIN recording_sessions s ON s.id=a.recording_session_id WHERE s.owner_admin_id IN ('.$scope.') ORDER BY c.asset_id'
 ] as $name=>$sql){$q=$db->prepare($sql);$q->execute($owners);$snapshot[$name]=$q->fetchAll(PDO::FETCH_ASSOC);}
 $snapshot['files']=[];$folder=$fixture.'/storage/recording-assets';
 if(is_dir($folder))foreach(new DirectoryIterator($folder) as $entry){
  if($entry->isDot())continue;
  if(!$entry->isFile()||$entry->isLink())throw new RuntimeException('Unexpected recording fixture entry.');
  $snapshot['files'][$entry->getFilename()]=hash_file('sha256',$entry->getPathname());
 }
 ksort($snapshot['files']);return $snapshot;
}
function uploadReject(PDO $db,array $owners,string $fixture,callable $request,int $status,string $message):void {
 $before=uploadSnapshot($db,$owners,$fixture);$response=$request();
 uploadCheck($response['status']===$status&&($response['json']['ok']??null)===false&&is_string($response['json']['error']??null),
  $message.' (expected HTTP '.$status.', received '.json_encode($response,JSON_UNESCAPED_SLASHES).')');
 uploadCheck(uploadSnapshot($db,$owners,$fixture)===$before,$message.' leaves existing bytes and all upload rows unchanged');
}
function uploadSession(PDO $db,int $owner,string $nonce,array $capture=[],bool $active=true,bool $grant=true):array {
 $session=RecordingSessions::create($db,$owner,['type'=>'production','title'=>'Phone upload regression','nonce'=>$nonce,'capture'=>$capture]);
 if($grant)RecordingPhone::grant($db,$owner,$session['id'],true);
 return $active?RecordingSessions::setStatus($db,$owner,$session['id'],'active',$session['revision']):$session;
}

$db=new PDO('mysql:host=127.0.0.1;port=3306;dbname=lucky_test;charset=utf8mb4','root','luckytestpassword',[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$db->exec("SET time_zone = '+00:00'");
foreach(MigrationSql::statements((string)file_get_contents(__DIR__.'/../lib/schema.sql')) as $sql)$db->exec($sql);
foreach(['v5q_claim_media.sql','v5q29e1_recording_sessions.sql','v5q29e2_phone_camera.sql',
 'v5q29e3_multicamera_coordination.sql','v5q29e4c_recording_profiles.sql'] as $migration)
 foreach(MigrationSql::statements((string)file_get_contents(__DIR__.'/../lib/migrations/'.$migration)) as $sql)$db->exec($sql);
uploadCheck($db->query("SHOW COLUMNS FROM recording_assets LIKE 'kind'")->fetch()!==false&&
 $db->query("SHOW COLUMNS FROM recording_media_files LIKE 'kind'")->fetch()===false,
 'Real schema stores kind on recording_assets, not recording_media_files');

$fixture=rtrim(sys_get_temp_dir(),DIRECTORY_SEPARATOR).'/lucky-phone-upload-g2-'.bin2hex(random_bytes(8));
$owners=[];$server=null;
try{
 mkdir($fixture,0700);
 uploadCopyTree(__DIR__.'/../lib',$fixture.'/lib');
 mkdir($fixture.'/api',0700);mkdir($fixture.'/storage',0700);mkdir($fixture.'/php-sessions',0700);mkdir($fixture.'/upload-temp',0700);
 if(!copy(__DIR__.'/../api/recording-phone.php',$fixture.'/api/recording-phone.php'))throw new RuntimeException('Could not copy real upload endpoint.');
 $config=['deployment'=>'cloud','db_host'=>'127.0.0.1','db_port'=>3306,'db_name'=>'lucky_test','db_user'=>'root','db_pass'=>'luckytestpassword'];
 file_put_contents($fixture.'/storage/config.php','<?php return '.var_export($config,true).';');
 $ready=bin2hex(random_bytes(16));file_put_contents($fixture.'/ready.txt',$ready);
 $socket=stream_socket_server('tcp://127.0.0.1:0',$errorCode,$errorMessage);
 if($socket===false)throw new RuntimeException('Could not reserve loopback port: '.$errorMessage);
 $address=stream_socket_get_name($socket,false);fclose($socket);
 if(!is_string($address)||!preg_match('/^127\.0\.0\.1:[0-9]+$/D',$address))throw new RuntimeException('Invalid loopback fixture address.');
 $base='http://'.$address;
 $server=proc_open([PHP_BINARY,'-d','display_errors=0','-d','log_errors=1','-d','upload_max_filesize=2M','-d','post_max_size=3M',
  '-d','session.save_path='.$fixture.'/php-sessions','-d','upload_tmp_dir='.$fixture.'/upload-temp','-S',$address,'-t',$fixture],
  [0=>['file','/dev/null','r'],1=>['file',$fixture.'/server.log','a'],2=>['file',$fixture.'/server.log','a']],$pipes,$fixture,null,['bypass_shell'=>true]);
 if(!is_resource($server))throw new RuntimeException('Could not start fixture PHP HTTP server.');
 $started=false;
 for($i=0;$i<50;$i++){
  if(!proc_get_status($server)['running'])throw new RuntimeException('Fixture HTTP server exited: '.file_get_contents($fixture.'/server.log'));
  if(@file_get_contents($base.'/ready.txt',false,stream_context_create(['http'=>['timeout'=>0.2,'ignore_errors'=>true]]))===$ready){$started=true;break;}
  usleep(100000);
 }
 if(!$started)throw new RuntimeException('Fixture HTTP server did not become ready.');

 foreach(['upload-owner','upload-other'] as $prefix){
  $db->prepare('INSERT INTO admins(username,password_hash) VALUES (?,?)')->execute([$prefix.'-'.bin2hex(random_bytes(6)),password_hash('test-only',PASSWORD_DEFAULT)]);
  $owners[]=(int)$db->lastInsertId();
 }
 [$owner,$other]=$owners;$pairId=bin2hex(random_bytes(16));$token=bin2hex(random_bytes(32));
 $db->prepare("INSERT INTO controller_pairs(id,host_admin_id,invite_hash,controller_hash,device_name,status,invite_expires_at,paired_expires_at) VALUES (?,?,?,?,'Upload fixture phone','paired',UTC_TIMESTAMP()+INTERVAL 30 MINUTE,UTC_TIMESTAMP()+INTERVAL 30 MINUTE)")
  ->execute([$pairId,$owner,hash('sha256','upload-fixture-invite'),ControllerPairing::tokenHash($token)]);
 $session=uploadSession($db,$owner,'phone-upload-main-001');
 $otherSession=uploadSession($db,$other,'phone-upload-other-001');
 $sameOwnerSession=uploadSession($db,$owner,'phone-upload-second-001');
 $draft=uploadSession($db,$owner,'phone-upload-draft-001',[],false);
 $unapproved=uploadSession($db,$owner,'phone-upload-unapproved-001',[],true,false);
 $audioOnly=uploadSession($db,$owner,'phone-upload-audio-only-001',['photo'=>false,'video'=>false,'audio'=>true]);
 $samples=str_repeat("\0",1600);
 $wav='RIFF'.pack('V',36+strlen($samples)).'WAVEfmt '.pack('VvvVVvv',16,1,1,8000,16000,2,16).'data'.pack('V',strlen($samples)).$samples;
 $changedWav=substr($wav,0,-2)."\1\0"; // Valid PCM, same MIME and length, different content digest.
 $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aS0kAAAAASUVORK5CYII=',true);
 if(!is_string($png))throw new RuntimeException('Invalid PNG fixture.');
 // One 16x16 VP8 frame generated with FFmpeg; the DB job does not need an encoder.
 $webm=base64_decode('GkXfo59ChoEBQveBAULygQRC84EIQoKEd2VibUKHgQJChYECGFOAZwH/////////EU2bdKtNu4tTq4QVSalmU6yBoU27i1OrhBZUrmtTrIHNTbuMU6uEElTDZ1OsggET7AEAAAAAAABoAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAVSalmpyrXsYMPQkBNgI1MYXZmNjAuMTYuMTAwV0GNTGF2ZjYwLjE2LjEwMBZUrmvBrgEAAAAAAAA414EBc8WIyLsJ6DC8PDqcgQAitZyDdW5kiIEAhoVWX1ZQOIOBASPjg4Q7msoA4ImwgRC6gRCagQISVMNn2HNzoGPAgGfImkWjh0VOQ09ERVJEh41MYXZmNjAuMTYuMTAwc3OyY8CLY8WIyLsJ6DC8PDpnyKFFo4dFTkNPREVSRIeUTGF2YzYwLjMxLjEwMiBsaWJ2cHgfQ7Z1wueBAKO9gQAAgLACAJ0BKhAAEAAARwiFhYiZhIgCAgJ1qgP4Agz9+wD+/00S//xKp/Eqn8Sqf/Eqn/0Yfww/hh/eEA==',true);
 if(!is_string($webm))throw new RuntimeException('Invalid WebM fixture.');
 $input=['id'=>$pairId,'session'=>$session['id'],'nonce'=>'phone-upload-audio-0001','consent'=>'yes','kind'=>'audio','title'=>'Phone original','durationMs'=>100];
 $send=static fn(array $fields,?string $media,?string $secret=null,string $mime='audio/wav',?string $origin=null):array=>
  uploadRequest($base,$secret??$token,$fields,$media,$mime,$origin);
 $reject=static function(callable $request,int $status,string $message)use($db,$owners,$fixture):void{uploadReject($db,$owners,$fixture,$request,$status,$message);};

 // This success path formerly failed in MariaDB with Unknown column 'm.kind'.
 $first=$send($input,$wav);$assetId=RecordingPhone::assetId($pairId,$input['nonce']);
 uploadCheck($first['status']===201&&($first['json']['ok']??false)===true&&($first['json']['asset']['existing']??null)===false&&
  ($first['json']['asset']['id']??'')===$assetId&&($first['json']['asset']['kind']??'')==='audio',
  'A real, consented multipart upload persists through the public API (received '.json_encode($first,JSON_UNESCAPED_SLASHES).')');
 $q=$db->prepare('SELECT a.*,m.mime,m.bytes,m.sha256,m.duration_ms,m.uploaded_by_pair_id,r.source_key,r.source_kind FROM recording_assets a JOIN recording_media_files m ON m.asset_id=a.id JOIN recording_sources r ON r.id=a.source_id WHERE a.id=?');
 $q->execute([$assetId]);$row=$q->fetch();$assetPath=$fixture.'/storage/recording-assets/'.$assetId.'.bin';
 uploadCheck($row!==false&&$row['recording_session_id']===$session['id']&&$row['kind']==='audio'&&$row['take_id']===null&&
  $row['uploaded_by_pair_id']===$pairId&&$row['source_key']==='p-'.$pairId&&$row['source_kind']==='phone'&&
  (int)$row['bytes']===strlen($wav)&&$row['sha256']===hash('sha256',$wav)&&(int)$row['duration_ms']===100,
  'Uploaded original has correct owner-scoped source, kind, byte count and digest in real tables');
 uploadCheck(is_file($assetPath)&&file_get_contents($assetPath)===$wav&&(fileperms($assetPath)&0777)===0600,
  'Uploaded original is stored unchanged with private file permissions in the temporary app');
 $before=uploadSnapshot($db,$owners,$fixture);$retry=$send($input,$wav);
 uploadCheck($retry['status']===200&&($retry['json']['asset']['id']??'')===$assetId&&($retry['json']['asset']['existing']??null)===true,
  'Identical authenticated retry returns the original asset without creating a duplicate');
 uploadCheck(uploadSnapshot($db,$owners,$fixture)===$before,'Successful retry leaves original file and all database rows unchanged');
 $reject(fn()=>$send($input,$changedWav),409,'Same nonce with changed content, same MIME and byte length is rejected');
 $reject(fn()=>$send(array_replace($input,['kind'=>'photo']),$png,$token,'image/png'),409,'Same nonce cannot replace an audio original with a photo');
 $reject(fn()=>$send(array_replace($input,['session'=>$sameOwnerSession['id']]),$wav),409,'Same nonce cannot move the original into another approved session');
 $reject(fn()=>$send($input,$wav,bin2hex(random_bytes(32))),403,'Incorrect controller token cannot replay an existing upload');
 $reject(fn()=>$send($input,$wav,''),403,'Missing controller token cannot upload');
 $reject(fn()=>$send(array_replace($input,['id'=>bin2hex(random_bytes(16))]),$wav),403,'Unknown phone pairing cannot upload');
 $reject(fn()=>$send(array_replace($input,['consent'=>'no']),$wav),403,'Consent remains mandatory even for an identical retry');
 $missingConsent=$input;unset($missingConsent['consent']);
 $reject(fn()=>$send($missingConsent,$wav),403,'Omitted consent is rejected');
 $reject(fn()=>$send($input,null),422,'A real uploaded media file is mandatory');
 $reject(fn()=>$send($input,'not a media file',$token,'image/png'),422,'Claimed multipart MIME does not bypass server-side media inspection');
 $reject(fn()=>$send(array_replace($input,['kind'=>'video']),$wav),422,'Claimed capture kind must match the media bytes');
 $reject(fn()=>$send(array_replace($input,['nonce'=>'invalid']),$wav),400,'Malformed upload nonce is rejected');
 $reject(fn()=>$send(array_replace($input,['session'=>'invalid']),$wav),422,'Malformed recording identifier is rejected');
 $reject(fn()=>$send($input,$wav,$token,'audio/wav','https://foreign.invalid'),403,'Cross-origin upload is rejected');
 $reject(fn()=>$send(array_replace($input,['session'=>$otherSession['id'],'nonce'=>'phone-upload-cross-owner']),$wav),403,'Approved phone cannot upload into another administrator recording');
 $reject(fn()=>$send(array_replace($input,['session'=>$draft['id'],'nonce'=>'phone-upload-draft-clip']),$wav),403,'Phone permission does not make a draft recording active');
 $reject(fn()=>$send(array_replace($input,['session'=>$unapproved['id'],'nonce'=>'phone-upload-no-grant']),$wav),403,'An active recording without explicit phone permission rejects upload');
 $reject(fn()=>$send(array_replace($input,['session'=>$audioOnly['id'],'nonce'=>'phone-upload-no-photo','kind'=>'photo']),$png,$token,'image/png'),403,'Recording profile capture restrictions are enforced');

 foreach(['revoked','pending','expired'] as $status){
  $db->prepare('UPDATE controller_pairs SET status=? WHERE id=?')->execute([$status,$pairId]);
  $reject(fn()=>$send($input,$wav),403,ucfirst($status).' pairing cannot replay an existing upload');
 }
 $db->prepare("UPDATE controller_pairs SET status='paired',paired_expires_at=UTC_TIMESTAMP()-INTERVAL 1 SECOND WHERE id=?")->execute([$pairId]);
 $reject(fn()=>$send($input,$wav),403,'Expired approval cannot replay an existing upload');
 $db->prepare("UPDATE controller_pairs SET paired_expires_at=UTC_TIMESTAMP()+INTERVAL 30 MINUTE WHERE id=?")->execute([$pairId]);
 RecordingPhone::grant($db,$owner,$session['id'],false);
 $reject(fn()=>$send($input,$wav),403,'Host revocation applies before idempotent retry handling');
 RecordingPhone::grant($db,$owner,$session['id'],true);

 $foreignTake=RecordingSessions::startTake($db,$other,$otherSession['id'],['title'=>'Other take','nonce'=>'other-upload-take-001']);
 $reject(fn()=>$send(array_replace($input,['nonce'=>'phone-upload-foreign-take','takeId'=>$foreignTake['id']]),$wav),403,'Take from another recording cannot receive uploaded media');
 $reject(fn()=>$send(array_replace($input,['nonce'=>'phone-upload-malformed-take','takeId'=>'invalid']),$wav),422,'Malformed take identifier is rejected');
 $take=RecordingSessions::startTake($db,$owner,$session['id'],['title'=>'Synchronized phone audio','nonce'=>'upload-synced-take-001']);
 $q=$db->prepare('SELECT started_at FROM recording_takes WHERE id=?');$q->execute([$take['id']]);
 $takeDate=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.v',(string)$q->fetchColumn(),new DateTimeZone('UTC'));
 if(!$takeDate)throw new RuntimeException('Invalid fixture take timestamp.');
 $start=(int)round((float)$takeDate->format('U.u')*1000)+200;
 $sync=array_replace($input,['nonce'=>'phone-upload-coordinated-001','takeId'=>$take['id'],'recordedStartMs'=>$start,'recordedEndMs'=>$start+100,'clockOffsetMs'=>10]);
 $reject(fn()=>$send(array_replace($input,['nonce'=>'phone-upload-partial-sync','recordedEndMs'=>$start]),$wav),422,'Incomplete synchronization metadata is rejected');
 $reject(fn()=>$send($sync,$wav),403,'Unarmed phone cannot submit a coordinated take');
 $pair=ControllerPairing::authenticate($db,$pairId,$token,true);
 RecordingMulticam::arm($db,$pair,$session['id'],true,'audio');
 $reject(fn()=>$send(array_replace($sync,['nonce'=>'phone-upload-bad-end','recordedEndMs'=>$start-1]),$wav),422,'Reversed capture timing is rejected');
 $reject(fn()=>$send(array_replace($sync,['nonce'=>'phone-upload-bad-clock','clockOffsetMs'=>86400001]),$wav),422,'Out-of-range clock offset is rejected');
 $reject(fn()=>$send(array_replace($sync,['nonce'=>'phone-upload-wrong-take-time','recordedStartMs'=>$start+3600001,'recordedEndMs'=>$start+3600101]),$wav),422,'Capture timing must belong to the selected take');
 $synced=$send($sync,$wav);$syncAsset=RecordingPhone::assetId($pairId,$sync['nonce']);
 uploadCheck($synced['status']===201&&($synced['json']['asset']['id']??'')===$syncAsset,'Armed phone uploads coordinated audio through the real API');
 $q=$db->prepare('SELECT a.take_id,a.start_offset_ms,a.sync_confidence,c.* FROM recording_assets a JOIN recording_capture_sync c ON c.asset_id=a.id WHERE a.id=?');
 $q->execute([$syncAsset]);$syncRow=$q->fetch();
 uploadCheck($syncRow!==false&&$syncRow['take_id']===$take['id']&&(int)$syncRow['start_offset_ms']===200&&(int)$syncRow['sync_confidence']===1&&
  (int)$syncRow['recorded_start_ms']===$start&&(int)$syncRow['recorded_end_ms']===$start+100&&(int)$syncRow['clock_offset_ms']===10&&$syncRow['sync_method']==='controller_poll',
  'Coordinated upload atomically persists its take, offset and complete timing metadata');
 $before=uploadSnapshot($db,$owners,$fixture);$syncRetry=$send($sync,$wav);
 uploadCheck($syncRetry['status']===200&&($syncRetry['json']['asset']['existing']??null)===true&&uploadSnapshot($db,$owners,$fixture)===$before,
  'Coordinated retry does not duplicate or alter timing rows');

 $photoInput=array_replace($input,['nonce'=>'phone-upload-photo-001','kind'=>'photo']);$photo=$send($photoInput,$png,$token,'image/png');
 uploadCheck($photo['status']===201&&($photo['json']['asset']['kind']??'')==='photo'&&
  file_get_contents($fixture.'/storage/recording-assets/'.RecordingPhone::assetId($pairId,$photoInput['nonce']).'.bin')===$png,
  'Real photo upload follows the same repaired persistence path without changing its bytes');
 $videoInput=array_replace($input,['nonce'=>'phone-upload-video-001','kind'=>'video','durationMs'=>1000]);
 $video=$send($videoInput,$webm,$token,'video/webm');$videoAsset=RecordingPhone::assetId($pairId,$videoInput['nonce']);
 uploadCheck($video['status']===201&&($video['json']['asset']['id']??'')===$videoAsset&&($video['json']['asset']['kind']??'')==='video'&&
  file_get_contents($fixture.'/storage/recording-assets/'.$videoAsset.'.bin')===$webm,
  'Real VP8 WebM video upload follows the repaired API path and preserves original bytes');
 $q=$db->prepare('SELECT a.kind,m.mime,m.bytes,m.sha256 FROM recording_assets a JOIN recording_media_files m ON m.asset_id=a.id WHERE a.id=?');
 $q->execute([$videoAsset]);$videoRow=$q->fetch();
 uploadCheck($videoRow!==false&&$videoRow['kind']==='video'&&$videoRow['mime']==='video/webm'&&
  (int)$videoRow['bytes']===strlen($webm)&&$videoRow['sha256']===hash('sha256',$webm),
  'Video kind, server-detected MIME and digest are correctly persisted in their separate tables');
 RecordingSessions::finishTake($db,$owner,$session['id'],$take['id']);
 RecordingSessions::setStatus($db,$owner,$session['id'],'completed',$session['revision']);
 $reject(fn()=>$send($input,$wav),403,'Completed recording rejects further phone uploads and old nonce retries');
 uploadCheck(count(uploadSnapshot($db,$owners,$fixture)['assets'])===4,'Only the four authorized original uploads were persisted');
 echo 'V5Q32G2 PHONE UPLOAD HTTP + MARIADB: '.$checks.'/'.$checks."\n";
}catch(Throwable $e){
 if(is_file($fixture.'/server.log'))fwrite(STDERR,"Fixture server log:\n".file_get_contents($fixture.'/server.log'));
 throw $e;
}finally{
 uploadStopServer($server);
 try{foreach($owners as $admin)$db->prepare('DELETE FROM admins WHERE id=?')->execute([$admin]);}
 finally{
  if(dirname($fixture)!==rtrim(sys_get_temp_dir(),DIRECTORY_SEPARATOR)||!str_starts_with(basename($fixture),'lucky-phone-upload-g2-'))
   throw new LogicException('Refusing cleanup outside the unique test fixture.');
  uploadRemoveTree($fixture);
 }
}
