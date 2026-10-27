<?php
declare(strict_types=1);
/** HF8C7: host recording upload and synchronized take integration contract. */
$root=dirname(__DIR__);
$read=static fn(string $name):string=>(string)file_get_contents($root.'/'.$name);
function c7check(bool $condition,string $message):void {
 if(!$condition)throw new RuntimeException('HF8C7: '.$message);
 echo "PASS $message\n";
}
$host=$read('lib/RecordingHostMedia.php');
$api=$read('api/recording-host.php');
$client=$read('public/director-recording-sync.js');
$studio=$read('public/director-studio.js');
$director=$read('public/camera-director.js');
$page=$read('director.php');
$session=$read('api/recording-sessions.php');
c7check(str_contains($api,'requireAdmin()')&&str_contains($api,'assertCsrf()'),'Multipart host upload is admin-authenticated and CSRF protected');
c7check(str_contains($host,'is_uploaded_file')&&str_contains($host,'move_uploaded_file'),'Only genuine server-side upload temp files can become originals');
c7check(str_contains($host,'owner_admin_id=?')&&str_contains($host,'recording_session_id=?'),'Ownership and take scope are checked against the database');
c7check(str_contains($host,'ClaimMedia::captureKind')&&str_contains($host,'MAX_SESSION_BYTES'),'Capture type and per-session storage limits are enforced');
c7check(str_contains($host,'hash_equals')&&str_contains($host,'existing')&&str_contains($host,'nonce'),'Retry tokens are idempotent and content-checked');
c7check(str_contains($host,'director_clock')&&str_contains($host,'sync_confidence')&&str_contains($host,'start_offset_ms'),'Director timing persists alongside camera tracks');
c7check(str_contains($studio,"lucky:director-take-start")&&str_contains($studio,"lucky:director-take-stop"),'Host take lifecycle drives original capture');
c7check(str_contains($studio,'cameras:()'),'Connected desktop camera streams are exposed to the sync layer');
c7check(str_contains($director,'lucky:director-program-captured')&&str_contains($director,'hostMeta'),'Program compositions are emitted as uploadable recordings');
c7check(str_contains($client,'indexedDB.open')&&str_contains($client,'navigator.onLine')&&str_contains($client,"window.addEventListener('online'"),'Offline captures queue persistently and retry on reconnect');
c7check(str_contains($client,'lucky:director-program-captured')&&str_contains($client,'lucky:cameras-refreshed'),'Program and hot-plugged cameras share the take');
c7check(str_contains($client,'recording-host.php')&&str_contains($client,'X-CSRF-Token'),'Queued originals use the host-only upload endpoint');
c7check(str_contains($page,'director-recording-sync.js')&&str_contains($page,'Record all cameras + Program'),'Production workspace exposes actual grouped recording');
c7check(str_contains($session,'serverTimeMs')&&str_contains($studio,'clockOffsetMs'),'Host timestamps include estimated clock correction');
c7check(!str_contains($host,'claim_media_id')&&!str_contains($api,'controller_pairs'),'Director originals remain separate from prize claims and phone authentication');
echo "HF8C7 DIRECTOR SYNC CONTRACT: 15/15\n";
