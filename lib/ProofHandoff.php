<?php
declare(strict_types=1);
/** A confirmed prize opens its paired-phone proof screen before page navigation. */
final class ProofHandoff {
 public static function activate(PDO $db,string $session,string $claim,int $hostAdminId):void {
  if(!preg_match('/^[a-f0-9]{32}$/D',$session)||!preg_match('/^[a-f0-9]{32}$/D',$claim)||$hostAdminId<1)
   throw new DomainException('Invalid prize proof handoff.',422);
  SchemaGuard::require($db,['controller_proof_state'],'Phone proof editor');
  $columns=$db->query("SHOW COLUMNS FROM controller_proof_state LIKE 'consent_ack'");
  if(!$columns||!$columns->fetch())throw new DomainException('Run upgrade.php to enable the phone proof editor.',409);
  $phone=$db->query("SHOW COLUMNS FROM controller_proof_state LIKE 'phone_camera_enabled'")->fetch();
  $q=$db->prepare("INSERT INTO controller_proof_state
   (session_id,host_admin_id,claim_id,is_open,review_count,consent_ack,capture_phase".($phone?',phone_camera_enabled':'').",updated_at)
   VALUES (?,?,?,1,0,0,'opening'".($phone?',0':'').",UTC_TIMESTAMP())
   ON DUPLICATE KEY UPDATE host_admin_id=VALUES(host_admin_id),claim_id=VALUES(claim_id),
   is_open=1,review_count=0,consent_ack=0,capture_phase='opening'".($phone?',phone_camera_enabled=0':'').",updated_at=UTC_TIMESTAMP()");
  $q->execute([$session,$hostAdminId,$claim]);
 }
}
