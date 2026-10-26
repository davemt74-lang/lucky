<?php
declare(strict_types=1);
require_once __DIR__.'/../lib/PhoneLiveSignaling.php';
function liveAssert(bool $passed,string $label):void {if(!$passed)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label."\n";}
function liveReject(callable $fn):bool {try{$fn();return false;}catch(DomainException){return true;}}
$nonce=str_repeat('a',32);
$sdp="v=0\r\no=- 1 1 IN IP4 127.0.0.1\r\ns=-\r\nt=0 0\r\n";
liveAssert(PhoneLiveSignaling::nonce($nonce)===$nonce,'Cryptographic live connection nonce accepted');
liveAssert(liveReject(static fn()=>PhoneLiveSignaling::nonce('oops')),'Malformed live connection nonce rejected');
liveAssert(PhoneLiveSignaling::sdp($sdp,'offer')===$sdp,'Valid encrypted peer-session offer accepted');
liveAssert(PhoneLiveSignaling::sdp($sdp,'answer')===$sdp,'Valid encrypted peer-session answer accepted');
liveAssert(liveReject(static fn()=>PhoneLiveSignaling::sdp('not-an-sdp','offer')),'Malformed session description rejected');
liveAssert(liveReject(static fn()=>PhoneLiveSignaling::sdp($sdp."\0",'offer')),'Embedded NUL data rejected');
liveAssert(liveReject(static fn()=>PhoneLiveSignaling::sdp('v=0\n'.str_repeat('a',PhoneLiveSignaling::MAX_SDP_BYTES),'offer')),'Oversized description rejected');
$root=dirname(__DIR__);
$desktop=(string)file_get_contents($root.'/proof.php');
$phone=(string)file_get_contents($root.'/controller.php');
$capture=(string)file_get_contents($root.'/public/claim-capture.js');
$phoneCapture=(string)file_get_contents($root.'/public/phone-capture.js');
$hostLive=(string)file_get_contents($root.'/public/phone-live-host.js');
liveAssert(str_contains($desktop,'phone-live-host.js')&&str_contains($desktop,'id="camera-grid"'),
 'Desktop loads live receiver into the existing proof camera grid');
liveAssert(str_contains($phone,'phone-live-phone.js')&&str_contains($phone,'phone-capture-live-start')&&
 str_contains($phone,'phone-capture-live-record')&&str_contains($phone,'phone-capture-live-stop'),
 'Phone supplies separate live, record and stop controls');
liveAssert(str_contains($hostLive,"grid.append(card)")&&str_contains($hostLive,'className=\'camera-source camera-source-phone\''),
 'Phone WebRTC tile reuses desktop camera source stage');
liveAssert(str_contains($capture,'lucky:proof-open')&&str_contains($capture,'lucky:cameras-refreshed')&&
 str_contains($capture,'lucky:proof-close')&&str_contains($phoneCapture,'lucky:phone-capture-stream'),
 'Proof and phone lifecycle events are connected');
liveAssert(str_contains($phoneCapture,'CHUNK_BYTES=512*1024')&&str_contains($phoneCapture,"uploadAction:'chunk'"),
 'High-quality original recording preserves resumable chunked upload');
liveAssert(str_contains((string)file_get_contents($root.'/upgrade.php'),'v5q29e4d1hf7_phone_live.sql')&&
 str_contains((string)file_get_contents($root.'/lib/schema.sql'),'controller_live_streams'),
 'Fresh installs and existing installations both provision live signaling table');
echo "V5Q.29E4D1 HF7 PHONE LIVE CONTRACT PASSED\n";
