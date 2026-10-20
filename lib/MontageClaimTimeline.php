<?php
declare(strict_types=1);
/** Claim-aware, non-destructive timeline updates for approved videos. */
final class MontageClaimTimeline {
 public static function apply(array $edits,array $source,array $new,array $claimOrder,?string $replaceId=null):array {
  $id=(string)($new['id']??'');$claim=(string)($new['claim_id']??'');
  if(!preg_match('/^[a-f0-9]{32}$/D',$id)||!preg_match('/^[a-f0-9]{32}$/D',$claim)||($new['kind']??null)!=='video')
   throw new DomainException('Invalid recording for the timeline.',422);
  $byId=[];foreach($source as $c)$byId[$c['id']]=$c;
  $original=$replaceId!==null?($byId[$replaceId]??null):null;
  if($replaceId!==null&&(!$original||$original['claim_id']!==$claim||$original['kind']!=='video'))
   throw new DomainException('Re-recorded clip does not belong to this claimed prize.',409);
  $record=['id'=>$id,'instanceId'=>$id,'title'=>(string)$new['title'],
   'trimStartMs'=>0,'trimEndMs'=>0,'photoDurationMs'=>4000,'transition'=>'fade'];
  // Re-recording replaces only the matching original, never other prize segments.
  $replaced=false;
  foreach($edits as $i=>$edit)if($replaceId!==null&&($edit['id']??'')===$replaceId){
   $updated=$record;
   $updated['instanceId']=$edit['instanceId']??$id;
   $updated['transition']=$edit['transition']??'fade';
   $edits[$i]=$updated;$replaced=true;
  }
  if($replaced)return $edits;
  if(count($edits)>=80)throw new DomainException('Timeline is full (80 clips); remove a segment before saving this proof.',409);
  $order=array_flip($claimOrder);
  $rank=static fn(string $key):int=>isset($order[$key])?(int)$order[$key]:PHP_INT_MAX;
  $position=count($edits);$lastInClaim=-1;
  foreach($edits as $i=>$edit){
   $other=(string)($byId[$edit['id']??'']['claim_id']??'');
   if($other===$claim)$lastInClaim=$i;
   elseif($lastInClaim===-1&&$position===count($edits)&&$rank($other)>$rank($claim))$position=$i;
  }
  if($lastInClaim>=0)$position=$lastInClaim+1;
  array_splice($edits,$position,0,[$record]);return $edits;
 }
}
