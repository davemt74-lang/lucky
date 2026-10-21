<?php
declare(strict_types=1);

/**
 * Read-side contract for the *current live* session on a HomeServer.
 * Historical/saved sessions are available only through explicit session/history APIs.
 * A package chooser and a controller in READY mode must never inherit old claims.
 */
final class CurrentGame {
 public const LIVE=['active','paused'];

 public static function isLive(?array $state):bool {
  return is_array($state)&&in_array($state['status']??null,self::LIVE,true);
 }

 public static function row(PDO $db,string $game='spin-wheel',bool $lock=false):?array {
  if(!preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/D',$game))throw new DomainException('Invalid game ID.',400);
  $sql="SELECT * FROM game_sessions WHERE game_id=? AND status IN ('active','paused') ORDER BY created_at DESC,id DESC LIMIT 1".($lock?' FOR UPDATE':'');
  $query=$db->prepare($sql);$query->execute([$game]);
  return $query->fetch(PDO::FETCH_ASSOC)?:null;
 }
}
