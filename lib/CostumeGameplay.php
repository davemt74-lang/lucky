<?php
declare(strict_types=1);

/** Host-authorized costume changes are immutable moments attached to a session snapshot. */
final class CostumeGameplay {
 public const MAX_EVENTS=512;

 public static function hasRequest(array $state,string $key):bool {
  if($key==='')return false;
  foreach($state['multiplayer']['costumeEvents']??[] as $event)
   if(($event['requestKey']??'')===$key)return true;
  return false;
 }

 public static function update(array &$state,string $playerId,string $itemId,string $action,string $requestKey,string $note='',string $claimId=''):array {
  if(!in_array($state['status']??'', ['active','paused'],true))
   throw new DomainException('Costume actions are available only during a running game.',409);
  if(!preg_match('/^[a-zA-Z0-9_-]{1,40}$/D',$playerId)||!preg_match('/^[a-zA-Z0-9_-]{1,40}$/D',$itemId))
   throw new DomainException('Invalid player or costume item.',422);
  if(!preg_match('/^[a-zA-Z0-9_-]{12,64}$/D',$requestKey))throw new DomainException('Invalid action request key.',422);
  if(!is_string($note)||strlen($note)>240||preg_match('/[\x00-\x1f\x7f]/',$note))throw new DomainException('Invalid costume moment note.',422);
  if($claimId!==''&&!preg_match('/^[a-f0-9]{32}$/D',$claimId))throw new DomainException('Invalid prize reference.',422);
  $roster=&$state['multiplayer'];
  if(!is_array($roster)||!is_array($roster['players']??null))throw new DomainException('This game has no player roster.',409);
  foreach($roster['costumeEvents']??[] as $prior)
   if(($prior['requestKey']??'')===$requestKey){
    if(($prior['playerId']??'')!==$playerId||($prior['itemId']??'')!==$itemId||($prior['action']??'')!==$action)
     throw new DomainException('Request key was already used for another costume action.',409);
    return ['changed'=>false,'event'=>$prior];
   }
  $events=$roster['costumeEvents']??[];
  if(count($events)>=self::MAX_EVENTS)throw new DomainException('Costume moment limit reached.',409);
  $playerIndex=null;$itemIndex=null;$source='costume';$role='players';
  foreach(['players','hosts'] as $candidateRole)foreach($roster[$candidateRole]??[] as $i=>$person)if(($person['id']??'')===$playerId){
   if($playerIndex!==null)throw new DomainException('Two participants have the same ID.',409);
   $role=$candidateRole;
   $playerIndex=$i;
   foreach($person['costume']['items']??[] as $j=>$item)if(($item['id']??'')===$itemId){
    if($itemIndex!==null)throw new DomainException('This costume has duplicate item IDs.',409);
    $itemIndex=$j;
   }
   foreach($person['prizeCostumeItems']??[] as $j=>$item)if(($item['id']??'')===$itemId){
    if($itemIndex!==null)throw new DomainException('This player has duplicate costume item IDs.',409);
    $itemIndex=$j;$source='prize';
   }
  }
  if($playerIndex===null||$itemIndex===null)throw new DomainException('Player costume item not found.',404);
  $person=&$roster[$role][$playerIndex];
  if($source==='prize')$item=&$person['prizeCostumeItems'][$itemIndex];
  else $item=&$person['costume']['items'][$itemIndex];
  $actions=$item['actions']??[['id'=>'equip','name'=>'Equip','kind'=>'equip'],['id'=>'remove','name'=>'Remove','kind'=>'remove']];
  $definition=null;
  foreach($actions as $candidate)if(($candidate['id']??'')===$action){$definition=$candidate;break;}
  if(!$definition)throw new DomainException('This action is not enabled for the selected costume item.',422);
  $kind=$definition['kind']??'cue';
  if(!in_array($kind,['equip','remove','cue'],true))throw new DomainException('Invalid saved costume action.',409);
  $equipped=$kind==='equip'?true:($kind==='remove'?false:($item['equipped']??true));
  if($kind!=='cue'&&($item['equipped']??true)===$equipped)return ['changed'=>false,'event'=>null];
  $ownerClaim=null;
  if($source==='prize'){
   if($claimId!==''&&$claimId!==($item['claimId']??''))throw new DomainException('This prize item belongs to another award.',422);
   $claimId=(string)($item['claimId']??'');
  }
  if($role==='hosts'&&$claimId!=='')throw new DomainException('Host costume actions cannot use a player prize claim.',422);
  if($claimId!==''){
   foreach($state['claims']??[] as $claim)if(($claim['id']??'')===$claimId){$ownerClaim=$claim;break;}
   if(!$ownerClaim||($ownerClaim['playerId']??'')!==$playerId)
    throw new DomainException('Prize does not belong to the selected player.',422);
  }elseif($role==='players'){
   foreach(array_reverse($state['claims']??[]) as $claim)if(($claim['playerId']??'')===$playerId){$ownerClaim=$claim;break;}
  }
  $item['equipped']=$equipped;
  $event=['id'=>bin2hex(random_bytes(16)),'requestKey'=>$requestKey,'at'=>gmdate('c'),
   'playerId'=>$person['id'],'playerName'=>$person['name'],'participantRole'=>$role==='hosts'?'host':'player',
   'costumeId'=>$item['costumeId']??($person['costume']['id']??''),
   'costumeName'=>$item['costumeName']??($person['costume']['name']??'Costume'),'itemId'=>$item['id'],'itemName'=>$item['name'],
   'category'=>$item['category']??'Accessory','layer'=>(int)($item['layer']??($itemIndex+1)),
  'action'=>$action,'actionName'=>$definition['name']??ucfirst($kind),'actionKind'=>$kind,'equipped'=>$equipped,'note'=>trim($note),
   'claimId'=>$ownerClaim['id']??null,'prizeName'=>$ownerClaim['name']??null,
   'turnNumber'=>(int)($ownerClaim['turnNumber']??(count($roster['turnHistory']??[])+1))];
  $roster['costumeEvents'][]=$event;
  $state['version']=(int)($state['version']??0)+1;
  return ['changed'=>true,'event'=>$event];
 }

 /** Public screens can show gameplay status without learning internal claim or retry keys. */
 public static function publicEvents(array $state):array {
  $events=$state['multiplayer']['costumeEvents']??[];
  foreach($events as &$event){unset($event['requestKey'],$event['claimId']);}
  unset($event);
  return $events;
 }
}
