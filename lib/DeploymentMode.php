<?php
declare(strict_types=1);
/** HomeServer installs must never place the database on an internet host. */
final class DeploymentMode {
 public static function assertDatabaseLocation(array $config):void {
  if(($config['deployment']??'cloud')!=='homeserver')return;
  $host=strtolower(trim((string)($config['db_host']??'')));
  if(!in_array($host,['127.0.0.1','localhost','::1'],true))
   throw new RuntimeException('HomeServer database must run locally on this computer.');
  $port=(int)($config['db_port']??0);
  if($port<1||$port>65535)throw new RuntimeException('Invalid local database port.');
 }
 public static function requiresCloud(array $config):bool {
  self::assertDatabaseLocation($config);
  // No SaaS or cloud login is required for normal gameplay.
  return false;
 }
}
