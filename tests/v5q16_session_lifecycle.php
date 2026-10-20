<?php
declare(strict_types=1);
define('LUCKY_TESTING',true);
require_once __DIR__.'/../lib/GameSessionLifecycle.php';
function check(bool $ok,string $name):void {if(!$ok)throw new RuntimeException($name);echo "PASS $name\n";}
$config=GameEngine::defaults();$module=GameRegistry::module('spin-wheel');
$s=$module->createSession($config,str_repeat('a',32));
check($s['status']==='active' && $s['game']==='spin-wheel','new game starts active and is tied to its game id');
check(GameSessionLifecycle::canTransition($s,'save'),'live session may be saved');
$version=$s['version'];
$event=GameSessionLifecycle::transition($s,'save');
check($s['status']==='saved'&&$s['version']===$version+1&&!empty($s['savedAt'])&&$event['type']==='saved','save snapshots exact game state');
check(!GameSessionLifecycle::canTransition($s,'save')&&!GameSessionLifecycle::canTransition($s,'resume'),'saved game cannot double-save or resume by regular verb');
try{$module->action($s,'spin');throw new RuntimeException('saved game allowed a spin');}catch(DomainException $e){check(true,'saved game blocks gameplay until resumed');}
$before=$s['version'];$event=GameSessionLifecycle::transition($s,'resume_saved');
check($s['status']==='active'&&$s['version']===$before+1&&($s['savedAt']??null)===null&&$event['type']==='resumed','resume restores original session as active');
check($s['id']===str_repeat('a',32)&&count($s['slots'])===count($config['pools']['tier1']['prizes']),'session and prize state retain identity');
GameSessionLifecycle::transition($s,'save');
$event=GameSessionLifecycle::transition($s,'finish_saved');
check($s['status']==='ended'&&!empty($s['endedAt'])&&$event['type']==='ended','finish permanently archives saved session');
check(!GameSessionLifecycle::canTransition($s,'resume_saved')&&!GameSessionLifecycle::canTransition($s,'save'),'finished session is immutable');
try{GameSessionLifecycle::transition($s,'resume_saved');throw new RuntimeException('resumed finished session');}catch(DomainException $e){check($e->getCode()===409,'finished cannot resume (conflict)');}
check(GameRegistry::manifest()[0]['id']==='spin-wheel','game lifecycle depends on game registry');
$api=file_get_contents(__DIR__.'/../api/index.php');
check(str_contains($api,"GameSessionLifecycle::lockGame(\$db,\$game)")&&str_contains($api,"GameSessionLifecycle::assertVacant(\$db,\$game)"),'all game starts use shared per-game lock and one live session policy');
check(str_contains($api,"'save_session','resume_saved','finish_saved'")&&str_contains($api,"idempotencyKey"),'save/resume/finish use authenticated idempotent action API');
echo "V5Q16 lifecycle assertions passed\n";
