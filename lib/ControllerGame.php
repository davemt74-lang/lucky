<?php
declare(strict_types=1);
/** Phone actions are applied to the same persisted game session as the host/TV.
 * No phone can select a prize, set odds, or bypass the game engine. */
require_once __DIR__.'/ProofHandoff.php';
require_once __DIR__.'/PrizePresentation.php';
final class ControllerGame {
 public const DIRECT=['start','spin','pause','resume','end','prize-accept','prize-decline'];
 public static function current(PDO $db,bool $locked=false):?array {
  $suffix=$locked?' FOR UPDATE':'';
  $q=$db->query("SELECT * FROM game_sessions WHERE game_id='spin-wheel' AND status IN ('active','paused') ORDER BY created_at DESC,id DESC LIMIT 1".$suffix);
  return $q->fetch()?:null;
 }
 public static function latest(PDO $db):?array {
  return self::current($db)?:($db->query("SELECT * FROM game_sessions WHERE game_id='spin-wheel' ORDER BY created_at DESC,id DESC LIMIT 1")->fetch()?:null);
 }
 public static function snapshot(PDO $db):array {
  // Existing installations may not yet have run the V5Q.15B migration.
  // Keep the paired controller alive while disabling unsafe prize actions.
  $prizeReady=PrizeDecision::available($db);
  $row=self::latest($db);
  $choice=['selected'=>null,'packages'=>[]];
  try{$choice=PrizePackages::read($db,false);}catch(DomainException $e){/* Older hosts need upgrade.php. */}
  if(!$row)return ['packages'=>$choice['packages'],'selectedPackage'=>$choice['selected'],'offer'=>null,'upgradeRequired'=>!$prizeReady,'id'=>null,'game'=>'spin-wheel','status'=>'ready','version'=>0,'spinCount'=>0,'claimedCount'=>0,'claimedCents'=>0,'remaining'=>0,'pendingBonus'=>false,'lastPrize'=>null,'spinning'=>false,'canStart'=>$prizeReady,'canSpin'=>false];
  $state=stateFromRow($row);$view=GameRegistry::module($state['game'])->publicView($state);
  $last=$state['lastSpin']??null;$spinning=SpinReveal::pending($state);
  $claimed=$view['claims']??[];
  if($spinning&&($last['type']??null)==='award')array_pop($claimed);
  return [
   'packages'=>$choice['packages'],'selectedPackage'=>$choice['selected'],
   'package'=>$state['prizePackage']??null,
   'id'=>$view['id'],'game'=>$view['game'],'status'=>$view['status'],'version'=>$view['version'],
   'spinCount'=>$view['spinCount'],'claimedCount'=>count($claimed),'claimedCents'=>array_sum(array_column($claimed,'cents')),
   'remaining'=>count(array_filter($view['slots'],fn($s)=>!empty($s['tier']))),
   'pendingBonus'=>$view['pendingBonus'],'lastPrize'=>$claimed?end($claimed)['name']:null,
   'spinning'=>$spinning,'offer'=>$prizeReady&&!$spinning?PrizePresentation::decorate(PrizeDecision::latest($db,$state['id']),$state):null,'upgradeRequired'=>!$prizeReady,'canStart'=>$prizeReady&&in_array($view['status'],['ended','saved'],true),
   'canSpin'=>$prizeReady&&$view['status']==='active'&&!$spinning&&!PrizeDecision::pending($db,$state['id'])&&(count(array_filter($view['slots'],fn($s)=>!empty($s['tier'])))>0||$view['pendingBonus']||($state['odds']['bonusChance']>0&&self::hasBonus($state))),
  ];
 }
 private static function hasBonus(array $state):bool {
  $claimed=array_fill_keys(array_column($state['claims'],'prizeId'),true);
  foreach($state['pools']['bonus']['prizes'] as $p)if($p['cents']>0&&!isset($claimed[$p['id']]))return true;
  return false;
 }
 public static function spinReady(array $state):void {
  if(SpinReveal::pending($state)||(!empty($state['lastSpin']['at']) && (time()-strtotime($state['lastSpin']['at'])<4)))
   throw new DomainException('The wheel is finishing its last spin. Try again in a moment.',409);
 }
 public static function apply(PDO $db,string $command,string $pairId,string $nonce,int $hostAdminId):array {
  if(in_array($command,['start','spin','select','prize-accept','prize-decline'],true))PrizeDecision::requireReady($db);
  // The caller already holds the controller pairing row under a transaction.
  // Lock the shared game settings before creating a session, like api/index.php.
  if($command==='start'||$command==='select'||$command==='spin'){
   $q=$db->query("SELECT game_id FROM game_settings WHERE game_id='spin-wheel' FOR UPDATE");
   if(!$q->fetch())throw new DomainException('Configure Spin the Wheel before starting.',409);
  }
  $row=self::current($db,true);
  if($command==='start'||(($command==='spin'||$command==='select')&&!$row)){
   if($row)throw new DomainException('A game is already running. Use Spin or Resume.',409);
   $pkg=PrizePackages::currentConfig($db);
   $state=GameRegistry::module('spin-wheel')->createSession($pkg['config'],bin2hex(random_bytes(16)));
   $state['prizePackage']=['id'=>$pkg['id'],'name'=>$pkg['name']];
   $q=$db->prepare('INSERT INTO game_sessions(id,game_id,status,version,state_json,viewer_token) VALUES (?,?,?,?,?,?)');
   $q->execute([$state['id'],$state['game'],$state['status'],$state['version'],json_encode($state,JSON_THROW_ON_ERROR),token()]);
   // The real host owner must be present for TV-QR pairing and audit attribution.
   $q=$db->prepare('INSERT INTO audit_log(admin_id,event,session_id,details) VALUES (?,?,?,?)');
   $q->execute([$hostAdminId,'session.start',$state['id'],json_encode(['pairId'=>$pairId,'source'=>'approved-phone','packageId'=>$pkg['id']],JSON_THROW_ON_ERROR)]);
   return ['applied'=>true,'effect'=>'started','sessionId'=>$state['id']];
  }
  if(!$row)throw new DomainException('Start a game first.',409);
  $state=stateFromRow($row);
  if(in_array($command,['prize-accept','prize-decline'],true)){
   if(SpinReveal::pending($state))throw new DomainException('Wait for the wheel to stop before accepting a prize.',409);
   $offer=PrizeDecision::pending($db,$state['id'],true);
   if(!$offer)throw new DomainException('There is no prize awaiting acceptance.',409);
   PrizeDecision::choose($db,$state['id'],$offer['claim_id'],$command==='prize-accept'?'accepted':'declined');
   if($command==='prize-accept')ProofHandoff::activate($db,$state['id'],$offer['claim_id'],$hostAdminId);
   return ['applied'=>true,'effect'=>$command==='prize-accept'?'prize_accepted':'prize_declined','sessionId'=>$state['id']];
  }
  if($command==='spin'||$command==='select'){
   if($state['status']!=='active')throw new DomainException('The game is paused. Resume before spinning.',409);
   self::spinReady($state);PrizeDecision::assertReady($db,$state['id']);
   $key='phone_'.hash('sha256',$pairId.'_'.$nonce);
   $module=GameRegistry::module($state['game']);$result=$module->action($state,'spin');
   saveState($db,$state);
   if($result['type']==='award'){
    $c=$result['claim'];$q=$db->prepare('INSERT INTO game_claims(id,session_id,prize_id,tier,prize_name,cents,created_at) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP())');
    $q->execute([$c['id'],$state['id'],$c['prizeId'],$c['tier'],$c['name'],$c['cents']]);
    PrizeDecision::create($db,$state['id'],$c['id']);
   }
   $out=['event'=>$result,'view'=>$module->publicView($state)];
   $q=$db->prepare('INSERT INTO game_events(session_id,idempotency_key,event_type,payload_json) VALUES (?,?,?,?)');
   $q->execute([$state['id'],$key,$result['type'],json_encode($out,JSON_THROW_ON_ERROR)]);
   audit($db,'controller.game-spin',$state['id'],['pairId'=>$pairId,'result'=>$result['type']]);
   return ['applied'=>true,'effect'=>$result['type'],'sessionId'=>$state['id']];
  }
  if($command==='end'){
   if(!in_array($state['status'],['active','paused'],true))throw new DomainException('Game already ended.',409);
   GameRegistry::module($state['game'])->action($state,'end');saveState($db,$state);
   audit($db,'controller.game-end',$state['id'],['pairId'=>$pairId]);
   return ['applied'=>true,'effect'=>'ended','sessionId'=>$state['id']];
  }
  if($command==='pause'||$command==='resume'){
   $actual=$state['status']==='active'?'pause':($state['status']==='paused'?'resume':null);
   if(!$actual)throw new DomainException('This game is not running.',409);
   GameRegistry::module($state['game'])->action($state,$actual);saveState($db,$state);
   audit($db,'controller.game-'.$actual,$state['id'],['pairId'=>$pairId]);
   return ['applied'=>true,'effect'=>$actual,'sessionId'=>$state['id']];
  }
  throw new DomainException('Not a direct gameplay command.',400);
 }
}
