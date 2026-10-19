<?php
declare(strict_types=1);
/** Keep award and Accept/Decline as separate immutable audit facts. */
final class PrizeDecision {
 public static function available(PDO $db):bool {
  $q=$db->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='prize_decisions' LIMIT 1");
  return $q->fetchColumn()!==false;
 }
 public static function requireReady(PDO $db):void {
  if(!self::available($db))throw new DomainException('Prize acceptance needs the V5Q.15B database upgrade. Sign in as host and run upgrade.php.',409);
 }
 public static function create(PDO $db,string $session,string $claim):void {
  $q=$db->prepare("INSERT INTO prize_decisions(claim_id,session_id,status) VALUES (?,?,'pending')");
  $q->execute([$claim,$session]);
 }
 public static function latest(PDO $db,string $session):?array {
  $q=$db->prepare('SELECT d.claim_id,d.session_id,d.status,d.decided_at,c.prize_name,c.cents,c.tier FROM prize_decisions d JOIN game_claims c ON c.id=d.claim_id WHERE d.session_id=? ORDER BY c.created_at DESC,c.id DESC LIMIT 1');
  $q->execute([$session]);$r=$q->fetch();
  return $r?['claimId'=>$r['claim_id'],'session'=>$r['session_id'],'status'=>$r['status'],'name'=>$r['prize_name'],'cents'=>(int)$r['cents'],'tier'=>$r['tier'],'decidedAt'=>$r['decided_at']]:null;
 }
 public static function pending(PDO $db,string $session,bool $lock=false):?array {
  $q=$db->prepare("SELECT d.claim_id,d.session_id,c.prize_name,c.cents FROM prize_decisions d JOIN game_claims c ON c.id=d.claim_id WHERE d.session_id=? AND d.status='pending' ORDER BY c.created_at DESC,c.id DESC LIMIT 1".($lock?' FOR UPDATE':''));
  $q->execute([$session]);return $q->fetch()?:null;
 }
 public static function assertReady(PDO $db,string $session):void {
  self::requireReady($db);
  if(self::pending($db,$session))throw new DomainException('Accept or decline the previous prize before spinning again.',409);
 }
 public static function choose(PDO $db,string $session,string $claim,string $choice):array {
  if(!in_array($choice,['accepted','declined'],true))throw new DomainException('Invalid prize decision.',400);
  $q=$db->prepare("UPDATE prize_decisions SET status=?,decided_at=UTC_TIMESTAMP() WHERE claim_id=? AND session_id=? AND status='pending'");
  $q->execute([$choice,$claim,$session]);if($q->rowCount()!==1)throw new DomainException('This prize was already accepted or declined.',409);
  $out=self::latest($db,$session);if(!$out||$out['claimId']!==$claim)throw new RuntimeException('Prize decision did not persist.');
  if($choice==='declined'){
   $r=sessionRow($db,$session,true);$state=stateFromRow($r);
   if(in_array($state['status'],['active','paused'],true)){
    GameRegistry::module($state['game'])->action($state,'end');saveState($db,$state);
   }
  }
  audit($db,'prize.'.$choice,$session,['claimId'=>$claim]);
  return $out;
 }
}
