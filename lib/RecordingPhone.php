<?php
declare(strict_types=1);
require_once __DIR__.'/ControllerPairing.php';
require_once __DIR__.'/ClaimMedia.php';

/** Capability-scoped recording access for already approved phone controllers. */
final class RecordingPhone {
 public static function grant(PDO $db,int $owner,string $sessionId,bool $enabled):array {
  RecordingSessions::id($sessionId);
  $q=$db->prepare('SELECT id FROM recording_sessions WHERE id=? AND owner_admin_id=?');$q->execute([$sessionId,$owner]);
  if(!$q->fetchColumn())throw new DomainException('Recording session not found.',404);
  if($enabled&&!RecordingProfiles::get($db,$sessionId)['capture']['phoneCameraEnabled'])
   throw new DomainException('Phone capture is disabled in this recording profile.',403);
  $q=$db->prepare('INSERT INTO recording_phone_permissions(recording_session_id,phone_enabled) VALUES (?,?) ON DUPLICATE KEY UPDATE phone_enabled=VALUES(phone_enabled)');
  $q->execute([$sessionId,(int)$enabled]);return ['sessionId'=>$sessionId,'phoneEnabled'=>$enabled];
 }
 /**
  * Repair a camera-enabled live Spin the Wheel launch if an earlier code path
  * did not create its group. Only the game's approved host phones may trigger
  * this, and the stable game nonce makes repeat listings idempotent.
  */
 public static function ensureSpinWheelCamera(PDO $db,array $pair):?string {
  if(ControllerPairing::status($pair)!=='paired')throw new DomainException('Phone pairing requires host approval.',403);
  $owner=(int)($pair['host_admin_id']??0);
  if($owner<1)return null;
  $q=$db->prepare("SELECT s.id,s.state_json FROM game_sessions s WHERE s.game_id='spin-wheel' AND s.status IN ('active','paused')
    AND EXISTS(SELECT 1 FROM audit_log a WHERE a.session_id=s.id AND a.event='session.start' AND a.admin_id=?)
    ORDER BY s.created_at DESC,s.id DESC LIMIT 1");
  $q->execute([$owner]);$game=$q->fetch(PDO::FETCH_ASSOC);
  if(!$game)return null;
  $state=json_decode((string)$game['state_json'],true);
  $packageId=is_array($state)?($state['prizePackage']['id']??null):null;
  if(!is_string($packageId)||!preg_match('/^[a-f0-9]{32}$/D',$packageId)){
   $q=$db->prepare("SELECT package_id FROM prize_package_selection WHERE game_id='spin-wheel'");
   $q->execute();$packageId=$q->fetchColumn();
  }
  if(!is_string($packageId)||!preg_match('/^[a-f0-9]{32}$/D',$packageId))return null;
  $q=$db->prepare("SELECT name,phone_camera_enabled FROM prize_packages WHERE id=? AND game_id='spin-wheel'");
  $q->execute([$packageId]);$package=$q->fetch(PDO::FETCH_ASSOC);
  if(!$package||(int)$package['phone_camera_enabled']!==1)return null;
  $nonce='spin-wheel-phone-'.$game['id'];
  $q=$db->prepare('SELECT id FROM recording_sessions WHERE owner_admin_id=? AND create_nonce=?');
  $q->execute([$owner,$nonce]);$prior=$q->fetchColumn();
  if($prior!==false)return (string)$prior; // Do not override a host's completed/revoked group.
  $q=$db->prepare("SELECT s.id FROM recording_sessions s JOIN recording_phone_permissions p ON p.recording_session_id=s.id
    WHERE s.owner_admin_id=? AND s.game_session_id=? AND s.status='active' AND p.phone_enabled=1 LIMIT 1");
  $q->execute([$owner,$game['id']]);$manual=$q->fetchColumn();
  if($manual!==false)return (string)$manual;
  $db->beginTransaction();
  try{
   $q=$db->prepare('SELECT status FROM game_sessions WHERE id=? FOR UPDATE');
   $q->execute([$game['id']]);$status=$q->fetchColumn();
   if(!in_array($status,['active','paused'],true)){$db->commit();return null;}
   $q=$db->prepare("SELECT name,phone_camera_enabled FROM prize_packages WHERE id=? AND game_id='spin-wheel' FOR UPDATE");
   $q->execute([$packageId]);$package=$q->fetch(PDO::FETCH_ASSOC);
   if(!$package||(int)$package['phone_camera_enabled']!==1){$db->commit();return null;}
   $id=RecordingSessions::autoSpinWheel($db,$owner,(string)$game['id'],(string)$package['name']);
   $db->commit();
   return $id;
  }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
 }
 public static function sessions(PDO $db,array $pair):array {
  if(ControllerPairing::status($pair)!=='paired')throw new DomainException('Phone pairing requires host approval.',403);
  $q=$db->prepare("SELECT s.id,s.title,s.recording_type,s.status,s.started_at,COUNT(DISTINCT a.id) AS clip_count
    FROM recording_sessions s JOIN recording_phone_permissions p ON p.recording_session_id=s.id
    LEFT JOIN recording_assets a ON a.recording_session_id=s.id
    WHERE s.owner_admin_id=? AND p.phone_enabled=1 AND s.status='active'
    GROUP BY s.id,s.title,s.recording_type,s.status,s.started_at ORDER BY s.started_at DESC,s.id DESC LIMIT 25");
  $q->execute([(int)$pair['host_admin_id']]);$out=[];
  foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){
   $t=$db->prepare("SELECT id FROM recording_takes WHERE recording_session_id=? AND status='recording' ORDER BY take_index DESC LIMIT 1");
   $t->execute([$r['id']]);
   $profile=RecordingProfiles::get($db,(string)$r['id']);
   $out[]=['id'=>$r['id'],'title'=>$r['title'],'type'=>$r['recording_type'],'status'=>$r['status'],'clips'=>(int)$r['clip_count'],
    'takeId'=>$t->fetchColumn()?:null,'capture'=>$profile['capture'],'brief'=>$profile['brief'],'shotList'=>$profile['shotList'],
    'tags'=>$profile['tags'],'location'=>$profile['location'],'eventDate'=>$profile['eventDate']];
  }
  return $out;
 }
 public static function assetId(string $pairId,string $nonce):string {
  ControllerPairing::checkId($pairId);ControllerPairing::nonce($nonce);
  return substr(hash('sha256','lucky-session-phone-v1:'.$pairId.':'.$nonce),0,32);
 }
 private static function assertApproved(PDO $db,string $pairId,string $token,string $sessionId):array {
  $q=$db->prepare('SELECT * FROM controller_pairs WHERE id=? FOR UPDATE');$q->execute([$pairId]);$pair=$q->fetch(PDO::FETCH_ASSOC);
  if(!$pair||!hash_equals((string)$pair['controller_hash'],ControllerPairing::tokenHash($token))||ControllerPairing::status($pair)!=='paired')
   throw new DomainException('Phone pairing was revoked or expired.',403);
  $q=$db->prepare('SELECT s.id,s.status,p.phone_enabled FROM recording_sessions s JOIN recording_phone_permissions p ON p.recording_session_id=s.id WHERE s.id=? AND s.owner_admin_id=? FOR UPDATE');
  $q->execute([$sessionId,(int)$pair['host_admin_id']]);$record=$q->fetch(PDO::FETCH_ASSOC);
  if(!$record||(int)$record['phone_enabled']!==1||$record['status']!=='active')throw new DomainException('This recording is not approved for phone capture.',403);
  return $pair;
 }
 public static function store(PDO $db,string $pairId,string $token,array $input,?array $file):array {
  $sessionId=RecordingSessions::id((string)($input['session']??''));
  $nonce=(string)($input['nonce']??'');$assetId=self::assetId($pairId,$nonce);
  if((string)($input['consent']??'')!=='yes')throw new DomainException('Confirm recording consent on your phone.',403);
  if(!is_array($file)||($file['error']??-1)!==UPLOAD_ERR_OK||!is_uploaded_file((string)($file['tmp_name']??'')))
   throw new DomainException('Choose a valid media file, or check the server upload size limit.',422);
  $bytes=(int)$file['size'];$mime=(new finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']);
  $dimensions=str_starts_with((string)$mime,'image/')?@getimagesize((string)$file['tmp_name']):null;
  $kind=ClaimMedia::captureKind((string)$mime,$bytes,$dimensions?:null,(string)($input['kind']??''),(string)$file['tmp_name']);
  $title=trim((string)($input['title']??''));if($title===''||mbSafeLen($title)>120||preg_match('/[\x00-\x1f\x7f]/',$title))$title='Phone '.ucfirst($kind);
  $duration=max(0,min(3600000,(int)($input['durationMs']??0)));
  $takeId=(string)($input['takeId']??'');if($takeId!=='')RecordingSessions::id($takeId);
  $syncStart=filter_var($input['recordedStartMs']??null,FILTER_VALIDATE_INT);
  $syncEnd=filter_var($input['recordedEndMs']??null,FILTER_VALIDATE_INT);
  $syncDelta=filter_var($input['clockOffsetMs']??null,FILTER_VALIDATE_INT);
  $coordinated=$syncStart!==null&&$syncStart!==false;
  if(!$coordinated&&(array_key_exists('recordedEndMs',$input)||array_key_exists('clockOffsetMs',$input)||array_key_exists('recordedStartMs',$input)))
   throw new DomainException('Synchronized capture metadata must be complete.',422);
  if($coordinated){
   if(!in_array($kind,['video','audio'],true)||$takeId===''||$syncEnd===false||$syncEnd===null||$syncDelta===false||$syncDelta===null||
     (int)$syncStart<1000000000000||(int)$syncEnd<(int)$syncStart||(int)$syncEnd-(int)$syncStart>3600000||abs((int)$syncDelta)>86400000)
    throw new DomainException('Invalid synchronized capture metadata.',422);
  }
  $digest=hash_file('sha256',(string)$file['tmp_name']);
  $path=LUCKY_ROOT.'/storage/recording-assets/'.$assetId.'.bin';$moved=false;
  $db->beginTransaction();try{
   $pair=self::assertApproved($db,$pairId,$token,$sessionId);
   RecordingProfiles::kind($db,$sessionId,$kind);
   $q=$db->prepare('SELECT a.id,a.recording_session_id,m.kind,m.mime,m.bytes,m.sha256 FROM recording_assets a JOIN recording_media_files m ON m.asset_id=a.id WHERE a.id=? FOR UPDATE');
   $q->execute([$assetId]);$old=$q->fetch(PDO::FETCH_ASSOC);
   if($old){
    if($old['recording_session_id']!==$sessionId||$old['kind']!==$kind||$old['mime']!==$mime||(int)$old['bytes']!==$bytes||!hash_equals((string)$old['sha256'],$digest)||!is_file($path))
     throw new DomainException('Upload identifier already used for different content.',409);
    $db->commit();return ['id'=>$assetId,'kind'=>$kind,'bytes'=>$bytes,'existing'=>true];
   }
   if($takeId!==''){$q=$db->prepare('SELECT id,started_at FROM recording_takes WHERE id=? AND recording_session_id=?');$q->execute([$takeId,$sessionId]);
    $take=$q->fetch(PDO::FETCH_ASSOC);if(!$take)throw new DomainException('Recording take does not belong to this session.',403);}
   $offset=0;
   if($coordinated){
    $q=$db->prepare('SELECT armed FROM recording_camera_arms WHERE recording_session_id=? AND pair_id=?');$q->execute([$sessionId,$pairId]);
    if((int)$q->fetchColumn()!==1)throw new DomainException('Phone was not armed for this coordinated recording.',403);
    $takeStart=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.v',(string)$take['started_at'],new DateTimeZone('UTC'));
    if(!$takeStart)throw new DomainException('Invalid take timestamp.',422);
    $offset=(int)$syncStart-(int)round((float)$takeStart->format('U.u')*1000);
    if($offset< -30000||$offset>3600000)throw new DomainException('Capture timing does not match this take.',422);
   }
   $q=$db->prepare('SELECT COUNT(*) AS total,COALESCE(SUM(m.bytes),0) AS used FROM recording_assets a JOIN recording_media_files m ON m.asset_id=a.id WHERE a.recording_session_id=?');
   $q->execute([$sessionId]);$usage=$q->fetch(PDO::FETCH_ASSOC);
   if((int)$usage['total']>=ClaimMedia::MAX_CLIPS_PER_SESSION||(int)$usage['used']+$bytes>ClaimMedia::MAX_SESSION_BYTES)
    throw new DomainException('Recording session media limit reached.',413);
   $key='p-'.$pairId;$sourceName='Paired phone · '.substr($pairId,0,8);
   $source=RecordingSessions::source($db,(int)$pair['host_admin_id'],$sessionId,['key'=>$key,'kind'=>'phone','name'=>$sourceName]);
   if(!is_dir(dirname($path))&&!mkdir(dirname($path),0700,true))throw new RuntimeException('Could not create private recording folder.');
   if(!move_uploaded_file((string)$file['tmp_name'],$path))throw new RuntimeException('Failed to save recording.');$moved=true;@chmod($path,0600);
   $q=$db->prepare('INSERT INTO recording_assets(id,recording_session_id,take_id,source_id,storage_key,kind,start_offset_ms,sync_confidence) VALUES (?,?,?,?,?,?,?,?)');
   $q->execute([$assetId,$sessionId,$takeId!==''?$takeId:null,$source['id'],$assetId,$kind,$offset,$coordinated?1:0]);
   $q=$db->prepare('INSERT INTO recording_media_files(asset_id,mime,title,bytes,sha256,duration_ms,uploaded_by_pair_id) VALUES (?,?,?,?,?,?,?)');
   $q->execute([$assetId,$mime,$title,$bytes,$digest,$duration,$pairId]);
   if($coordinated){$q=$db->prepare("INSERT INTO recording_capture_sync(asset_id,recorded_start_ms,recorded_end_ms,clock_offset_ms,sync_method) VALUES (?,?,?,?,'controller_poll')");
    $q->execute([$assetId,(int)$syncStart,(int)$syncEnd,(int)$syncDelta]);}
   $db->commit();return ['id'=>$assetId,'kind'=>$kind,'bytes'=>$bytes,'existing'=>false];
  }catch(Throwable $e){if($db->inTransaction())$db->rollBack();if($moved)@unlink($path);throw $e;}
 }
}
