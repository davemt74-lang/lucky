<?php
declare(strict_types=1);
require_once __DIR__.'/../lib/RecordingProfiles.php';
require_once __DIR__.'/../lib/GameCatalog.php';
function e4cCheck(bool $pass,string $label):void {if(!$pass)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label."\n";}
function e4cReject(callable $fn,int $code,string $label):void {
 try{$fn();throw new RuntimeException('Expected validation error: '.$label);}catch(DomainException $e){e4cCheck($e->getCode()===$code,$label);}
}
$root=dirname(__DIR__);
foreach(['install.php','upgrade.php','homeserver/migrate.php','homeserver/initialize.php'] as $file)
 e4cCheck(str_contains((string)file_get_contents($root.'/'.$file),'v5q29e4c_recording_profiles.sql'),$file.' applies E4C recording migration');
e4cCheck(str_contains((string)file_get_contents($root.'/lib/migrations/v5q29e4c_recording_profiles.sql'),'recording_session_profiles'),'Immutable profile storage migration');
$capture=RecordingProfiles::capture(['multicameraEnabled'=>false,'maxCameras'=>8,'countdownSeconds'=>10,'audio'=>false]);
e4cCheck($capture['maxCameras']===1&&$capture['countdownSeconds']===10&&$capture['audio']===false,'Single-camera policy clamps camera capacity');
e4cReject(fn()=>RecordingProfiles::capture(['maxCameras'=>0]),422,'Camera count bounded');
e4cReject(fn()=>RecordingProfiles::capture(['countdownSeconds'=>31]),422,'Countdown bounded');
e4cReject(fn()=>RecordingProfiles::capture(['video'=>false,'photo'=>false,'audio'=>false]),422,'At least one capture mode required');
e4cReject(fn()=>RecordingProfiles::capture(['unexpected'=>true]),422,'Unknown capture permission rejected');
$meta=RecordingProfiles::metadata(['tags'=>['event','music','event'],'eventDate'=>'2026-10-25','location'=>'Main room','shotList'=>['Wide','Close-up']]);
e4cCheck($meta['tags']===['event','music']&&count($meta['shotList'])===2,'Structured searchable recording metadata normalized');
e4cReject(fn()=>RecordingProfiles::metadata(['eventDate'=>'2026-02-30']),422,'Invalid calendar date rejected');
e4cReject(fn()=>RecordingProfiles::metadata(['tags'=>array_fill(0,13,'x')]),422,'Tags bounded');
$sandbox=sys_get_temp_dir().'/lucky-e4c-catalog-'.bin2hex(random_bytes(8));
try{
 $saved=GameCatalog::save('test-recording',['title'=>'Film game','recording'=>['multicameraEnabled'=>false,'maxCameras'=>8,'countdownSeconds'=>5,'photo'=>false]],$sandbox);
 e4cCheck($saved['recording']['maxCameras']===1&&!$saved['recording']['photo'],'Game policy is saved and normalized');
 $reload=GameCatalog::load('test-recording',$sandbox);
 e4cCheck($reload['recording']===$saved['recording'],'Game camera rules persist across catalog reload');
}finally{
 if(is_dir($sandbox)){
  $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sandbox,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
  foreach($files as $entry){if($entry->isDir())rmdir($entry->getPathname());else unlink($entry->getPathname());}
  rmdir($sandbox);
 }
}
foreach(['public/recording-camera.js','public/admin-recordings.js','public/game-catalog.js'] as $file)
 e4cCheck(str_contains((string)file_get_contents($root.'/'.$file),'recording')&&str_contains((string)file_get_contents($root.'/admin.php'),'catalog-record-multicam'),$file.' wired to recording controls');
echo "V5Q.29E4C PROFILE CONTRACT CERTIFIED\n";
