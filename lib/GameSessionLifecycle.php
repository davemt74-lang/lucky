<?php
declare(strict_types=1);
require_once __DIR__.'/GameRegistry.php';require_once __DIR__.'/GameFoundation.php';

/**
 * Shared lifecycle for EVERY registered game module.
 * One live (active/paused) session per game; saved drafts can coexist and be
 * resumed later. Finished sessions are immutable and remain in history.
 * Use inside a DB transaction. Serialize start/resume with the game_settings row.
 */
final class GameSessionLifecycle {
 public const LIVE=['active','paused'];
 public const SAVED='saved';
 public const FINISHED='ended';

 /**
  * Serialize console start/resume across different game modules. Per-game locks
  * alone cannot stop two different games starting at the same time.
  * Call FIRST in a transaction, before lockGame/assertConsoleVacant.
  */
 public static function lockConsole(PDO $db):void {
  GameFoundation::settings($db,'spin-wheel'); // Stable, existing HomeServer lock row.
 }
 public static function assertConsoleVacant(PDO $db,?string $except=null):void {
  $q=$db->query("SELECT id FROM game_sessions WHERE status IN ('active','paused') ORDER BY created_at DESC,id DESC LIMIT 1 FOR UPDATE");
  $id=$q->fetchColumn();
  if($id!==false && $id!==$except)
   throw new DomainException('Another game is running on this HomeServer. Save or finish it first.',409);
 }
 public static function lockGame(PDO $db,string $game):array {
  return GameFoundation::settings($db,$game);
 }
 public static function assertVacant(PDO $db,string $game,?string $except=null):void {
  $q=$db->prepare("SELECT id FROM game_sessions WHERE game_id=? AND status IN ('active','paused') ORDER BY created_at DESC,id DESC LIMIT 1 FOR UPDATE");
  $q->execute([$game]);
  if(($id=$q->fetchColumn())!==false && $id!==$except)throw new DomainException('This game already has an active session. Save or finish it first.',409);
 }
 public static function canTransition(array $state,string $verb):bool {
  $status=$state['status']??'';
  return match($verb){
   'save'=>in_array($status,self::LIVE,true),
   'resume_saved'=>$status===self::SAVED,
   'finish_saved'=>$status===self::SAVED,
   default=>false
  };
 }
 public static function transition(array &$state,string $verb):array {
  if(!self::canTransition($state,$verb))throw new DomainException('Invalid session action for its current status.',409);
  if($verb==='save'){
   $state['status']=self::SAVED;$state['savedAt']=gmdate('c');$state['version']++;
   return ['type'=>'saved','version'=>$state['version']];
  }
  // Saved is a persistence status. A game module only needs standard resume/end.
  // Convert it to paused for its existing rule implementation; no extra version step.
  $state['status']='paused';$state['savedAt']=null;
  $result=GameRegistry::module($state['game'])->action($state,$verb==='resume_saved'?'resume':'end');
  if($verb==='finish_saved')$result['type']='ended';
  return $result;
 }
}
