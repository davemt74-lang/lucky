<?php
declare(strict_types=1);
function timelineContract(bool $test,string $name):void{if(!$test)throw new RuntimeException('FAIL '.$name);echo 'PASS '.$name."\n";}
$root=dirname(__DIR__);
$schema=file_get_contents($root.'/lib/migrations/v5q29e4a_multicamera_timeline.sql');
$lib=file_get_contents($root.'/lib/RecordingTimeline.php');
$api=file_get_contents($root.'/api/recording-timeline.php');
$html=file_get_contents($root.'/recording-editor.php');
$js=file_get_contents($root.'/public/recording-editor.js');
timelineContract(str_contains($schema,'recording_timeline_edits')&&str_contains($schema,'revision')&&str_contains($schema,'timeline_json'),'Durable non-destructive alignment revision table');
timelineContract(str_contains(file_get_contents($root.'/install.php'),'v5q29e4a_multicamera_timeline.sql')&&str_contains(file_get_contents($root.'/upgrade.php'),'v5q29e4a_multicamera_timeline.sql'),'Installer and upgrader include timeline migration');
timelineContract(str_contains($api,'requireAdmin()')&&str_contains($api,'assertCsrf()')&&str_contains($api,'RecordingTimeline::save'),'Editing requires authenticated admin and CSRF');
timelineContract(str_contains($lib,'FOR UPDATE')&&str_contains($lib,'revision')&&str_contains($lib,'Another editor changed'),'Conflict-safe saving');
timelineContract(str_contains($lib,'recording_session_id=?')&&str_contains($lib,'SELECT a.id,a.kind'),'Cross-session media validation');
timelineContract(str_contains($lib,'TIMESTAMPDIFF(MICROSECOND')&&str_contains($lib,'start_offset_ms'),'Different takes share one session-wide clock');
timelineContract(str_contains($html,'mc-timeline-scroll')&&str_contains($html,'mc-angles')&&str_contains($html,'mc-offset'),'Live angle switcher and clip inspector available');
timelineContract(str_contains($js,'timeline.switches')&&str_contains($js,'timeline.offsets')&&str_contains($js,'api(\'save\''),'Client can align and save camera switches');
timelineContract(str_contains($js,'recording-media.php')&&str_contains($js,'MediaRecorder')===false,'Only private media previews; no microphone request from editor');
echo "V5Q.29E4A MULTICAMERA TIMELINE CONTRACT CERTIFICATION PASSED\n";
