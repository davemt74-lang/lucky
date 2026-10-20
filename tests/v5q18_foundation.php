<?php
declare(strict_types=1);
define('LUCKY_TESTING',true);
require_once __DIR__.'/../lib/GameFoundation.php';
require_once __DIR__.'/../lib/GameSessionLifecycle.php';
require_once __DIR__.'/../lib/DeploymentMode.php';
function yes(bool $ok,string $message):void {if(!$ok)throw new RuntimeException('FAIL '.$message);echo "PASS ".$message."\n";}
final class ExampleGameTwo implements GameModule {
 public function id():string{return 'game-2';}
 public function manifest():array{return ['id'=>'game-2','name'=>'Example Game Two','description'=>'Module contract fixture','status'=>'ready','path'=>'game-two.php'];}
 public function defaults():array{return ['rounds'=>3];}
 public function validateSettings(array $settings):array{if(($settings['rounds']??null)!==3)throw new DomainException('Invalid test settings.');return $settings;}
 public function createSession(array $settings,string $id):array{
  return ['id'=>$id,'game'=>'game-2','status'=>'active','version'=>1,'round'=>0,'rounds'=>$settings['rounds'],'endedAt'=>null];
 }
 public function applySettings(array &$s,array $settings):void {$s['rounds']=$settings['rounds'];$s['version']++;}
 public function action(array &$s,string $verb,array $payload=[]):array {
  if($verb==='next_round'&&$s['status']==='active'&&($payload['answer']??'yes')==='yes'){$s['round']++;$s['version']++;return ['type'=>'round_advanced','version'=>$s['version']];}
  if($verb==='pause'&&$s['status']==='active'){$s['status']='paused';$s['version']++;return ['type'=>'paused'];}
  if($verb==='resume'&&$s['status']==='paused'){$s['status']='active';$s['version']++;return ['type'=>'resumed'];}
  if($verb==='end'&&in_array($s['status'],['active','paused'],true)){$s['status']='ended';$s['endedAt']=gmdate('c');$s['version']++;return ['type'=>'ended'];}
  throw new DomainException('Invalid game state.',409);
 }
 public function publicView(array $s):array {return ['id'=>$s['id'],'game'=>$s['game'],'status'=>$s['status'],'version'=>$s['version'],'round'=>$s['round']];}
}
$example=new ExampleGameTwo();
$id=str_repeat('a',32);
$state=$example->createSession($example->validateSettings($example->defaults()),$id);
GameFoundation::checkSession($example,$state,$id);yes(true,'Game 2 module can start using shared session contract');
$round=$example->action($state,'next_round',['answer'=>'yes']);
yes($round['type']==='round_advanced'&&$state['version']===2&&$state['round']===1,'independent game-specific round action increments version');
$example->action($state,'pause');
yes($state['status']==='paused','Game 2 can pause without wheel logic');
$event=GameSessionLifecycle::transition($state,'save');
yes($state['status']==='saved'&&$event['version']===4,'Game 2 can persist through shared saved state');
yes(GameSessionLifecycle::canTransition($state,'resume_saved'),'generic saved resume transition is available');
$state['status']='paused';$example->action($state,'resume');
yes($state['round']===1&&$state['status']==='active','Game 2 resume retains round and state');
$example->action($state,'end');
yes($state['status']==='ended'&&!GameSessionLifecycle::canTransition($state,'save'),'finished game is immutable');
$bad=$example->createSession($example->defaults(),$id);$bad['id']='wrong';
try{GameFoundation::checkSession($example,$bad,$id);throw new RuntimeException('Invalid ID passed validation');}
catch(DomainException $e){yes($e->getCode()===422,'module contract rejects wrong session identity');}
$api=file_get_contents(__DIR__.'/../api/index.php');
$bootstrap=file_get_contents(__DIR__.'/../lib/GameFoundation.php');
yes(str_contains($api,"'game_action'")&&str_contains($api,"$"."module->action($"."s,$"."verb)") ,'API dispatches arbitrary game rules through the registered module');
yes(str_contains($api,"$"."s['game']==='spin-wheel'&&$"."verb==='spin'"),'wheel-only prize rules are isolated from Game 2');
yes(str_contains($api,'GameFoundation::checkSession'),'shared API validates newly created session');
yes(str_contains($bootstrap,'INSERT IGNORE INTO game_settings')&&str_contains($bootstrap,'FOR UPDATE'),'settings can initialize from registered module without migration');
yes(!str_contains(file_get_contents(__DIR__.'/../admin.php'),'PLATFORM CONTROL CENTER'),'duplicate Admin Studio headline is absent');
$local=['deployment'=>'homeserver','db_host'=>'127.0.0.1','db_port'=>3306];
DeploymentMode::assertDatabaseLocation($local);yes(true,'HomeServer accepts loopback database');
try{DeploymentMode::assertDatabaseLocation(['deployment'=>'homeserver','db_host'=>'cloud.example','db_port'=>3306]);throw new RuntimeException('Remote DB accepted');}
catch(RuntimeException $e){yes(str_contains($e->getMessage(),'locally'),'HomeServer rejects remote database hosts');}
DeploymentMode::assertDatabaseLocation(['db_host'=>'database','db_port'=>3306]);
yes(true,'Existing cloud deployment remains backward compatible');
echo "V5Q.18 game foundation contract passed\n";
