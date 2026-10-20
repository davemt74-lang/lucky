<?php
declare(strict_types=1);
require_once __DIR__.'/../lib/GameEngine.php';
function check(bool $ok,string $label):void {if(!$ok)throw new RuntimeException($label);echo "PASS ".$label."\n";}
$s=GameEngine::newSession(GameEngine::defaults(),str_repeat('a',32));
$s['lastSpin']=['type'=>'award','at'=>gmdate('c',time()-2),'spinCount'=>1,'index'=>0,'slices'=>[['name'=>'Original winner','cents'=>1000]]];
$s['spinCount']=1;$s['version']=2;
check(SpinReveal::pending($s),'new persisted win remains private until animation completes');
check(GameEngine::publicView($s)['spinPending']===true,'desktop and audience receive pending-reveal flag');
$version=$s['version'];
try{SpinReveal::complete($s,2);throw new RuntimeException('stale spin was accepted');}catch(DomainException $e){check($e->getCode()===409,'stale spin cannot reveal a different prize');}
check(SpinReveal::complete($s,1),'matching finished spin can be revealed');
check(!SpinReveal::pending($s),'finished spin can be displayed to desktop and phone');
check($s['version']===$version+1,'revealing spin updates the shared session version');
check(SpinReveal::complete($s,1)===false,'repeated acknowledgement is idempotent');
check(GameEngine::publicView($s)['spinPending']===false,'reloaded game sees revealed spin');
$s['lastSpin']['revealedAt']=null;$s['lastSpin']['at']=gmdate('c',time()-25);
check(!SpinReveal::pending($s),'abandoned host spin eventually recovers without locking phone');
$s['lastSpin']['at']=gmdate('c');$s['lastSpin']['type']='bonus_trigger';
check(SpinReveal::pending($s),'bonus trigger also waits for wheel animation');
