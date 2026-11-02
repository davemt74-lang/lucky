<?php
declare(strict_types=1);
define('LUCKY_TESTING',true);
require_once __DIR__.'/../lib/Bootstrap.php';
require_once __DIR__.'/../lib/LiveGameDirector.php';

function checkRundown(bool $ok,string $message):void {
 if(!$ok)throw new RuntimeException('FAIL '.$message);
 echo 'PASS '.$message."\n";
}
function rejectsRundown(callable $run):bool {try{$run();return false;}catch(DomainException){return true;}}
final class RundownNoDatabase extends PDO {public function __construct(){}}
$db=new RundownNoDatabase();
$state=GameEngine::newSession(GameEngine::defaults(),str_repeat('a',32));
$state['multiplayer']=['players'=>[['id'=>'player-1','name'=>'Ada'],['id'=>'player-2','name'=>'Ben']],
 'hosts'=>[['id'=>'host-1','name'=>'MC']],'turnIndex'=>0,'turnHistory'=>[]];
$default=LiveGameDirector::rundown($state);
checkRundown(count($default['steps'])===6&&!$default['enabled']&&!$default['paused'],
 'New games start with an editable 6-step rundown and automation off');
checkRundown(!LiveGameDirector::onTrigger($state,'spin_start','spin-1')&&empty($state['director']['presentation']),
 'Automated cues never fire without explicit Director activation');
$save=['action'=>'rundown_save','requestKey'=>'rundown_save_first_0001','claimId'=>'',
 'steps'=>$default['steps']];
$result=LiveGameDirector::apply($db,$state,1,$save);
checkRundown($result['changed']&&($state['director']['rundown']['steps'][0]['id']??'')==='intro',
 'Host saves a validated timeline in server-owned game state');
$version=$state['version'];
checkRundown(!LiveGameDirector::apply($db,$state,1,$save)['changed']&&$state['version']===$version,
 'Retried rundown edits are idempotent');
$bad=$default['steps'];$bad[0]['durationSec']=180;
checkRundown(rejectsRundown(static function()use(&$state,$db,$bad){
 LiveGameDirector::apply($db,$state,1,['action'=>'rundown_save','requestKey'=>'rundown_bad_time_0002','steps'=>$bad]);
}), 'Excessive cue duration is rejected');
$bad=$default['steps'];$bad[1]['id']=$bad[0]['id'];
checkRundown(rejectsRundown(static function()use(&$state,$db,$bad){
 LiveGameDirector::apply($db,$state,1,['action'=>'rundown_save','requestKey'=>'rundown_bad_ids_0003','steps'=>$bad]);
}), 'Duplicate timeline step identifiers are rejected');
$bad=$default['steps'];$bad[0]['detail']="Title\nInjected";
checkRundown(rejectsRundown(static function()use(&$state,$db,$bad){
 LiveGameDirector::apply($db,$state,1,['action'=>'rundown_save','requestKey'=>'rundown_bad_text_0004','steps'=>$bad]);
}), 'Unsafe control characters are rejected from show graphics');
LiveGameDirector::apply($db,$state,1,['action'=>'rundown_control','command'=>'enable','requestKey'=>'rundown_enable_000005']);
checkRundown(LiveGameDirector::rundown($state)['enabled'],
 'Automation starts only after an authenticated Director action');
checkRundown(LiveGameDirector::onTrigger($state,'spin_start','spin-1')&&
 ($state['director']['presentation']['detail']??'')==='Wheel spinning',
 'Wheel-start event presents generic text without leaking the prize');
$eventCount=count($state['director']['events']);
checkRundown(!LiveGameDirector::onTrigger($state,'spin_start','spin-1')&&count($state['director']['events'])===$eventCount,
 'Gameplay event deduplication prevents duplicate show cues');
$claim=['id'=>str_repeat('b',32),'name'=>'Space Helmet','playerName'=>'Ada','playerId'=>'player-1','at'=>gmdate('c')];
$state['claims'][]=$claim;
checkRundown(LiveGameDirector::onTrigger($state,'prize_reveal','reveal-1',$claim)&&
 ($state['director']['presentation']['headline']??'')==='Prize: Space Helmet',
 'Prize reveal cue uses authoritative awarded prize after reveal completion');
LiveGameDirector::apply($db,$state,1,['action'=>'rundown_control','command'=>'pause','requestKey'=>'rundown_pause_000006']);
checkRundown(!LiveGameDirector::onTrigger($state,'costume_action','costume-1')&&
 empty($state['director']['presentation']), 'Pause removes current lower-third and stops future automatic cues');
LiveGameDirector::apply($db,$state,1,['action'=>'rundown_control','command'=>'resume','requestKey'=>'rundown_resume_0007']);
checkRundown(LiveGameDirector::onTrigger($state,'costume_action','costume-1',[
 'playerName'=>'Ada','itemName'=>'Glitter cloak'])&&
 ($state['director']['presentation']['detail']??'')==='Glitter cloak',
 'Resuming allows timecoded costume actions to cue a spotlight');
LiveGameDirector::apply($db,$state,1,['action'=>'rundown_control','command'=>'next','requestKey'=>'rundown_next_000008']);
checkRundown(!empty($state['director']['rundown']['lastStepId'])&&
 !empty($state['director']['presentation']), 'Manual Next plays and tracks a saved timeline step');
LiveGameDirector::apply($db,$state,1,['action'=>'rundown_control','command'=>'replay','requestKey'=>'rundown_replay_0009']);
checkRundown(!empty($state['director']['presentation']), 'Replay restores the previous show cue');
LiveGameDirector::apply($db,$state,1,['action'=>'rundown_control','command'=>'skip','requestKey'=>'rundown_skip_00010']);
checkRundown(empty($state['director']['presentation']), 'Skip clears Program text and advances the rundown cursor');
$view=GameEngine::publicView($state);
checkRundown(!isset($view['director'])&&!isset($view['rundown'])&&!isset($view['showProgram']),
 'Audience state never receives private Director commands, rundown or stream credentials');
$director=file_get_contents(__DIR__.'/../director.php');$publisher=file_get_contents(__DIR__.'/../public/director-publish.js');
checkRundown(str_contains($director,'id="director-game-broadcast-url"')&&
 str_contains($director,'id="director-game-broadcast-copy"')&&
 str_contains($publisher,"url.searchParams.set('id',result.id)")&&
 str_contains($publisher,"if(visibility==='private')url.searchParams.set('key',result.key)"),
 'Live Director exposes the real Program viewing link with private invitation isolation');
echo "V5Q32D SHOW RUNDOWN / BROADCAST CORE: 18/18\n";
