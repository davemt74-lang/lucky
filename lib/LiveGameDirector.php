<?php
declare(strict_types=1);
require_once __DIR__.'/PartyStudio.php';
require_once __DIR__.'/SpinReveal.php';

/** Server-owned show controls shared by the gameplay and Camera Director browsers. */
final class LiveGameDirector {
 public const MAX_EVENTS=256;
 private const CUES=['turn_intro'=>'Turn introduction','prize_reveal'=>'Prize reveal','reaction'=>'Player reaction',
  'costume_focus'=>'Costume close-up','celebration'=>'Celebration','scene_cut'=>'Scene change'];

 private static function id(mixed $id):string {
  if(!is_string($id)||preg_match('/^[a-f0-9]{32}$/D',$id)!==1)throw new DomainException('Invalid game or prize identifier.',422);
  return $id;
 }
 public static function hasRequest(array $state,string $key):bool {
  foreach($state['director']['events']??[] as $event)if(($event['requestKey']??'')===$key)return true;
  return false;
 }
 private static function stamp(int $ms):string {
  return gmdate('Y-m-d\TH:i:s',intdiv($ms,1000)).sprintf('.%03dZ',$ms%1000);
 }
 private static function timestampMs(string $value):?int {
  if($value==='')return null;
  try{$date=new DateTimeImmutable($value,new DateTimeZone('UTC'));return (int)round((float)$date->format('U.u')*1000);}
  catch(Throwable){return null;}
 }
 private static function claim(array $state,string $id):?array {
  foreach($state['claims']??[] as $claim)if(($claim['id']??'')===$id)return $claim;
  return null;
 }
 public static function apply(PDO $db,array &$state,int $owner,array $input):array {
  if(($state['game']??'')!=='spin-wheel'||!in_array($state['status']??'', ['active','paused'],true))
   throw new DomainException('Live Game Director actions need a running Spin the Wheel session.',409);
  $key=$input['requestKey']??'';
  if(!is_string($key)||preg_match('/^[a-zA-Z0-9_-]{12,64}$/D',$key)!==1)
   throw new DomainException('Invalid director request key.',422);
  $action=$input['action']??'';
  if(!is_string($action)||!in_array($action,['assign_camera','cue','next_player'],true))
   throw new DomainException('Unknown Live Game Director action.',422);
  $requestSignature=hash('sha256',json_encode([$action,$input['claimId']??'',
   $input['pairId']??'',$input['cue']??'',$input['note']??''],JSON_THROW_ON_ERROR));
  foreach($state['director']['events']??[] as $prior)if(($prior['requestKey']??'')===$key){
   if(($prior['requestSignature']??'')!==$requestSignature)
    throw new DomainException('Director request key was already used for another command.',409);
   return ['changed'=>false,'event'=>$prior];
  }
  if(count($state['director']['events']??[])>=self::MAX_EVENTS)
   throw new DomainException('Director event limit reached for this game.',409);
  $roster=&$state['multiplayer'];$players=$roster['players']??[];
  if(!$players)throw new DomainException('This game has no players.',409);
  $current=$players[((int)($roster['turnIndex']??0))%count($players)];
  $claimId=$input['claimId']??'';
  if(!is_string($claimId))throw new DomainException('Invalid prize reference.',422);
  if($claimId!=='')self::id($claimId);
  $claim=$claimId!==''?self::claim($state,$claimId):null;
  if($claimId!==''&&!$claim)throw new DomainException('This prize does not belong to the current game.',404);
  $note=$input['note']??'';
  if(!is_string($note)||strlen($note)>240||preg_match('/[\x00-\x1f\x7f]/',$note))
   throw new DomainException('Invalid director note.',422);
  $ms=(int)round(microtime(true)*1000);
  $event=['id'=>bin2hex(random_bytes(16)),'requestKey'=>$key,'requestAction'=>$action,
   'requestSignature'=>$requestSignature,'at'=>self::stamp($ms),'timeMs'=>$ms,
   'playerId'=>$claim['playerId']??$current['id'],'playerName'=>$claim['playerName']??$current['name'],
   'claimId'=>$claim['id']??null,'prizeName'=>$claim['name']??null,'note'=>trim($note)];
  if($action==='assign_camera'){
   if(!$claim)throw new DomainException('Award a prize before assigning its cameraman.',409);
   $pairId=$input['pairId']??'';
   if(!is_string($pairId))throw new DomainException('Invalid player camera.',422);
   $operator=null;
   if($pairId!==''){
    self::id($pairId);
    foreach($players as $candidate)if(($candidate['phonePairId']??'')===$pairId){$operator=$candidate;break;}
    if(!$operator)throw new DomainException('The camera must belong to a player in this game.',422);
    PartyStudio::approvedPhone($db,$pairId,$owner);
   }
   $prior=$state['director']['assignments'][$claimId]??'';
   if($prior===$pairId)return ['changed'=>false,'event'=>null];
   $state['director']['assignments'][$claimId]=$pairId;
   $event['type']='camera_assignment';$event['phonePairId']=$pairId;
   $event['cameraOperator']=$operator['name']??null;
   $event['label']=$operator?'Assigned '.$operator['name'].' as cameraman':'Cleared prize cameraman';
  }elseif($action==='next_player'){
   if(count($players)<2)throw new DomainException('Add at least two players to advance turns.',409);
   if($claimId!=='')throw new DomainException('Turn changes do not use a prize reference.',422);
   if(SpinReveal::pending($state))throw new DomainException('Finish the current wheel reveal before skipping a turn.',409);
   $roster['turnIndex']=(((int)($roster['turnIndex']??0))+1)%count($players);
   $next=$players[$roster['turnIndex']];
   $event['type']='turn_skip';$event['nextPlayerId']=$next['id'];$event['nextPlayerName']=$next['name'];
   $event['label']='Skipped '.$current['name'].' · next: '.$next['name'];
  }else{
   $cue=$input['cue']??'';
   if(!is_string($cue)||!isset(self::CUES[$cue]))throw new DomainException('Invalid live show cue.',422);
   $event['type']='cue';$event['cue']=$cue;
   $event['label']=self::CUES[$cue].($claim?' · '.$claim['name']:'');
  }
  $state['director']['events'][]=$event;
  $state['version']=(int)$state['version']+1;
  return ['changed'=>true,'event'=>$event];
 }

 /** Read-only summary used by the separate Camera Director computer. */
 public static function context(PDO $db,array $state,int $owner):array {
  if(($state['game']??'')!=='spin-wheel')throw new DomainException('Not a Spin the Wheel game.',422);
  $id=self::id($state['id']??'');$roster=$state['multiplayer']??[];
  $players=$roster['players']??[];$current=$players?($players[((int)($roster['turnIndex']??0))%count($players)]):null;
  $claims=$state['claims']??[];$latest=$claims?end($claims):null;
  $approved=[];
  $q=$db->prepare("SELECT id FROM controller_pairs WHERE host_admin_id=? AND status='paired' AND paired_expires_at>UTC_TIMESTAMP()");
  $q->execute([$owner]);foreach($q->fetchAll(PDO::FETCH_COLUMN) as $pair)$approved[$pair]=true;
  $operators=[];
  foreach($players as $player)if(($player['phonePairId']??'')!==''){
   $pair=$player['phonePairId'];
   $operators[]=['playerId'=>$player['id'],'name'=>$player['name'],'pairId'=>$pair,'approved'=>isset($approved[$pair])];
  }
  $recording=null;$takes=[];
  if($db->query("SHOW TABLES LIKE 'recording_sessions'")->fetchColumn()){
   $q=$db->prepare('SELECT id,title,status,started_at FROM recording_sessions WHERE game_session_id=? AND owner_admin_id=? ORDER BY created_at DESC LIMIT 1');
   $q->execute([$id,$owner]);$recording=$q->fetch(PDO::FETCH_ASSOC)?:null;
   if($recording){
    $q=$db->prepare('SELECT id,title,status,started_at,ended_at FROM recording_takes WHERE recording_session_id=? ORDER BY take_index LIMIT 40');
    $q->execute([$recording['id']]);$takes=$q->fetchAll(PDO::FETCH_ASSOC);
   }
  }
  $moments=[];
  foreach($roster['turnHistory']??[] as $turn)
   $moments[]=['id'=>'win-'.$turn['turnNumber'],'type'=>'prize_win','at'=>$turn['at'],'label'=>($turn['playerName']??'Player').' won '.($turn['prizeName']??'a prize'),
    'playerId'=>$turn['playerId']??null,'claimId'=>$turn['claimId']??null];
  foreach($roster['costumeEvents']??[] as $event)
   $moments[]=['id'=>$event['id'],'type'=>'costume','at'=>$event['at'],'label'=>($event['playerName']??'Participant').' · '.($event['itemName']??'Item').' · '.($event['actionName']??$event['action']),
    'playerId'=>$event['playerId']??null,'claimId'=>$event['claimId']??null];
  foreach($state['director']['events']??[] as $event)
   $moments[]=['id'=>$event['id'],'type'=>$event['type'],'at'=>$event['at'],'label'=>$event['label'],
    'playerId'=>$event['playerId']??null,'claimId'=>$event['claimId']??null];
  foreach($moments as &$moment){
   $ms=self::timestampMs((string)$moment['at']);$moment['takeId']=null;$moment['offsetMs']=null;
   if($ms===null)continue;
   foreach($takes as $take){
    $start=self::timestampMs((string)($take['started_at']??''));$end=self::timestampMs((string)($take['ended_at']??''));
    if($start!==null&&$ms >= $start&&($end===null||$ms<=$end)){
     $moment['takeId']=$take['id'];$moment['offsetMs']=$ms-$start;
     $moment['sync']='estimated';break;
    }
   }
  }unset($moment);
  usort($moments,static fn($a,$b)=>strcmp((string)$a['at'],(string)$b['at']));
  return ['game'=>['id'=>$id,'version'=>(int)$state['version'],'status'=>$state['status'],
    'party'=>$roster['party']['name']??null,'theme'=>$roster['theme']['name']??null,
    'players'=>array_map(static fn($p)=>['id'=>$p['id'],'name'=>$p['name']],$players),
    'hosts'=>array_map(static fn($p)=>['id'=>$p['id'],'name'=>$p['name']],$roster['hosts']??[]),
    'currentPlayer'=>$current?['id'=>$current['id'],'name'=>$current['name'],'costume'=>$current['costume']??null]:null,
    'latestPrize'=>$latest?['id'=>$latest['id'],'name'=>$latest['name'],'playerName'=>$latest['playerName']??null,
     'playerId'=>$latest['playerId']??null,'costumeItem'=>$latest['costumeItem']??null]:null,
    'claims'=>array_map(static fn($c)=>['id'=>$c['id'],'name'=>$c['name'],'playerName'=>$c['playerName']??null],$claims)],
   'operators'=>$operators,'assignments'=>$state['director']['assignments']??[],
   'moments'=>array_slice($moments,-160),'recording'=>$recording,'takes'=>$takes,
   'serverTimeMs'=>(int)round(microtime(true)*1000)];
 }
}
