<?php
declare(strict_types=1);
require_once __DIR__.'/PrizeDecision.php';
require_once __DIR__.'/SchemaGuard.php';
require_once __DIR__.'/ControllerPairing.php';
/** Accepted prize history for a host-approved phone. Video files and URLs stay private. */
final class PhoneClaimHistory {
 public static function session(PDO $db,int $hostAdminId):?array {
  $q=$db->prepare("SELECT s.* FROM game_sessions s WHERE s.game_id='spin-wheel' AND EXISTS (SELECT 1 FROM audit_log a WHERE a.session_id=s.id AND a.admin_id=? AND a.event='session.start') ORDER BY CASE WHEN s.status IN ('active','paused') THEN 0 ELSE 1 END,s.created_at DESC,s.id DESC LIMIT 1");
  $q->execute([$hostAdminId]);return $q->fetch(PDO::FETCH_ASSOC)?:null;
 }
 public static function accepted(PDO $db,?array $session):array {
  if(!$session||!PrizeDecision::available($db))return [];
  $q=$db->prepare("SELECT c.id,c.prize_name,c.tier,c.cents FROM game_claims c JOIN prize_decisions d ON d.claim_id=c.id AND d.session_id=c.session_id WHERE c.session_id=? AND d.status='accepted'");
  $q->execute([$session['id']]);$accepted=[];
  foreach($q->fetchAll(PDO::FETCH_ASSOC) as $c)$accepted[$c['id']]=$c;
  $videos=[];
  if($accepted&&SchemaGuard::missing($db,['claim_media'])===[]){
   $q=$db->prepare("SELECT DISTINCT claim_id FROM claim_media WHERE session_id=? AND kind='video'");$q->execute([$session['id']]);
   foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id)$videos[$id]=true;
  }
  $claims=[];$state=stateFromRow($session);
  // The session's claim array, not second-resolution DB timestamps, determines award order.
  foreach(($state['claims']??[]) as $item){
   $id=(string)($item['id']??'');if(!isset($accepted[$id]))continue;
   $c=$accepted[$id];$claims[]=['id'=>$id,'name'=>$c['prize_name'],'tier'=>$c['tier'],'cents'=>(int)$c['cents'],'hasVideo'=>isset($videos[$id])];
  }
  return $claims;
 }
 public static function assertAccepted(PDO $db,int $hostAdminId,string $claimId):string {
  ControllerPairing::checkId($claimId);
  $session=self::session($db,$hostAdminId);
  if(!$session)throw new DomainException('No claimed prizes are available for this host.',409);
  $q=$db->prepare("SELECT 1 FROM game_claims c JOIN prize_decisions d ON d.claim_id=c.id AND d.session_id=c.session_id WHERE c.id=? AND c.session_id=? AND d.status='accepted' LIMIT 1");
  $q->execute([$claimId,$session['id']]);
  if(!$q->fetchColumn())throw new DomainException('This prize was not accepted in the selected game.',403);
  return (string)$session['id'];
 }
}
