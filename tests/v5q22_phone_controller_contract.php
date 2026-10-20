<?php
declare(strict_types=1);
require_once __DIR__.'/../lib/GameRegistry.php';
function expectV5Q22(bool $truth,string $label):void {if(!$truth)throw new RuntimeException($label);}
final class GameWithoutPhone implements GameModule {
 public function id():string{return 'no-phone';}
 public function manifest():array{return ['id'=>$this->id()];}
 public function defaults():array{return [];}
 public function validateSettings(array $settings):array{return $settings;}
 public function createSession(array $settings,string $id):array{return ['id'=>$id];}
 public function applySettings(array &$session,array $settings):void{}
 public function action(array &$session,string $verb,array $payload=[]):array{return [];}
 public function publicView(array $session):array{return $session;}
}
$without=new GameWithoutPhone();
expectV5Q22(!($without instanceof PhoneControllerProvider),'Phone controllers must be optional');
$wheel=GameRegistry::controller('spin-wheel');
expectV5Q22(($wheel['layout']??null)==='spin-wheel','Wheel needs its own controller layout');
expectV5Q22(in_array('spin',$wheel['actions'],true),'Wheel must declare spin action');
expectV5Q22(in_array('prize-accept',$wheel['actions'],true),'Wheel prize acceptance must remain available');
$manifest=GameRegistry::manifest();
expectV5Q22(($manifest[0]['phoneController']??null)===true,'Wheel manifest controller flag');
expectV5Q22(array_key_exists('phoneController',$manifest[1])&&$manifest[1]['phoneController']===null,'Future game controller cannot be assumed');
echo "V5Q.22 phone controller module contract passed\n";
