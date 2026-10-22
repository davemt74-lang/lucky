<?php
declare(strict_types=1);
require __DIR__.'/../lib/Bootstrap.php';
require __DIR__.'/../lib/ControllerPairing.php';
require __DIR__.'/../lib/ControllerGame.php';require_once __DIR__.'/../lib/PackageControllerBridge.php';require_once __DIR__.'/../lib/GameSessionLifecycle.php';require_once __DIR__.'/../lib/PrizeDecision.php';
require_once __DIR__.'/../lib/SchemaGuard.php';
require_once __DIR__.'/../lib/ControllerRate.php';
require_once __DIR__.'/../lib/PhoneGameDashboard.php';
require_once __DIR__.'/../lib/MoviePreview.php';
headerSecurity();
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
try {
 if(!installed())throw new DomainException('Complete platform installation first.',503);
 // Bearer credentials and pairing URLs must not traverse cleartext HTTP.
 $secure=(!empty($_SERVER['HTTPS'])&&strtolower((string)$_SERVER['HTTPS'])!=='off')
   ||strtolower((string)($_SERVER['REQUEST_SCHEME']??''))==='https';
 $local=in_array(strtolower(preg_replace('/:\d+$/','',(string)($_SERVER['HTTP_HOST']??''))),['localhost','127.0.0.1','[::1]'],true);
 if(!$secure&&!$local)throw new DomainException('Phone pairing requires HTTPS.',403);

 if(($_SERVER['REQUEST_METHOD']??'')!=='POST')throw new DomainException('POST required.',405);
 if(!str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE']??'')),'application/json'))throw new DomainException('JSON required.',415);
 // Prevent a controller bearer token being used by another website in a browser.
 $origin=(string)($_SERVER['HTTP_ORIGIN']??'');
 if($origin!==''){
  $originHost=parse_url($origin,PHP_URL_HOST);$host=preg_replace('/:\d+$/','',(string)($_SERVER['HTTP_HOST']??''));
  if(!$originHost||strcasecmp((string)$originHost,$host)!==0)throw new DomainException('Origin not permitted.',403);
 }
 $action=strval($_GET['action']??'');$in=jsonInput();$db=db();
 $phoneActions=['claim','claim_display','status','command','disconnect','package_status','package_command','dashboard_games','dashboard_launch','movie_command'];
 if(in_array($action,$phoneActions,true)){
  if($action==='claim'){
   $id=strval($in['id']??'');$key=strval($in['key']??'');$name=ControllerPairing::label($in['name']??'');
   ControllerPairing::checkId($id);$hash=ControllerPairing::tokenHash($key);
   $db->beginTransaction();try{
    $q=$db->prepare('SELECT * FROM controller_pairs WHERE id=? FOR UPDATE');$q->execute([$id]);$p=$q->fetch();
    if(!$p||!hash_equals($p['invite_hash'],$hash)||$p['status']!=='inviting'||ControllerPairing::status($p)!=='inviting')throw new DomainException('This QR invitation expired or was already used.',403);
    $token=bin2hex(random_bytes(32));
    $q=$db->prepare("UPDATE controller_pairs SET controller_hash=?,device_name=?,status='pending' WHERE id=? AND status='inviting'");
    $q->execute([ControllerPairing::tokenHash($token),$name,$id]);
    audit($db,'controller.request',null,['pairId'=>$id]);$db->commit();
   }catch(Throwable $e){$db->rollBack();throw $e;}
   success(['id'=>$id,'controllerToken'=>$token,'status'=>'pending']);
  }
  if($action==='claim_display'){
   $id=strval($in['session']??'');$viewer=strval($in['viewer']??'');$name=ControllerPairing::label($in['name']??'');
   ControllerPairing::checkId($id);
   if(!preg_match('/^[a-f0-9]{48}$/D',$viewer))throw new DomainException('Invalid TV invitation.',403);
   $db->beginTransaction();try{
    $game=sessionRow($db,$id,true);
    if(!hash_equals($game['viewer_token'],$viewer)||!in_array($game['status'],['active','paused'],true))throw new DomainException('This TV invitation is no longer active.',403);
    $q=$db->prepare("SELECT admin_id FROM audit_log WHERE session_id=? AND event='session.start' ORDER BY id ASC LIMIT 1");$q->execute([$id]);$adminId=(int)$q->fetchColumn();
    if($adminId<=0)throw new DomainException('Game host unavailable.',403);
    $q=$db->prepare("SELECT COUNT(*) FROM controller_pairs WHERE host_admin_id=? AND ((status IN ('inviting','pending') AND invite_expires_at>UTC_TIMESTAMP()) OR (status='paired' AND paired_expires_at>UTC_TIMESTAMP()))");$q->execute([$adminId]);
    if((int)$q->fetchColumn()>=8)throw new DomainException('The host has reached the phone controller limit.',429);
    $newId=bin2hex(random_bytes(16));$token=bin2hex(random_bytes(32));$secret=bin2hex(random_bytes(32));
    $q=$db->prepare("INSERT INTO controller_pairs (id,host_admin_id,invite_hash,controller_hash,device_name,status,invite_expires_at) VALUES (?,?,?,?,?,'pending',UTC_TIMESTAMP()+INTERVAL 5 MINUTE)");
    $q->execute([$newId,$adminId,ControllerPairing::tokenHash($secret),ControllerPairing::tokenHash($token),$name]);
    audit($db,'controller.tv-request',$id,['pairId'=>$newId]);$db->commit();
   }catch(Throwable $e){$db->rollBack();throw $e;}
   success(['id'=>$newId,'controllerToken'=>$token,'status'=>'pending']);
  }
  $id=strval($in['id']??'');$token=(string)($_SERVER['HTTP_X_CONTROLLER_TOKEN']??'');
  $p=ControllerPairing::authenticate($db,$id,$token,in_array($action,['command','select_package','package_command','dashboard_games','dashboard_launch','movie_command'],true));
  $status=ControllerPairing::status($p);
  if(in_array($action,['package_status','package_command'],true)){
   $session=(string)($in['session']??'');$viewer=(string)($in['viewer']??'');
   ControllerPairing::checkId($session);
   if($status!=='paired'){
    if($action==='package_status'&&$status==='pending')success(['status'=>'pending','game'=>null]);
    throw new DomainException('Phone is awaiting approval or has expired.',403);
   }
   $pre=sessionRow($db,$session);$module=GameRegistry::module((string)$pre['game_id']);
   if(!PackageControllerBridge::isPackage($module))
    throw new DomainException('This game has no packaged phone controller.',403);
   $q=$db->prepare("SELECT admin_id FROM audit_log WHERE session_id=? AND event='session.start' ORDER BY id ASC LIMIT 1");
   $q->execute([$session]);$owner=(int)$q->fetchColumn();
   PackageControllerBridge::bound($p,$pre,$viewer,$owner);
   if($action==='package_status')
    success(['status'=>'paired','game'=>$module->publicView(stateFromRow($pre)),'phone'=>true]);
   $cmd=PackageControllerBridge::action($in['verb']??null,$in['payload']??[], $in['confirm']??false);
   $nonce=ControllerPairing::nonce($in['nonce']??null);
   $db->beginTransaction();
   try{
    // Existing wheel controller transactions lock pair before game settings.
    // Keep the same ordering so two games sharing an approved phone cannot
    // deadlock on reversed pair/game locks.
    $q=$db->prepare('SELECT * FROM controller_pairs WHERE id=? FOR UPDATE');$q->execute([$id]);$locked=$q->fetch();
    if(!$locked||!hash_equals((string)($locked['controller_hash']??''),ControllerPairing::tokenHash($token))||
       ControllerPairing::status($locked)!=='paired')
     throw new DomainException('The phone connection was revoked or expired.',403);
    GameSessionLifecycle::lockGame($db,(string)$pre['game_id']);
    $q=$db->prepare('SELECT id FROM controller_commands WHERE pair_id=? AND client_nonce=?');$q->execute([$id,$nonce]);
    if($q->fetch()){
     $current=sessionRow($db,$session);$db->commit();
     success(['applied'=>true,'repeat'=>true,'game'=>$module->publicView(stateFromRow($current))]);
    }
    $q=$db->prepare('SELECT COUNT(*) FROM controller_commands WHERE pair_id=? AND created_at>=UTC_TIMESTAMP()-INTERVAL 1 SECOND');
    $q->execute([$id]);$second=(int)$q->fetchColumn();
    $q=$db->prepare('SELECT COUNT(*) FROM controller_commands WHERE pair_id=? AND created_at>=UTC_TIMESTAMP()-INTERVAL 1 MINUTE');
    $q->execute([$id]);$minute=(int)$q->fetchColumn();ControllerRate::check($second,$minute,$cmd['verb']);
    $row=sessionRow($db,$session,true);
    PackageControllerBridge::bound($locked,$row,$viewer,$owner);
    if(!in_array($row['status'],['active','paused'],true))
     throw new DomainException('This game has already ended or been saved.',409);
    // The duplicate nonce branch above returns success before this check.
    // A delayed first delivery must never act on an unexpectedly newer game.
    PackageControllerBridge::assertFreshVersion($in['expectedVersion']??null,(int)$row['version']);
    $state=stateFromRow($row);$event=$module->action($state,$cmd['verb'],$cmd['payload']);
    saveState($db,$state);
    $q=$db->prepare('INSERT INTO game_events(session_id,idempotency_key,event_type,payload_json) VALUES (?,?,?,?)');
    $q->execute([$session,PackageControllerBridge::key($id,$nonce),$event['type'],
     json_encode(['event'=>$event,'source'=>'paired-phone'],JSON_THROW_ON_ERROR)]);
    $q=$db->prepare('INSERT INTO controller_commands(pair_id,client_nonce,command,delivered_at) VALUES (?,?,?,UTC_TIMESTAMP())');
    $q->execute([$id,$nonce,$cmd['verb']]);
    audit($db,'game.package_phone_command',$session,['pairId'=>$id,'verb'=>$cmd['verb']]);
    $view=$module->publicView($state);$db->commit();
   }catch(Throwable $e){$db->rollBack();throw $e;}
   success(['applied'=>true,'repeat'=>false,'game'=>$view]);
  }
  if($action==='dashboard_games'){
   if($status!=='paired')throw new DomainException('Approve the phone before browsing games.',403);
   success(['games'=>PhoneGameDashboard::catalog()]);
  }
  if($action==='dashboard_launch'){
   if($status!=='paired')throw new DomainException('Approve the phone before launching games.',403);
   if(($in['confirm']??null)!==true)throw new DomainException('Confirm the selected game on your phone.',403);
   $game=GameCatalog::slug($in['game']??null);
   $nonce=ControllerPairing::nonce($in['nonce']??null);
   $db->beginTransaction();
   try{
    $q=$db->prepare('SELECT * FROM controller_pairs WHERE id=? FOR UPDATE');
    $q->execute([$id]);$locked=$q->fetch(PDO::FETCH_ASSOC);
    if(!$locked||!hash_equals((string)$locked['controller_hash'],ControllerPairing::tokenHash($token))||
        ControllerPairing::status($locked)!=='paired')
     throw new DomainException('Phone approval was revoked or expired.',403);
    $out=PhoneGameDashboard::launch($db,$game,$id,$nonce,(int)$locked['host_admin_id']);
    $db->commit();
   }catch(Throwable $e){$db->rollBack();throw $e;}
   success(['launch'=>$out]);
  }
  if($action==='movie_command'){
   if($status!=='paired')throw new DomainException('Phone approval required.',403);
   $verb=(string)($in['verb']??'');
   if(!in_array($verb,['play','pause','stop'],true))throw new DomainException('Unknown movie command.',422);
   $nonce=ControllerPairing::nonce($in['nonce']??null);
   $db->beginTransaction();try{
    $q=$db->prepare('SELECT * FROM controller_pairs WHERE id=? FOR UPDATE');$q->execute([$id]);$locked=$q->fetch();
    if(!$locked||!hash_equals((string)$locked['controller_hash'],ControllerPairing::tokenHash($token))||ControllerPairing::status($locked)!=='paired')throw new DomainException('Phone pairing no longer approved.',403);
    MoviePreview::requireReady($db);
    $owner=(int)$locked['host_admin_id'];
    $q=$db->prepare('SELECT command FROM controller_commands WHERE pair_id=? AND client_nonce=?');$q->execute([$id,$nonce]);$prior=$q->fetchColumn();
    if($prior!==false){if($prior!=='movie-'.$verb)throw new DomainException('Command identifier already used.',409);$out=MoviePreview::public(MoviePreview::row($db,$owner));$db->commit();success(['movie'=>$out,'repeat'=>true]);}
    $q=$db->prepare('SELECT COUNT(*) FROM controller_commands WHERE pair_id=? AND created_at>=UTC_TIMESTAMP()-INTERVAL 1 SECOND');$q->execute([$id]);$second=(int)$q->fetchColumn();
    $q=$db->prepare('SELECT COUNT(*) FROM controller_commands WHERE pair_id=? AND created_at>=UTC_TIMESTAMP()-INTERVAL 1 MINUTE');$q->execute([$id]);ControllerRate::check($second,(int)$q->fetchColumn(),'movie-control');
    $session=(string)($in['session']??'');ControllerPairing::checkId($session);
    $out=MoviePreview::control($db,$owner,$session,$verb);
    $q=$db->prepare('INSERT INTO controller_commands(pair_id,client_nonce,command,delivered_at) VALUES (?,?,?,UTC_TIMESTAMP())');$q->execute([$id,$nonce,'movie-'.$verb]);
    $db->commit();
   }catch(Throwable $e){$db->rollBack();throw $e;}
   success(['movie'=>$out,'repeat'=>false]);
  }
  if($action==='status'){
   $commandId=(int)($in['commandId']??0);$delivery=null;
   if($commandId>0){
    SchemaGuard::require($db,['controller_ui_events'],'Phone controller acknowledgements');
    $q=$db->prepare('SELECT u.acknowledged_at,u.failure_message,c.created_at FROM controller_commands c JOIN controller_ui_events u ON u.command_id=c.id WHERE c.id=? AND c.pair_id=?');
    $q->execute([$commandId,$id]);if($r=$q->fetch())$delivery=$r['acknowledged_at']?'executed':($r['failure_message']?'failed':(strtotime($r['created_at'].' UTC')<time()-15?'expired':'pending'));
    $reason=$r['failure_message']??null;
   }
   $proof=null;
   if($status==='paired'){
    if(!SchemaGuard::missing($db,['controller_proof_state'])){
    $current=ControllerGame::snapshot($db);$legacyProof=false;
    try{
     $q=$db->prepare("SELECT s.session_id,s.claim_id,s.is_open,s.review_count,s.consent_ack,s.capture_phase FROM controller_proof_state s WHERE s.host_admin_id=? AND s.is_open=1 AND (s.updated_at>UTC_TIMESTAMP()-INTERVAL 12 SECOND OR (s.capture_phase='opening' AND s.updated_at>UTC_TIMESTAMP()-INTERVAL 45 SECOND)) ORDER BY s.updated_at DESC LIMIT 1");
     $q->execute([(int)$p['host_admin_id']]);$proof=$q->fetch()?:null;
    }catch(PDOException $e){
     if($e->getCode()!=='42S22')throw $e;
     // Old installed schema: keep pairing status healthy until upgrade.php runs.
     $legacyProof=true;
     $q=$db->prepare("SELECT s.session_id,s.claim_id,s.is_open,s.review_count FROM controller_proof_state s WHERE s.host_admin_id=? AND s.is_open=1 AND s.updated_at>UTC_TIMESTAMP()-INTERVAL 12 SECOND ORDER BY s.updated_at DESC LIMIT 1");
     $q->execute([(int)$p['host_admin_id']]);$proof=$q->fetch()?:null;
    }
    if($legacyProof&&$proof){$proof['capture_phase']='upgrade';$proof['consent_ack']=0;}
    if($proof && !SchemaGuard::missing($db,['claim_media'])){
     $media=$db->prepare('SELECT COUNT(*) FROM claim_media WHERE claim_id=? AND session_id=?');
     $media->execute([$proof['claim_id'],$proof['session_id']]);$proof['saved_count']=(int)$media->fetchColumn();
    }
    }
   }
   success(['status'=>$status,'deviceName'=>$p['device_name'],'expiresAt'=>$p['paired_expires_at'],'game'=>$status==='paired'?($current??ControllerGame::snapshot($db)):null,'proof'=>$proof?['active'=>true,'claimId'=>$proof['claim_id'],'reviewCount'=>(int)$proof['review_count'],'consent'=>(bool)($proof['consent_ack']??false),'phase'=>(string)($proof['capture_phase']??'live'),'savedCount'=>(int)($proof['saved_count']??0)]:['active'=>false],'delivery'=>$delivery,'reason'=>$reason??null,
    'moviePreview'=>$status==='paired'&&SchemaGuard::missing($db,['movie_preview_state'])===[]?MoviePreview::public(MoviePreview::row($db,(int)$p['host_admin_id'])):null]);
  }
  if($action==='select_package'){
   $package=PrizePackages::id($in['package']??null);
   $db->beginTransaction();
   try{
    $q=$db->prepare('SELECT status,paired_expires_at,host_admin_id,controller_hash FROM controller_pairs WHERE id=? FOR UPDATE');
    $q->execute([$id]);$locked=$q->fetch();
    if(!$locked||!hash_equals((string)($locked['controller_hash']??''),ControllerPairing::tokenHash($token))||
       ControllerPairing::status($locked)!=='paired')throw new DomainException('Phone pairing is no longer approved.',403);
    // Selection is only a package preference for the next game, never a prize award.
    PrizePackages::select($db,$package,true);
    audit($db,'package.selected',null,['packageId'=>$package,'pairId'=>$id,'source'=>'paired-phone']);
    $db->commit();
   }catch(Throwable $e){$db->rollBack();throw $e;}
   success(['selected'=>$package]);
  }
  if($action==='disconnect'){
   $db->beginTransaction();try{
    $q=$db->prepare("UPDATE controller_pairs SET status='revoked',revoked_at=UTC_TIMESTAMP() WHERE id=? AND status IN ('pending','paired')");$q->execute([$id]);
    $q=$db->prepare('DELETE FROM controller_commands WHERE pair_id=? AND delivered_at IS NULL');$q->execute([$id]);
    audit($db,'controller.self-disconnect',null,['pairId'=>$id]);$db->commit();
   }catch(Throwable $e){$db->rollBack();throw $e;}
   success(['status'=>'revoked']);
  }
  if($action==='command'){
   $command=ControllerPairing::command($in['command']??null);$nonce=ControllerPairing::nonce($in['nonce']??null);
   if(!in_array($command,ControllerGame::DIRECT,true))SchemaGuard::require($db,['controller_ui_events'],'Phone navigation');
   $db->beginTransaction();try{
    $q=$db->prepare('SELECT * FROM controller_pairs WHERE id=? FOR UPDATE');$q->execute([$id]);$locked=$q->fetch();
    if(!$locked||!hash_equals($locked['controller_hash']??'',ControllerPairing::tokenHash($token))||ControllerPairing::status($locked)!=='paired')throw new DomainException('Controller connection is no longer active.',403);
    $q=$db->prepare('SELECT id,delivered_at FROM controller_commands WHERE pair_id=? AND client_nonce=?');$q->execute([$id,$nonce]);
    if($row=$q->fetch()){$db->commit();success(['accepted'=>true,'repeat'=>true,'applied'=>$row['delivered_at']!==null,'commandId'=>(int)$row['id'],'game'=>ControllerGame::snapshot($db)]);}
    $q=$db->prepare('SELECT COUNT(*) FROM controller_commands WHERE pair_id=? AND created_at>=UTC_TIMESTAMP()-INTERVAL 1 SECOND');$q->execute([$id]);$second=(int)$q->fetchColumn();
    $q=$db->prepare('SELECT COUNT(*) FROM controller_commands WHERE pair_id=? AND created_at>=UTC_TIMESTAMP()-INTERVAL 1 MINUTE');$q->execute([$id]);$minute=(int)$q->fetchColumn();
    ControllerRate::check($second,$minute,$command);
    if(in_array($command,['spin','start','end'],true)){
     $q=$db->prepare('SELECT COUNT(*) FROM controller_commands WHERE pair_id=? AND command=? AND delivered_at IS NULL AND created_at>UTC_TIMESTAMP()-INTERVAL 12 SECOND');
     $q->execute([$id,$command]);if((int)$q->fetchColumn()>0)throw new DomainException('This button press is already queued.',409);
    }
    // Gameplay is executed and committed on the server, not merely queued for an optional browser tab.
    // UI/audio commands remain queued for the signed-in host display.
    $direct=in_array($command,ControllerGame::DIRECT,true);
    if($command==='prize-video-play'){
     $snapshot=ControllerGame::snapshot($db);
     if(!in_array(($snapshot['offer']['status']??''),['pending','accepted'],true)||empty($snapshot['offer']['videoUrl']))
      throw new DomainException('This prize has no playable video.',409);
     if(($snapshot['offer']['status']??'')==='accepted'){
      $proof=$db->prepare('SELECT claim_id FROM controller_proof_state WHERE host_admin_id=? AND is_open=1 AND updated_at>UTC_TIMESTAMP()-INTERVAL 12 SECOND LIMIT 1');
      $proof->execute([(int)$locked['host_admin_id']]);
      if($proof->fetchColumn()!==($snapshot['offer']['claimId']??''))
       throw new DomainException('Open this claimed prize proof on the desktop to play its video.',409);
     }
    }
    if($command==='end'&&($in['confirm']??false)!==true)throw new DomainException('Confirm End Game on the phone first.',409);
    $effect=$direct?ControllerGame::apply($db,$command,$id,$nonce,(int)$locked['host_admin_id']):null;
    $sessionId=$direct?null:(ControllerGame::current($db)['id']??null);
    $q=$db->prepare($direct
       ?'INSERT INTO controller_commands(pair_id,client_nonce,command,delivered_at) VALUES (?,?,?,UTC_TIMESTAMP())'
       :'INSERT INTO controller_commands(pair_id,client_nonce,command) VALUES (?,?,?)');
    $q->execute([$id,$nonce,$command]);
    $commandId=(int)$db->lastInsertId();
    if(!$direct){
     $q=$db->prepare('INSERT INTO controller_ui_events(command_id,session_id) VALUES (?,?)');$q->execute([$commandId,$sessionId]);
    }
    $db->commit();
   }catch(Throwable $e){$db->rollBack();throw $e;}
   success(['accepted'=>true,'applied'=>$direct,'commandId'=>$direct?null:$commandId,'effect'=>$effect['effect']??null,'repeat'=>false,'game'=>ControllerGame::snapshot($db)],$direct?200:202);
  }
 }
 // Read-only audience screens can receive limited UI/audio controls for their own game session.
 // Every command must be acknowledged AFTER the browser handles it; no destructive queue polling.
 if(in_array($action,['ui_events','ui_ack'],true)){
  SchemaGuard::require($db,['controller_ui_events'],'Phone navigation receiver');
  $viewer=(string)($in['viewer']??'');$session=(string)($in['session']??'');
  $isDisplay=$viewer!=='';
  if($isDisplay){$r=sessionRow($db,$session);if(!hash_equals((string)$r['viewer_token'],$viewer))throw new DomainException('Invalid TV display authorization.',403);}
  else {requireAdmin();assertCsrf();}
  $filter=$isDisplay?"u.session_id=? AND c.command NOT LIKE 'proof-%' AND c.command<>'prize-video-play'":'p.host_admin_id=?';$scope=$isDisplay?$session:(int)$_SESSION['admin_id'];
  if($action==='ui_events'){
   $after=max(0,(int)($in['after']??0));
   $q=$db->prepare("SELECT c.id,c.command,p.device_name, u.acknowledged_at FROM controller_ui_events u JOIN controller_commands c ON c.id=u.command_id JOIN controller_pairs p ON p.id=c.pair_id WHERE $filter AND p.status='paired' AND p.paired_expires_at>UTC_TIMESTAMP() AND c.id>? AND c.created_at>UTC_TIMESTAMP()-INTERVAL 15 SECOND ORDER BY c.id ASC LIMIT 32");
   $q->execute([$scope,$after]);$events=$q->fetchAll();
   success(['events'=>array_map(fn($e)=>['id'=>(int)$e['id'],'command'=>$e['command'],'deviceName'=>$e['device_name'],'acknowledged'=>$e['acknowledged_at']!==null],$events)]);
  }
  $id=(int)($in['commandId']??0);if($id<=0)throw new DomainException('Invalid command receipt.',400);
  $good=($in['success']??true)===true;
  $reason=trim((string)($in['error']??''));
  if(strlen($reason)>180)$reason=substr($reason,0,180);
  if(!$good&&$reason==='')$reason='The game screen could not perform this action.';
  if($good){
   $q=$db->prepare("UPDATE controller_ui_events u JOIN controller_commands c ON c.id=u.command_id JOIN controller_pairs p ON p.id=c.pair_id SET u.acknowledged_at=COALESCE(u.acknowledged_at,UTC_TIMESTAMP()),u.failure_message=NULL,u.ack_source=COALESCE(u.ack_source,?) WHERE u.command_id=? AND $filter AND p.status='paired' AND p.paired_expires_at>UTC_TIMESTAMP()");
   $q->execute([$isDisplay?'display':'host',$id,$scope]);
  }else{
   $q=$db->prepare("UPDATE controller_ui_events u JOIN controller_commands c ON c.id=u.command_id JOIN controller_pairs p ON p.id=c.pair_id SET u.failure_message=? WHERE u.command_id=? AND $filter AND u.acknowledged_at IS NULL AND p.status='paired' AND p.paired_expires_at>UTC_TIMESTAMP()");
   $q->execute([$reason,$id,$scope]);
  }
  if($q->rowCount()===0){
   $v=$db->prepare("SELECT u.command_id FROM controller_ui_events u JOIN controller_commands c ON c.id=u.command_id JOIN controller_pairs p ON p.id=c.pair_id WHERE u.command_id=? AND $filter");
   $v->execute([$id,$scope]);if(!$v->fetch())throw new DomainException('Command receipt not authorized.',403);
  }
  success(['acknowledged'=>$good,'failure'=>$good?null:$reason]);
 }
 // Host commands always require the ordinary admin session and CSRF token.
 requireAdmin();assertCsrf();$adminId=(int)$_SESSION['admin_id'];
 if($action==='proof_state'){
  SchemaGuard::require($db,['controller_proof_state'],'Live prize proof controls');
  if(!$db->query("SHOW COLUMNS FROM controller_proof_state LIKE 'consent_ack'")->fetch())throw new DomainException('Run upgrade.php to activate V5Q.15F phone recording consent.',409);
  $session=(string)($in['session']??'');$claim=(string)($in['claim']??'');$open=($in['open']??false)===true;$reviewCount=max(0,min(4,(int)($in['reviewCount']??0)));$consent=$open&&($in['consent']??false)===true;$phase=$open?(string)($in['phase']??'live'):'live';if(!in_array($phase,['live','opening','countdown','recording','processing','review','saving','saved'],true))throw new DomainException('Invalid recording phase.',422); 
  ControllerPairing::checkId($session);
  sessionRow($db,$session);
  if($open){ControllerPairing::checkId($claim);$q=$db->prepare('SELECT id FROM game_claims WHERE id=? AND session_id=?');$q->execute([$claim,$session]);if(!$q->fetch())throw new DomainException('Claim does not belong to this session.',403);}
  $q=$db->prepare('INSERT INTO controller_proof_state(session_id,host_admin_id,claim_id,is_open,review_count,consent_ack,capture_phase,updated_at) VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE host_admin_id=VALUES(host_admin_id),claim_id=VALUES(claim_id),is_open=VALUES(is_open),review_count=VALUES(review_count),consent_ack=VALUES(consent_ack),capture_phase=VALUES(capture_phase),updated_at=UTC_TIMESTAMP()');
  $q->execute([$session,$adminId,$open?$claim:null,$open?1:0,$open?$reviewCount:0,$consent?1:0,$phase]);success(['active'=>$open]);
 }
 if($action==='invite'){
  $db->beginTransaction();try{
   $q=$db->prepare("SELECT COUNT(*) FROM controller_pairs WHERE host_admin_id=? AND ((status IN ('inviting','pending') AND invite_expires_at>UTC_TIMESTAMP()) OR (status='paired' AND paired_expires_at>UTC_TIMESTAMP()))");$q->execute([$adminId]);
   if((int)$q->fetchColumn()>=8)throw new DomainException('Disconnect an old phone before making another invitation.');
   $id=bin2hex(random_bytes(16));$secret=bin2hex(random_bytes(32));
   $q=$db->prepare("INSERT INTO controller_pairs(id,host_admin_id,invite_hash,invite_expires_at) VALUES (?,?,?,UTC_TIMESTAMP()+INTERVAL 5 MINUTE)");
   $q->execute([$id,$adminId,ControllerPairing::tokenHash($secret)]);
   audit($db,'controller.invite',null,['pairId'=>$id]);$db->commit();
  }catch(Throwable $e){$db->rollBack();throw $e;}
  success(['id'=>$id,'url'=>ControllerPairing::inviteUrl($id,$secret),'expiresInSeconds'=>300],201);
 }
 if($action==='list'){
  $q=$db->prepare('SELECT id,device_name,status,invite_expires_at,paired_expires_at,created_at FROM controller_pairs WHERE host_admin_id=? ORDER BY created_at DESC LIMIT 30');$q->execute([$adminId]);
  $rows=$q->fetchAll();foreach($rows as &$row)$row['status']=ControllerPairing::status($row);unset($row);
  success(['pairs'=>$rows]);
 }
 if(in_array($action,['approve','revoke'],true)){
  $id=strval($in['id']??'');ControllerPairing::checkId($id);
  $db->beginTransaction();try{
   $q=$db->prepare('SELECT * FROM controller_pairs WHERE id=? AND host_admin_id=? FOR UPDATE');$q->execute([$id,$adminId]);$p=$q->fetch();
   if(!$p)throw new DomainException('Phone request not found.',404);
   if($action==='approve'){
    if(ControllerPairing::status($p)!=='pending')throw new DomainException('This request is not awaiting approval.',409);
    $q=$db->prepare("UPDATE controller_pairs SET status='paired',paired_expires_at=UTC_TIMESTAMP()+INTERVAL 8 HOUR,approved_at=UTC_TIMESTAMP() WHERE id=?");$q->execute([$id]);
   }else{
    $q=$db->prepare("UPDATE controller_pairs SET status='revoked',revoked_at=UTC_TIMESTAMP() WHERE id=?");$q->execute([$id]);
    $q=$db->prepare('DELETE FROM controller_commands WHERE pair_id=? AND delivered_at IS NULL');$q->execute([$id]);
   }
   audit($db,'controller.'.$action,null,['pairId'=>$id]);$db->commit();
  }catch(Throwable $e){$db->rollBack();throw $e;}
  success(['status'=>$action==='approve'?'paired':'revoked']);
 }
 if($action==='poll')success(['commands'=>[],'deprecated'=>true]);
 throw new DomainException('Unknown controller operation.',404);
}catch(Throwable $e){error_log('Lucky controller: '.get_class($e).' '.$e->getMessage());errorOut($e);}
