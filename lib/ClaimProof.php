<?php
declare(strict_types=1);
require_once __DIR__.'/ClaimMedia.php';
/** Only claim-linked, private media can appear in a revision. The original media is immutable. */
final class ClaimProof {
 public static function note(mixed $value):string {
  if(!is_string($value))throw new DomainException('Enter a plain-text revision note.',422);
  $note=trim($value);
  if(mbSafeLen($note)>250||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$note))throw new DomainException('Revision note must be 250 characters or less.',422);
  return $note;
 }
 public static function snapshot(array $clips):string {
  if(count($clips)>ClaimMedia::MAX_CLIPS_PER_SESSION)throw new DomainException('Too many proof clips.',422);
  $data=[];
  foreach($clips as $row){$c=ClaimMedia::asPublic($row);$data[]=[
   'id'=>$c['id'],'kind'=>$c['kind'],'title'=>$c['title'],'sourceLabel'=>$c['sourceLabel'],
   'angleLabel'=>$c['angleLabel'],'angleIndex'=>$c['angleIndex'],'durationMs'=>$c['durationMs'],
   'trimStartMs'=>$c['trimStartMs'],'trimEndMs'=>$c['trimEndMs'],'photoDurationMs'=>$c['photoDurationMs'],
   'order'=>$c['order'],'src'=>$c['src'],'at'=>$c['at']];}
  return json_encode($data,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
 }
}
