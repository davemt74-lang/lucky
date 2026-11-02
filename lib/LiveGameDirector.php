<?php
declare(strict_types=1);
require_once __DIR__.'/PartyStudio.php';
require_once __DIR__.'/SpinReveal.php';

/** Server-owned show controls shared by the gameplay and Camera Director browsers. */
final class LiveGameDirector {
 public const MAX_EVENTS=256;
 private const CUES=['turn_intro'=>'Turn introduction','prize_reveal'=>'Prize reveal','reaction'=>'Player reaction',
  'costume_focus'=>'Costume close-up','celebration'=>'Celebration','scene_cut'=>'Scene change'];
 private const TRIGGERS=['manual'=>'Manual','spin_start'=>'Wheel starts','prize_reveal'=>'Wheel reveal complete',
  'costume_action'=>'Costume action','next_player'=>'Next player'];
 private const TRANSITIONS=['cut'=>'Cut','fade'=>'Fade','wipe'=>'Wipe','dip'=>'Dip to black'];

 public static function defaultSteps():array {
  return [
   ['id'=>'intro','trigger'=>'next_player','cue'=>'turn_intro','durationSec'=>8,'enabled'=>true,'detail'=>''],
   ['id'=>'spin','trigger'=>'spin_start','cue'=>'scene_cut','durationSec'=>6,'enabled'=>true,'detail'=>'Wheel spinning'],
   ['id'=>'prize','trigger'=>'prize_reveal','cue'=>'prize_reveal','durationSec'=>12,'enabled'=>true,'detail'=>''],
   ['id'=>'costume','trigger'=>'costume_action','cue'=>'costume_focus','durationSec'=>9,'enabled'=>true,'detail'=>''],
   ['id'=>'reaction','trigger'=>'manual','cue'=>'reaction','durationSec'=>8,'enabled'=>true,'detail'=>''],
   ['id'=>'finale','trigger'=>'manual','cue'=>'celebration','durationSec'=>10,'enabled'=>true,'detail'=>'']
  ];
 }
 public static function rundown(array $state):array {
  $saved=$state['director']['rundown']??[];
  return ['steps'=>$saved['steps']??self::defaultSteps(),'enabled'=>(bool)($saved['enabled']??false),
   'paused'=>(bool)($saved['paused']??false),'cursor'=>(int)($saved['cursor']??0),
   'lastStepId'=>$saved['lastStepId']??null];
 }
 private static function checkedSteps(mixed $steps):array {
  if(!is_array($steps)||!array_is_list($steps)||count($steps)<1||count($steps)>12)
   throw new DomainException('Rundown needs 1 to 12 steps.',422);
  $clean=[];$seen=[];
  foreach($steps as $step){
   if(!is_array($step))throw new DomainException('Invalid rundown step.',422);
   $id=$step['id']??'';$trigger=$step['trigger']??'';$cue=$step['cue']??'';$duration=$step['durationSec']??null;
   $detail=$step['detail']??'';
   if(!is_string($id)||preg_match('/^[a-zA-Z0-9_-]{3,32}$/D',$id)!==1||isset($seen[$id])||
    !is_string($trigger)||!isset(self::TRIGGERS[$trigger])||!is_string($cue)||!isset(self::CUES[$cue])||
    !is_int($duration)||$duration<3||$duration>45||!is_bool($step['enabled']??null)||
    !is_string($detail)||strlen($detail)>120||preg_match('/[\x00-\x1f\x7f]/',$detail))
    throw new DomainException('Invalid rundown step fields.',422);
   $seen[$id]=true;$clean[]=['id'=>$id,'trigger'=>$trigger,'cue'=>$cue,'durationSec'=>$duration,
    'enabled'=>$step['enabled'],'detail'=>trim($detail)];
  }
  return $clean;
 }
 private static function presentation(array $state,array $step,array $event,int $ms,?array $source=null):array {
  $players=$state['multiplayer']['players']??[];
  $index=count($players)?((int)($state['multiplayer']['turnIndex']??0)%count($players)):0;
  $person=$source['playerName']??($players[$index]['name']??'Player');
  $prize=$source['name']??($source['prizeName']??null);
  $cue=$step['cue'];
  $headline=match($cue){
   'turn_intro'=>$person."'s turn",'prize_reveal'=>$prize?'Prize: '.$prize:'Prize reveal',
   'reaction'=>$person.' reacts','costume_focus'=>'Costume spotlight',
   'celebration'=>'Congratulations, '.$person.'!','scene_cut'=>'Camera scene change'};
  $detail=$step['detail']!==''?$step['detail']:($source['itemName']??$prize??'Spin the Wheel');
  return ['id'=>$event['id'],'cue'=>$cue,'headline'=>$headline,'detail'=>$detail,
   'expiresAtMs'=>$ms+$step['durationSec']*1000];
 }
 /** Triggered only inside a locked gameplay mutation. Never starts cameras or reveals a prize before the wheel stops. */
 public static function onTrigger(array &$state,string $trigger,string $sourceKey,?array $source=null):bool {
  if(($state['game']??'')!=='spin-wheel'||!in_array($state['status']??'',['active','paused'],true))return false;
  $r=self::rundown($state);
  if(!$r['enabled']||$r['paused']||!isset(self::TRIGGERS[$trigger]))return false;
  foreach($state['director']['events']??[] as $event)if(($event['triggerKey']??'')===$sourceKey)return false;
  if(count($state['director']['events']??[])>=self::MAX_EVENTS)return false;
  foreach($r['steps'] as $i=>$step){
   if(!$step['enabled']||$step['trigger']!==$trigger)continue;
   $ms=(int)round(microtime(true)*1000);
   $event=['id'=>bin2hex(random_bytes(16)),'at'=>self::stamp($ms),'timeMs'=>$ms,
    'type'=>'rundown_cue','triggerKey'=>$sourceKey,'trigger'=>$trigger,'stepId'=>$step['id'],
    'cue'=>$step['cue'],'label'=>'AUTO · '.self::CUES[$step['cue']],
    'playerId'=>$source['playerId']??null,'playerName'=>$source['playerName']??null,
    'claimId'=>$source['id']??($source['claimId']??null)];
   $state['director']['events'][]=$event;
   $state['director']['presentation']=self::presentation($state,$step,$event,$ms,$source);
   $state['director']['rundown']['cursor']=($i+1)%count($r['steps']);
   $state['director']['rundown']['lastStepId']=$step['id'];
   return true;
  }
  return false;
 }

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
  if(!is_string($action)||!in_array($action,['assign_camera','cue','next_player','program_cut',
   'clear_presentation','rundown_save','rundown_control'],true))
   throw new DomainException('Unknown Live Game Director action.',422);
  $fingerprint=[$action,$input['claimId']??'',$input['pairId']??'',$input['cue']??'',$input['note']??''];
  if(array_key_exists('command',$input)||array_key_exists('steps',$input))
   array_push($fingerprint,$input['command']??'',$input['steps']??null);
  // Preserve V5Q32A retry signatures for existing saved game sessions.
  if(($input['itemId']??'')!==''||($input['sourceLabel']??'')!==''||($input['transition']??'')!=='')
   array_push($fingerprint,$input['itemId']??'',$input['sourceLabel']??'',$input['transition']??'');
  $requestSignature=hash('sha256',json_encode($fingerprint,JSON_THROW_ON_ERROR));
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
  if($action==='rundown_save'){
   $steps=self::checkedSteps($input['steps']??null);
   $old=self::rundown($state);
   $state['director']['rundown']=['steps'=>$steps,'enabled'=>$old['enabled'],
    'paused'=>$old['paused'],'cursor'=>0,'lastStepId'=>null];
   $event['type']='rundown_edit';$event['label']='Updated show rundown · '.count($steps).' steps';
  }elseif($action==='rundown_control'){
   $command=$input['command']??'';
   if(!is_string($command)||!in_array($command,['enable','disable','pause','resume','next','skip','replay'],true))
    throw new DomainException('Invalid rundown control.',422);
   $r=self::rundown($state);$steps=$r['steps'];$count=count($steps);$chosen=null;
   if($command==='enable'){$r['enabled']=true;$r['paused']=false;}
   elseif($command==='disable'){$r['enabled']=false;unset($state['director']['presentation']);}
   elseif($command==='pause'){$r['paused']=true;unset($state['director']['presentation']);}
   elseif($command==='resume')$r['paused']=false;
   elseif($command==='skip'){$r['cursor']=($r['cursor']+1)%$count;unset($state['director']['presentation']);}
   else{
    $chosen=null;$index=null;
    if($command==='replay'&&$r['lastStepId'])foreach($steps as $i=>$step)
     if($step['id']===$r['lastStepId']&&$step['enabled']){$chosen=$step;$index=$i;break;}
    if($command==='replay'&&!$chosen)throw new DomainException('No rundown cue to replay.',409);
    if($command==='next')for($j=0;$j<$count;$j++){
     $i=($r['cursor']+$j)%$count;
     if($steps[$i]['enabled']){$chosen=$steps[$i];$index=$i;break;}
    }
    if(!$chosen)throw new DomainException('Enable at least one rundown step.',409);
    $r['cursor']=($index+1)%$count;$r['lastStepId']=$chosen['id'];
    $state['director']['presentation']=self::presentation($state,$chosen,$event,$ms,
     $claim??null);
    $event['cue']=$chosen['cue'];$event['stepId']=$chosen['id'];
   }
   $state['director']['rundown']=$r;
   $event['type']='rundown_control';$event['command']=$command;
   $event['label']='Show rundown · '.$command.($chosen?' · '.self::CUES[$chosen['cue']]:'');
  }elseif($action==='assign_camera'){
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
   $state['director']['presentation']=['id'=>$event['id'],'cue'=>'turn_intro',
    'headline'=>$next['name']."'s turn",'detail'=>'Next up on Spin the Wheel',
    'expiresAtMs'=>$ms+15000];
  }elseif($action==='program_cut'){
   $transition=$input['transition']??'cut';$sourceLabel=$input['sourceLabel']??'';
   if(!is_string($transition)||!isset(self::TRANSITIONS[$transition]))
    throw new DomainException('Invalid Program transition.',422);
   if(!is_string($sourceLabel)||trim($sourceLabel)===''||strlen($sourceLabel)>100||
    preg_match('/[\x00-\x1f\x7f]/',$sourceLabel))throw new DomainException('Invalid Program source name.',422);
   $event['type']='program_cut';$event['transition']=$transition;
   $event['sourceLabel']=trim($sourceLabel);
   $event['label']=self::TRANSITIONS[$transition].' to '.$event['sourceLabel'];
   $state['director']['lastCamera']=['label'=>$event['sourceLabel'],'transition'=>$transition,'at'=>$event['at']];
  }elseif($action==='clear_presentation'){
   if(empty($state['director']['presentation']))return ['changed'=>false,'event'=>null];
   unset($state['director']['presentation']);
   $event['type']='clear_presentation';$event['label']='Cleared game presentation';
  }else{
   $cue=$input['cue']??'';
   if(!is_string($cue)||!isset(self::CUES[$cue]))throw new DomainException('Invalid live show cue.',422);
   $event['type']='cue';$event['cue']=$cue;
   $event['label']=self::CUES[$cue].($claim?' · '.$claim['name']:'');
   $subject=$claim['playerName']??$current['name'];
   $headline=match($cue){
    'turn_intro'=>$subject."'s turn",'prize_reveal'=>$claim?'Prize: '.$claim['name']:'Prize reveal',
    'reaction'=>$subject.' reacts','costume_focus'=>'Costume spotlight',
    'celebration'=>'Congratulations, '.$subject.'!','scene_cut'=>'Camera scene change'};
   $detail=$claim['name']??($current['costume']['name']??'Spin the Wheel');
   if($cue==='costume_focus'){
    $itemId=$input['itemId']??'';
    if(!is_string($itemId)||strlen($itemId)>100)throw new DomainException('Invalid costume layer.',422);
    if($itemId!==''){
     $items=array_merge($current['costume']['items']??[],$current['prizeCostumeItems']??[]);
     $found=null;foreach($items as $item)if(($item['id']??'')===$itemId){$found=$item;break;}
     if(!$found)throw new DomainException('The selected costume layer belongs to another player.',422);
     $detail=(string)$found['name'];$event['itemId']=$itemId;
    }
   }
   if($note!=='')$detail=trim($note);
   $state['director']['presentation']=['id'=>$event['id'],'cue'=>$cue,
    'headline'=>$headline,'detail'=>$detail,'expiresAtMs'=>$ms+15000];
  }
  $state['director']['events'][]=$event;
  if($action==='next_player')self::onTrigger($state,'next_player','turn-skip-'.$event['id'],$next);
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
    'currentPlayer'=>$current?['id'=>$current['id'],'name'=>$current['name'],'costume'=>$current['costume']??null,
     'prizeCostumeItems'=>$current['prizeCostumeItems']??[]]:null,
    'latestPrize'=>$latest?['id'=>$latest['id'],'name'=>$latest['name'],'playerName'=>$latest['playerName']??null,
     'playerId'=>$latest['playerId']??null,'costumeItem'=>$latest['costumeItem']??null]:null,
    'claims'=>array_map(static fn($c)=>['id'=>$c['id'],'name'=>$c['name'],'playerName'=>$c['playerName']??null],$claims)],
   'operators'=>$operators,'assignments'=>$state['director']['assignments']??[],
   'moments'=>array_slice($moments,-160),'recording'=>$recording,'takes'=>$takes,
   'presentation'=>$state['director']['presentation']??null,
   'rundown'=>self::rundown($state),
   'lastCamera'=>$state['director']['lastCamera']??null,
   'serverTimeMs'=>(int)round(microtime(true)*1000)];
 }
}
