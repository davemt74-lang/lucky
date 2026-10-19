<?php
declare(strict_types=1);
require_once __DIR__.'/../GameModule.php';require_once __DIR__.'/../GameEngine.php';
final class SpinWheel implements GameModule {
 public function id():string{return 'spin-wheel';}
 public function manifest():array{return ['id'=>$this->id(),'name'=>'Spin the Wheel','description'=>'Tier 1 → Tier 2 → Bonus','status'=>'ready','path'=>'game.php?game=spin-wheel'];}
 public function defaults():array{return GameEngine::defaults();}
 public function validateSettings(array $settings):array{return GameEngine::validateConfig($settings);}
 public function createSession(array $settings,string $id):array{return GameEngine::newSession($settings,$id);}
 public function applySettings(array &$s,array $settings):void{GameEngine::applyConfig($s,$settings);}
 public function action(array &$s,string $verb):array {
  switch($verb){
   case 'spin':return GameEngine::spin($s);
   case 'pause':GameEngine::pause($s);return ['type'=>'paused','version'=>$s['version']];
   case 'resume':GameEngine::resume($s);return ['type'=>'resumed','version'=>$s['version']];
   case 'end':if(!in_array($s['status'],['active','paused'],true))throw new DomainException('Game already ended.');GameEngine::end($s);return ['type'=>'ended','version'=>$s['version']];
   default:throw new DomainException('Unsupported game action.');
  }
 }
 public function publicView(array $s):array{return GameEngine::publicView($s);}
}
