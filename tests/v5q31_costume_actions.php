<?php
declare(strict_types=1);
define('LUCKY_TESTING',true);
require_once __DIR__.'/../lib/Bootstrap.php';
require_once __DIR__.'/../lib/CostumeGameplay.php';
function checkCostume(bool $ok,string $message):void {if(!$ok)throw new RuntimeException('FAIL '.$message);echo "PASS $message\n";}
function rejectCostume(callable $fn):bool {try{$fn();return false;}catch(DomainException){return true;}}
$id=str_repeat('a',32);$cue=str_repeat('e',32);
$state=GameEngine::newSession(GameEngine::defaults(),$id);
$state['multiplayer']=['players'=>[['id'=>'player-1','name'=>'Ada','costume'=>['id'=>str_repeat('c',32),'name'=>'Astronaut','items'=>[
 ['id'=>'helmet','name'=>'Helmet','layer'=>1,'category'=>'Prop','actions'=>[
  ['id'=>'equip','name'=>'Equip','kind'=>'equip'],['id'=>'remove','name'=>'Remove','kind'=>'remove'],['id'=>$cue,'name'=>'Reveal visor','kind'=>'cue']]]]]]],
 'hosts'=>[['id'=>'host-1','name'=>'Host 1','costume'=>['id'=>'host-costume','name'=>'MC','items'=>[
  ['id'=>'hat','name'=>'Top hat','actions'=>[['id'=>$cue,'name'=>'Tip hat','kind'=>'cue']]]]]]],'turnIndex'=>0,'turnHistory'=>[]];
$key1='costume_action_test1';$key2='costume_action_test2';$key3='costume_action_test3';
$result=CostumeGameplay::update($state,'player-1','helmet','remove',$key1);
checkCostume($result['changed']&&$state['multiplayer']['players'][0]['costume']['items'][0]['equipped']===false,'Remove changes only selected player layer');
$version=$state['version'];$same=CostumeGameplay::update($state,'player-1','helmet','remove',$key1);
checkCostume(!$same['changed']&&$state['version']===$version,'Repeated request key is idempotent');
checkCostume(rejectCostume(static function()use(&$state,$key1,$cue){CostumeGameplay::update($state,'player-1','helmet',$cue,$key1);}),
 'A request key cannot be reused for a different action');
$result=CostumeGameplay::update($state,'player-1','helmet',$cue,$key2,'Dramatic reveal');
checkCostume($result['changed']&&$result['event']['actionName']==='Reveal visor'&&$result['event']['equipped']===false,
 'Custom action creates a named moment without changing equipped status');
CostumeGameplay::update($state,'player-1','helmet','equip',$key3);
checkCostume($state['multiplayer']['players'][0]['costume']['items'][0]['equipped']===true,'Equip returns selected layer to equipped');
checkCostume(rejectCostume(static function()use(&$state){CostumeGameplay::update($state,'player-1','helmet','dance','unique_action_request4');}),
 'Disabled actions cannot be executed');
$host=CostumeGameplay::update($state,'host-1','hat',$cue,'host_costume_action1');
checkCostume($host['changed']&&$host['event']['participantRole']==='host'&&$host['event']['claimId']===null,
 'Host costume layers support selected actions without claiming a player prize');
$prizeLink=['costumeId'=>str_repeat('c',32),'costumeName'=>'Astronaut','itemId'=>'boots','itemName'=>'Boots',
 'category'=>'Footwear','layer'=>2,'actions'=>[['id'=>'equip','name'=>'Equip','kind'=>'equip'],['id'=>$cue,'name'=>'Celebrate','kind'=>'cue']]];
$state['prizeCostumeMap']['t1-1']=$prizeLink;
$won=GameEngine::spin($state,1,0);
checkCostume($won['type']==='award'&&$won['claim']['costumeItem']['itemName']==='Boots','Prize award snapshots attached costume item');
$prizeItems=$state['multiplayer']['players'][0]['prizeCostumeItems'];
checkCostume(count($prizeItems)===1&&!$prizeItems[0]['equipped']&&$prizeItems[0]['claimId']===$won['claim']['id'],
 'Prize item is staged for winning player without automatic equipping');
$result=CostumeGameplay::update($state,'player-1',$prizeItems[0]['id'],'equip','prize_costume_action5');
checkCostume($result['changed']&&$result['event']['claimId']===$won['claim']['id'],'Host can equip awarded prize item with exact prize association');
$public=GameEngine::publicView($state);
checkCostume(!isset($public['multiplayer']['players'][0]['prizeCostumeItems'][0]['claimId'])&&
 !isset($public['multiplayer']['costumeEvents'][0]['requestKey']), 'Public view excludes host retry and private prize references');
echo "V5Q31 COSTUME GAMEPLAY: 11/11\n";
