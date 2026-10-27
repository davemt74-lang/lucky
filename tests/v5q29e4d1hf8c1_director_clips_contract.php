<?php
declare(strict_types=1);
function clipsCheck(bool $yes,string $label):void {if(!$yes)throw new RuntimeException('HF8C1: '.$label);echo 'PASS '.$label."\n";}
$root=dirname(__DIR__);
$js=file_get_contents($root.'/public/camera-director.js');
$page=file_get_contents($root.'/proof.php');
$css=file_get_contents($root.'/public/platform.css');
$layout=file_get_contents($root.'/lib/Layout.php');
clipsCheck(str_contains($page,'director-refresh-clips')&&str_contains($page,'director-clip-count'),'Saved proof clips visible in Camera Director');
clipsCheck(str_contains($js,'api/claim-media.php?action=list')&&str_contains($js,"credentials:'same-origin'"),'Clips sourced through authenticated existing proof media endpoint');
clipsCheck(str_contains($js,"c.kind==='video'&&c.claimId===context.claim"),'Only current prize video clips can be cued');
clipsCheck(str_contains($js,"c.src!=='api/claim-media.php?action=stream&id='+c.id"),'Untrusted external media URLs are rejected');
clipsCheck(str_contains($js,'const clips=new Map()')&&str_contains($js,"id='clip:'+c.id"),'Recorded clips share one source selector with live cameras');
clipsCheck(str_contains($js,'trimStartMs')&&str_contains($js,'trimEndMs')&&str_contains($js,'current.end-.04'),'Published playback honors clip trim start and end');
clipsCheck(str_contains($js,"['cut','fade','wipe','dip']")&&str_contains($js,'programCtx.clip()'),'Program supports cut, fade, wipe and dip transitions');
clipsCheck(str_contains($page,'director-wipe')&&str_contains($page,'director-dip'),'Operator has explicit Wipe and Dip controls');
clipsCheck(str_contains($js,"window.addEventListener('lucky:proof-close',close)")&&str_contains($js,'for(const id of [...clips.keys()])discardClip(id)'),'Proof close releases prerecorded sources');
clipsCheck((str_contains($layout,'platform.css?v=5q29e4d1hf8c1')||str_contains($layout,'platform.css?v=5q29e4d1hf8c2')||str_contains($layout,'platform.css?v=5q29e4d1hf8c3')||str_contains($layout,'platform.css?v=5q29e4d1hf8c4'))&&str_contains($css,'.director-clip-toolbar'),'CSS and script release assets are versioned');
echo "HF8C1 CAMERA DIRECTOR CLIPS CONTRACT: 10/10\n";
