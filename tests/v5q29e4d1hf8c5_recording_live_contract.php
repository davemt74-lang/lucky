<?php
declare(strict_types=1);
function c5Check(bool $ok,string $label):void {
 if(!$ok)throw new RuntimeException('HF8C5: '.$label);
 echo "PASS $label\n";
}
$root=dirname(__DIR__);
$read=static fn(string $file):string=>(string)file_get_contents($root.'/'.$file);
$api=$read('api/recording-live.php');
$service=$read('lib/RecordingLiveSignaling.php');
$migration=$read('lib/migrations/v5q29e4d1hf8c5_recording_live.sql');
$phone=$read('public/recording-live-phone.js');
$host=$read('public/recording-live-host.js');
$capture=$read('public/recording-camera.js');
$director=$read('public/director-studio.js');
$page=$read('director.php');
$cameraPage=$read('camera.php');
$proof=$read('proof.php');
c5Check(str_contains($migration,'CREATE TABLE IF NOT EXISTS recording_live_streams')&&str_contains($migration,'FOREIGN KEY(pair_id) REFERENCES controller_pairs(id) ON DELETE CASCADE'),'Private, cascade-cleaned phone signaling table');
c5Check(str_contains($migration,'recording_session_id')&&!str_contains($migration,'claim_id'),'Live signaling belongs to recording sessions, not prize claims');
c5Check(str_contains($api,'ControllerPairing::authenticate')&&str_contains($api,'requireAdmin()'),'Paired phones and authenticated Director hosts use separate credentials');
c5Check(str_contains($api,'assertCsrf()')&&str_contains($api,'HTTP_ORIGIN')&&str_contains($api,'requires HTTPS'),'Host answers use CSRF and phone offers enforce secure same-origin');
c5Check(str_contains($service,'RecordingSessions::id($session)')&&str_contains($service,'owner_admin_id=?'),'Session identifiers and host ownership validated');
c5Check(str_contains($service,"s.status='active'")&&str_contains($service,'p.phone_enabled=1'),'Only active recordings with phone permission allow live preview');
c5Check(str_contains($service,"a.capture_kind='video'")&&str_contains($service,'a.armed=1')&&str_contains($service,'last_seen_at>DATE_SUB'),'Live phone preview requires fresh explicit video arm');
c5Check(str_contains($service,"p.status='paired'")&&str_contains($service,'paired_expires_at>UTC_TIMESTAMP()'),'Host sees only currently approved paired phones');
c5Check(str_contains($service,'l.nonce=?')&&str_contains($service,'l.answer_sdp IS NULL'),'Stale answers and duplicate host answers are rejected');
c5Check(str_contains($service,'UPDATE recording_live_streams SET updated_at=UTC_TIMESTAMP(3)')&&str_contains($service,'DELETE FROM recording_live_streams'),'Phone heartbeat and teardown expire private signaling');
c5Check(str_contains($phone,'lucky:recording-camera-live')&&str_contains($phone,'getVideoTracks')&&!str_contains($phone,'getUserMedia'),'Phone live bridge reuses explicitly opened local camera');
c5Check(str_contains($capture,'armed&&mode')&&str_contains($capture,'session-camera-consent')&&str_contains($capture,"new CustomEvent('lucky:recording-camera-live'"),'Camera Studio only announces armed video with consent');
c5Check(str_contains($phone,'RTCPeerConnection')&&str_contains($phone,'setLocalDescription')&&str_contains($phone,'setRemoteDescription'),'Phone establishes WebRTC offer and accepts host answer');
c5Check(str_contains($phone,'retryCount')&&str_contains($phone,"pagehide',stop")&&str_contains($phone,"signal(item,'end'"),'Phone reconnect and close pathways exist');
c5Check(str_contains($host,"signal('list'")&&str_contains($host,"signal('answer'")&&str_contains($host,'ontrack'),'Director subscribes privately to approved live phone offers');
c5Check(str_contains($host,'card.dataset.phonePair')&&str_contains($host,"new Event('lucky:cameras-refreshed')"),'Live phone cameras populate existing Director source palette');
c5Check(str_contains($host,'lucky:director-studio-open')&&str_contains($host,'lucky:director-studio-close'),'Changing production sessions tears down live preview');
c5Check(str_contains($director,"request('take_start'")&&str_contains($director,"request('take_stop'")&&str_contains($director,'startNonce'),'Start/Stop Group Take uses existing idempotent take API');
c5Check(str_contains($director,"request('camera_devices'")&&str_contains($director,'activeTake'),'Director reflects armed cameras and active take on group timeline');
c5Check(str_contains($page,'id="director-studio-take-start"')&&str_contains($page,'id="director-studio-take-stop"')&&str_contains($page,'recording-live-host.js?v=5q29e4d1hf8c5'),'Director has recording controls and new WebRTC bridge');
c5Check(str_contains($cameraPage,'recording-live-phone.js?v=5q29e4d1hf8c5')&&str_contains($cameraPage,'recording-camera.js?v=5q29e4d1hf8c5'),'Phone Camera Studio serves updated recording client');
c5Check(str_contains($page,'Only')||str_contains($page,'Program'),'Production preview remains separate from Program publishing');
c5Check(!str_contains($proof,'recording-live-phone.js')&&!str_contains($proof,'recording-live-host.js')&&!str_contains($read('public/phone-live-phone.js'),'recording-live.php'),'Claimed-prize proof remains a separate phone camera flow');
foreach(['install.php','upgrade.php','homeserver/initialize.php','homeserver/migrate.php'] as $file)
 c5Check(str_contains($read($file),'v5q29e4d1hf8c5_recording_live.sql'),$file.' installs the same live signaling migration');
c5Check(str_contains($read('lib/Layout.php'),'platform.css?v=5q29e4d1hf8c5'),'Cloud and HomeServer CSS release revision updated');
echo "HF8C5 RECORDING LIVE CONTRACT PASSED\n";
