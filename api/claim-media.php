<?php
declare(strict_types=1);
require __DIR__.'/../lib/Bootstrap.php';
require_once __DIR__.'/../lib/ClaimMedia.php';
require_once __DIR__.'/../lib/MontageClaimTimeline.php';
require_once __DIR__.'/../lib/ClaimMediaUpload.php';
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
   success(['clip'=>ClaimMediaUpload::store($db,$_POST,$_FILES['media']??null)],201);
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
   audit($db,'claim.media-delete',$session,['clip'=>$r['id']]);
   @unlink(LUCKY_ROOT.'/storage/claim-media/'.$r['id'].'.bin');
   @unlink(LUCKY_ROOT.'/storage/media-analysis/'.$r['id'].'.json');
   success();
 }
 throw new DomainException('Unknown media operation.',404);
} catch(Throwable $e){error_log('Lucky claim media: '.get_class($e).' '.$e->getMessage());errorOut($e);}
