<?php
declare(strict_types=1);
define('LUCKY_TESTING',true);
require_once __DIR__.'/../lib/Bootstrap.php';
require_once __DIR__.'/../lib/LiveGameDirector.php';

function verifyProgram(bool $ok,string $message):void {
 if(!$ok)throw new RuntimeException('FAIL '.$message);
 echo "PASS $message\n";
}
function rejectProgram(callable $callback):bool {
 try{$callback();return false;}catch(DomainException){return true;}
}
final class ProgramNoConnection extends PDO {public function __construct(){}}
$db=new ProgramNoConnection();
$state=GameEngine::newSession(GameEngine::defaults(),str_repeat('a',32));
$state['status']='active';
$state['multiplayer']=['players'=>[
 ['id'=>'player-1','name'=>'Ada','costume'=>['name'=>'Space Explorer','items'=>[['id'=>'hat','name'=>'Astronaut helmet']]],
  'prizeCostumeItems'=>[['id'=>'prize-helmet','name'=>'Golden visor']]],
 ['id'=>'player-2','name'=>'Ben','costume'=>null]],
 'hosts'=>[['id'=>'host-1','name'=>'Host 1']],'turnIndex'=>0,'turnHistory'=>[]];
$claim=str_repeat('b',32);
$state['claims']=[['id'=>$claim,'name'=>'Rocket Prize','playerName'=>'Ada','playerId'=>'player-1','at'=>gmdate('c')]];
$baseline=(int)$state['version'];
$cue=['action'=>'cue','requestKey'=>'program_cue_good_0001','cue'=>'costume_focus','itemId'=>'hat',
 'note'=>'','claimId'=>''];
$first=LiveGameDirector::apply($db,$state,1,$cue);
verifyProgram($first['changed']&&($state['director']['presentation']['detail']??'')==='Astronaut helmet',
 'Costume spotlight shows the selected item layer on the stage');
$public=GameEngine::publicView($state);
verifyProgram(($public['showPresentation']['headline']??'')==='Costume spotlight'&&
 ($public['showPresentation']['expiresAtMs']??0)>time()*1000,
 'Authorized live cue appears in the public game view with an expiry');
verifyProgram(!isset($public['director'])&&!isset($public['showProgram'])&&
 !isset($public['showPresentation']['token'])&&!isset($public['showPresentation']['key'])&&
 !isset($public['showPresentation']['broadcastId']),
 'Public audience data exposes no Director state or private broadcast invitation');
$replay=LiveGameDirector::apply($db,$state,1,$cue);
verifyProgram(!$replay['changed']&&$state['version']===$baseline+1,
 'Replayed cue remains idempotent');
verifyProgram(rejectProgram(static function()use(&$state,$db){
 LiveGameDirector::apply($db,$state,1,['action'=>'cue','requestKey'=>'program_bad_layer_002',
  'cue'=>'costume_focus','itemId'=>'other-player-layer']);
}), 'A costume layer not owned by the active player is rejected');
verifyProgram(rejectProgram(static function()use(&$state,$db){
 LiveGameDirector::apply($db,$state,1,['action'=>'program_cut','requestKey'=>'program_bad_cut_0003',
  'sourceLabel'=>'Cam A','transition'=>'unsupported']);
}), 'Unsupported Program transitions cannot enter the timeline');
verifyProgram(rejectProgram(static function()use(&$state,$db){
 LiveGameDirector::apply($db,$state,1,['action'=>'program_cut','requestKey'=>'program_bad_src_0004',
  'sourceLabel'=>"Camera\nInjected",'transition'=>'cut']);
}), 'Control characters cannot enter Program source names');
$cut=['action'=>'program_cut','requestKey'=>'program_cut_good_0005',
 'sourceLabel'=>'Phone camera · Ada','transition'=>'fade'];
$result=LiveGameDirector::apply($db,$state,1,$cut);
verifyProgram(($result['event']['type']??'')==='program_cut'&&
 ($state['director']['lastCamera']['label']??'')==='Phone camera · Ada'&&
 ($state['director']['lastCamera']['transition']??'')==='fade',
 'Real Program take can be logged as a synchronized camera cut');
$replay=LiveGameDirector::apply($db,$state,1,$cut);
verifyProgram(!$replay['changed'],
 'Program cut retries do not duplicate editing events');
$clear=LiveGameDirector::apply($db,$state,1,['action'=>'clear_presentation',
 'requestKey'=>'program_clear_good_006']);
verifyProgram($clear['changed']&&!isset($state['director']['presentation'])&&
 GameEngine::publicView($state)['showPresentation']===null,
 'Clear removes the lower third from both game screens');
$next=LiveGameDirector::apply($db,$state,1,['action'=>'next_player',
 'requestKey'=>'program_next_good_0007']);
verifyProgram(($next['event']['nextPlayerName']??'')==='Ben'&&
 ($state['director']['presentation']['headline']??'')==="Ben's turn",
 'Turn changes cue the new player on audience screens');
$state['status']='ended';
verifyProgram(GameEngine::publicView($state)['showPresentation']===null,
 'Ended games never keep a live presentation card visible');
verifyProgram(rejectProgram(static function()use(&$state,$db){
 LiveGameDirector::apply($db,$state,1,['action'=>'cue',
  'requestKey'=>'program_ended_cue_008','cue'=>'celebration']);
}), 'Ended sessions reject new Director cues');
echo "V5Q32B PROGRAM STAGE CORE: 10/10\n";
