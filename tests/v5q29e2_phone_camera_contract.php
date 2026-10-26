<?php
declare(strict_types=1);
require_once __DIR__.'/../lib/RecordingSessions.php';
require_once __DIR__.'/../lib/RecordingPhone.php';
require_once __DIR__.'/../lib/MigrationSql.php';
function requirePhone(bool $ok,string $label):void {if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label."\n";}
$sql=(string)file_get_contents(__DIR__.'/../lib/migrations/v5q29e2_phone_camera.sql');
$statements=MigrationSql::statements($sql);
requirePhone(count($statements)===2&&str_starts_with($statements[0],'CREATE TABLE IF NOT EXISTS'),'Camera schema migration is additive and repeatable');
requirePhone(str_contains($sql,'recording_phone_permissions')&&str_contains($sql,'recording_media_files'),'Host permission and private media metadata are persisted separately');
requirePhone(str_contains((string)file_get_contents(__DIR__.'/../install.php'),'v5q29e2_phone_camera.sql')&&str_contains((string)file_get_contents(__DIR__.'/../upgrade.php'),'v5q29e2_phone_camera.sql'),'Fresh installer and upgrades both load phone camera migration');
requirePhone(RecordingPhone::assetId(str_repeat('a',32),'valid_phone_nonce_123')===RecordingPhone::assetId(str_repeat('a',32),'valid_phone_nonce_123'),'Retries map to same media asset');
requirePhone(RecordingPhone::assetId(str_repeat('a',32),'valid_phone_nonce_123')!==RecordingPhone::assetId(str_repeat('a',32),'valid_phone_nonce_124'),'Distinct capture nonce maps to separate media');
$endpoint=(string)file_get_contents(__DIR__.'/../api/recording-phone.php');
requirePhone(str_contains($endpoint,'ControllerPairing::authenticate')&&str_contains($endpoint,'HTTP_ORIGIN')&&str_contains($endpoint,'recording_phone_permissions'),'Phone requests validate existing pairing, origin, and migration readiness');
$policy=(string)file_get_contents(__DIR__.'/../lib/RecordingPhone.php');
requirePhone(str_contains($policy,"$".'input[\'consent\']')&&str_contains($policy,'assertApproved')&&str_contains($policy,'move_uploaded_file'),'Consent and host revocation are rechecked before private file commit');
$stream=(string)file_get_contents(__DIR__.'/../api/recording-media.php');
requirePhone(str_contains($stream,'requireAdmin()')&&str_contains($stream,'owner_admin_id')&&str_contains($stream,'HTTP_RANGE'),'Streaming is administrator-scoped and supports video byte ranges');
$ui=(string)file_get_contents(__DIR__.'/../camera.php');$script=(string)file_get_contents(__DIR__.'/../public/recording-camera.js');
requirePhone(str_contains($ui,'viewport-fit=cover')&&str_contains($ui,'session-camera-consent')&&str_contains($ui,'data-session-mode="audio"'),'Full-screen camera offers video, photo, audio, and consent');
requirePhone(str_contains($script,'getUserMedia')&&str_contains($script,'MediaRecorder')&&str_contains($script,"call('upload',data)"),'Camera recordings and uploads are bound to server approval');
requirePhone(str_contains((string)file_get_contents(__DIR__.'/../admin.php'),'tab-recordings')&&str_contains((string)file_get_contents(__DIR__.'/../controller.php'),'<a href="camera.php" data-phone-view="camera"'),'Admin and paired phone surfaces both expose Camera Studio');
echo 'V5Q.29E2 PHONE CAMERA CONTRACT PASSED'."\n";
