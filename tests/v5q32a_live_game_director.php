<?php
declare(strict_types=1);
define('LUCKY_TESTING',true);
require_once __DIR__.'/../lib/Bootstrap.php';
require_once __DIR__.'/../lib/LiveGameDirector.php';

function verifyDirector(bool $ok,string $message):void {
 if(!$ok)throw new RuntimeException('FAIL '.$message);
 echo "PASS $message\n";
}
function rejectDirector(callable $fn):bool {
 try{$fn();return false;}catch(DomainException){return true;}
}
// Show cues and turn advancement are pure state operations. No database is
// needed unless assigning an approved player's real phone to a claim.
final class DirectorNoConnection extends PDO {public function __construct(){}}
$db=new DirectorNoConnection();
$gameId=str_repeat('a',32);$prizeId=str_repeat('b',32);
$state=GameEngine::newSession(GameEngine::defaults(),$gameId);
$state['status']='active';
$state['multiplayer']=['players'=>[['id'=>'player-1','name'=>'Ada'],['id'=>'player-2','name'=>'Ben']],
 'hosts'=>[['id'=>'host-1','name'=>'Host 1']],'turnIndex'=>0,'turnHistory'=>[]];
$state['claims']=[['id'=>$prizeId,'name'=>'Golden Ticket','playerName'=>'Ada','playerId'=>'player-1','at'=>gmdate('c')]];
$originalVersion=$state['version'];
$first=['action'=>'cue','requestKey'=>'director_cue_test1','claimId'=>$prizeId,'cue'=>'prize_reveal','note'=>'Winner close-up'];
$result=LiveGameDirector::apply($db,$state,1,$first);
verifyDirector($result['changed']&&$result['event']['prizeName']==='Golden Ticket'&&$state['version']===$originalVersion+1,
 'Second-screen cue saves selected prize and advances synchronized version');
$replay=LiveGameDirector::apply($db,$state,1,$first);
verifyDirector(!$replay['changed']&&$result['event']['id']===$replay['event']['id'],
 'Replayed Director command does not duplicate an event');
verifyDirector(rejectDirector(static function()use(&$state,$db,$first){
 LiveGameDirector::apply($db,$state,1,[...$first,'note'=>'Changed payload']);
}), 'A reused request token cannot silently change the command payload');
$turn=LiveGameDirector::apply($db,$state,1,['action'=>'next_player','requestKey'=>'director_next_test2','claimId'=>'','note'=>'']);
verifyDirector($turn['event']['type']==='turn_skip'&&$state['multiplayer']['turnIndex']===1&&$turn['event']['nextPlayerName']==='Ben',
 'Next-player control advances the server turn without inventing a prize');
verifyDirector(rejectDirector(static function()use(&$state,$db){
 LiveGameDirector::apply($db,$state,1,['action'=>'cue','requestKey'=>'director_bad_cue3','cue'=>'unsafe','note'=>'']);
}), 'Unsupported show cues are rejected');
verifyDirector(rejectDirector(static function()use(&$state,$db){
 LiveGameDirector::apply($db,$state,1,['action'=>'cue','requestKey'=>'director_bad_claim4','cue'=>'reaction',
  'claimId'=>str_repeat('e',32),'note'=>'']);
}), 'Director cannot target an unrelated prize claim');
verifyDirector(rejectDirector(static function()use(&$state,$db,$prizeId){
 LiveGameDirector::apply($db,$state,1,['action'=>'assign_camera','requestKey'=>'director_bad_phone5',
  'claimId'=>$prizeId,'pairId'=>str_repeat('d',32),'note'=>'']);
}), 'Director cannot assign a phone that belongs to no player');
$state['lastSpin']=['type'=>'award','at'=>gmdate('c')];
verifyDirector(rejectDirector(static function()use(&$state,$db){
 LiveGameDirector::apply($db,$state,1,['action'=>'next_player','requestKey'=>'director_spin_busy6',
  'claimId'=>'','note'=>'']);
}), 'Director cannot skip a player during an unfinished wheel reveal');
$state['status']='ended';
verifyDirector(rejectDirector(static function()use(&$state,$db){
 LiveGameDirector::apply($db,$state,1,['action'=>'cue','requestKey'=>'director_game_ended7','cue'=>'reaction','note'=>'']);
}), 'An ended game rejects live Director actions');
$public=GameEngine::publicView($state);
verifyDirector(!array_key_exists('director',$public), 'Public gameplay view does not expose private Director event details');
echo "V5Q32A LIVE DIRECTOR CORE: 10/10\n";
