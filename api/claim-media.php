<?php
declare(strict_types=1);
require __DIR__.'/../lib/Bootstrap.php';
require_once __DIR__.'/../lib/ClaimMedia.php';
require_once __DIR__.'/../lib/MontageClaimTimeline.php';
headerSecurity();
$method=$_SERVER['REQUEST_METHOD']??'GET';$action=(string)($_GET['action']??'');
function mediaSession(PDO $db,string $id):void { if(!ClaimMedia::validId($id))throw new DomainException('Invalid game session.',400);sessionRow($db,$id); }
function mediaRow(PDO $db,string $id):array{
 if(!ClaimMedia::validId($id))throw new DomainException('Invalid clip.',400);
 $q=$db->prepare('SELECT m.*,c.prize_name,c.tier FROM claim_media m INNER JOIN game_claims c ON c.id=m.claim_id WHERE m.id=?');$q->execute([$id]);
 $r=$q->fetch();if(!$r)throw new DomainException('Clip not found.',404);return $r;
}
function mediaStream(array $r,bool $head=false):never{
 $file=LUCKY_ROOT.'/storage/claim-media/'.$r['id'].'.bin';if(!is_file($file))throw new DomainException('Clip file missing.',404);
 $bytes=filesize($file);if($bytes<1)throw new DomainException('Clip file unavailable.',404);
 $start=0;$end=$bytes-1;
 header('Content-Type: '.$r['mime']);header('Content-Disposition: inline; filename="clip"');header('Accept-Ranges: bytes');
 header('Cache-Control: private, no-store');
 if(isset($_SERVER['HTTP_RANGE'])){
  if(!preg_match('/^bytes=(\d*)-(\d*)$/D',(string)$_SERVER['HTTP_RANGE'],$m)||($m[1]===''&&$m[2]==='')){http_response_code(416);header('Content-Range: bytes */'.$bytes);exit;}
  if($m[1]===''){$suffix=(int)$m[2];if($suffix<=0){http_response_code(416);exit;}$start=max(0,$bytes-$suffix);}
  else{$start=(int)$m[1];if($m[2]!=='')$end=min($end,(int)$m[2]);}
  if($start>$end||$start>=$bytes){http_response_code(416);header('Content-Range: bytes */'.$bytes);exit;}
  header("Content-Range: bytes $start-$end/$bytes");http_response_code(206);
 }
 header('Content-Length: '.($end-$start+1));if($head)exit;
 $f=fopen($file,'rb');fseek($f,$start);$left=$end-$start+1;
 while($left>0&&!feof($f)){$chunk=fread($f,min(65536,$left));if($chunk===false||$chunk==='')break;echo $chunk;$left-=strlen($chunk);}
 fclose($f);exit;
}
try {
 if(!installed())throw new DomainException('Platform not installed.',503);
 requireAdmin();$db=db();
 if(($method==='GET'||$method==='HEAD')&&$action==='stream')mediaStream(mediaRow($db,(string)($_GET['id']??'')),$method==='HEAD');
 if($method==='GET'&&$action==='list'){
   $id=(string)($_GET['session']??'');mediaSession($db,$id);
   $q=$db->prepare('SELECT m.*,c.prize_name,c.tier FROM claim_media m INNER JOIN game_claims c ON c.id=m.claim_id WHERE m.session_id=? ORDER BY m.sort_order,m.created_at,m.id');$q->execute([$id]);
   success(['clips'=>array_map([ClaimMedia::class,'asPublic'],$q->fetchAll())]);
 }
 if($method!=='POST')throw new DomainException('Unsupported method.',405);
 assertCsrf();
 if($action==='upload'){
   $sessionId=(string)($_POST['session']??'');$claimId=(string)($_POST['claim']??'');
   if(!ClaimMedia::validId($sessionId)||!ClaimMedia::validId($claimId))throw new DomainException('Invalid claim association.',400);
   $file=$_FILES['media']??null;if(!is_array($file)||($file['error']??-1)!==UPLOAD_ERR_OK||!isset($file['tmp_name'])||!is_uploaded_file($file['tmp_name']))throw new DomainException('Choose a valid file, or check your server upload size limit.',422);
   $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
   $dim=str_starts_with($mime,'image/')?@getimagesize($file['tmp_name']):null;
   $kind=ClaimMedia::validateUpload($mime,(int)$file['size'],$dim?:null);
   $claimedKind=(string)($_POST['kind']??'');if($claimedKind!==''&&$claimedKind!==$kind)throw new DomainException('Recorded media type does not match the file.',422);
   $title=trim((string)($_POST['title']??''));if($title===''||mbSafeLen($title)>120)$title=ucfirst($kind).' reaction';
   $sourceLabel=trim((string)($_POST['sourceLabel']??''));
   if(mbSafeLen($sourceLabel)>120)throw new DomainException('Camera name must be 120 characters or less.',422);
   $sourceLabel=preg_replace('/[\x00-\x1f\x7f]/u','',$sourceLabel)??'';
   $angleLabel=trim((string)($_POST['angleLabel']??''));
   $angleIndex=(int)($_POST['angleIndex']??0);
   if(mbSafeLen($angleLabel)>80||preg_match('/[\x00-\x1f\x7f]/',$angleLabel)||$angleIndex<0||$angleIndex>4)
     throw new DomainException('Invalid proof angle.',422);
   $duration=max(0,min(3600000,(int)($_POST['durationMs']??0)));
   $replaceClipId=trim((string)($_POST['replaceClipId']??''));if($replaceClipId!==''&&!ClaimMedia::validId($replaceClipId))throw new DomainException('Invalid re-recording reference.',422);
   $id=bin2hex(random_bytes(16));$path=LUCKY_ROOT.'/storage/claim-media/'.$id.'.bin';
   $db->beginTransaction();try {
     sessionRow($db,$sessionId,true);
     $q=$db->prepare('SELECT id FROM game_claims WHERE session_id=? AND id=?');$q->execute([$sessionId,$claimId]);
     if(!$q->fetch())throw new DomainException('Prize claim was not found in this game.',404);
     if($replaceClipId!==''){
      if($kind!=='video')throw new DomainException('Only video recordings can replace a video segment.',422);
      $q=$db->prepare("SELECT id FROM claim_media WHERE id=? AND session_id=? AND claim_id=? AND kind='video'");
      $q->execute([$replaceClipId,$sessionId,$claimId]);if(!$q->fetch())throw new DomainException('Original recording does not belong to this prize.',409);
     }
     $q=$db->prepare('SELECT COUNT(*) AS total,COALESCE(SUM(bytes),0) AS used,COALESCE(MAX(sort_order),0) AS last_order FROM claim_media WHERE session_id=?');$q->execute([$sessionId]);$usage=$q->fetch();
     if((int)$usage['total']>=ClaimMedia::MAX_CLIPS_PER_SESSION||(int)$usage['used']+(int)$file['size']>ClaimMedia::MAX_SESSION_BYTES)throw new DomainException('Game media limit reached. Delete older clips to continue.',413);
     if(!is_dir(dirname($path))&&!mkdir(dirname($path),0700,true))throw new RuntimeException('Could not create private media directory.');
     if(!move_uploaded_file($file['tmp_name'],$path))throw new RuntimeException('Could not store your recording.');@chmod($path,0600);
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
      $state=stateFromRow(sessionRow($db,$sessionId));
      $claimOrder=array_column($state['claims']??[],'id');
      $edits=MontageClaimTimeline::apply($edits,$allMedia,$newRow,$claimOrder,$replaceClipId!==''?$replaceClipId:null);
      $q=$db->prepare('INSERT INTO montage_projects(session_id,edit_json) VALUES (?,?) ON DUPLICATE KEY UPDATE edit_json=VALUES(edit_json)');
      $q->execute([$sessionId,json_encode($edits,JSON_THROW_ON_ERROR)]);
     }
     audit($db,'claim.media-upload',$sessionId,['claim'=>$claimId,'clip'=>$id,'kind'=>$kind,'replace'=>$replaceClipId?:null]);$db->commit();
   }catch(Throwable $e){$db->rollBack();@unlink($path);throw $e;}
   success(['clip'=>ClaimMedia::asPublic(mediaRow($db,$id))],201);
 }
 $in=jsonInput();$session=(string)($in['session']??'');mediaSession($db,$session);
 if($action==='update'){
   $r=mediaRow($db,(string)($in['id']??''));if($r['session_id']!==$session)throw new DomainException('Clip does not belong to this game.',403);
   $values=ClaimMedia::edit($r,$in);
   $q=$db->prepare('UPDATE claim_media SET title=?,trim_start_ms=?,trim_end_ms=?,photo_duration_ms=?,duration_ms=? WHERE id=?');
   $duration=isset($in['durationMs'])?max(0,min(3600000,(int)$in['durationMs'])):(int)$r['duration_ms'];
   $q->execute([$values['title'],$values['trim_start_ms'],$values['trim_end_ms'],$values['photo_duration_ms'],$duration,$r['id']]);
   audit($db,'claim.media-edit',$session,['clip'=>$r['id']]);success(['clip'=>ClaimMedia::asPublic(mediaRow($db,$r['id']))]);
 }
 if($action==='reorder'){
   $ids=$in['ids']??[];if(!is_array($ids)||count($ids)>ClaimMedia::MAX_CLIPS_PER_SESSION||count($ids)!==count(array_unique($ids)))throw new DomainException('Invalid clip order.',422);
   $db->beginTransaction();try{
     sessionRow($db,$session,true);$q=$db->prepare('SELECT id FROM claim_media WHERE session_id=? ORDER BY sort_order,id');$q->execute([$session]);$all=array_column($q->fetchAll(),'id');
     $a=$all;$b=$ids;sort($a);sort($b);if($a!==$b)throw new DomainException('Timeline changed. Reload before reordering.',409);
     $q=$db->prepare('UPDATE claim_media SET sort_order=? WHERE session_id=? AND id=?');foreach($ids as $i=>$id)$q->execute([$i+1,$session,$id]);
     audit($db,'claim.media-reorder',$session,['count'=>count($ids)]);$db->commit();
   }catch(Throwable $e){$db->rollBack();throw $e;}
   success();
 }
 if($action==='delete'){
   $r=mediaRow($db,(string)($in['id']??''));if($r['session_id']!==$session)throw new DomainException('Clip does not belong to this game.',403);
   // Saved proof revisions are immutable evidence. Reject deletion of a referenced original.
   $q=$db->prepare('SELECT 1 FROM claim_proof_revision_clips WHERE clip_id=? LIMIT 1');$q->execute([$r['id']]);
   if($q->fetchColumn())throw new DomainException('This original recording belongs to a saved proof revision and cannot be deleted.',409);
   $q=$db->prepare('DELETE FROM claim_media WHERE session_id=? AND id=?');$q->execute([$session,$r['id']]);
   audit($db,'claim.media-delete',$session,['clip'=>$r['id']]);@unlink(LUCKY_ROOT.'/storage/claim-media/'.$r['id'].'.bin');success();
 }
 throw new DomainException('Unknown media operation.',404);
} catch(Throwable $e){error_log('Lucky claim media: '.get_class($e).' '.$e->getMessage());errorOut($e);}
