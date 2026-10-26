<?php
declare(strict_types=1);
require __DIR__.'/../lib/Bootstrap.php';require_once __DIR__.'/../lib/CloudGameUpdates.php';require_once __DIR__.'/../lib/GamePackageInstaller.php';require_once __DIR__.'/../lib/GamePackageActivation.php';require_once __DIR__.'/../lib/CloudGamePackageDownload.php';require_once __DIR__.'/../lib/GamePublisherChannels.php';require_once __DIR__.'/../lib/GameCatalog.php';require_once __DIR__.'/../lib/ProductCatalog.php';require_once __DIR__.'/../lib/ControllerGame.php';require_once __DIR__.'/../lib/PrizeDecision.php';require_once __DIR__.'/../lib/GameSessionLifecycle.php';require_once __DIR__.'/../lib/GamePluginCapabilities.php';require_once __DIR__.'/../lib/RecordingSessions.php';headerSecurity();
$action=strval($_GET['action']??'');$method=$_SERVER['REQUEST_METHOD']??'GET';
function setting(PDO $db,string $game='spin-wheel'):array {
 $q=$db->prepare('SELECT * FROM game_settings WHERE game_id=?');$q->execute([$game]);$r=$q->fetch();
 if(!$r)throw new DomainException('Unknown game.',404);
 return ['config'=>json_decode($r['config_json'],true,512,JSON_THROW_ON_ERROR),
   'intro'=>json_decode($r['intro_json'],true,512,JSON_THROW_ON_ERROR),
   'theme'=>json_decode($r['theme_json'],true,512,JSON_THROW_ON_ERROR)];
}
function getSessionView(PDO $db,string $id,bool $viewer=false,string $viewerToken=''):array {
 $r=sessionRow($db,$id);
 if($viewer){if($viewerToken===''||!hash_equals($r['viewer_token'],$viewerToken))throw new DomainException('Invalid display token.',403);}
 else requireAdmin();
 $s=stateFromRow($r);$public=GameRegistry::module($s['game'])->publicView($s);
 return $viewer?$public:['session'=>$s,'viewerToken'=>$r['viewer_token'],'view'=>$public];
}
function streamTrack(PDO $db,string $id):never {
 if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new DomainException('Unknown audio track.',404);
 $q=$db->prepare('SELECT * FROM media_tracks WHERE id=?');$q->execute([$id]);$r=$q->fetch();
 if(!$r||$r['kind']!=='upload')throw new DomainException('Track not available.',404);
 $path=LUCKY_ROOT.'/storage/audio/'.$id.'.audio';
 if(!is_file($path))throw new DomainException('Audio file missing.',404);
 $size=filesize($path);$start=0;$end=$size-1;
 header_remove('Cache-Control');header('Cache-Control: private, max-age=600');header('Accept-Ranges: bytes');header('Content-Type: '.$r['mime']);
 if(isset($_SERVER['HTTP_RANGE'])){
  $range=$_SERVER['HTTP_RANGE'];
  if(!preg_match('/^bytes=(\d*)-(\d*)$/D',$range,$m)) {http_response_code(416);exit;}
  if($m[1]===''&&$m[2]===''){http_response_code(416);exit;}
  if($m[1]===''){$count=(int)$m[2];$start=max(0,$size-$count);}else{$start=(int)$m[1];}
  if($m[2]!==''&&$m[1]!=='')$end=min($end,(int)$m[2]);
  if($start>$end||$start >= $size){header('Content-Range: bytes */'.$size);http_response_code(416);exit;}
  http_response_code(206);header("Content-Range: bytes $start-$end/$size");
 }
 header('Content-Length: '.($end-$start+1));
 if($_SERVER['REQUEST_METHOD']==='HEAD')exit;
 $f=fopen($path,'rb');fseek($f,$start);$left=$end-$start+1;
 while($left>0&&!feof($f)){$chunk=fread($f,min(65536,$left));if($chunk==='')break;echo $chunk;$left-=strlen($chunk);}
 fclose($f);exit;
}
function streamPrizeVideo(string $id):never {
 requireAdmin();
 if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new DomainException('Prize video unavailable.',404);
 $dir=LUCKY_ROOT.'/storage/prize-videos/';$file='';$mime='';
 foreach(['mp4'=>'video/mp4','webm'=>'video/webm'] as $ext=>$type){
  if(is_file($dir.$id.'.'.$ext)){$file=$dir.$id.'.'.$ext;$mime=$type;break;}
 }
 if($file==='')throw new DomainException('Prize video unavailable.',404);
 $size=(int)filesize($file);$start=0;$end=$size-1;
 header_remove('Cache-Control');header('Cache-Control: private, no-store');
 header('X-Content-Type-Options: nosniff');header('Content-Type: '.$mime);header('Accept-Ranges: bytes');
 if(isset($_SERVER['HTTP_RANGE'])){
  $range=(string)$_SERVER['HTTP_RANGE'];
  if(!preg_match('/^bytes=(\d*)-(\d*)$/D',$range,$matches)||($matches[1]===''&&$matches[2]==='')){http_response_code(416);header('Content-Range: bytes */'.$size);exit;}
  if($matches[1]==='')$start=max(0,$size-(int)$matches[2]);else $start=(int)$matches[1];
  if($matches[2]!==''&&$matches[1]!=='')$end=min($end,(int)$matches[2]);
  if($start>$end||$start >= $size){http_response_code(416);header('Content-Range: bytes */'.$size);exit;}
  http_response_code(206);header('Content-Range: bytes '.$start.'-'.$end.'/'.$size);
 }
 header('Content-Length: '.($end-$start+1));
 if(($_SERVER['REQUEST_METHOD']??'GET')==='HEAD')exit;
 $handle=fopen($file,'rb');fseek($handle,$start);$remaining=$end-$start+1;
 while($remaining>0&&!feof($handle)){$chunk=fread($handle,min(65536,$remaining));if($chunk==='')break;echo $chunk;$remaining-=strlen($chunk);}
 fclose($handle);exit;
}
function streamPrizeImage(PDO $db,string $id):never {
 if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new DomainException('Image unavailable.',404);
 $q=$db->prepare('SELECT mime,bytes FROM prize_images WHERE id=?');$q->execute([$id]);$r=$q->fetch();
 if(!$r)throw new DomainException('Image not found.',404);
 $path=LUCKY_ROOT.'/storage/images/'.$id.'.img';if(!is_file($path))throw new DomainException('Image missing.',404);
 header_remove('Cache-Control');header('Cache-Control: public, max-age=86400');header('Content-Type: '.$r['mime']);header('Content-Length: '.filesize($path));
 readfile($path);exit;
}
try {
 if(!installed())throw new DomainException('Run setup first.',503);
 $db=db();
 if($action==='game_art'&&$method==='GET'){
  $art=GameCatalog::artwork(strval($_GET['id']??''),strval($_GET['file']??''));
  header('Content-Type: '.$art['mime']);header('Content-Length: '.$art['bytes']);
  header('Cache-Control: public, max-age=86400, immutable');readfile($art['path']);exit;
 }
 if($action==='prize_image'&&$method==='GET')streamPrizeImage($db,strval($_GET['id']??''));
 if($action==='prize_video'&&in_array($method,['GET','HEAD'],true))streamPrizeVideo(strval($_GET['id']??''));
 if($action==='track'&&in_array($method,['GET','HEAD'],true))streamTrack($db,strval($_GET['id']??''));
 if($method==='GET'){
  if($action==='health')success(['service'=>'Lucky Game Hub','installed'=>true,'database'=>'connected','build'=>'5P']);
  if($action==='manifest')success(['games'=>GameRegistry::manifest()]);
  if($action==='game_catalog'){
   requireAdmin();success(['games'=>GameCatalog::all()]);
  }
  if($action==='phone_dashboard_launch'){
   requireAdmin();
   require_once __DIR__.'/../lib/PhoneGameDashboard.php';
   success(['launch'=>PhoneGameDashboard::pendingForHost($db,(int)$_SESSION['admin_id'])]);
  }
  if($action==='game_packages'){
   requireAdmin();$state=GamePackageActivation::inspect();success(['packages'=>GamePackageInstaller::listing(),'active'=>$state['active'],'issues'=>$state['issues']]);
  }
  if($action==='game_update_connection'){
   requireAdmin();success(['connection'=>CloudGameUpdates::summary()]);
  }
  if($action==='game_publisher_channels'){
   requireAdmin();success(['channels'=>GamePublisherChannels::summary(),'sdkVersion'=>GamePublisherChannels::SDK_VERSION]);
  }
  if($action==='catalog'||$action==='catalog_admin'){
   if($action==='catalog_admin')requireAdmin();
   $query=$action==='catalog_admin'
    ?'SELECT id,title,summary,category,status,price_cents AS priceCents,image_url AS imageUrl,purchase_url AS purchaseUrl,sort_order AS sortOrder FROM catalog_products ORDER BY sort_order,title'
    :"SELECT id,title,summary,category,status,price_cents AS priceCents,image_url AS imageUrl,purchase_url AS purchaseUrl FROM catalog_products WHERE status IN ('available','coming_soon') ORDER BY sort_order,title";
   $rows=$db->query($query)->fetchAll();foreach($rows as &$product){$product['priceCents']=(int)$product['priceCents'];if(isset($product['sortOrder']))$product['sortOrder']=(int)$product['sortOrder'];}unset($product);
   success(['products'=>$rows]);
  }

  if($action==='me')success(['admin'=>isAdmin(),'csrf'=>isAdmin()?csrf():null]);
  if($action==='settings'){
   $game=strval($_GET['game']??'spin-wheel');
   if(isAdmin()&&isset(GameRegistry::modules()[$game]))GameFoundation::settings($db,$game);
   $cfg=setting($db,$game);
   if(!isAdmin())$cfg=['intro'=>$cfg['intro'],'theme'=>$cfg['theme']];
   success(['settings'=>$cfg]);
  }
  if($action==='prize_packages'){
   success(PrizePackages::read($db,isAdmin()));
  }
  if($action==='tracks'){
   $list=$db->query('SELECT id,title,kind,source,mime,bytes FROM media_tracks ORDER BY created_at DESC')->fetchAll();
   foreach($list as &$t){$t['src']=$t['kind']==='upload'?'api/index.php?action=track&id='.$t['id']:$t['source'];unset($t['source']);}unset($t);
   success(['tracks'=>$list]);
  }
  if($action==='sessions'){
   requireAdmin();$filter=strval($_GET['game']??'');
   if($filter!=='')GameRegistry::module($filter);
   if($filter!==''){$q=$db->prepare('SELECT id,game_id,status,version,created_at,updated_at,ended_at,state_json FROM game_sessions WHERE game_id=? ORDER BY created_at DESC,id DESC LIMIT 100');$q->execute([$filter]);}
   else $q=$db->query('SELECT id,game_id,status,version,created_at,updated_at,ended_at,state_json FROM game_sessions ORDER BY created_at DESC,id DESC LIMIT 100');
   $sessions=[];
   foreach($q as $row){
    $state=json_decode($row['state_json'],true,512,JSON_THROW_ON_ERROR);
    $sessions[]=['id'=>$row['id'],'game'=>$row['game_id'],'status'=>$row['status'],'version'=>(int)$row['version'],
     'createdAt'=>$row['created_at'],'updatedAt'=>$row['updated_at'],'endedAt'=>$row['ended_at'],
     'savedAt'=>$state['savedAt']??null,'spins'=>(int)($state['spinCount']??0),
     'claims'=>count($state['claims']??[]),'awardedCents'=>array_sum(array_column($state['claims']??[],'cents'))];
   }
   success(['sessions'=>$sessions]);
  }
  if($action==='prize_offer'){
   requireAdmin();$session=(string)($_GET['session']??'');sessionRow($db,$session);
   PrizeDecision::requireReady($db);
   $state=stateFromRow(sessionRow($db,$session));
    success(['offer'=>SpinReveal::pending($state)?null:PrizeDecision::latest($db,$session)]);
  }
  if($action==='current'){
   requireAdmin();require_once __DIR__.'/../lib/CurrentGame.php';
   $game=strval($_GET['game']??'spin-wheel');GameRegistry::module($game);
   $row=CurrentGame::row($db,$game);
   success(['data'=>$row?getSessionView($db,(string)$row['id']):null]);
  }
  if($action==='session')success(getSessionView($db,strval($_GET['id']??'')));
  if($action==='watch')success(['view'=>getSessionView($db,strval($_GET['id']??''),true,strval($_GET['token']??''))]);
  if($action==='last'){
   requireAdmin();
   $game=strval($_GET['game']??'spin-wheel');GameRegistry::module($game);
   $q=$db->prepare("SELECT id FROM game_sessions WHERE game_id=? ORDER BY CASE WHEN status IN ('active','paused') THEN 0 WHEN status='saved' THEN 1 ELSE 2 END,created_at DESC,id DESC LIMIT 1");
   $q->execute([$game]);$id=$q->fetchColumn();success(['data'=>$id?getSessionView($db,$id):null]);
  }
  if($action==='history'){
   requireAdmin();$q=$db->query("SELECT id,game_id,created_at,ended_at,state_json FROM game_sessions WHERE status='ended' ORDER BY created_at DESC,id DESC LIMIT 100");$rows=[];
   foreach($q as $r){$state=json_decode($r['state_json'],true);$rows[]=['id'=>$r['id'],'game'=>$r['game_id'],'createdAt'=>$r['created_at'],'endedAt'=>$r['ended_at'],'spins'=>$state['spinCount']??0,'claims'=>count($state['claims']??[]),'awardedCents'=>array_sum(array_column($state['claims']??[],'cents'))];}
   success(['history'=>$rows]);
  }
  if($action==='events'){
   requireAdmin();$id=strval($_GET['id']??'');sessionRow($db,$id);
   $q=$db->prepare('SELECT id,event_type,payload_json,created_at FROM game_events WHERE session_id=? ORDER BY id DESC LIMIT 100');$q->execute([$id]);$events=[];
   foreach($q->fetchAll() as $r)$events[]=['id'=>$r['id'],'type'=>$r['event_type'],'at'=>$r['created_at'],'payload'=>json_decode($r['payload_json'],true)];
   success(['events'=>$events]);
  }
  throw new DomainException('Unknown endpoint.',404);
 }
 if($method!=='POST')throw new DomainException('Method not supported.',405);
 assertCsrf();
 if($action==='login')throw new DomainException('Use the sign-in page.',400);
 requireAdmin();
 if($action==='logout') {session_unset();session_destroy();success(['loggedOut'=>true]);}
 if($action==='game_package_recover'){
  $input=jsonInput();$id=GameCatalog::slug($input['game']??null);
  $version=GamePackageInstaller::version($input['version']??null);
  if(($input['confirm']??false)!==true)
   throw new DomainException('Confirm recovery of this damaged game installation.',403);
  $db->beginTransaction();
  try {
   // Lock the existing settings row directly: a damaged package intentionally
   // cannot be resolved through GameRegistry::module().
   $q=$db->prepare('SELECT game_id FROM game_settings WHERE game_id=? FOR UPDATE');
   $q->execute([$id]);$q->fetch();
   GamePackageActivation::assertNoLiveSessions($db,$id);
   $result=GamePackageActivation::recover($id,$version,true);
   audit($db,'game.package_recovered',null,['game'=>$id,'version'=>$version]);
   $db->commit();
  }catch(Throwable $e){$db->rollBack();throw $e;}
  success(['recovery'=>$result]);
 }
 if($action==='game_package_activate'){
  $input=jsonInput();$id=GameCatalog::slug($input['game']??null);
  $version=GamePackageInstaller::version($input['version']??null);
  $expected=$input['expectedCurrent']??null;
  if($expected!==null)$expected=GamePackageInstaller::version($expected);
  if(($input['confirm']??false)!==true)throw new DomainException('Explicit owner approval required.',403);
  // Hold the same game-settings lock as gameplay session start, ensuring
  // an update cannot switch code while a session is actively running.
  $db->beginTransaction();
  try{
   $q=$db->prepare('SELECT game_id FROM game_settings WHERE game_id=? FOR UPDATE');
   $q->execute([$id]);
   GamePackageActivation::assertNoLiveSessions($db,$id);
   $result=GamePackageActivation::activate($id,$version,$expected,true);
   audit($db,'game.package_activated',null,['game'=>$id,'version'=>$version,
      'previous'=>$result['previous']??null]);
   $db->commit();
  }catch(Throwable $e){$db->rollBack();throw $e;}
  success(['activation'=>$result]);
 }
 if($action==='game_package_upload'){
  $package=$_FILES['package']??null;
  if(!is_array($package)||($package['error']??-1)!==UPLOAD_ERR_OK||
    !is_uploaded_file((string)($package['tmp_name']??'')))
   throw new DomainException('Choose a game package ZIP file.',422);
  $game=GamePackageInstaller::inspectIdentity($package['tmp_name']);
  $channel=GamePublisherChannels::config($game);
  // Each game is verified with its own trust anchor, never another game key.
  $settings=$channel??CloudGameUpdates::settings();
  $key=$settings['publicKey']??null;
  $entry=GamePackageInstaller::install($package['tmp_name'],null,$key);
  audit($db,'game.package_staged',null,['game'=>$entry['id'],'version'=>$entry['version'],
    'signatureVerified'=>$entry['signatureVerified']]);
  success(['package'=>$entry],201);
 }
 if($action==='game_art_upload'){
  $id=GameCatalog::slug($_POST['id']??null);$slot=strval($_POST['slot']??'');
  $file=$_FILES['image']??null;
  if(!is_array($file)||($file['error']??-1)!==UPLOAD_ERR_OK||!is_uploaded_file((string)($file['tmp_name']??'')))
   throw new DomainException('Select a JPG, PNG, or WebP game image.',422);
  $result=GameCatalog::saveImage($id,$slot,$file['tmp_name']);
  audit($db,'game.artwork',null,['game'=>$id,'slot'=>$slot]);success($result,201);
 }
 if($action==='game_publisher_connect'){
  $data=jsonInput();$status=GamePublisherChannels::connect($data);
  audit($db,'game.publisher_connected',null,['game'=>$status['game'],'publisher'=>$status['publisher'],
    'host'=>parse_url($status['url'],PHP_URL_HOST),'keyFingerprint'=>$status['keyFingerprint']]);
  success(['channel'=>$status]);
 }
 if($action==='game_publisher_disconnect'){
  $input=jsonInput();$game=GameCatalog::slug($input['game']??null);
  if(($input['confirm']??null)!==true)throw new DomainException('Confirm disconnection of the selected publisher.',403);
  $status=GamePublisherChannels::disconnect($game);
  audit($db,'game.publisher_disconnected',null,['game'=>$game]);
  success(['channel'=>$status]);
 }
 if($action==='game_publisher_check'){
  $input=jsonInput();$game=GameCatalog::slug($input['game']??null);
  $updates=GamePublisherChannels::check($game);
  audit($db,'game.publisher_checked',null,['game'=>$game,'releases'=>count($updates['releases'])]);
  success(['updates'=>$updates]);
 }
 if($action==='game_update_connect'){
  $data=jsonInput();$status=CloudGameUpdates::connect($data);
  audit($db,'game_updates.connected',null,['feedHost'=>parse_url($status['url'],PHP_URL_HOST),'keyFingerprint'=>$status['keyFingerprint']]);
  success(['connection'=>$status]);
 }
 if($action==='game_update_disconnect'){
  $status=CloudGameUpdates::disconnect();
  audit($db,'game_updates.disconnected');success(['connection'=>$status]);
 }
 if($action==='game_update_check'){
  // No background or automatic network access. The operator explicitly requested this check.
  $updates=CloudGameUpdates::check();
  audit($db,'game_updates.checked',null,['releaseCount'=>count($updates['releases'])]);
  success(['updates'=>$updates]);
 }
 if($action==='game_update_stage'){
  // Client supplies ONLY immutable game + version selection. Source URL and
  // digest are reloaded from a fresh, verified publisher feed server-side.
  $input=jsonInput();$id=GameCatalog::slug($input['game']??null);
  $version=GamePackageInstaller::version($input['version']??null);
  $package=CloudGamePackageDownload::stage($id,$version);
  audit($db,'game_updates.package_staged',null,['game'=>$id,'version'=>$version,
   'signatureVerified'=>$package['signatureVerified']]);
  success(['package'=>$package],201);
 }
 if($action==='game_catalog_save'){
  $data=jsonInput();$id=GameCatalog::slug($data['id']??null);
  $game=GameCatalog::save($id,$data);
  audit($db,'game.catalog_save',null,['game'=>$id]);success(['game'=>$game]);
 }
 if($action==='spin_revealed'){
   $input=jsonInput();$session=(string)($input['session']??'');$spinCount=(int)($input['spinCount']??0);
   if($spinCount<1)throw new DomainException('Invalid spin number.',400);
   $db->beginTransaction();
   try {
    $state=stateFromRow(sessionRow($db,$session,true));
    $changed=SpinReveal::complete($state,$spinCount);
    if($changed)saveState($db,$state);
    $db->commit();
   }catch(Throwable $e){$db->rollBack();throw $e;}
   success(['revealed'=>true,'spinCount'=>$spinCount,'version'=>$state['version']]);
  }
  if($action==='prize_decide'){
  $data=jsonInput();$session=(string)($data['session']??'');$claim=(string)($data['claim']??'');$choice=(string)($data['choice']??'');
  $spinState=stateFromRow(sessionRow($db,$session));
   if(SpinReveal::pending($spinState))throw new DomainException('Wait for the wheel to stop before accepting the prize.',409);
   PrizeDecision::requireReady($db);if(!preg_match('/^[a-f0-9]{32}$/D',$claim))throw new DomainException('Invalid claim ID.',400);
  $db->beginTransaction();try{
   $offer=PrizeDecision::pending($db,$session,true);
   if(!$offer||$offer['claim_id']!==$claim)throw new DomainException('This prize is not awaiting a decision.',409);
   $result=PrizeDecision::choose($db,$session,$claim,$choice);
   if($choice==='accepted')ProofHandoff::activate($db,$session,$claim,(int)$_SESSION['admin_id']);
   $db->commit();
  }catch(Throwable $e){$db->rollBack();throw $e;}
  success(['offer'=>$result]);
 }
 if($action==='prize_video_upload'){
  $video=$_FILES['video']??null;
  if(!is_array($video)||($video['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file((string)($video['tmp_name']??'')))
   throw new DomainException('Choose an MP4 or WebM file within the server upload limit.',422);
  if(($video['size']??0)<=0||$video['size']>50*1024*1024)throw new DomainException('Prize video must be 50 MB or less.',422);
  $mime=(new finfo(FILEINFO_MIME_TYPE))->file($video['tmp_name']);
  $ext=['video/mp4'=>'mp4','video/webm'=>'webm'][$mime]??null;
  if($ext===null)throw new DomainException('Upload an MP4 or WebM video.',422);
  $dir=LUCKY_ROOT.'/storage/prize-videos/';
  if(!is_dir($dir)||!is_file($dir.'.htaccess'))throw new RuntimeException('Private prize video storage is missing. Deploy all storage protection files.');
  if(count(glob($dir.'*.mp4')?:[])+count(glob($dir.'*.webm')?:[])>=120)throw new DomainException('The prize video library limit has been reached (120 uploads).',422);
  $id=bin2hex(random_bytes(16));$path=$dir.$id.'.'.$ext;
  if(!move_uploaded_file($video['tmp_name'],$path))throw new RuntimeException('Unable to save uploaded prize video.');
  @chmod($path,0600);
  audit($db,'prize.video-upload',null,['id'=>$id,'mime'=>$mime,'bytes'=>(int)$video['size']]);
  success(['id'=>$id,'path'=>'api/index.php?action=prize_video&id='.$id],201);
 }
 if($action==='image_upload'){
  if(empty($_FILES['image'])||!is_uploaded_file($_FILES['image']['tmp_name']))throw new DomainException('Choose a JPG, PNG, or WebP photo.');
  $f=$_FILES['image'];if($f['error']!==UPLOAD_ERR_OK||$f['size']<=0||$f['size']>5*1024*1024)throw new DomainException('Image must be 5MB or less.');
  $mime=(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
  if(!in_array($mime,['image/jpeg','image/png','image/webp'],true))throw new DomainException('Unsupported picture format.');
  $size=@getimagesize($f['tmp_name']);if(!$size||$size[0]<1||$size[1]<1||$size[0]>6000||$size[1]>6000)throw new DomainException('Invalid image size.');
  if((int)$db->query('SELECT COUNT(*) FROM prize_images')->fetchColumn()>=200)throw new DomainException('Prize image library limit reached (200 images).');
  $id=bin2hex(random_bytes(16));$path=LUCKY_ROOT.'/storage/images/'.$id.'.img';
  if(!move_uploaded_file($f['tmp_name'],$path))throw new RuntimeException('Unable to store image.');@chmod($path,0600);
  try{$q=$db->prepare('INSERT INTO prize_images(id,mime,bytes) VALUES (?,?,?)');$q->execute([$id,$mime,$f['size']]);audit($db,'prize.image-upload',null,['id'=>$id]);}
  catch(Throwable $e){@unlink($path);throw $e;}
  success(['id'=>$id,'path'=>'api/index.php?action=prize_image&id='.$id],201);
 }
 if($action==='upload'){
  if(empty($_FILES['track'])||!is_uploaded_file($_FILES['track']['tmp_name']))throw new DomainException('Select an audio file.');
  $f=$_FILES['track'];$name=trim(strval($_POST['title']??''));
  if($name===''||strlen($name)>160||$f['error']!==UPLOAD_ERR_OK||$f['size']<=0||$f['size']>20*1024*1024)throw new DomainException('Track title or upload size is invalid.');
  $mime=(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
  $types=['audio/mpeg'=>'audio/mpeg','audio/mp4'=>'audio/mp4','audio/x-m4a'=>'audio/mp4','audio/wav'=>'audio/wav','audio/x-wav'=>'audio/wav','audio/ogg'=>'audio/ogg','audio/webm'=>'audio/webm','application/ogg'=>'audio/ogg'];
  if(!isset($types[$mime]))throw new DomainException('Upload an MP3, M4A, WAV, OGG, or WebM audio file.');
  if((int)$db->query('SELECT COUNT(*) FROM media_tracks')->fetchColumn()>=30)throw new DomainException('The library limit is 30 tracks.');
  $id=bin2hex(random_bytes(16));$path=LUCKY_ROOT.'/storage/audio/'.$id.'.audio';
  if(!move_uploaded_file($f['tmp_name'],$path))throw new RuntimeException('Could not store audio.');@chmod($path,0600);
  try{$q=$db->prepare('INSERT INTO media_tracks(id,title,kind,source,mime,bytes) VALUES (?,?,?,?,?,?)');$q->execute([$id,$name,'upload','',$types[$mime],$f['size']]);audit($db,'music.upload',null,['id'=>$id]);}
  catch(Throwable $e){@unlink($path);throw $e;}
  success(['id'=>$id],201);
 }
 $in=jsonInput();
 if(in_array($action,['package_create','package_save','package_select','package_delete','package_publish'],true)){
  $db->beginTransaction();
  try{
   if($action==='package_create'){
    $out=PrizePackages::create($db,$in['name']??'',isset($in['cloneFrom'])?(string)$in['cloneFrom']:null,
     array_key_exists('budgetCents',$in)?$in['budgetCents']:null,array_key_exists('budgetCents',$in));
    audit($db,'package.created',null,['packageId'=>$out['id']]);
   }elseif($action==='package_save'){
    if(array_key_exists('phoneCameraEnabled',$in)&&!is_bool($in['phoneCameraEnabled']))throw new DomainException('Phone camera setting must be on or off.',422);
     $out=PrizePackages::save($db,(string)($in['id']??''),(int)($in['version']??0),
     $in['name']??'',is_array($in['config']??null)?$in['config']:[],
     ($in['enabled']??false)===true,($in['playerSelectable']??false)===true,
     array_key_exists('budgetCents',$in)?$in['budgetCents']:null,$in['image']??'',array_key_exists('phoneCameraEnabled',$in)&&is_bool($in['phoneCameraEnabled'])?$in['phoneCameraEnabled']:null);
    audit($db,'package.saved',null,['packageId'=>$out['id'],'version'=>$out['version']]);
   }elseif($action==='package_publish'){
    if(!array_key_exists('published',$in)||!is_bool($in['published']))throw new DomainException('Publication status must be Draft or Published.',400);
    $out=PrizePackages::publish($db,PrizePackages::id($in['id']??null),
     (int)($in['version']??0),($in['published']??null)===true);
    audit($db,'package.publication_changed',null,['packageId'=>$out['id'],'status'=>$out['publicationStatus'],'version'=>$out['version']]);
   }elseif($action==='package_select'){
    $id=PrizePackages::id($in['id']??null);
    PrizePackages::select($db,$id);$out=['selected'=>$id];
    audit($db,'package.selected',null,['packageId'=>$id,'source'=>'admin']);
   }else{
    $id=PrizePackages::id($in['id']??null);
    PrizePackages::delete($db,$id);
    audit($db,'package.deleted',null,['packageId'=>$id]);$out=['deleted'=>true];
   }
   $db->commit();
  }catch(Throwable $e){$db->rollBack();throw $e;}
  success(['package'=>$out]);
 }
 if($action==='catalog_save'){
  $product=ProductCatalog::validate($in);$id=(string)($in['id']??'');
  if($id!==''&&!preg_match('/^[a-f0-9]{32}$/D',$id))throw new DomainException('Invalid product ID.',400);
  if($id===''){
   if((int)$db->query('SELECT COUNT(*) FROM catalog_products')->fetchColumn()>=500)throw new DomainException('Catalog limit reached.',422);
   $id=bin2hex(random_bytes(16));
   $q=$db->prepare('INSERT INTO catalog_products (id,title,summary,category,status,price_cents,image_url,purchase_url,sort_order) VALUES (?,?,?,?,?,?,?,?,?)');
   $q->execute([$id,$product['title'],$product['summary'],$product['category'],$product['status'],$product['priceCents'],$product['imageUrl'],$product['purchaseUrl'],$product['sortOrder']]);
  }else{
   $q=$db->prepare('UPDATE catalog_products SET title=?,summary=?,category=?,status=?,price_cents=?,image_url=?,purchase_url=?,sort_order=? WHERE id=?');
   $q->execute([$product['title'],$product['summary'],$product['category'],$product['status'],$product['priceCents'],$product['imageUrl'],$product['purchaseUrl'],$product['sortOrder'],$id]);
   if($q->rowCount()===0){$check=$db->prepare('SELECT id FROM catalog_products WHERE id=?');$check->execute([$id]);if(!$check->fetch())throw new DomainException('Product not found.',404);}
  }
  audit($db,'catalog.save',null,['id'=>$id]);success(['id'=>$id,'product'=>$product]);
 }
 if($action==='catalog_delete'){
  $id=(string)($in['id']??'');if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new DomainException('Invalid product ID.',400);
  $q=$db->prepare('DELETE FROM catalog_products WHERE id=?');$q->execute([$id]);
  if($q->rowCount()===0)throw new DomainException('Product not found.',404);
  audit($db,'catalog.delete',null,['id'=>$id]);success();
 }
 if($action==='track_add'){
  $title=trim(strval($in['title']??''));$src=strval($in['url']??'');
  if($title===''||strlen($title)>160||!GameEngine::validUrl($src)||$src==='')throw new DomainException('Provide a valid song title and web URL.');
  if((int)$db->query('SELECT COUNT(*) FROM media_tracks')->fetchColumn()>=30)throw new DomainException('The library limit is 30 tracks.');
  $id=bin2hex(random_bytes(16));$q=$db->prepare('INSERT INTO media_tracks(id,title,kind,source,mime,bytes) VALUES (?,?,?,?,?,0)');$q->execute([$id,$title,'url',$src,'audio/*']);
  audit($db,'music.add',null,['id'=>$id]);success(['id'=>$id],201);
 }
 if($action==='track_remove'){
  $id=strval($in['id']??'');if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new DomainException('Invalid song.');
  $q=$db->prepare('SELECT kind FROM media_tracks WHERE id=?');$q->execute([$id]);$r=$q->fetch();
  if(!$r)throw new DomainException('Song not found.',404);
  $q=$db->prepare('DELETE FROM media_tracks WHERE id=?');$q->execute([$id]);if($r['kind']==='upload')@unlink(LUCKY_ROOT.'/storage/audio/'.$id.'.audio');
  audit($db,'music.remove',null,['id'=>$id]);success();
 }
 if($action==='settings_save'){
  $game=strval($in['game']??'spin-wheel');$module=GameRegistry::modules()[$game]??null;
  if(!$module&&$game!=='game-2')throw new DomainException('Unknown game.');
  if($module)GameFoundation::settings($db,$game);
  $old=setting($db,$game);$intro=$in['intro']??$old['intro'];$theme=$in['theme']??$old['theme'];
  if(!is_array($intro)||!is_array($theme)||strlen(strval($intro['title']??''))>100||strlen(strval($intro['subtitle']??''))>200||
     !isset($intro['title'])||trim(strval($intro['title']))===''||!is_numeric($intro['volume']??null)||$intro['volume']<0||$intro['volume']>100||
     !in_array(strval($intro['trackId']??''),array_merge([''],array_column($db->query('SELECT id FROM media_tracks')->fetchAll(),'id')),true))throw new DomainException('Invalid intro settings.');
  $theme=['accent'=>preg_match('/^#[0-9a-fA-F]{6}$/D',strval($theme['accent']??''))?$theme['accent']:'#f6cb74',
    'background'=>preg_match('/^#[0-9a-fA-F]{6}$/D',strval($theme['background']??''))?$theme['background']:'#0d1423'];
  $intro=['title'=>trim(strval($intro['title'])),'subtitle'=>trim(strval($intro['subtitle']??'')),'trackId'=>strval($intro['trackId']??''),'volume'=>max(0,min(100,(int)$intro['volume'])),'announcement'=>($intro['announcement']??true)!==false];
  $changesConfig=array_key_exists('config',$in);
  if($game==='spin-wheel'&&$changesConfig){
   $q=$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='prize_packages'");
   if((int)$q->fetchColumn()===1)throw new DomainException('Edit prize values within their package tab instead.',409);
  }
  $config=$module?$module->validateSettings($in['config']??$old['config']):$old['config'];
  $db->beginTransaction();try{
    $lock=$db->prepare('SELECT game_id FROM game_settings WHERE game_id=? FOR UPDATE');$lock->execute([$game]);$lock->fetch();
    $activeQuery=$db->prepare("SELECT * FROM game_sessions WHERE game_id=? AND status IN ('active','paused') ORDER BY created_at DESC,id DESC LIMIT 1 FOR UPDATE");
    $activeQuery->execute([$game]);$active=$activeQuery->fetch();
    if($active && $module && $changesConfig){
      $state=stateFromRow($active);$module->applySettings($state,$config);saveState($db,$state);
    }
    $q=$db->prepare('UPDATE game_settings SET config_json=?,intro_json=?,theme_json=? WHERE game_id=?');
    $q->execute([json_encode($config),json_encode($intro),json_encode($theme),$game]);audit($db,'settings.save');$db->commit();
  }catch(Throwable $e){$db->rollBack();throw $e;}
  success(['settings'=>['config'=>$config,'intro'=>$intro,'theme'=>$theme]]);
 }
 if($action==='import_legacy'){
  $source=strval($in['sourceGameId']??'');$legacy=$in['legacy']??null;
  if(!is_array($legacy)||$source===''||strlen($source)>160)throw new DomainException('Invalid legacy game export.');
  $fingerprint=hash('sha256',$source);$s=GameEngine::importLegacy($legacy,bin2hex(random_bytes(16)));
  $db->beginTransaction();try{
   $q=$db->prepare('SELECT session_id FROM legacy_imports WHERE fingerprint=? FOR UPDATE');$q->execute([$fingerprint]);
   if($q->fetch())throw new DomainException('This legacy game has already been imported.');
   $q=$db->prepare('INSERT INTO game_sessions(id,game_id,status,version,state_json,viewer_token,ended_at) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP())');
   $q->execute([$s['id'],'spin-wheel',$s['status'],$s['version'],json_encode($s,JSON_THROW_ON_ERROR),token()]);
   $q=$db->prepare('INSERT INTO game_claims(id,session_id,prize_id,tier,prize_name,cents,created_at) VALUES (?,?,?,?,?,?,?)');
   foreach($s['claims'] as $c)$q->execute([$c['id'],$s['id'],$c['prizeId'],$c['tier'],$c['name'],$c['cents'],gmdate('Y-m-d H:i:s',strtotime($c['at']))]);
   $q=$db->prepare('INSERT INTO legacy_imports(fingerprint,session_id) VALUES (?,?)');$q->execute([$fingerprint,$s['id']]);
   audit($db,'session.legacy-import',$s['id'],['claims'=>count($s['claims'])]);$db->commit();
  }catch(Throwable $e){$db->rollBack();throw $e;}
  success(['sessionId'=>$s['id'],'claims'=>count($s['claims'])],201);
 }
 if($action==='start'){
  $game=strval($in['game']??'spin-wheel');
  if($game==='spin-wheel')PrizeDecision::requireReady($db);
  $db->beginTransaction();try{
   GameSessionLifecycle::lockConsole($db);
   $config=GameSessionLifecycle::lockGame($db,$game);
   GameSessionLifecycle::assertConsoleVacant($db);
   GameSessionLifecycle::assertVacant($db,$game);
   if($game==='spin-wheel'){
    PrizePackages::requireReady($db);
    PrizePackages::lockGame($db,$game);
    $pkg=PrizePackages::currentConfig($db,$game);
    $config=$pkg['config'];
   }
   $s=GameRegistry::module($game)->createSession($config,bin2hex(random_bytes(16)));
   if(isset($pkg))$s['prizePackage']=['id'=>$pkg['id'],'name'=>$pkg['name'],'phoneCameraEnabled'=>$pkg['phoneCameraEnabled']];
   GameFoundation::checkSession(GameRegistry::module($game),$s,(string)($s['id']??''));
   $viewToken=token();$q=$db->prepare('INSERT INTO game_sessions(id,game_id,status,version,state_json,viewer_token) VALUES (?,?,?,?,?,?)');
   $q->execute([$s['id'],$game,$s['status'],$s['version'],json_encode($s,JSON_THROW_ON_ERROR),$viewToken]);
   if($game==='spin-wheel'&&($pkg['phoneCameraEnabled']??false)){
    $recordingId=RecordingSessions::autoSpinWheel($db,(int)$_SESSION['admin_id'],(string)$s['id'],(string)$pkg['name']);
    audit($db,'recording.game-auto-created',$s['id'],['recordingId'=>$recordingId,'packageId'=>$pkg['id']]);
   }
   audit($db,'session.start',$s['id'],['game'=>$game,'packageId'=>$pkg['id']??null]);$db->commit();
  }catch(Throwable $e){$db->rollBack();throw $e;}
  success(['data'=>['session'=>$s,'viewerToken'=>$viewToken,'view'=>GameRegistry::module($s['game'])->publicView($s)]],201);
 }
 if($action==='delete_session'){
  require_once __DIR__.'/../lib/GameSessionDeletion.php';
  $id=(string)($in['id']??'');$version=(int)($in['expectedVersion']??-1);
  if(!GameSessionDeletion::confirm($id,(string)($in['confirmation']??'')))throw new DomainException('Type DELETE and the complete session ID to confirm.',422);
  $db->beginTransaction();
  try {
   $row=sessionRow($db,$id,true);
   if($version!==(int)$row['version'])throw new DomainException('Session changed. Refresh and try again.',409);
   $q=$db->prepare('SELECT id,status FROM montage_renders WHERE session_id=? FOR UPDATE');
   $q->execute([$id]);$renders=$q->fetchAll();
   foreach($renders as $render)if(in_array($render['status'],['running','queued'],true))
    throw new DomainException('Finish or cancel the pending export before deleting.',409);
   $q=$db->prepare('SELECT id FROM claim_media WHERE session_id=? FOR UPDATE');$q->execute([$id]);
   $mediaIds=$q->fetchAll(PDO::FETCH_COLUMN);$renderIds=array_column($renders,'id');
   $q=$db->prepare('DELETE rc FROM claim_proof_revision_clips rc INNER JOIN claim_proof_revisions rev ON rev.id=rc.revision_id WHERE rev.session_id=?');
   $q->execute([$id]);
   $q=$db->prepare('DELETE FROM game_sessions WHERE id=? AND version=?');$q->execute([$id,$version]);
   if($q->rowCount()!==1)throw new DomainException('Session already changed or removed.',409);
   audit($db,'session.deleted',$id,['game'=>$row['game_id'],'clips'=>count($mediaIds),'exports'=>count($renderIds)]);
   $db->commit();
  }catch(Throwable $e){$db->rollBack();throw $e;}
  $removed=0;$warnings=0;
  foreach([['claim-media',$mediaIds,'.bin'],['renders',$renderIds,'.mp4']] as [$folder,$ids,$suffix]){
   foreach($ids as $fileId){
    if(!is_string($fileId)||!preg_match('/^[a-f0-9]{32}$/D',$fileId))continue;
    $file=LUCKY_ROOT.'/storage/'.$folder.'/'.$fileId.$suffix;
    if(is_file($file)){if(@unlink($file))$removed++;else $warnings++;}
   }
  }
  if($warnings)error_log('Session cleanup: '.$warnings.' private media files could not be removed.');
  success(['deleted'=>true,'removedFiles'=>$removed,'cleanupWarnings'=>$warnings]);
 }
 if($action==='plugin_capability'){
  $id=(string)($in['id']??'');$operation=$in['operation']??null;
  if(!is_string($operation)||strlen($operation)>40)
   throw new DomainException('Invalid SDK operation.',400);
  $args=GamePluginCapabilities::validateArgs($operation,$in['args']??[]);
  $isWrite=GamePluginCapabilities::changesState($operation)||$operation==='session.save';
  $key=$in['idempotencyKey']??null;
  if($isWrite&&(!is_string($key)||!preg_match('/^[A-Za-z0-9_-]{12,80}$/D',$key)))
   throw new DomainException('SDK writes require a unique request ID.',400);
  // Create the private namespace table before beginning the transaction.
  // This is deliberately separate from any game-controlled schema or SQL.
  if(str_starts_with($operation,'storage.'))GamePluginCapabilities::ensureSchema($db);
  $db->beginTransaction();
  try{
   $lookup=sessionRow($db,$id);
   $game=(string)$lookup['game_id'];
   if(!GameRegistry::module($game) instanceof PackageGameModule)
    throw new DomainException('SDK capabilities are only available to installed game packages.',403);
   GameSessionLifecycle::lockGame($db,$game);
   $row=sessionRow($db,$id,true);
   $state=stateFromRow($row);
   if($isWrite){
    $q=$db->prepare('SELECT payload_json FROM game_events WHERE session_id=? AND idempotency_key=?');
    $q->execute([$id,$key]);
    if($old=$q->fetch()){$out=json_decode($old['payload_json'],true,128,JSON_THROW_ON_ERROR);
      $db->commit();success(['result'=>$out,'repeat'=>true]);}
    if(!isset($in['expectedVersion'])||!is_int($in['expectedVersion'])||
       $in['expectedVersion']!==(int)$row['version'])
     throw new DomainException('Game state changed. Refresh before trying again.',409);
   }
   if($operation==='session.save'){
    GamePluginCapabilities::authorize($game,(string)($state['packageVersion']??''),$operation);
    $event=GameSessionLifecycle::transition($state,'save');
    $result=['operation'=>$operation,'value'=>['status'=>'saved'],'version'=>$state['version']];
   }else $result=GamePluginCapabilities::execute($db,$state,$operation,$args);
   if($isWrite){
    saveState($db,$state);
    $out=['capability'=>$result,'view'=>GameRegistry::module($game)->publicView($state)];
    $q=$db->prepare('INSERT INTO game_events(session_id,idempotency_key,event_type,payload_json) VALUES (?,?,?,?)');
    $q->execute([$id,$key,'sdk',json_encode($out,JSON_THROW_ON_ERROR)]);
    audit($db,'game.sdk_action',$id,['game'=>$game,'operation'=>$operation]);
   }else $out=['capability'=>$result,'view'=>GameRegistry::module($game)->publicView($state)];
   $db->commit();
  }catch(Throwable $e){$db->rollBack();throw $e;}
  success(['result'=>$out,'repeat'=>false]);
 }
 if(in_array($action,['save_session','resume_saved','finish_saved'],true)){
  $id=strval($in['id']??'');$key=strval($in['idempotencyKey']??'');
  if(!preg_match('/^[A-Za-z0-9_-]{12,80}$/D',$key))throw new DomainException('Missing unique request key.',400);
  $db->beginTransaction();try{
   $lookup=sessionRow($db,$id);$game=(string)$lookup['game_id'];
   if($action==='resume_saved')GameSessionLifecycle::lockConsole($db);
   GameSessionLifecycle::lockGame($db,$game);
   $row=sessionRow($db,$id,true);
   $q=$db->prepare('SELECT payload_json FROM game_events WHERE session_id=? AND idempotency_key=?');$q->execute([$id,$key]);
   if($previous=$q->fetch()){$out=json_decode($previous['payload_json'],true,512,JSON_THROW_ON_ERROR);$db->commit();success(['result'=>$out,'repeat'=>true]);}
   $state=stateFromRow($row);
   if($action==='resume_saved'){GameSessionLifecycle::assertConsoleVacant($db,$id);GameSessionLifecycle::assertVacant($db,$game,$id);}
   if($game==='spin-wheel' && (SpinReveal::pending($state)||PrizeDecision::pending($db,$id)))
    throw new DomainException('Resolve the current prize reveal or decision before changing the session.',409);
   $event=GameSessionLifecycle::transition($state,$action==='save_session'?'save':$action);
   saveState($db,$state);
   $out=['event'=>$event,'view'=>GameRegistry::module($state['game'])->publicView($state)];
   $q=$db->prepare('INSERT INTO game_events(session_id,idempotency_key,event_type,payload_json) VALUES (?,?,?,?)');
   $q->execute([$id,$key,$event['type'],json_encode($out,JSON_THROW_ON_ERROR)]);
   audit($db,'session.'.$event['type'],$id,['game'=>$game]);$db->commit();
  }catch(Throwable $e){$db->rollBack();throw $e;}
  success(['result'=>$out,'repeat'=>false]);
 }
 if(in_array($action,['spin','end','pause','resume','game_action'],true)){
  $verb=$action==='game_action'?strval($in['verb']??''):$action;
  if(!preg_match('/^[a-z][a-z0-9_-]{0,39}$/D',$verb)
     ||in_array($verb,['start','save_session','resume_saved','finish_saved','delete_session'],true))
   throw new DomainException('Invalid gameplay action.',400);
  $payload=$action==='game_action'?($in['payload']??[]):[];
  if(!is_array($payload)||count($payload)>32||strlen(json_encode($payload,JSON_THROW_ON_ERROR))>16384)
   throw new DomainException('Gameplay payload is too large or malformed.',400);
  $id=strval($in['id']??'');$key=strval($in['idempotencyKey']??'');
  if(!preg_match('/^[A-Za-z0-9_-]{12,80}$/D',$key))throw new DomainException('Missing unique request key.',400);
  $db->beginTransaction();try{
   $r=sessionRow($db,$id,true);
   $q=$db->prepare('SELECT payload_json FROM game_events WHERE session_id=? AND idempotency_key=?');$q->execute([$id,$key]);
   if($old=$q->fetch()){$out=json_decode($old['payload_json'],true,512,JSON_THROW_ON_ERROR);$db->commit();success(['result'=>$out,'repeat'=>true]);}
   $s=stateFromRow($r);$module=GameRegistry::module($s['game']);
   if($s['game']==='spin-wheel'&&$verb==='spin'){ControllerGame::spinReady($s);PrizeDecision::assertReady($db,$id);}
   $beforeVersion=(int)($s['version']??0);
   $result=$module->action($s,$verb,$payload);
   $type=(string)($result['type']??'');
   if(!preg_match('/^[a-z][a-z0-9_-]{0,39}$/D',$type)||($s['version']??0)!==$beforeVersion+1)
    throw new DomainException('Game module returned an invalid action or state version.',422);
   saveState($db,$s);
   if($type==='award'&&$s['game']==='spin-wheel'){
      $c=$result['claim'];$q=$db->prepare('INSERT INTO game_claims(id,session_id,prize_id,tier,prize_name,cents,created_at) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP())');
      $q->execute([$c['id'],$id,$c['prizeId'],$c['tier'],$c['name'],$c['cents']]);
      PrizeDecision::create($db,$id,$c['id']);
   }
   $out=['event'=>$result,'view'=>GameRegistry::module($s['game'])->publicView($s)];
   $q=$db->prepare('INSERT INTO game_events(session_id,idempotency_key,event_type,payload_json) VALUES (?,?,?,?)');
   $q->execute([$id,$key,$type,json_encode($out,JSON_THROW_ON_ERROR)]);
   audit($db,'game.'.$verb,$id,['game'=>$s['game'],'result'=>$type]);$db->commit();
  }catch(Throwable $e){$db->rollBack();throw $e;}
  success(['result'=>$out,'repeat'=>false]);
 }
 throw new DomainException('Unknown operation.',404);
}catch(Throwable $e){error_log('Lucky API: '.get_class($e).' '.$e->getMessage());errorOut($e);}
