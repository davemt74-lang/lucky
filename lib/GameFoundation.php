<?php
declare(strict_types=1);
require_once __DIR__.'/GameRegistry.php';

/**
 * Per-game bootstrap. Registered modules provide validated defaults; no game
 * requires a bespoke settings migration or shared-engine code changes.
 *
 * Call from within the caller's transaction to serialize first start/resume
 * using the game's settings row. Existing configured games are never reset.
 */
final class GameFoundation {
 public static function settings(PDO $db,string $game):array {
  $module=GameRegistry::module($game);
  $manifest=$module->manifest();
  if($module->id()!==$game
    || !preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/D',$game)
    || ($manifest['id']??null)!==$game
    || ($manifest['status']??null)!=='ready'
    || !is_string($manifest['path']??null)
    || !preg_match('~^[a-z0-9_-]+\.php(?:\?[a-z0-9_=&%-]+)?$~iD',$manifest['path']))
    throw new DomainException('The game module is not ready.',409);
  $defaults=$module->validateSettings($module->defaults());
  $intro=['title'=>strval($manifest['name']??$game),'subtitle'=>'Ready to play','trackId'=>'','volume'=>70,'announcement'=>true];
  $theme=['accent'=>'#f6cb74','background'=>'#0d1423'];
  $q=$db->prepare('INSERT IGNORE INTO game_settings(game_id,config_json,intro_json,theme_json) VALUES (?,?,?,?)');
  $q->execute([$game,json_encode($defaults,JSON_THROW_ON_ERROR),json_encode($intro,JSON_THROW_ON_ERROR),json_encode($theme,JSON_THROW_ON_ERROR)]);
  $q=$db->prepare('SELECT config_json FROM game_settings WHERE game_id=? FOR UPDATE');
  $q->execute([$game]);$row=$q->fetch();
  if(!$row)throw new DomainException('Game settings are unavailable.',409);
  $stored=json_decode($row['config_json'],true,512,JSON_THROW_ON_ERROR);
  if(!is_array($stored))throw new DomainException('Game settings must be an object.',409);
  // Older installs pre-seeded an unused game-2 placeholder. Migrate it only
  // after that game is registered, while its settings row is locked.
  if($game==='game-2'&&$stored===['pools'=>[]]){
   $stored=$defaults;
   $q=$db->prepare('UPDATE game_settings SET config_json=? WHERE game_id=?');
   $q->execute([json_encode($stored,JSON_THROW_ON_ERROR),$game]);
  }
  return $module->validateSettings($stored);
 }

 /** Stable initial state contract required for Game 2 and later games. */
 public static function checkSession(GameModule $module,array $session,string $id):void {
  if(($session['id']??null)!==$id
     ||($session['game']??null)!==$module->id()
     ||($session['status']??null)!=='active'
     ||($session['version']??null)!==1
     ||!is_array($module->publicView($session))
     ||($module->publicView($session)['id']??null)!==$id)
    throw new DomainException('Game module returned an invalid session state.',422);
 }
}
