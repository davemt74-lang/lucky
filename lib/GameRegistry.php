<?php
declare(strict_types=1);
require_once __DIR__.'/GameModule.php';
require_once __DIR__.'/PhoneControllerProvider.php';
final class GameRegistry {
 /** Register gameplay modules here; a module may omit phone support entirely. */
 private static function readyModules():array {require_once __DIR__.'/modules/SpinWheel.php';return [new SpinWheel()];}
 public static function modules():array {$map=[];foreach(self::readyModules() as $m)$map[$m->id()]=$m;return $map;}
 public static function module(string $id):GameModule {$m=self::modules()[$id]??null;if(!$m)throw new DomainException('This game is not ready.',404);return $m;}
 /** The explicit controller declaration belongs to each game, not to the platform. */
 public static function controller(string $gameId):?array {
  $module=self::module($gameId);
  if(!$module instanceof PhoneControllerProvider)return null;
  $spec=$module->phoneController();
  if(!is_array($spec) || ($spec['game']??null)!==$gameId
    || !in_array($spec['mode']??null,['custom'],true)
    || !is_string($spec['layout']??null)
    || !preg_match('/^[a-z][a-z0-9-]{0,63}$/D',$spec['layout'])
    || !isset($spec['actions']) || !is_array($spec['actions'])
    || count($spec['actions'])>32)throw new LogicException('Invalid game phone controller definition.');
  $seen=[];
  foreach($spec['actions'] as $action){
   if(!is_string($action)||!preg_match('/^[a-z][a-z0-9-]{0,63}$/D',$action)||isset($seen[$action]))
    throw new LogicException('Invalid or duplicate phone controller action.');
   $seen[$action]=true;
  }
  return ['game'=>$gameId,'mode'=>'custom','layout'=>$spec['layout'],'actions'=>array_values($spec['actions'])];
 }
 public static function manifest():array {
  $list=[];
  foreach(self::modules() as $module){
   $entry=$module->manifest();
   $entry['phoneController']=self::controller($module->id())!==null;
   $list[]=$entry;
  }
  $list[]=['id'=>'game-2','name'=>'Game 2','description'=>'Reserved for the next game','status'=>'upcoming','path'=>null,'phoneController'=>null];
  return $list;
 }
}
