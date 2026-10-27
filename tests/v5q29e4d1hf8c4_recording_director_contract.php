<?php
declare(strict_types=1);
function c4Check(bool $ok,string $message):void {if(!$ok)throw new RuntimeException('HF8C4: '.$message);echo "PASS $message\n";}
$root=dirname(__DIR__);$read=static fn(string $name):string=>(string)file_get_contents($root.'/'.$name);
$admin=$read('admin.php');$page=$read('director.php');$proof=$read('proof.php');$js=$read('public/director-studio.js');
$engine=$read('public/camera-director.js');$publisher=$read('public/director-publish.js');$sceneJs=$read('public/director-scenes.js');
$api=$read('api/director-live.php');$broadcast=$read('lib/DirectorBroadcast.php');$sceneApi=$read('api/director-scenes.php');
$sceneService=$read('lib/DirectorRecordingScenes.php');$migration=$read('lib/migrations/v5q29e4d1hf8c4_director_recordings.sql');$css=$read('public/platform.css');
c4Check(str_contains($admin,'href="director.php"')&&str_contains($admin,'admin-director-nav'),'Admin contains direct Camera Director navigation');
c4Check(str_contains($page,"isAdmin()")&&str_contains($page,'director-studio-session'),'Dedicated page requires administrator login');
c4Check(str_contains($page,'id="camera-director"')&&str_contains($page,'id="director-program"')&&str_contains($page,'id="director-preview"'),'Standalone Program and Preview monitors');
c4Check(str_contains($page,'director-studio-add-camera')&&str_contains($js,'getUserMedia({video:')&&str_contains($js,'audio:false'),'Local video permissions explicit; microphones do not auto-start');
c4Check(str_contains($js,"api/recording-sessions.php?")&&str_contains($js,"request('list'")&&str_contains($js,"request('detail'"),'Director selects and synchronizes existing recording sessions');
c4Check(str_contains($read('public/admin-recordings.js'),'recording-open-director')&&str_contains($read('public/admin-recordings.js'),'director.php?recording='),'Selected recording sessions open directly in Camera Director');
c4Check(str_contains($engine,"action=media_list")&&str_contains($engine,'api/recording-media.php?id=')&&str_contains($engine,'recordingSession:recording'),'Director loads session-owned video uploads');
c4Check(str_contains($engine,'lucky:director-studio-open')&&str_contains($publisher,'lucky:director-studio-open')&&str_contains($sceneJs,'lucky:director-studio-open'),'Camera, publisher and scenes share one session lifecycle');
c4Check(str_contains($js,'closeSession()')&&str_contains($js,'stopCameras()')&&str_contains($publisher,'lucky:director-studio-close'),'Switch/exit revokes broadcast and releases devices');
c4Check(str_contains($api,'DirectorBroadcast::startRecording')&&str_contains($api,"assertCsrf()")&&str_contains($broadcast,'self::recording('),'Broadcast publishing enforces owner session checks');
c4Check(str_contains($broadcast,'recording_sessions WHERE id=? AND owner_admin_id=?')&&str_contains($broadcast,"status IN ('draft','active')"),'Finished and foreign recordings cannot publish');
c4Check(str_contains($broadcast,'director_broadcast_recordings')&&str_contains($broadcast,'hash_equals(')&&str_contains($broadcast,"b.visibility='public'"),'Invitation/privacy protections remain enforced');
c4Check(str_contains($sceneApi,'DirectorRecordingScenes::save')&&str_contains($sceneJs,'recordingSession:id'),'Scene presets bind to selected recording session');
c4Check(str_contains($sceneService,'recording_assets WHERE id=? AND recording_session_id=?')&&str_contains($sceneService,'owner_admin_id=?'),'Scenes cannot import foreign recording clips');
c4Check(str_contains($migration,'CREATE TABLE IF NOT EXISTS director_broadcast_recordings')&&str_contains($migration,'CREATE TABLE IF NOT EXISTS director_recording_scenes'),'Non-destructive standalone studio migration');
foreach(['install.php','upgrade.php','homeserver/initialize.php','homeserver/migrate.php'] as $file)
 c4Check(str_contains($read($file),'v5q29e4d1hf8c4_director_recordings.sql'),$file.' provisions independent Director storage');
c4Check(str_contains($css,'.proof-editor-page #camera-director{display:none')&&str_contains($proof,'capture-phone-camera'),'Prize proof retains phone capture but hides production Director');
c4Check((str_contains($page,'director-studio.js?v=5q29e4d1hf8c4')||str_contains($page,'director-studio.js?v=5q29e4d1hf8c5'))&&(str_contains($read('lib/Layout.php'),'platform.css?v=5q29e4d1hf8c4')||str_contains($read('lib/Layout.php'),'platform.css?v=5q29e4d1hf8c5')),'New studio resources are cache-busted');
echo "HF8C4 RECORDING CAMERA DIRECTOR CONTRACT PASSED\n";
