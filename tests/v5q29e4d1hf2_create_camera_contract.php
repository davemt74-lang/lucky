<?php
declare(strict_types=1);
function checkCreateCamera(bool $ok,string $label):void {
 if(!$ok)throw new RuntimeException('FAIL '.$label);
 echo 'PASS '.$label."\n";
}
$root=dirname(__DIR__);
$admin=(string)file_get_contents($root.'/admin.php');
$js=(string)file_get_contents($root.'/public/admin.js');
$api=(string)file_get_contents($root.'/api/index.php');
$packages=(string)file_get_contents($root.'/lib/PrizePackages.php');
$css=(string)file_get_contents($root.'/public/platform.css');
checkCreateCamera(str_contains($admin,'id="prize-package-modal-phone-camera" type="checkbox" checked'),'Create-package form exposes enabled-by-default camera toggle');
checkCreateCamera(str_contains($admin,'id="package-phone-camera" type="checkbox" checked'),'Existing package settings retain camera toggle');
checkCreateCamera(str_contains($js,"$('prize-package-modal-phone-camera').checked=true"),'Create form resets camera to on for each new package');
checkCreateCamera(str_contains($js,"phoneCameraEnabled:$('prize-package-modal-phone-camera').checked"),'Create form collects boolean choice');
checkCreateCamera(str_contains($js,'phoneCameraEnabled:draft.phoneCameraEnabled'),'Create request sends camera choice');
checkCreateCamera(str_contains($api,"if(array_key_exists('phoneCameraEnabled',\$in)&&!is_bool(\$in['phoneCameraEnabled']))"),'Create endpoint rejects invalid camera choices');
checkCreateCamera(str_contains($packages,'$phoneEnabled=$phoneCameraEnabled??true;'),'New packages default camera on at database layer');
checkCreateCamera(str_contains($css,'.prize-package-modal-fields .prize-package-create-camera input[type=checkbox]'),'Create modal checkbox has compact mobile-safe styling');
echo "V5Q.29E4D1HF2 CAMERA PACKAGE CREATION CONTRACT PASSED\n";
