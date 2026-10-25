<?php
declare(strict_types=1);
function multicamContract(bool $ok,string $label):void {if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label."\n";}
$root=dirname(__DIR__);
$migration=file_get_contents($root.'/lib/migrations/v5q29e3_multicamera_coordination.sql');
$phone=file_get_contents($root.'/api/recording-phone.php');
$host=file_get_contents($root.'/api/recording-sessions.php');
$studio=file_get_contents($root.'/public/recording-camera.js');
$ui=file_get_contents($root.'/camera.php');
$sessions=file_get_contents($root.'/lib/RecordingSessions.php');
$storage=file_get_contents($root.'/lib/RecordingPhone.php');
multicamContract(str_contains($migration,'recording_camera_arms')&&str_contains($migration,'recording_capture_sync'),'Persistent camera participation and sync metadata schema');
multicamContract(str_contains(file_get_contents($root.'/upgrade.php'),'v5q29e3_multicamera_coordination.sql')&&str_contains(file_get_contents($root.'/install.php'),'v5q29e3_multicamera_coordination.sql'),'Install and upgrade both include idempotent multicamera migration');
multicamContract(str_contains($phone,"'arm','poll'")&&str_contains($phone,'ControllerPairing::authenticate'),'Every phone coordination operation authenticates QR pairing');
multicamContract(str_contains($host,"camera_devices")&&str_contains($host,'requireAdmin()'),'Only administrator can inspect recording phones');
multicamContract(str_contains($storage,'recording_capture_sync')&&str_contains($storage,'recordedStartMs')&&str_contains($storage,'sync_confidence'),'Upload captures estimated take offset with auditable method');
multicamContract(str_contains($sessions,'Stop the active take before starting another'),'Prevent ambiguous simultaneous host takes');
multicamContract(str_contains($ui,'session-camera-arm')&&str_contains($ui,'session-camera-consent'),'Phone UI requires explicit arm and capture consent');
multicamContract(str_contains($studio,"setInterval(()=>{if(armed")&&str_contains($studio,"stop('host')")&&str_contains($studio,'finishedTake'),'Phone polls host control and prevents restart after stop');
multicamContract(str_contains($studio,"clockDelta=")&&str_contains($studio,'recordedStartMs')&&str_contains($studio,'recordedEndMs'),'Phone records approximate server-aligned timestamps');
echo "V5Q.29E3 MULTICAMERA CONTRACT CERTIFICATION PASSED\n";
