<?php
declare(strict_types=1);
require_once __DIR__.'/ControllerPairing.php';

/** A paired phone may contribute media only to an explicitly enabled, live host proof. */
final class PhoneCapturePolicy {
 public static function verify(array $pair,array $proof,string $session,string $claim,int $now):void {
  if(ControllerPairing::status($pair)!=='paired')throw new DomainException('This phone is no longer approved.',403);
  if((string)($pair['host_admin_id']??'')!==(string)($proof['host_admin_id']??''))throw new DomainException('This proof belongs to another host.',403);
  if((string)($proof['session_id']??'')!==$session||(string)($proof['claim_id']??'')!==$claim)
   throw new DomainException('The host changed the prize proof. Reopen the camera.',409);
  if((int)($proof['is_open']??0)!==1||(int)($proof['phone_camera_enabled']??0)!==1)
   throw new DomainException('The host must enable Use phone camera in proof permissions.',403);
  if((int)($proof['consent_ack']??0)!==1)throw new DomainException('Confirm recording consent on the phone before uploading.',403);
  $updated=strtotime((string)($proof['updated_at']??'').' UTC');
  if(!$updated||$updated<$now-12||$updated>$now+5)throw new DomainException('The host proof is no longer live. Reopen it on the desktop.',409);
 }
 public static function mediaId(string $pairId,string $nonce):string {
  ControllerPairing::checkId($pairId);ControllerPairing::nonce($nonce);
  return substr(hash('sha256','lucky-phone-media-v1:'.$pairId.':'.$nonce),0,32);
 }
}
