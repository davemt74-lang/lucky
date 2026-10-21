<?php
declare(strict_types=1);
require __DIR__.'/../lib/Bootstrap.php';
require_once __DIR__.'/../lib/ClaimMedia.php';
require_once __DIR__.'/../lib/MontagePlan.php';
require_once __DIR__.'/../lib/MontageLayers.php';
require_once __DIR__.'/../lib/MontageTemplate.php';
require_once __DIR__.'/../lib/SchemaGuard.php';
require_once __DIR__.'/../lib/MoviePreview.php';
require_once __DIR__.'/../lib/MontageAutoDraft.php';
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
/** Private, immutable render metadata saved by the render worker. */
function movieGalleryMeta(array $r):array {
 $id=(string)$r['id'];$root=LUCKY_ROOT.'/storage/renders/';
 $file=$root.$id.'.meta.json';$data=[];
 if(is_file($file) && filesize($file)<=4096){
  $parsed=json_decode((string)file_get_contents($file),true);
  if(is_array($parsed))$data=$parsed;
 }
 $duration=(int)($data['durationMs']??0);
 if($duration<=0){
  $clips=json_decode((string)($r['snapshot_json']??''),true);
  if(is_array($clips)){
   require_once __DIR__.'/../lib/MontagePlan.php';
   foreach($clips as $clip)if(is_array($clip))$duration+=MontagePlan::ms($clip);
  }
 }
 return ['durationMs'=>max(0,$duration), 'thumbnail'=>($r['status']==='ready' && is_file($root.$id.'.jpg'))?'api/montage.php?action=poster&id='.$id:null];
}
function renderPoster(array $r,bool $head):never {
 if($r['status']!=='ready')throw new DomainException('Video is not finished.',409);
 $path=LUCKY_ROOT.'/storage/renders/'.$r['id'].'.jpg';
 if(!is_file($path)||!($size=filesize($path)))throw new DomainException('Movie thumbnail is unavailable.',404);
 header('Content-Type: image/jpeg');header('Content-Length: '.$size);header('Cache-Control: private, no-store');
 header('X-Content-Type-Options: nosniff');if($head)exit;
 readfile($path);exit;
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
 if(($method==='GET'||$method==='HEAD')&&$action==='poster')renderPoster(renderRow($db,(string)($_GET['id']??'')),$method==='HEAD');
 if($method==='GET'&&$action==='preview_state'){
  MoviePreview::requireReady($db);
  $row=MoviePreview::row($db,(int)$_SESSION['admin_id']);
  success(['movie'=>($row&&$row['session_id']===(string)($_GET['session']??''))?MoviePreview::public($row):null]);
 }
 // Gallery is administrator-only, server-scoped, and includes completed renders from every game.
 if($method==='GET'&&$action==='gallery'){
  $q=$db->query("SELECT r.*,s.game_id,s.status AS game_status FROM montage_renders r JOIN game_sessions s ON s.id=r.session_id ORDER BY r.created_at DESC,r.id DESC LIMIT 150");
  $items=[];
  foreach($q->fetchAll() as $r){
   $item=renderPublic($r);
   $item['game']=$r['game_id'];$item['gameStatus']=$r['game_status'];
   $item=array_merge($item,movieGalleryMeta($r));
   $available=$r['status']==='ready'&&is_file(LUCKY_ROOT.'/storage/renders/'.$r['id'].'.mp4');
   $item['available']=$available;
   if(!$available){$item['play']=null;$item['download']=null;}
   $items[]=$item;
  }
  success(['renders'=>$items]);
 }
 if($method==='GET'&&$action==='list'){
  $session=(string)($_GET['session']??'');sessionRow($db,$session);
  $q=$db->prepare('SELECT * FROM montage_renders WHERE session_id=? ORDER BY created_at DESC LIMIT 25');$q->execute([$session]);
  success(['renders'=>array_map('renderPublic',$q->fetchAll())]);
 }
 if($method==='GET'&&$action==='project'){
  $session=(string)($_GET['session']??'');sessionRow($db,$session);
  $q=$db->prepare('SELECT edit_json FROM montage_projects WHERE session_id=?');$q->execute([$session]);$raw=$q->fetchColumn();
  SchemaGuard::require($db,['montage_timeline_layers'],'Text/audio timeline tracks');
  $q=$db->prepare('SELECT layers_json FROM montage_timeline_layers WHERE session_id=?');$q->execute([$session]);$layers=$q->fetchColumn();
  success(['project'=>$raw?json_decode($raw,true,512,JSON_THROW_ON_ERROR):null,'layers'=>$layers?json_decode($layers,true,512,JSON_THROW_ON_ERROR):['titles'=>[],'background'=>null]]);
 }
 if($method==='GET'&&$action==='templates'){
  $session=(string)($_GET['session']??'');sessionRow($db,$session);
  SchemaGuard::require($db,['montage_templates'],'Video template library');
  $q=$db->prepare('SELECT id,name,template_json,created_at FROM montage_templates WHERE admin_id=? ORDER BY created_at DESC LIMIT 20');
  $q->execute([(int)$_SESSION['admin_id']]);
  $items=[];
  foreach($q->fetchAll() as $row)$items[]=['id'=>$row['id'],'name'=>$row['name'],'template'=>json_decode($row['template_json'],true,512,JSON_THROW_ON_ERROR),'createdAt'=>$row['created_at']];
  success(['templates'=>$items]);
 }
 if($method==='GET'&&$action==='auto'){
  $session=(string)($_GET['session']??'');sessionRow($db,$session);
  $q=$db->prepare('SELECT m.* FROM claim_media m WHERE m.session_id=? ORDER BY m.sort_order,m.created_at,m.id');$q->execute([$session]);
  $rows=$q->fetchAll();$ordered=MontagePlan::autoOrder($rows);
  success(['ids'=>array_column($ordered,'id'),'metrics'=>MontagePlan::validate($ordered)]);
 }
 if($method!=='POST')throw new DomainException('Unsupported operation.',405);
 assertCsrf();$data=jsonInput();$session=(string)($data['session']??'');sessionRow($db,$session);
 // Session shortcut: enqueue exactly the saved edit, or a validated automatic draft.
 // Only a signed-in administrator may create a real MP4 job; phones cannot access this endpoint.
 if($action==='queue_current'){
  SchemaGuard::require($db,['montage_renders','montage_projects','montage_timeline_layers'],'Admin movie exports');
  $db->beginTransaction();try{
   $game=sessionRow($db,$session,true);
   if(!in_array($game['status'],['active','paused'],true))throw new DomainException('Only active or paused games can export current clips. Use Video Studio for archived sessions.',409);
   $q=$db->prepare("SELECT id FROM montage_renders WHERE session_id=? AND status IN ('queued','running') LIMIT 1 FOR UPDATE");
   $q->execute([$session]);if($q->fetch())throw new DomainException('A movie export is already in progress for this game.',409);
   $q=$db->prepare('SELECT * FROM claim_media WHERE session_id=? ORDER BY sort_order,created_at,id FOR UPDATE');
   $q->execute([$session]);$media=$q->fetchAll();$source=[];
   foreach($media as $row)$source[$row['id']]=$row;
   $q=$db->prepare('SELECT edit_json FROM montage_projects WHERE session_id=?');$q->execute([$session]);$saved=$q->fetchColumn();
   // An intentionally empty saved timeline is not replaced behind the administrator's back.
   $edits=$saved!==false?json_decode($saved,true,512,JSON_THROW_ON_ERROR):MontageAutoDraft::decisions($media);
   if(!is_array($edits)||!$edits)throw new DomainException('No clips available. Capture proof or add media in Video Studio first.',422);
   $clips=MontagePlan::materialize($source,$edits);$metrics=MontagePlan::validate($clips);
   $q=$db->prepare('SELECT layers_json FROM montage_timeline_layers WHERE session_id=?');$q->execute([$session]);$raw=$q->fetchColumn();
   $extra=['titles'=>[],'background'=>null];$music=null;$volume=0;
   if($raw!==false){
    $layerData=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
    $songs=$db->query("SELECT id FROM media_tracks WHERE kind='upload'")->fetchAll(PDO::FETCH_COLUMN);
    $extra=MontageLayers::normalize($layerData,$metrics['estimatedMs'],array_fill_keys($songs,true));
    if($extra['background']!==null){$music=$extra['background']['trackId'];$volume=$extra['background']['volume'];}
   }
   $clips=MontageLayers::apply($clips,$extra);$id=bin2hex(random_bytes(16));
   $q=$db->prepare('INSERT INTO montage_renders(id,session_id,admin_id,profile,snapshot_json,soundtrack_id,music_volume) VALUES (?,?,?,?,?,?,?)');
   $q->execute([$id,$session,(int)$_SESSION['admin_id'],'standard',json_encode($clips,JSON_THROW_ON_ERROR),$music,(int)$volume]);
   audit($db,'montage.queued-admin-session',$session,['render'=>$id,'clips'=>$metrics['clipCount']]);$db->commit();
  }catch(Throwable $e){$db->rollBack();throw $e;}
  success(['render'=>['id'=>$id,'session'=>$session,'status'=>'queued'],'metrics'=>$metrics],201);
 }
 if($action==='preview_open'){success(['movie'=>MoviePreview::open($db,(int)$_SESSION['admin_id'],$session)]);}
 if($action==='preview_close'){MoviePreview::stop($db,(int)$_SESSION['admin_id'],$session);success(['movie'=>null]);}
 if($action==='preview_heartbeat'){success(['movie'=>MoviePreview::heartbeat($db,(int)$_SESSION['admin_id'],$session)]);}
 if($action==='save_template'){
  SchemaGuard::require($db,['montage_templates'],'Video template library');
  $name=trim((string)($data['name']??''));
  if($name===''||strlen($name)>100)throw new DomainException('Template name must be 1–100 characters.',422);
  $raw=$data['template']??null;if(!is_array($raw))throw new DomainException('Invalid template.',422);
  $songs=$db->query("SELECT id FROM media_tracks WHERE kind='upload'")->fetchAll(PDO::FETCH_COLUMN);
  $safe=MontageTemplate::validate($raw,array_fill_keys($songs,true));
  $owner=(int)($_SESSION['admin_id']??0);if($owner<1)throw new DomainException('Host account required.',403);
  $db->beginTransaction();try{
   $q=$db->prepare('SELECT COUNT(*) FROM montage_templates WHERE admin_id=? FOR UPDATE');$q->execute([$owner]);
   if((int)$q->fetchColumn()>=20)throw new DomainException('Delete an older template before adding another (20 maximum).',409);
   $id=bin2hex(random_bytes(16));
   $q=$db->prepare('INSERT INTO montage_templates(id,admin_id,name,template_json) VALUES (?,?,?,?)');
   $q->execute([$id,$owner,$name,json_encode($safe,JSON_THROW_ON_ERROR)]);
   audit($db,'montage.template-saved',$session,['id'=>$id]);$db->commit();
  }catch(Throwable $e){$db->rollBack();throw $e;}
  success(['template'=>['id'=>$id,'name'=>$name,'template'=>$safe]],201);
 }
 if($action==='delete_template'){
  SchemaGuard::require($db,['montage_templates'],'Video template library');
  $id=(string)($data['id']??'');
  if(!ClaimMedia::validId($id))throw new DomainException('Invalid template ID.',422);
  $q=$db->prepare('DELETE FROM montage_templates WHERE id=? AND admin_id=?');
  $q->execute([$id,(int)$_SESSION['admin_id']]);
  if($q->rowCount()!==1)throw new DomainException('Template not found.',404);
  audit($db,'montage.template-deleted',$session,['id'=>$id]);success(['deleted'=>true]);
 }
 if($action==='save_project'){
  $edits=$data['edits']??[];if(!is_array($edits))throw new DomainException('Invalid edits.',422);
  $db->beginTransaction();try{
   sessionRow($db,$session,true);
   $q=$db->prepare('SELECT * FROM claim_media WHERE session_id=?');$q->execute([$session]);$map=[];foreach($q->fetchAll() as $row)$map[$row['id']]=$row;
   $clips=$edits?MontagePlan::materialize($map,$edits):[];
   $json=json_encode($edits,JSON_THROW_ON_ERROR);
   if(array_key_exists('layers',$data)){
    SchemaGuard::require($db,['montage_timeline_layers'],'Text/audio timeline tracks');
    if(!is_array($data['layers']))throw new DomainException('Invalid extra timeline tracks.',422);
    $songs=$db->query("SELECT id FROM media_tracks WHERE kind='upload'")->fetchAll(PDO::FETCH_COLUMN);
    $layerData=MontageLayers::normalize($data['layers'],$clips?MontagePlan::validate($clips)['estimatedMs']:0,array_fill_keys($songs,true));
    $trackSql=json_encode($layerData,JSON_THROW_ON_ERROR);
    $saveLayers=$db->prepare('INSERT INTO montage_timeline_layers(session_id,layers_json) VALUES (?,?) ON DUPLICATE KEY UPDATE layers_json=VALUES(layers_json)');
    $saveLayers->execute([$session,$trackSql]);
   }
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
   if(array_key_exists('layers',$data)){
    SchemaGuard::require($db,['montage_timeline_layers'],'Text/audio timeline tracks');
    if(!is_array($data['layers']))throw new DomainException('Invalid timeline tracks.',422);
    $songs=$db->query("SELECT id FROM media_tracks WHERE kind='upload'")->fetchAll(PDO::FETCH_COLUMN);
    $extra=MontageLayers::normalize($data['layers'],$metrics['estimatedMs'],array_fill_keys($songs,true));
    $clips=MontageLayers::apply($clips,$extra);
    if($extra['background']!==null){$music=$extra['background']['trackId'];$volume=$extra['background']['volume'];}
    else{$music='';$volume=0;}
   }
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
