<?php
declare(strict_types=1);
function assertLive(bool $condition,string $message):void {if(!$condition)throw new RuntimeException('HF8B: '.$message);echo "PASS $message\n";}
$root=dirname(__DIR__);
$proof=file_get_contents($root.'/proof.php');
$director=file_get_contents($root.'/public/camera-director.js');
$publisher=file_get_contents($root.'/public/director-publish.js');
$viewer=file_get_contents($root.'/public/director-view.js');
$api=file_get_contents($root.'/api/director-live.php');
$service=file_get_contents($root.'/lib/DirectorBroadcast.php');
$page=file_get_contents($root.'/live-view.php');
$schema=file_get_contents($root.'/lib/schema.sql');
$migration=file_get_contents($root.'/lib/migrations/v5q29e4d1hf8b_director_live.sql');
$upgrade=file_get_contents($root.'/upgrade.php');
assertLive(str_contains($proof,'director-publish-consent')&&str_contains($proof,'director-unpublish'),'Explicit broadcast consent and stop controls');
assertLive(str_contains($proof,'director-publish.js')&&str_contains($proof,'camera-director.js'),'Publisher follows Program mixer');
assertLive(str_contains($director,'program.captureStream(25)')&&(str_contains($director,'programStream:')||(str_contains($director,'function programStream(')&&str_contains($director,'programStream,')))&&!str_contains($publisher,'director-preview'),'Only Program canvas exposed to publisher');
assertLive(str_contains($publisher,"request('publish'")&&str_contains($publisher,"request('stop'")&&str_contains($publisher,'lucky:proof-close'),'Explicit publish, revoke and proof-close lifecycle');
assertLive(str_contains($api,'requireAdmin()')&&str_contains($api,'assertCsrf()')&&str_contains($api,'SchemaGuard::require'),'Administrative publication, CSRF and upgrade guard');
assertLive(str_contains($service,"hash('sha256',".'$key'.")")&&str_contains($service,'hash_equals('),'Viewer bearer token stored only as hash');
assertLive(str_contains($service,'consent_ack=1')&&str_contains($service,'is_open=1'),'Live publication requires active consented proof');
assertLive(str_contains($service,'INTERVAL 14 SECOND')&&str_contains($service,'MAX_VIEWERS=8'),'Inactive broadcasts expire and viewer count is bounded');
assertLive(str_contains($page,'no-referrer')&&str_contains($page,'director-view-video')&&str_contains($viewer,"addTransceiver('video'"),'Read-only Live View with no leaked invitation referrer');
assertLive(str_contains($schema,'director_broadcasts')&&str_contains($schema,'director_viewers')&&str_contains($migration,'ON DELETE CASCADE')&&str_contains($upgrade,'v5q29e4d1hf8b_director_live.sql'),'Bundled schema and upgrade migration for broadcasts');
echo "HF8B PROGRAM BROADCAST CONTRACT: 10/10\n";
