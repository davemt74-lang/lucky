<?php
declare(strict_types=1);
require_once __DIR__.'/ClaimMedia.php';
require_once __DIR__.'/MontageClaimTimeline.php';

/** Shared trusted upload pipeline for Admin and capability-scoped paired phones. */
final class ClaimMediaUpload {
 public static function store(PDO $db,array $form,?array $file,?callable $authorize=null,?string $fixedId=null,?string $trustedStagedPath=null):array {
  $sessionId=(string)($form['session']??'');$claimId=(string)($form['claim']??'');
  if(!ClaimMedia::validId($sessionId)||!ClaimMedia::validId($claimId))throw new DomainException('Invalid claim association.',400);
  if(!is_array($file)||($file['error']??-1)!==UPLOAD_ERR_OK||!isset($file['tmp_name']))
   throw new DomainException('Choose a valid file, or check your server upload size limit.',422);
  // Only the private, server-assembled phone chunk path may bypass PHP's
  // is_uploaded_file() check. Untrusted form paths never reach this branch.
  if($trustedStagedPath!==null){
   $dir=realpath(LUCKY_ROOT.'/storage/phone-media-pending');$real=realpath($trustedStagedPath);
   if(!$dir||!$real||dirname($real)!==$dir||!preg_match('/^[a-f0-9]{64}\.part$/D',basename($real))||
      is_link($trustedStagedPath)||$real!==(string)$file['tmp_name']||
      !is_file($real)||filesize($real)!==(int)($file['size']??0))
    throw new DomainException('Invalid private phone upload staging file.',403);
  }elseif(!is_uploaded_file((string)$file['tmp_name']))
   throw new DomainException('Choose a valid file, or check your server upload size limit.',422);
  $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
  $dimensions=str_starts_with($mime,'image/')?@getimagesize($file['tmp_name']):null;
  $kind=ClaimMedia::captureKind($mime,(int)$file['size'],$dimensions?:null,(string)($form['kind']??''),$file['tmp_name']);
  $title=trim((string)($form['title']??''));if($title===''||mbSafeLen($title)>120)$title=ucfirst($kind).' reaction';
  $sourceLabel=trim((string)($form['sourceLabel']??''));
  if(mbSafeLen($sourceLabel)>120)throw new DomainException('Camera name must be 120 characters or less.',422);
  $sourceLabel=preg_replace('/[\x00-\x1f\x7f]/u','',$sourceLabel)??'';
  $angleLabel=trim((string)($form['angleLabel']??''));$angleIndex=(int)($form['angleIndex']??0);
  if(mbSafeLen($angleLabel)>80||preg_match('/[\x00-\x1f\x7f]/',$angleLabel)||$angleIndex<0||$angleIndex>4)
   throw new DomainException('Invalid proof angle.',422);
  $duration=max(0,min(3600000,(int)($form['durationMs']??0)));
  $replaceClipId=trim((string)($form['replaceClipId']??''));
  if($replaceClipId!==''&&!ClaimMedia::validId($replaceClipId))throw new DomainException('Invalid re-recording reference.',422);
  $id=$fixedId??bin2hex(random_bytes(16));if(!ClaimMedia::validId($id))throw new DomainException('Invalid upload receipt.',400);
  $path=LUCKY_ROOT.'/storage/claim-media/'.$id.'.bin';$moved=false;
  $db->beginTransaction();try {
   sessionRow($db,$sessionId,true);
   // The authorization is re-evaluated inside this transaction after an upload finishes.
   // A host revocation or a change of claim therefore blocks new media commits.
   if($authorize)$authorize($db,$sessionId,$claimId);
   if($fixedId!==null){
    $q=$db->prepare('SELECT * FROM claim_media WHERE id=? FOR UPDATE');$q->execute([$id]);$prior=$q->fetch(PDO::FETCH_ASSOC);
    if($prior){
     if($prior['session_id']!==$sessionId||$prior['claim_id']!==$claimId||$prior['mime']!==$mime||$prior['kind']!==$kind||
        (int)$prior['bytes']!==(int)$file['size']||!is_file($path)||!hash_equals((string)hash_file('sha256',$path),(string)hash_file('sha256',$file['tmp_name'])))
      throw new DomainException('This upload identifier was used for different media.',409);
     $db->commit();return ClaimMedia::asPublic($prior);
    }
   }
   $q=$db->prepare('SELECT id FROM game_claims WHERE session_id=? AND id=?');$q->execute([$sessionId,$claimId]);
   if(!$q->fetch())throw new DomainException('Prize claim was not found in this game.',404);
   if($replaceClipId!==''){
    if($kind!=='video')throw new DomainException('Only video recordings can replace a video segment.',422);
    $q=$db->prepare("SELECT id FROM claim_media WHERE id=? AND session_id=? AND claim_id=? AND kind='video'");
    $q->execute([$replaceClipId,$sessionId,$claimId]);if(!$q->fetch())throw new DomainException('Original recording does not belong to this prize.',409);
   }
   $q=$db->prepare('SELECT COUNT(*) AS total,COALESCE(SUM(bytes),0) AS used,COALESCE(MAX(sort_order),0) AS last_order FROM claim_media WHERE session_id=?');
   $q->execute([$sessionId]);$usage=$q->fetch();
   if((int)$usage['total']>=ClaimMedia::MAX_CLIPS_PER_SESSION||(int)$usage['used']+(int)$file['size']>ClaimMedia::MAX_SESSION_BYTES)
    throw new DomainException('Game media limit reached. Delete older clips to continue.',413);
   if(!is_dir(dirname($path))&&!mkdir(dirname($path),0700,true))throw new RuntimeException('Could not create private media directory.');
   $stored=$trustedStagedPath!==null?rename($file['tmp_name'],$path):move_uploaded_file($file['tmp_name'],$path);
   if(!$stored)throw new RuntimeException('Could not store your recording.');$moved=true;@chmod($path,0600);
   $q=$db->prepare('INSERT INTO claim_media (id,session_id,claim_id,kind,mime,title,source_label,angle_label,angle_index,bytes,duration_ms,sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
   $q->execute([$id,$sessionId,$claimId,$kind,$mime,$title,$sourceLabel,$angleLabel,$angleIndex,(int)$file['size'],$duration,(int)$usage['last_order']+1]);
   if($kind==='video'){
    $q=$db->prepare('SELECT * FROM claim_media WHERE id=?');$q->execute([$id]);$newRow=$q->fetch();
    $q=$db->prepare('SELECT * FROM claim_media WHERE session_id=? ORDER BY sort_order,created_at,id');$q->execute([$sessionId]);$allMedia=$q->fetchAll();
    $q=$db->prepare('SELECT edit_json FROM montage_projects WHERE session_id=? FOR UPDATE');$q->execute([$sessionId]);$previous=$q->fetchColumn();
    if($previous!==false)$edits=json_decode((string)$previous,true,512,JSON_THROW_ON_ERROR);
    else{
     $edits=[];foreach($allMedia as $existing)if($existing['id']!==$id)
      $edits[]=['id'=>$existing['id'],'instanceId'=>$existing['id'],'title'=>$existing['title'],'trimStartMs'=>(int)$existing['trim_start_ms'],'trimEndMs'=>(int)$existing['trim_end_ms'],'photoDurationMs'=>(int)$existing['photo_duration_ms'],'transition'=>'fade'];
    }
    if(!is_array($edits))throw new RuntimeException('Saved timeline needs repair.');
    $state=stateFromRow(sessionRow($db,$sessionId));$claimOrder=array_column($state['claims']??[],'id');
    $edits=MontageClaimTimeline::apply($edits,$allMedia,$newRow,$claimOrder,$replaceClipId!==''?$replaceClipId:null);
    $q=$db->prepare('INSERT INTO montage_projects(session_id,edit_json) VALUES (?,?) ON DUPLICATE KEY UPDATE edit_json=VALUES(edit_json)');
    $q->execute([$sessionId,json_encode($edits,JSON_THROW_ON_ERROR)]);
   }
   audit($db,'claim.media-upload',$sessionId,['claim'=>$claimId,'clip'=>$id,'kind'=>$kind,'source'=>$authorize?'paired-phone':'admin-browser','replace'=>$replaceClipId?:null]);
   $db->commit();
  }catch(Throwable $e){if($db->inTransaction())$db->rollBack();if($moved)@unlink($path);throw $e;}
  $q=$db->prepare('SELECT m.*,c.prize_name,c.tier FROM claim_media m INNER JOIN game_claims c ON c.id=m.claim_id WHERE m.id=?');
  $q->execute([$id]);return ClaimMedia::asPublic($q->fetch(PDO::FETCH_ASSOC));
 }
}
