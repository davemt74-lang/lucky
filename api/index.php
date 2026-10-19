<?php
declare(strict_types=1);
require __DIR__.'/../lib/Bootstrap.php';require_once __DIR__.'/../lib/ProductCatalog.php';require_once __DIR__.'/../lib/ControllerGame.php';headerSecurity();
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
 if($action==='prize_image'&&$method==='GET')streamPrizeImage($db,strval($_GET['id']??''));
 if($action==='track'&&in_array($method,['GET','HEAD'],true))streamTrack($db,strval($_GET['id']??''));
 if($method==='GET'){
  if($action==='health')success(['service'=>'Lucky Game Hub','installed'=>true,'database'=>'connected','build'=>'5P']);
  if($action==='manifest')success(['games'=>GameRegistry::manifest()]);
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
   $game=strval($_GET['game']??'spin-wheel');$cfg=setting($db,$game);
   if(!isAdmin())$cfg=['intro'=>$cfg['intro'],'theme'=>$cfg['theme']];
   success(['settings'=>$cfg]);
  }
  if($action==='tracks'){
   $list=$db->query('SELECT id,title,kind,source,mime,bytes FROM media_tracks ORDER BY created_at DESC')->fetchAll();
   foreach($list as &$t){$t['src']=$t['kind']==='upload'?'api/index.php?action=track&id='.$t['id']:$t['source'];unset($t['source']);}unset($t);
   success(['tracks'=>$list]);
  }
  if($action==='sessions'){
   requireAdmin();$q=$db->query('SELECT id,game_id,status,version,created_at,updated_at,ended_at FROM game_sessions ORDER BY created_at DESC,id DESC LIMIT 100');success(['sessions'=>$q->fetchAll()]);
  }
  if($action==='session')success(getSessionView($db,strval($_GET['id']??'')));
  if($action==='watch')success(['view'=>getSessionView($db,strval($_GET['id']??''),true,strval($_GET['token']??''))]);
  if($action==='last'){
   requireAdmin();$q=$db->query("SELECT id FROM game_sessions WHERE game_id='spin-wheel' ORDER BY created_at DESC,id DESC LIMIT 1");
   $id=$q->fetchColumn();success(['data'=>$id?getSessionView($db,$id):null]);
  }
  if($action==='history'){
   requireAdmin();$q=$db->query("SELECT id,created_at,ended_at,state_json FROM game_sessions WHERE status='ended' ORDER BY created_at DESC,id DESC LIMIT 100");$rows=[];
   foreach($q as $r){$s=json_decode($r['state_json'],true);$rows[]=['id'=>$r['id'],'createdAt'=>$r['created_at'],'endedAt'=>$r['ended_at'],'spins'=>$s['spinCount']??0,'claims'=>count($s['claims']??[]),'awardedCents'=>array_sum(array_column($s['claims']??[],'cents'))];}
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
  $game=strval($in['game']??'spin-wheel');if(!in_array($game,['spin-wheel','game-2'],true))throw new DomainException('Unknown game.');
  $old=setting($db,$game);$intro=$in['intro']??$old['intro'];$theme=$in['theme']??$old['theme'];
  if(!is_array($intro)||!is_array($theme)||strlen(strval($intro['title']??''))>100||strlen(strval($intro['subtitle']??''))>200||
     !isset($intro['title'])||trim(strval($intro['title']))===''||!is_numeric($intro['volume']??null)||$intro['volume']<0||$intro['volume']>100||
     !in_array(strval($intro['trackId']??''),array_merge([''],array_column($db->query('SELECT id FROM media_tracks')->fetchAll(),'id')),true))throw new DomainException('Invalid intro settings.');
  $theme=['accent'=>preg_match('/^#[0-9a-fA-F]{6}$/D',strval($theme['accent']??''))?$theme['accent']:'#f6cb74',
    'background'=>preg_match('/^#[0-9a-fA-F]{6}$/D',strval($theme['background']??''))?$theme['background']:'#0d1423'];
  $intro=['title'=>trim(strval($intro['title'])),'subtitle'=>trim(strval($intro['subtitle']??'')),'trackId'=>strval($intro['trackId']??''),'volume'=>max(0,min(100,(int)$intro['volume'])),'announcement'=>($intro['announcement']??true)!==false];
  $config=$game==='spin-wheel'?GameRegistry::module($game)->validateSettings($in['config']??$old['config']):$old['config'];
  $db->beginTransaction();try{
    $db->query("SELECT game_id FROM game_settings WHERE game_id='spin-wheel' FOR UPDATE")->fetch();
    $active=$db->query("SELECT * FROM game_sessions WHERE game_id='spin-wheel' AND status IN ('active','paused') ORDER BY created_at DESC,id DESC LIMIT 1 FOR UPDATE")->fetch();
    if($active && $game==='spin-wheel'){
      $s=stateFromRow($active);GameRegistry::module($game)->applySettings($s,$config);saveState($db,$s);
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
  $game=strval($in['game']??'spin-wheel');if($game!=='spin-wheel')throw new DomainException('Game not available.');
  $db->beginTransaction();try{
   $db->query("SELECT game_id FROM game_settings WHERE game_id='spin-wheel' FOR UPDATE")->fetch();
   $q=$db->query("SELECT * FROM game_sessions WHERE game_id='spin-wheel' AND status IN ('active','paused') ORDER BY created_at DESC,id DESC LIMIT 1 FOR UPDATE");
   if($existing=$q->fetch())throw new DomainException('An active session already exists. End it first.');
   $settings=setting($db,'spin-wheel');$s=GameRegistry::module($game)->createSession($settings['config'],bin2hex(random_bytes(16)));
   $viewToken=token();$q=$db->prepare('INSERT INTO game_sessions(id,game_id,status,version,state_json,viewer_token) VALUES (?,?,?,?,?,?)');
   $q->execute([$s['id'],$game,$s['status'],$s['version'],json_encode($s,JSON_THROW_ON_ERROR),$viewToken]);
   audit($db,'session.start',$s['id']);$db->commit();
  }catch(Throwable $e){$db->rollBack();throw $e;}
  success(['data'=>['session'=>$s,'viewerToken'=>$viewToken,'view'=>GameRegistry::module($s['game'])->publicView($s)]],201);
 }
 if(in_array($action,['spin','end','pause','resume'],true)){
  $id=strval($in['id']??'');$key=strval($in['idempotencyKey']??'');
  if(!preg_match('/^[A-Za-z0-9_-]{12,80}$/D',$key))throw new DomainException('Missing unique request key.',400);
  $db->beginTransaction();try{
   $r=sessionRow($db,$id,true);
   $q=$db->prepare('SELECT payload_json FROM game_events WHERE session_id=? AND idempotency_key=?');$q->execute([$id,$key]);
   if($old=$q->fetch()){$out=json_decode($old['payload_json'],true,512,JSON_THROW_ON_ERROR);$db->commit();success(['result'=>$out,'repeat'=>true]);}
   $s=stateFromRow($r);$module=GameRegistry::module($s['game']);
   if($action==='spin')ControllerGame::spinReady($s);
   $result=$module->action($s,$action);$type=$result['type'];
   saveState($db,$s);
   if($type==='award'){
      $c=$result['claim'];$q=$db->prepare('INSERT INTO game_claims(id,session_id,prize_id,tier,prize_name,cents,created_at) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP())');
      $q->execute([$c['id'],$id,$c['prizeId'],$c['tier'],$c['name'],$c['cents']]);
   }
   $out=['event'=>$result,'view'=>GameRegistry::module($s['game'])->publicView($s)];
   $q=$db->prepare('INSERT INTO game_events(session_id,idempotency_key,event_type,payload_json) VALUES (?,?,?,?)');
   $q->execute([$id,$key,$type,json_encode($out,JSON_THROW_ON_ERROR)]);
   audit($db,$action==='spin'?'game.spin':'session.end',$id,['result'=>$type]);$db->commit();
  }catch(Throwable $e){$db->rollBack();throw $e;}
  success(['result'=>$out,'repeat'=>false]);
 }
 throw new DomainException('Unknown operation.',404);
}catch(Throwable $e){error_log('Lucky API: '.get_class($e).' '.$e->getMessage());errorOut($e);}
