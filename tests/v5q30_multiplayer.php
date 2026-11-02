<?php
declare(strict_types=1);
define('LUCKY_TESTING',true);
require_once __DIR__.'/../lib/Bootstrap.php';
require_once __DIR__.'/../lib/ControllerGame.php';
require_once __DIR__.'/../lib/PartyStudio.php';
require_once __DIR__.'/../lib/MontageAutoDraft.php';
require_once __DIR__.'/../lib/MontageClaimTimeline.php';
function checkMulti(bool $ok,string $message):void {if(!$ok)throw new RuntimeException('FAIL '.$message);echo "PASS $message\n";}
function deniedMulti(callable $fn):bool {try{$fn();return false;}catch(DomainException){return true;}}
$cfg=GameEngine::defaults();$state=GameEngine::newSession($cfg,str_repeat('a',32));
$a=str_repeat('1',32);$b=str_repeat('2',32);
$state['multiplayer']=PartyStudio::defaultRoster();
$state['multiplayer']['players']=[
 ['id'=>'player-1','name'=>'Ada','role'=>'player','phonePairId'=>$a,'costume'=>['id'=>'suit','name'=>'Astronaut','items'=>[['name'=>'Helmet','layer'=>1],['name'=>'Boots','layer'=>2]]]],
 ['id'=>'player-2','name'=>'Ben','role'=>'player','phonePairId'=>$b,'costume'=>null]
];
checkMulti(count($state['multiplayer']['players'][0]['costume']['items'])===2,'Costume pieces stay itemized in the session');
checkMulti(deniedMulti(static fn()=>ControllerGame::assertPlayerPhoneTurn($state,$b)),'Other player phone cannot spin outside its turn');
ControllerGame::assertPlayerPhoneTurn($state,$a);
$first=GameEngine::spin($state,1,0);
checkMulti($first['type']==='award'&&$first['claim']['playerName']==='Ada'&&$first['claim']['turnNumber']===1,'Award attributed to current player and turn');
checkMulti($state['multiplayer']['turnIndex']===1&&count($state['multiplayer']['turnHistory'])===1,'Award advances to next player');
ControllerGame::assertPlayerPhoneTurn($state,$b);
checkMulti(deniedMulti(static fn()=>ControllerGame::assertPlayerPhoneTurn($state,$a)),'Previous player cannot spin next turn');
ControllerGame::assertPlayerPhoneTurn($state,$a,true);
checkMulti(deniedMulti(static fn()=>ControllerGame::assertPlayerPhoneTurn($state,$b,true)),'Prize decisions stay assigned to winning player');
$view=GameEngine::publicView($state);
checkMulti(!isset($view['multiplayer']['players'][0]['phonePairId'])&&!isset($view['multiplayer']['turnHistory'][0]['claimId']),
 'Public display hides phone pairing and claim IDs');
checkMulti($view['claims'][0]['playerName']==='Ada'&&$view['multiplayer']['players'][0]['costume']['items'][0]['name']==='Helmet',
 'Display can show player turn and individual costume pieces');
$clipId=str_repeat('3',32);
$draft=MontageAutoDraft::withTurnLabels([['id'=>$clipId,'claim_id'=>$first['claim']['id'],'title'=>'Reaction video']],$state);
checkMulti(str_starts_with($draft[0]['title'],'Turn 1 · Ada · Reaction'),'Auto-video timeline labels winning player');
$labeledClip=$draft[0]+['kind'=>'video'];
$timeline=MontageClaimTimeline::apply([],[$labeledClip+['claim_id'=>$first['claim']['id']]],
 $labeledClip+['claim_id'=>$first['claim']['id']],[$first['claim']['id']]);
checkMulti(str_starts_with($timeline[0]['title'],'Turn 1 · Ada'),'New video segment keeps turn attribution in editable timeline');
$bonusCfg=GameEngine::defaults();$bonusCfg['pools']['bonus']=['budgetCents'=>100,'prizes'=>[['id'=>'b-1','name'=>'Bonus','cents'=>100]]];
$bonusCfg['odds']['bonusChance']=100;
$bonus=GameEngine::newSession($bonusCfg,str_repeat('c',32));$bonus['multiplayer']=$state['multiplayer'];$bonus['multiplayer']['turnIndex']=0;$bonus['multiplayer']['turnHistory']=[];
$trigger=GameEngine::spin($bonus,0.0,0.0);
checkMulti($trigger['type']==='bonus_trigger'&&$bonus['multiplayer']['turnIndex']===0,'Bonus trigger does not skip the current player');
$won=GameEngine::spin($bonus,0.0,0.0);
checkMulti($won['type']==='award'&&$won['claim']['playerName']==='Ada'&&$bonus['multiplayer']['turnIndex']===1,
 'Bonus prize belongs to the same player, then rotates turn');
echo "V5Q30 MULTIPLAYER ENGINE: 12/12\n";
