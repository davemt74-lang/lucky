<?php
declare(strict_types=1);
require_once __DIR__.'/SchemaGuard.php';
/** One transient movie preview per owning host. The current timeline remains in the editor. */
final class MoviePreview {
 public static function requireReady(PDO $db):void {SchemaGuard::require($db,['movie_preview_state'],'Movie preview remote controls');}
 public static function row(PDO $db,int $owner,bool $lock=false):?array {
  if($owner<1)return null;
  $q=$db->prepare('SELECT admin_id,session_id,state,revision,updated_at FROM movie_preview_state WHERE admin_id=?'.($lock?' FOR UPDATE':''));
  $q->execute([$owner]);$row=$q->fetch(PDO::FETCH_ASSOC);return $row?:null;
 }
 public static function public(?array $row):?array {
  if(!$row||$row['state']==='stopped'||strtotime($row['updated_at'].' UTC')<time()-12)return null;
  return ['session'=>$row['session_id'],'state'=>$row['state'],'revision'=>(int)$row['revision']];
 }
 public static function open(PDO $db,int $owner,string $session):array {
  if($owner<1)throw new DomainException('Sign in as the host.',403);
  sessionRow($db,$session);
  self::requireReady($db);
  $q=$db->prepare("INSERT INTO movie_preview_state(admin_id,session_id,state,revision,updated_at) VALUES (?,?,'playing',1,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE session_id=VALUES(session_id),state='playing',revision=revision+1,updated_at=UTC_TIMESTAMP()");
  $q->execute([$owner,$session]);return self::public(self::row($db,$owner));
 }
 public static function stop(PDO $db,int $owner,string $session):void {
  self::requireReady($db);
  $q=$db->prepare("UPDATE movie_preview_state SET state='stopped',revision=revision+1,updated_at=UTC_TIMESTAMP() WHERE admin_id=? AND session_id=? AND state<>'stopped'");$q->execute([$owner,$session]);
 }
 public static function heartbeat(PDO $db,int $owner,string $session):?array {
  self::requireReady($db);
  $q=$db->prepare("UPDATE movie_preview_state SET updated_at=UTC_TIMESTAMP() WHERE admin_id=? AND session_id=? AND state<>'stopped'");
  $q->execute([$owner,$session]);$row=self::row($db,$owner);return ($row&&$row['session_id']===$session)?self::public($row):null;
 }
 public static function control(PDO $db,int $owner,string $session,string $verb):array {
  if(!in_array($verb,['play','pause','stop'],true))throw new DomainException('Invalid movie command.',422);
  self::requireReady($db);
  $row=self::row($db,$owner,true);
  if(!$row||$row['session_id']!==$session||$row['state']==='stopped'||strtotime($row['updated_at'].' UTC')<time()-12)
   throw new DomainException('Movie preview is not active on the desktop.',409);
  $new=['play'=>'playing','pause'=>'paused','stop'=>'stopped'][$verb];
  if($new!==$row['state']){
   $q=$db->prepare('UPDATE movie_preview_state SET state=?,revision=revision+1,updated_at=UTC_TIMESTAMP() WHERE admin_id=?');
   $q->execute([$new,$owner]);
  }
  return self::public(self::row($db,$owner))??['session'=>$session,'state'=>'stopped','revision'=>(int)$row['revision']+1];
 }
}
