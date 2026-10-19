<?php
declare(strict_types=1);
require_once __DIR__.'/GameModule.php';
final class GameRegistry {
 /** To add Game 2, register its module here without changing SpinWheel or shared services. */
 private static function readyModules():array {require_once __DIR__.'/modules/SpinWheel.php';return [new SpinWheel()];}
 public static function modules():array {$map=[];foreach(self::readyModules() as $m)$map[$m->id()]=$m;return $map;}
 public static function module(string $id):GameModule {$m=self::modules()[$id]??null;if(!$m)throw new DomainException('This game is not ready.',404);return $m;}
 public static function manifest():array {
  return [...array_values(array_map(fn(GameModule $m)=>$m->manifest(),self::modules())),
    ['id'=>'game-2','name'=>'Game 2','description'=>'Reserved for the next game','status'=>'upcoming','path'=>null]];
 }
}
