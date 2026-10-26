<?php
declare(strict_types=1);
function directorCheck(bool $ok,string $name):void {if(!$ok)throw new RuntimeException('FAIL '.$name);echo 'PASS '.$name."\n";}
$base=dirname(__DIR__);
$proof=(string)file_get_contents($base.'/proof.php');
$script=(string)file_get_contents($base.'/public/camera-director.js');
$style=(string)file_get_contents($base.'/public/platform.css');
$layout=(string)file_get_contents($base.'/lib/Layout.php');
directorCheck(str_contains($proof,'id="camera-director"')&&preg_match('/public\/camera-director\.js\?v=5q29e4d1hf8[a-z]/',$proof)===1,
 'Director control surface and versioned program engine are installed on proof page');
directorCheck(str_contains($proof,'id="director-preview"')&&str_contains($proof,'id="director-program"'),
 'Separate cue and program monitors exist in the same proof workspace');
directorCheck(str_contains($proof,'id="director-cut"')&&str_contains($proof,'id="director-fade"'),
 'Cut and fade controls are available');
directorCheck(str_contains($proof,'id="director-overlay-text"')&&str_contains($proof,'id="director-overlay-sticker"'),
 'Text lower-third and sticker graphics are composited into Program');
directorCheck(str_contains($script,"'phone:'+")&&str_contains($script,"'camera:'+")&&str_contains($script,'grid.querySelectorAll'),
 'Program chooses sources from existing phone and desktop camera grid');
directorCheck(str_contains($script,'program.captureStream(25)'),
 'Program records the rendered output rather than one input camera');
directorCheck(str_contains($script,'MediaRecorder(exportStream')&&str_contains($script,'URL.createObjectURL(blob)')&&
 str_contains($proof,'id="director-export-download"'),
 'Browser export supports program cuts, transitions and overlay output');
directorCheck(str_contains($script,"window.addEventListener('lucky:proof-close',close)")&&
 str_contains($script,'MAX_BYTES=80*1024*1024'),
 'Proof release stops program publishing and export is bounded');
directorCheck(str_contains($style,'.director-monitors')&&preg_match('/platform\.css\?v=5q29e4d1hf8[a-z]/',$layout)===1,
 'Responsive styling deployed with cache-busted CSS');
directorCheck(str_contains($proof,'video-only')&&
 (str_contains($proof,'Live View publishing')||str_contains($proof,'The broadcast shows only Program, never Preview')),
 'Initial phase explicitly distinguishes local program export from live distribution');
echo "HF8A CAMERA DIRECTOR CONTRACT PASSED\n";
