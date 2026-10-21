<?php
declare(strict_types=1);
require __DIR__.'/../lib/CurrentGame.php';
require __DIR__.'/../lib/GameSessionLifecycle.php';
$checks=[];
function verifySession(string $label,bool $pass):void {global $checks;$checks[$label]=$pass;echo ($pass?'PASS ':'FAIL ').$label."\n";}
foreach(['active','paused'] as $status){
 verifySession($status.' is a live session',CurrentGame::isLive(['status'=>$status]));
 verifySession($status.' allows save',GameSessionLifecycle::canTransition(['status'=>$status],'save'));
}
foreach(['ended','saved','ready','pending',''] as $status){
 verifySession($status.' cannot populate current game',!CurrentGame::isLive(['status'=>$status]));
 verifySession($status.' cannot save',!GameSessionLifecycle::canTransition(['status'=>$status],'save'));
}
verifySession('No session is not live',!CurrentGame::isLive(null));
verifySession('Saved sessions may resume only by explicit action',GameSessionLifecycle::canTransition(['status'=>'saved'],'resume_saved'));
verifySession('Saved sessions may finish only by explicit action',GameSessionLifecycle::canTransition(['status'=>'saved'],'finish_saved'));
verifySession('Finished sessions cannot be resumed',!GameSessionLifecycle::canTransition(['status'=>'ended'],'resume_saved'));
$state=['id'=>str_repeat('a',32),'status'=>'active','version'=>5];
$result=GameSessionLifecycle::transition($state,'save');
verifySession('Save transitions active to saved',$state['status']==='saved'&&$result['type']==='saved');
verifySession('Save increments authoritative state version once',$state['version']===6);
verifySession('Saved state no longer presents as live',!CurrentGame::isLive($state));
echo 'V5Q.25 session contract: '.count(array_filter($checks)).' / '.count($checks)."\n";
if(in_array(false,$checks,true))exit(1);
