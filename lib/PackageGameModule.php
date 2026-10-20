<?php
declare(strict_types=1);
require_once __DIR__.'/GameModule.php';
require_once __DIR__.'/PhoneControllerProvider.php';

/**
 * Data-only module for versioned game packages. No uploaded PHP is executed.
 * External HTML/JS runs only in an opaque-origin sandboxed browser iframe.
 */
final class PackageGameModule implements GameModule, PhoneControllerProvider {
 public function __construct(private array $package){}
 public function id():string{return (string)$this->package['id'];}
 public function manifest():array{return ['id'=>$this->id(),'name'=>$this->package['title'],
  'description'=>'Installed game by '.$this->package['publisher'],
  'status'=>'ready','path'=>'package-game.php?game='.$this->id(),
  'packageVersion'=>$this->package['version']];}
 public function phoneController():array {
  if(!$this->package['phone'])throw new LogicException('No phone interface for this game.');
  return ['game'=>$this->id(),'mode'=>'custom','layout'=>'package-'.$this->id(),
   'actions'=>['input','end','pause','resume']];
 }
 public function hasPhone():bool{return (bool)$this->package['phone'];}
 public function defaults():array{return ['packageVersion'=>$this->package['version']];}
 public function validateSettings(array $settings):array{return ['packageVersion'=>$this->package['version']];}
 public function createSession(array $settings,string $id):array {
  return ['id'=>$id,'game'=>$this->id(),'status'=>'active','version'=>1,
   'packageVersion'=>$this->package['version'],'events'=>[],
   'lastInput'=>null,'createdAt'=>gmdate('c'),'endedAt'=>null];
 }
 public function applySettings(array &$session,array $settings):void {
  // Live sessions keep the package version with which they began.
 }
 public function action(array &$s,string $verb,array $payload=[]):array {
  if(!in_array($s['status']??'', ['active','paused'],true))
   throw new DomainException('Game session is no longer live.',409);
  $version=$s['version']+1;
  switch($verb){
   case 'pause':
    if($s['status']!=='active')throw new DomainException('Game already paused.',409);
    $s['status']='paused';break;
   case 'resume':
    if($s['status']!=='paused')throw new DomainException('Game is not paused.',409);
    $s['status']='active';break;
   case 'end':
    $s['status']='ended';$s['endedAt']=gmdate('c');break;
   case 'input':
    if($s['status']!=='active')throw new DomainException('Game is paused.',409);
    $name=$payload['name']??null;$value=$payload['value']??null;
    if(!is_string($name)||!preg_match('/^[a-z][a-z0-9_-]{0,39}$/D',$name)||
       !(is_bool($value)||is_int($value)||is_string($value)||$value===null)||
       is_string($value)&&strlen($value)>240)
     throw new DomainException('Invalid package controller input.',422);
    $event=['type'=>'input','name'=>$name,'value'=>$value,'version'=>$version];
    $s['lastInput']=$event;
    $s['events'][]=$event;$s['events']=array_slice($s['events'],-24);
    break;
   default:throw new DomainException('Unsupported packaged game action.',400);
  }
  $s['version']=$version;
  return ['type'=>$verb,'version'=>$version];
 }
 public function publicView(array $s):array {
  return ['id'=>$s['id'],'game'=>$this->id(),'status'=>$s['status'],
    'version'=>$s['version'],'packageVersion'=>$s['packageVersion'],
    'lastInput'=>$s['lastInput']??null,'events'=>$s['events']??[]];
 }
}
