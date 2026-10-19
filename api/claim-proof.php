<?php
declare(strict_types=1);
require __DIR__.'/../lib/Bootstrap.php';
require_once __DIR__.'/../lib/ClaimProof.php';
require_once __DIR__.'/../lib/SchemaGuard.php';
headerSecurity();
function proofIds(string $session,string $claim):void {
 if(!ClaimMedia::validId($session)||!ClaimMedia::validId($claim))throw new DomainException('Invalid game or prize claim.',400);
}
function proofClaim(PDO $db,string $session,string $claim,bool $lock=false):void {
 proofIds($session,$claim);sessionRow($db,$session,$lock);
 $q=$db->prepare('SELECT id FROM game_claims WHERE id=? AND session_id=?'.($lock?' FOR UPDATE':''));$q->execute([$claim,$session]);
 if(!$q->fetch())throw new DomainException('Claim not found in this game.',404);
}
function proofClips(PDO $db,string $session,string $claim):array {
 $q=$db->prepare('SELECT m.*,c.prize_name,c.tier FROM claim_media m INNER JOIN game_claims c ON c.id=m.claim_id WHERE m.session_id=? AND m.claim_id=? ORDER BY m.sort_order,m.created_at,m.id');
 $q->execute([$session,$claim]);return $q->fetchAll();
}
function proofStatus(PDO $db,string $session,string $claim):array {
 $q=$db->prepare('SELECT needs_revision,request_note,updated_at FROM claim_proof_state WHERE session_id=? AND claim_id=?');$q->execute([$session,$claim]);$row=$q->fetch()?:[];
 $q=$db->prepare('SELECT MAX(revision_no) FROM claim_proof_revisions WHERE session_id=? AND claim_id=?');$q->execute([$session,$claim]);
 return ['needsRevision'=>(bool)($row['needs_revision']??false),'requestNote'=>$row['request_note']??'','latestRevision'=>(int)$q->fetchColumn(),'updatedAt'=>$row['updated_at']??null];
}
function proofInsertRevision(PDO $db,string $session,string $claim,string $note):array {
 $q=$db->prepare('SELECT MAX(revision_no) FROM claim_proof_revisions WHERE claim_id=?');$q->execute([$claim]);$n=1+(int)$q->fetchColumn();
 if($n>1000)throw new DomainException('Maximum proof revisions reached.',422);
 $clips=proofClips($db,$session,$claim);$snapshot=ClaimProof::snapshot($clips);$id=bin2hex(random_bytes(16));
 $q=$db->prepare('INSERT INTO claim_proof_revisions (id,claim_id,session_id,revision_no,note,clip_snapshot_json) VALUES (?,?,?,?,?,?)');$q->execute([$id,$claim,$session,$n,$note,$snapshot]);
 $q=$db->prepare('INSERT INTO claim_proof_revision_clips (revision_id,clip_id) VALUES (?,?)');foreach($clips as $clip)$q->execute([$id,$clip['id']]);
 return ['id'=>$id,'number'=>$n,'note'=>$note,'clips'=>json_decode($snapshot,true,512,JSON_THROW_ON_ERROR)];
}
try {
 if(!installed())throw new DomainException('Platform not installed.',503);
 requireAdmin();$db=db();SchemaGuard::require($db,SchemaGuard::PROOF,'Prize proof and revision history');$method=$_SERVER['REQUEST_METHOD']??'GET';
 if($method==='GET'){
  $session=(string)($_GET['session']??'');$claim=(string)($_GET['claim']??'');
  if(($_GET['action']??'')==='summary'){
   if(!ClaimMedia::validId($session))throw new DomainException('Invalid game session.',400);sessionRow($db,$session);
   $q=$db->prepare('SELECT s.claim_id,s.needs_revision,s.request_note,COALESCE(r.version,0) AS latest_revision FROM claim_proof_state s LEFT JOIN (SELECT claim_id,MAX(revision_no) AS version FROM claim_proof_revisions WHERE session_id=? GROUP BY claim_id) r ON r.claim_id=s.claim_id WHERE s.session_id=?');$q->execute([$session,$session]);$states=[];
   foreach($q->fetchAll() as $row)$states[$row['claim_id']]=['needsRevision'=>(bool)$row['needs_revision'],'requestNote'=>$row['request_note'],'latestRevision'=>(int)$row['latest_revision']];
   success(['states'=>$states]);
  }
  proofClaim($db,$session,$claim);$state=proofStatus($db,$session,$claim);
  $q=$db->prepare('SELECT id,revision_no,note,clip_snapshot_json,created_at FROM claim_proof_revisions WHERE session_id=? AND claim_id=? ORDER BY revision_no DESC');$q->execute([$session,$claim]);
  $history=[];foreach($q->fetchAll() as $row)$history[]=['id'=>$row['id'],'number'=>(int)$row['revision_no'],'note'=>$row['note'],'at'=>$row['created_at'],'clips'=>json_decode($row['clip_snapshot_json'],true,512,JSON_THROW_ON_ERROR)];
  success(['status'=>$state,'revisions'=>$history,'clipCount'=>count(proofClips($db,$session,$claim))]);
 }
 if($method!=='POST')throw new DomainException('Method not allowed.',405);
 assertCsrf();$data=jsonInput();$session=(string)($data['session']??'');$claim=(string)($data['claim']??'');$action=(string)($_GET['action']??'');
 if(!in_array($action,['baseline','request','save'],true))throw new DomainException('Unknown proof action.',404);
 $note=ClaimProof::note($data['note']??'');
 $db->beginTransaction();try {
  proofClaim($db,$session,$claim,true);
  $db->prepare('INSERT IGNORE INTO claim_proof_state (claim_id,session_id) VALUES (?,?)')->execute([$claim,$session]);
  $revision=null;
  if($action==='baseline'){
   $q=$db->prepare('SELECT COUNT(*) FROM claim_proof_revisions WHERE claim_id=?');$q->execute([$claim]);
   if(!(int)$q->fetchColumn())$revision=proofInsertRevision($db,$session,$claim,'Original proof');
  }elseif($action==='request'){
   $db->prepare('UPDATE claim_proof_state SET needs_revision=1,request_note=? WHERE claim_id=?')->execute([$note,$claim]);
   audit($db,'claim.proof-revision-request',$session,['claim'=>$claim]);
  }else {
   $q=$db->prepare('SELECT COUNT(*) FROM claim_proof_revisions WHERE claim_id=?');$q->execute([$claim]);
   if(!(int)$q->fetchColumn())proofInsertRevision($db,$session,$claim,'Original proof');
   $revision=proofInsertRevision($db,$session,$claim,$note?:'Updated proof');
   $db->prepare("UPDATE claim_proof_state SET needs_revision=0,request_note='' WHERE claim_id=?")->execute([$claim]);
   audit($db,'claim.proof-revision-saved',$session,['claim'=>$claim,'version'=>$revision['number']]);
  }
  $db->commit();success(['status'=>proofStatus($db,$session,$claim),'revision'=>$revision]);
 }catch(Throwable $e){$db->rollBack();throw $e;}
} catch(Throwable $e){error_log('Lucky proof revisions: '.get_class($e).' '.$e->getMessage());errorOut($e);}
