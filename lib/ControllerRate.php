<?php
declare(strict_types=1);
/** Separate UI-navigation throughput from rate-limited prize-changing actions. */
final class ControllerRate {
 public const NAV=['up','down','left','right','select','back','menu','done','home'];
 public static function limits(string $command):array {return in_array($command,self::NAV,true)?[18,360]:[5,90];}
 public static function check(int $lastSecond,int $lastMinute,string $command):void {
  [$second,$minute]=self::limits($command);
  if($lastSecond >= $second || $lastMinute >= $minute)throw new DomainException(
   in_array($command,self::NAV,true)?'Navigation is receiving too many rapid presses. Try again in a moment.':'Controller input rate limit reached. Try again in a moment.',429);
 }
}
