<?php
declare(strict_types=1);
/** Capability-scoped, short-lived phone controller pairing. No controller token can call the game engine. */
final class ControllerPairing {
 public const ALLOWED=['spin','select','back','menu','start','pause','resume','end','left','right','up','down','music-on','music-off','music-next','volume-up','volume-down','mute','unmute','done','music-play','music-pause','music-previous','effects-on','effects-off','speech-on','speech-off','home','proof-record','proof-stop','proof-play','proof-approve','proof-rerecord','proof-delete'];
 public static function checkId(string $id):void {
  if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new DomainException('Invalid pairing identifier.',400);
 }
 public static function checkSecret(string $secret):void {
  if(!preg_match('/^[a-f0-9]{64}$/D',$secret))throw new DomainException('Invalid pairing secret.',403);
 }
 public static function label(mixed $name):string {
  if(!is_string($name))throw new DomainException('Invalid phone name.');
  $name=trim($name);
  if($name===''||strlen($name)>48||preg_match('/[\x00-\x1f\x7f]/',$name))throw new DomainException('Enter a phone name of 1–48 characters.');
  return $name;
 }
 public static function command(mixed $value):string {
  if(!is_string($value)||!(in_array($value,self::ALLOWED,true)||preg_match('/^volume-(?:100|[0-9]{1,2})$/D',$value)||preg_match('/^music-find-[a-z0-9](?:[a-z0-9-]{0,78}[a-z0-9])?$/D',$value)))throw new DomainException('Controller command not allowed.',400);
  return $value;
 }
 public static function nonce(mixed $value):string {
  if(!is_string($value)||!preg_match('/^[a-zA-Z0-9_-]{12,80}$/D',$value))throw new DomainException('Invalid command identifier.',400);
  return $value;
 }
 public static function tokenHash(string $secret):string {self::checkSecret($secret);return hash('sha256',hex2bin($secret));}
 public static function authenticate(PDO $db,string $id,string $secret,bool $needsApproval=false):array {
  self::checkId($id);$hash=self::tokenHash($secret);
  $q=$db->prepare('SELECT * FROM controller_pairs WHERE id=?');$q->execute([$id]);$row=$q->fetch();
  if(!$row||!$row['controller_hash']||!hash_equals($row['controller_hash'],$hash))throw new DomainException('Controller authorization expired or invalid.',403);
  if(in_array($row['status'],['revoked','expired'],true))throw new DomainException('This controller was disconnected.',403);
  if($needsApproval&&($row['status']!=='paired'||!$row['paired_expires_at']||strtotime($row['paired_expires_at'].' UTC')<=time()))throw new DomainException('Your phone has not been approved or its connection has expired.',403);
  return $row;
 }
 public static function status(array $row):string {
  if($row['status']==='inviting'||$row['status']==='pending')return strtotime($row['invite_expires_at'].' UTC')>time()?$row['status']:'expired';
  if($row['status']==='paired')return $row['paired_expires_at'] && strtotime($row['paired_expires_at'].' UTC')>time()?'paired':'expired';
  return $row['status'];
 }
 public static function inviteUrl(string $id,string $secret):string {
  self::checkId($id);self::checkSecret($secret);return 'controller.php?invite='.$id.'&key='.$secret;
 }
}
