<?php
declare(strict_types=1);
require_once __DIR__.'/GameCatalog.php';
require_once __DIR__.'/ControllerPairing.php';

/** A paired phone can request that the signed-in desktop launch an installed
 * game. The phone never receives an owner session/CSRF or navigates the host
 * directly. The host explicitly accepts a short-lived DB-queued request. */
final class PhoneGameDashboard {
 public static function ensureSchema(PDO $db):void {
  $db->exec("CREATE TABLE IF NOT EXISTS controller_game_launches (
   id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
   host_admin_id BIGINT NOT NULL,
   pair_id CHAR(32) NOT NULL,
   nonce VARCHAR(80) NOT NULL,
   game_id VARCHAR(64) NOT NULL,
   status VARCHAR(12) NOT NULL DEFAULT 'queued',
   created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
   accepted_at TIMESTAMP NULL,
   UNIQUE KEY uq_phone_dashboard_command(pair_id,nonce),
   INDEX idx_host_dashboard_launch(host_admin_id,status,created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
 }
 public static function catalog():array {
  $list=[];
  foreach(GameRegistry::modules() as $id=>$module){
   $game=GameCatalog::load($id);
   if(($game['enabled']??false)!==true||($game['status']??'')!=='ready')continue;
   $list[]=['id'=>$id,'title'=>$game['title'],'description'=>$game['tagline']?:$game['description'],
    'version'=>$game['version'],'controllerMode'=>$game['controllerMode'],
    'builtIn'=>$id==='spin-wheel'];
  }
  return $list;
 }
 public static function active(PDO $db):bool {
  return (int)$db->query("SELECT COUNT(*) FROM game_sessions WHERE status IN ('active','paused')")->fetchColumn()>0;
 }
 public static function request(PDO $db,array $pair,string $game,string $nonce):array {
  ControllerPairing::nonce($nonce);
  GameCatalog::slug($game);
  $list=self::catalog();
  if(!in_array($game,array_column($list,'id'),true))
   throw new DomainException('Game is not installed, enabled, and ready.',404);
  self::ensureSchema($db);
  $db->beginTransaction();
  try {
   $q=$db->prepare('SELECT * FROM controller_pairs WHERE id=? FOR UPDATE');
   $q->execute([$pair['id']]);$current=$q->fetch();
   if(!$current||!hash_equals((string)($current['controller_hash']??''),(string)($pair['controller_hash']??''))||
       ControllerPairing::status($current)!=='paired')
    throw new DomainException('Controller pairing expired or was revoked.',403);
   $q=$db->prepare('SELECT id,game_id,status FROM controller_game_launches WHERE pair_id=? AND nonce=?');
   $q->execute([$pair['id'],$nonce]);$old=$q->fetch();
   if($old){
    if($old['game_id']!==$game)throw new DomainException('Request ID was used for a different game.',409);
    $db->commit();return ['id'=>(int)$old['id'],'game'=>$game,'status'=>$old['status'],'repeat'=>true];
   }
   if(self::active($db))throw new DomainException('Finish or save the current game before choosing another.',409);
   $q=$db->prepare("SELECT COUNT(*) FROM controller_game_launches WHERE host_admin_id=? AND status='queued' AND created_at>UTC_TIMESTAMP()-INTERVAL 2 MINUTE");
   $q->execute([(int)$current['host_admin_id']]);
   if((int)$q->fetchColumn()>0)throw new DomainException('A game launch is already waiting for the desktop.',409);
   $q=$db->prepare("INSERT INTO controller_game_launches(host_admin_id,pair_id,nonce,game_id,status) VALUES (?,?,?,?,'queued')");
   $q->execute([(int)$current['host_admin_id'],$pair['id'],$nonce,$game]);
   $out=['id'=>(int)$db->lastInsertId(),'game'=>$game,'status'=>'queued','repeat'=>false];
   $db->commit();return $out;
  }catch(Throwable $e){$db->rollBack();throw $e;}
 }
 public static function progress(PDO $db,string $id):?array {
  $q=$db->prepare("SELECT id,game_id,status,created_at FROM controller_game_launches WHERE pair_id=? ORDER BY id DESC LIMIT 1");
  $q->execute([$id]);$row=$q->fetch();
  if(!$row||strtotime($row['created_at'].' UTC')<time()-120)return null;
  return ['id'=>(int)$row['id'],'game'=>$row['game_id'],'status'=>$row['status']];
 }
 public static function next(PDO $db,int $adminId):?array {
  self::ensureSchema($db);
  $q=$db->prepare("SELECT l.id,l.game_id,l.created_at FROM controller_game_launches l
   JOIN controller_pairs p ON p.id=l.pair_id
   WHERE l.host_admin_id=? AND p.host_admin_id=?
   AND p.status='paired' AND p.paired_expires_at>UTC_TIMESTAMP()
   AND l.status='queued' AND l.created_at>UTC_TIMESTAMP()-INTERVAL 2 MINUTE
   ORDER BY l.id ASC LIMIT 1");
  $q->execute([$adminId,$adminId]);$row=$q->fetch();
  if(!$row)return null;
  return ['id'=>(int)$row['id'],'game'=>$row['game_id']];
 }
 public static function accept(PDO $db,int $adminId,int $id,string $game):array {
  if($id<1||$id>PHP_INT_MAX-1)throw new DomainException('Invalid game launch request.',400);
  GameCatalog::slug($game);
  $db->beginTransaction();
  try{
   $q=$db->prepare("SELECT l.*,p.status AS pair_status,p.paired_expires_at,p.host_admin_id AS pair_admin
      FROM controller_game_launches l JOIN controller_pairs p ON p.id=l.pair_id
      WHERE l.id=? AND l.host_admin_id=? FOR UPDATE");
   $q->execute([$id,$adminId]);$row=$q->fetch();
   if(!$row||$row['game_id']!==$game||$row['status']!=='queued'||
      (int)$row['pair_admin']!==$adminId||$row['pair_status']!=='paired'||
      strtotime((string)$row['paired_expires_at'].' UTC')<=time()||
      strtotime((string)$row['created_at'].' UTC')<time()-120)
    throw new DomainException('Game launch expired or was already accepted.',409);
   if(self::active($db))throw new DomainException('Another game is already running.',409);
   if(!in_array($game,array_column(self::catalog(),'id'),true))
    throw new DomainException('Game is no longer available.',404);
   $q=$db->prepare("UPDATE controller_game_launches SET status='accepted',accepted_at=UTC_TIMESTAMP() WHERE id=? AND status='queued'");
   $q->execute([$id]);
   if($q->rowCount()!==1)throw new DomainException('Launch was already accepted.',409);
   $db->commit();
  }catch(Throwable $e){$db->rollBack();throw $e;}
  return ['id'=>$id,'game'=>$game,'target'=>$game==='spin-wheel'?'game.php':'package-game.php?game='.rawurlencode($game)];
 }
}
