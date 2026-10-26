<?php
declare(strict_types=1);
function checkCameraHF4(bool $passed,string $label):void {
 if(!$passed)throw new RuntimeException('FAIL '.$label);
 echo 'PASS '.$label."\n";
}
$root=dirname(__DIR__);
$controller=(string)file_get_contents($root.'/controller.php');
$js=(string)file_get_contents($root.'/public/phone-controller.js');
$css=(string)file_get_contents($root.'/public/platform.css');
checkCameraHF4(str_contains($controller,'<a href="camera.php" data-phone-view="camera" class="phone-camera-studio-link">'),'Both Camera Studio actions are native navigation links');
checkCameraHF4(substr_count($controller,'<a href="camera.php" data-phone-view="camera" class="phone-camera-studio-link">')===2,'Bottom dock and More menu both have always-available Camera Studio');
checkCameraHF4(!str_contains($controller,'data-phone-view="camera" disabled'),'Camera Studio is never rendered disabled');
checkCameraHF4(str_contains($js,"if(name!=='camera')button.disabled=!availability[name]"),'Controller status refresh does not disable independent studio link');
checkCameraHF4(str_contains($js,"if(button.dataset.phoneView==='camera')return;"),'Normal camera link is not intercepted by game/proof navigation');
checkCameraHF4(str_contains($css,'.phone-bottom-nav :is(button,a.phone-camera-studio-link)')&&str_contains($css,'.phone-menu-items :is(button,a.phone-camera-studio-link)'),'Camera links retain designed dock and menu styling');
checkCameraHF4(str_contains($controller,'public/phone-controller.js?v=5q29e4d1hf4')&&str_contains($controller,'public/platform.css?v=5q29e4d1hf4'),'Controller refreshes its own cached scripts and CSS');
echo "V5Q.29E4D1HF4 NATIVE CAMERA NAV CONTRACT PASSED\n";
