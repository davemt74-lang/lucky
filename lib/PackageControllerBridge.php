<?php
declare(strict_types=1);
require_once __DIR__.'/PackageGameModule.php';

/** Bound packaged-game remote commands. All decisions remain server-side. */
final class PackageControllerBridge {
 public static function bound(array $pair,array $row,string $viewer,int $hostOwner):void {
  if(($pair['status']??null)!=='paired'||
     empty($pair['paired_expires_at'])||strtotime((string)$pair['paired_expires_at'].' UTC')<=time())
   throw new DomainException('Controller is not approved or has expired.',403);
  if(!isset($pair['host_admin_id'])||(int)$pair['host_admin_id']!==$hostOwner||
     $hostOwner<=0)
   throw new DomainException('Controller is not approved for this host.',403);
  if(strlen($viewer)!==48||!preg_match('/^[a-f0-9]{48}$/D',$viewer)||
     !hash_equals((string)($row['viewer_token']??''),$viewer))
   throw new DomainException('Game controller link is invalid.',403);
  if(!preg_match('/^[a-f0-9]{32}$/D',(string)($row['id']??''))||
     !in_array(($row['status']??null),['active','paused','ended','saved'],true))
   throw new DomainException('Game session unavailable.',403);
 }
 public static function action(mixed $name,mixed $payload,mixed $confirmed):array {
  if(!is_string($name)||!in_array($name,['input','pause','resume','end'],true))
   throw new DomainException('Unsupported package controller action.',400);
  if($name==='end'&&$confirmed!==true)
   throw new DomainException('Confirm End Game on the phone first.',409);
  if($name!=='input')return ['verb'=>$name,'payload'=>[]];
  if(!is_array($payload)||array_keys($payload)!==['name','value'])
   throw new DomainException('Invalid controller payload.',422);
  $key=$payload['name'];$value=$payload['value'];
  if(!is_string($key)||!preg_match('/^[a-z][a-z0-9_-]{0,39}$/D',$key)||
    !(is_int($value)||is_bool($value)||is_string($value)||$value===null)||
    is_string($value)&&strlen($value)>240)
   throw new DomainException('Invalid game input.',422);
  return ['verb'=>'input','payload'=>['name'=>$key,'value'=>$value]];
 }
 public static function key(string $pairId,string $nonce):string {
  return hash('sha256','lucky-package-phone:'.$pairId.':'.$nonce);
 }
 public static function isPackage(GameModule $module):bool {
  return $module instanceof PackageGameModule && $module->hasPhone();
 }
}
