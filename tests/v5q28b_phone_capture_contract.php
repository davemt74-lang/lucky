<?php
declare(strict_types=1);
require_once __DIR__.'/../lib/PhoneCapturePolicy.php';
function expectPhone(bool $ok,string $label):void {if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label."\n";}
function denyPhone(callable $call,string $label,int $code):void {
 try{$call();}catch(DomainException $e){expectPhone($e->getCode()===$code,$label);return;}
 throw new RuntimeException('Unexpectedly accepted '.$label);
}
$now=time();$host='7';$session=str_repeat('a',32);$claim=str_repeat('b',32);
$pair=['status'=>'paired','host_admin_id'=>$host,'paired_expires_at'=>gmdate('Y-m-d H:i:s',$now+300)];
$proof=['host_admin_id'=>$host,'session_id'=>$session,'claim_id'=>$claim,'is_open'=>1,'phone_camera_enabled'=>1,'consent_ack'=>1,'updated_at'=>gmdate('Y-m-d H:i:s',$now)];
PhoneCapturePolicy::verify($pair,$proof,$session,$claim,$now);expectPhone(true,'Approved, consented live host proof permits one claim');
denyPhone(fn()=>PhoneCapturePolicy::verify($pair,$proof,$session,str_repeat('c',32),$now),'Wrong claim cannot receive media',409);
denyPhone(fn()=>PhoneCapturePolicy::verify($pair,$proof,str_repeat('c',32),$claim,$now),'Wrong session cannot receive media',409);
denyPhone(fn()=>PhoneCapturePolicy::verify([...$pair,'host_admin_id'=>'8'],$proof,$session,$claim,$now),'Other host pairing cannot upload',403);
denyPhone(fn()=>PhoneCapturePolicy::verify([...$pair,'status'=>'revoked'],$proof,$session,$claim,$now),'Revoked pairing fails',403);
denyPhone(fn()=>PhoneCapturePolicy::verify([...$pair,'paired_expires_at'=>gmdate('Y-m-d H:i:s',$now-1)],$proof,$session,$claim,$now),'Expired pairing fails',403);
denyPhone(fn()=>PhoneCapturePolicy::verify($pair,[...$proof,'phone_camera_enabled'=>0],$session,$claim,$now),'Desktop permission revocation fails',403);
denyPhone(fn()=>PhoneCapturePolicy::verify($pair,[...$proof,'is_open'=>0],$session,$claim,$now),'Closed host proof fails',403);
denyPhone(fn()=>PhoneCapturePolicy::verify($pair,[...$proof,'consent_ack'=>0],$session,$claim,$now),'Recording consent missing fails',403);
denyPhone(fn()=>PhoneCapturePolicy::verify($pair,[...$proof,'updated_at'=>gmdate('Y-m-d H:i:s',$now-20)],$session,$claim,$now),'Stale host heartbeat fails',409);
$id=PhoneCapturePolicy::mediaId(str_repeat('d',32),'phone-media-upload-12345');
expectPhone(strlen($id)===32&&ctype_xdigit($id)&&$id===PhoneCapturePolicy::mediaId(str_repeat('d',32),'phone-media-upload-12345'),'Retry nonce generates identical media ID');
expectPhone($id!==PhoneCapturePolicy::mediaId(str_repeat('d',32),'phone-media-upload-54321'),'New capture uses distinct media ID');
denyPhone(fn()=>PhoneCapturePolicy::mediaId(str_repeat('d',32),'bad'),'Invalid nonce rejected',400);
echo "V5Q.28B PAIRED PHONE CAPTURE POLICY PASSED\n";
