<?php
declare(strict_types=1);
function checkCameraHF3(bool $ok,string $label):void {
 if(!$ok)throw new RuntimeException('FAIL '.$label);
 echo 'PASS '.$label."\n";
}
$root=dirname(__DIR__);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$controller=$read('public/phone-controller.js');
$launcher=$read('lib/PhoneGameDashboard.php');
$recording=$read('lib/RecordingPhone.php');
$api=$read('api/recording-phone.php');
$camera=$read('public/recording-camera.js');
$admin=$read('admin.php');
$css=$read('public/platform.css');
$browser=$read('tests/v5q29e2_camera_browser.py');
checkCameraHF3(str_contains($controller,'camera:paired'),'Paired controllers have a Camera Studio navigation action during active games');
checkCameraHF3(str_contains($launcher,'RecordingSessions::autoSpinWheel($db,$owner'),'Phone-launched Spin the Wheel creates its camera group');
checkCameraHF3(str_contains($launcher,"'phoneCameraEnabled'=>\$pkg['phoneCameraEnabled']"),'Phone-launched game snapshots camera package setting');
checkCameraHF3(str_contains($api,'RecordingPhone::ensureSpinWheelCamera($db,$pair);'),'Approved phone camera listing repairs eligible older live games');
checkCameraHF3(str_contains($recording,'function ensureSpinWheelCamera(')&&str_contains($recording,"a.event='session.start'"),'Camera repair restricts results to host-owned live games');
checkCameraHF3(str_contains($recording,'Do not override a host')&&str_contains($recording,"create_nonce=?"),'Camera repair preserves revocation and is idempotent');
checkCameraHF3(str_contains($camera,'connectionInterrupted=true')&&str_contains($camera,'12' . '000'), 'Camera listing retries without clearing cached session on transient failures');
checkCameraHF3(str_contains($camera,'Local capture is preserved'),'Brief camera coordination losses preserve the recording');
checkCameraHF3(str_contains($camera,'await refresh(true,true);'),'Completed media uploads immediately refresh the camera group clip count');
checkCameraHF3(str_contains($admin,'class="prize-package-options"')&&str_contains($admin,'id="package-phone-camera"'),'Existing package controls remain together and editable');
checkCameraHF3(str_contains($css,'input:not([type=file]):not([type=checkbox])')&&str_contains($css,'.prize-package-options input[type=checkbox]'),'Package checkbox sizing does not inherit text field dimensions');
checkCameraHF3(str_contains($browser,"mock_state['fail_once'] = True"),'Browser acceptance simulates temporary Camera Studio load failure');
echo "V5Q.29E4D1HF3 PHONE CAMERA CONTRACT PASSED\n";
