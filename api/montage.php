<?php
declare(strict_types=1);
require __DIR__.'/../lib/Bootstrap.php';
require_once __DIR__.'/../lib/ClaimMedia.php';
require_once __DIR__.'/../lib/MontagePlan.php';
headerSecurity();
function renderRow(PDO $db,string $id):array {
 if(!ClaimMedia::validId($id))throw new DomainException('Invalid render identifier.',400);
 $q=$db->prepare('SELECT * FROM montage_renders WHERE id=?');$q->execute([$id]);$r=$q->fetch();
 if(!$r)throw new DomainException('Render not found.',404);return $r;
}
function renderPublic(array $r):array {
 return ['id'=>$r['id'],'session'=>$r['session_id'],'status'=>$r['status'],'profile'=>$r['profile'],'bytes'=>(int)$r['output_bytes'],'error'=>$r['error_message']??'',
  'createdAt'=>$r['created_at'],'startedAt'=>$r['started_at'],'finishedAt'=>$r['finished_at'],
  'download'=>$r['status']==='ready'?'api/montage.php?action=download&id='.$r['id']:null, 'play'=>$r['status']==='ready'?'api/montage.php?action=play&id='.$r['id']:null];
}
function renderDownload(array $r,bool $head,bool $inline=false):never {
 if($r['status']!=='ready')throw new DomainException('Video has not finished rendering.',409);
 $path=LUCKY_ROOT.'/storage/renders/'.$r['id'].'.mp4';
 if(!is_file($path)||($bytes=filesize($path))===false||$bytes<1)throw new DomainException('Rendered file not available.',404);
 $start=0;$end=$bytes-1;
 header('Content-Type: video/mp4');header('Content-Disposition: '.($inline?'inline':'attachment').'; filename="lucky-game-'.$r['id'].'.mp4"');header('Accept-Ranges: bytes');header('Cache-Control: private, no-store');
 if(isset($_SERVER['HTTP_RANGE'])){
  if(!preg_match('/^bytes=(\d*)-(\d*)$/D',(string)$_SERVER['HTTP_RANGE'],$match)||($match[1]===''&&$match[2]==='')){http_response_code(416);header('Content-Range: bytes */'.$bytes);exit;}
  if($match[1]===''){$suffix=(int)$match[2];if($suffix<=0){http_response_code(416);exit;}$start=max(0,$bytes-$suffix);}
  else{$start=(int)$match[1];if($match[2]!=='')$end=min($end,(int)$match[2]);}
  if($start>=$bytes||$end<$start){http_response_code(416);header('Content-Range: bytes */'.$bytes);exit;}
  http_response_code(206);header("Content-Range: bytes $start-$end/$bytes");
 }
 header('Content-Length: '.($end-$start+1));if($head)exit;
 $handle=fopen($path,'rb');fseek($handle,$start);$remaining=$end-$start+1;
 while($remaining>0&&!feof($handle)){$part=fread($handle,min(65536,$remaining));if($part===false||$part==='')break;echo $part;$remaining-=strlen($part);}fclose($handle);exit;
}
try {
 if(!installed())throw new DomainException('Platform not installed.',503);
 requireAdmin();$db=db();$action=(string)($_GET['action']??'');$method=$_SERVER['REQUEST_METHOD']??'GET';
 if(($method==='GET'||$method==='HEAD')&&in_array($action,['download','play'],true))renderDownload(renderRow($db,(string)($_GET['id']??'')),$method==='HEAD',$action==='play');
 if($method==='GET'&&$action==='list'){
  $session=(string)($_GET['session']??'');sessionRow($db,$session);
  $q=$db->prepare('SELECT * FROM montage_renders WHERE session_id=? ORDER BY created_at DESC LIMIT 25');$q->execute([$session]);
  success(['renders'=>array_map('renderPublic',$q->fetchAll())]);
 }
 if($method==='GET'&&$action==='project'){
  $session=(string)($_GET['session']??'');sessionRow($db,$session);
  $q=$db->prepare('SELECT edit_json FROM montage_projects WHERE session_id=?');$q->execute([$session]);$raw=$q->fetchColumn();
  success(['project'=>$raw?json_decode($raw,true,512,JSON_THROW_ON_ERROR):null]);
 }
 if($method==='GET'&&$action==='auto'){
  $session=(string)($_GET['session']??'');sessionRow($db,$session);
  $q=$db->prepare('SELECT m.* FROM claim_media m WHERE m.session_id=? ORDER BY m.sort_order,m.created_at,m.id');$q->execute([$session]);
  $rows=$q->fetchAll();$ordered=MontagePlan::autoOrder($rows);
  success(['ids'=>array_column($ordered,'id'),'metrics'=>MontagePlan::validate($ordered)]);
 }
 if($method!=='POST')throw new DomainException('Unsupported operation.',405);
 assertCsrf();$data=jsonInput();$session=(string)($data['session']??'');sessionRow($db,$session);
 if($action==='save_project'){
  $edits=$data['edits']??[];if(!is_array($edits))throw new DomainException('Invalid edits.',422);
  $db->beginTransaction();try{
   sessionRow($db,$session,true);
   $q=$db->prepare('SELECT * FROM claim_media WHERE session_id=?');$q->execute([$session]);$map=[];foreach($q->fetchAll() as $row)$map[$row['id']]=$row;
   if($edits)MontagePlan::materialize($map,$edits);
   $json=json_encode($edits,JSON_THROW_ON_ERROR);
   $q=$db->prepare('INSERT INTO montage_projects(session_id,edit_json) VALUES (?,?) ON DUPLICATE KEY UPDATE edit_json=VALUES(edit_json)');$q->execute([$session,$json]);
   $db->commit();
  }catch(Throwable $e){$db->rollBack();throw $e;}
  success(['saved'=>true]);
 }
 if($action==='queue'){
  $edits=$data['edits']??[];if(!is_array($edits)||!$edits)throw new DomainException('No clips selected for rendering.',422);
  $profile=(string)($data['profile']??'standard');MontagePlan::profile($profile);
  $volume=$data['musicVolume']??18;MontagePlan::musicVolume($volume);
  $music=(string)($data['soundtrackId']??'');
  if($music!==''){
   if(!ClaimMedia::validId($music))throw new DomainException('Invalid song identifier.',422);
   $q=$db->prepare("SELECT id FROM media_tracks WHERE id=? AND kind='upload'");$q->execute([$music]);
   if(!$q->fetch())throw new DomainException('Choose an uploaded song for rendering. Hosted links cannot be rendered.',422);
  }
  $db->beginTransaction();try{
   sessionRow($db,$session,true);
   $q=$db->prepare('SELECT m.* FROM claim_media m WHERE m.session_id=? FOR UPDATE');$q->execute([$session]);$available=[];
   foreach($q->fetchAll() as $row)$available[$row['id']]=$row;
   $clips=MontagePlan::materialize($available,$edits);
   $metrics=MontagePlan::validate($clips);
   $q=$db->prepare("SELECT COUNT(*) FROM montage_renders WHERE session_id=? AND status IN ('queued','running')");$q->execute([$session]);
   if((int)$q->fetchColumn()>=2)throw new DomainException('Wait for a pending render to finish before queuing another.',409);
   $id=bin2hex(random_bytes(16));
   $q=$db->prepare('INSERT INTO montage_renders (id,session_id,admin_id,profile,snapshot_json,soundtrack_id,music_volume) VALUES (?,?,?,?,?,?,?)');
   $q->execute([$id,$session,$_SESSION['admin_id']??null,$profile,json_encode($clips,JSON_THROW_ON_ERROR),$music?:null,(int)$volume]);
   audit($db,'montage.queued',$session,['render'=>$id,'clips'=>$metrics['clipCount']]);$db->commit();
  }catch(Throwable $e){$db->rollBack();throw $e;}
  success(['render'=>['id'=>$id,'status'=>'queued'],'metrics'=>$metrics],201);
 }
 if($action==='cancel'){
  $r=renderRow($db,(string)($data['id']??''));if($r['session_id']!==$session)throw new DomainException('Render belongs to another game.',403);
  $q=$db->prepare("UPDATE montage_renders SET status='cancelled', finished_at=UTC_TIMESTAMP() WHERE id=? AND status='queued'");$q->execute([$r['id']]);
  if($q->rowCount()!==1)throw new DomainException('Only queued renders can be cancelled.',409);
  audit($db,'montage.cancelled',$session,['render'=>$r['id']]);success();
 }
 throw new DomainException('Unknown video operation.',404);
}catch(Throwable $e){error_log('Lucky montage API: '.get_class($e).' '.$e->getMessage());errorOut($e);}
