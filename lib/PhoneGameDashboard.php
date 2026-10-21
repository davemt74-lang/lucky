<?php
declare(strict_types=1);
require_once __DIR__.'/GameSessionLifecycle.php';
require_once __DIR__.'/ControllerGame.php';
require_once __DIR__.'/GamePackageActivation.php';
require_once __DIR__.'/ControllerRate.php';
require_once __DIR__.'/PrizeDecision.php';

/** Approved-phone game picker. No admin credentials or private game data in replies. */
final class PhoneGameDashboard {
 public static function catalog():array {
  $games=[['id'=>'spin-wheel','title'=>'Spin the Wheel','publisher'=>'Lucky Games','hasPhone'=>true,'builtIn'=>true]];
  foreach(GamePackageActivation::all() as $id=>$p){
   $games[]=['id'=>$id,'title'=>$p['title'],'publisher'=>$p['publisher'],
    'hasPhone'=>(bool)$p['phone'],'builtIn'=>false];
  }
  return $games;
 }
 /** Inside the caller's pair-locked DB transaction. Start once per nonce. */
 public static function launch(PDO $db,string $game,string $pairId,string $nonce,int $owner):array {
  $game=GameCatalog::slug($game);
  $q=$db->prepare('SELECT c.command,a.session_id FROM controller_commands c LEFT JOIN audit_log a ON a.event=\'controller.dashboard-launch\' AND JSON_UNQUOTE(JSON_EXTRACT(a.details,\'$.nonce\'))=? AND JSON_UNQUOTE(JSON_EXTRACT(a.details,\'$.pairId\'))=? WHERE c.pair_id=? AND c.client_nonce=?');
  // Replays must return the SAME session, never create a second one.
  // The phone launch audit entry stores only IDs (no bearer secrets).
  $q->execute([$nonce,$pairId,$pairId,$nonce]);
  $record=$q->fetch(PDO::FETCH_ASSOC);
  if($record){
   if($record['command']!=='launch')throw new DomainException('This request identifier was used for another action.',409);
   if(!$record['session_id'])throw new DomainException('Launch receipt unavailable. Refresh the dashboard.',409);
   $row=sessionRow($db,$record['session_id']);
   return ['id'=>$row['id'],'game'=>$row['game_id'],'viewer'=>$row['viewer_token'],'repeat'=>true];
  }
  $q=$db->prepare('SELECT COUNT(*) FROM controller_commands WHERE pair_id=? AND created_at>=UTC_TIMESTAMP()-INTERVAL 1 SECOND');
  $q->execute([$pairId]);$second=(int)$q->fetchColumn();
  $q=$db->prepare('SELECT COUNT(*) FROM controller_commands WHERE pair_id=? AND created_at>=UTC_TIMESTAMP()-INTERVAL 1 MINUTE');
  $q->execute([$pairId]);ControllerRate::check($second,(int)$q->fetchColumn(),'start');
  $modules=GameRegistry::modules();
  if(!isset($modules[$game]))throw new DomainException('This game is not installed and activated.',404);
  // Serialize cross-game starts with the same HomeServer lock used by host,
  // phone, and saved-session resume; an unlocked read could race another game.
  GameSessionLifecycle::lockConsole($db);
  GameSessionLifecycle::assertConsoleVacant($db);
  if($game==='spin-wheel'){
   PrizeDecision::requireReady($db);
   GameSessionLifecycle::lockGame($db,'spin-wheel');
   GameSessionLifecycle::assertVacant($db,'spin-wheel');
   PrizePackages::requireReady($db);
   PrizePackages::lockGame($db,'spin-wheel');
   $pkg=PrizePackages::currentConfig($db);
   $session=$modules[$game]->createSession($pkg['config'],bin2hex(random_bytes(16)));
   $session['prizePackage']=['id'=>$pkg['id'],'name'=>$pkg['name']];
  }else{
   $config=GameSessionLifecycle::lockGame($db,$game);
   GameSessionLifecycle::assertVacant($db,$game);
   $session=$modules[$game]->createSession($config,bin2hex(random_bytes(16)));
  }
  GameFoundation::checkSession($modules[$game],$session,(string)$session['id']);
  $viewer=token();
  $q=$db->prepare('INSERT INTO game_sessions(id,game_id,status,version,state_json,viewer_token) VALUES (?,?,?,?,?,?)');
  $q->execute([$session['id'],$game,$session['status'],$session['version'],json_encode($session,JSON_THROW_ON_ERROR),$viewer]);
  $q=$db->prepare('INSERT INTO audit_log(admin_id,event,session_id,details) VALUES (?,?,?,?)');
  $q->execute([$owner,'session.start',$session['id'],json_encode(['game'=>$game,'source'=>'approved-phone','pairId'=>$pairId],JSON_THROW_ON_ERROR)]);
  $q=$db->prepare('INSERT INTO controller_commands(pair_id,client_nonce,command,delivered_at) VALUES (?,? ,\'launch\',UTC_TIMESTAMP())');
  $q->execute([$pairId,$nonce]);
  $q=$db->prepare('INSERT INTO audit_log(admin_id,event,session_id,details) VALUES (?,\'controller.dashboard-launch\',?,?)');
  $q->execute([$owner,$session['id'],json_encode(['pairId'=>$pairId,'nonce'=>$nonce,'game'=>$game],JSON_THROW_ON_ERROR)]);
  return ['id'=>$session['id'],'game'=>$game,'viewer'=>$viewer,'repeat'=>false];
 }
 public static function pendingForHost(PDO $db,int $owner):?array {
  $q=$db->prepare("SELECT s.id,s.game_id,a.id AS launch_id
   FROM audit_log a JOIN game_sessions s ON s.id=a.session_id
   WHERE a.admin_id=? AND a.event='controller.dashboard-launch'
     AND a.created_at>UTC_TIMESTAMP()-INTERVAL 2 MINUTE
     AND s.status IN ('active','paused')
   ORDER BY a.id DESC LIMIT 1");
  $q->execute([$owner]);$r=$q->fetch(PDO::FETCH_ASSOC);
  return $r?['game'=>$r['game_id'],'session'=>$r['id'],'eventId'=>(int)$r['launch_id']]:null;
 }
}
