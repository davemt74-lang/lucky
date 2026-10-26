<?php
declare(strict_types=1);
require_once __DIR__.'/GameEngine.php';

/**
 * HomeServer-owned prize packages. Sessions copy validated package data at
 * creation; changing a package cannot alter a running or archived game's prizes.
 * All mutators run under the caller's transaction and game_settings row lock.
 */
final class PrizePackages {
 public static function id(mixed $id):string {
  if(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/D',$id))throw new DomainException('Invalid prize package ID.',400);
  return $id;
 }
 public static function label(mixed $name):string {
  if(!is_string($name))throw new DomainException('Invalid package name.',422);
  $name=trim($name);
  if($name===''||strlen($name)>100||preg_match('/[\x00-\x1f\x7f]/',$name))
   throw new DomainException('Enter a package name up to 100 characters.',422);
  return $name;
 }
 public static function ensureBudgetColumn(PDO $db):bool {
  $q=$db->query("SHOW TABLES LIKE 'prize_packages'");
  if(!$q->fetchColumn())return false;
  if($db->query("SHOW COLUMNS FROM prize_packages LIKE 'budget_cents'")->fetch())return false;
  $db->exec("ALTER TABLE prize_packages ADD COLUMN budget_cents INT UNSIGNED NULL DEFAULT NULL AFTER config_json");
  return true;
 }
 public static function ensureImageColumn(PDO $db):bool {
  if(!$db->query("SHOW TABLES LIKE 'prize_packages'")->fetchColumn())return false;
  if($db->query("SHOW COLUMNS FROM prize_packages LIKE 'image_url'")->fetch())return false;
  $db->exec("ALTER TABLE prize_packages ADD COLUMN image_url VARCHAR(2048) NOT NULL DEFAULT '' AFTER budget_cents");
  return true;
 }
 public static function ensurePhoneCameraColumn(PDO $db):bool {
  if(!$db->query("SHOW TABLES LIKE 'prize_packages'")->fetchColumn())return false;
  if($db->query("SHOW COLUMNS FROM prize_packages LIKE 'phone_camera_enabled'")->fetch())return false;
  $db->exec("ALTER TABLE prize_packages ADD COLUMN phone_camera_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER player_selectable");
  return true;
 }
 private static function image(mixed $value):string {
  if(!is_string($value)||!GameEngine::validUrl($value))throw new DomainException('Use a valid package image HTTP(S) URL or leave blank.',422);
  return $value;
 }
 private static function budget(mixed $budget,array $config):?int {
  if($budget!==null&&!is_int($budget))throw new DomainException('Package budget must be an integer amount in cents or blank.',422);
  if($budget===null)return null;
  if($budget<0||$budget>1000000000)throw new DomainException('Package budget must be between $0 and $10,000,000.',422);
  $total=array_sum(array_map(static fn($pool)=>(int)$pool['budgetCents'],$config['pools']));
  if($total>$budget)throw new DomainException('The combined Tier 1, Tier 2 and Bonus budgets exceed this package budget.',422);
  return $budget;
 }
 public static function requireReady(PDO $db):void {
  $q=$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('prize_packages','prize_package_selection')");
  if((int)$q->fetchColumn()!==2)throw new DomainException('Prize packages need a database upgrade. Run upgrade.php.',409);
  if(!$db->query("SHOW COLUMNS FROM prize_packages LIKE 'phone_camera_enabled'")->fetch())
   throw new DomainException('Run upgrade.php to activate prize-package phone cameras.',409);
 }
 public static function lockGame(PDO $db,string $game='spin-wheel'):void {
  if($game!=='spin-wheel')throw new DomainException('Prize packages are not configured for this game.',404);
  $q=$db->prepare('SELECT config_json FROM game_settings WHERE game_id=? FOR UPDATE');
  $q->execute([$game]);
  $config=$q->fetchColumn();
  if($config===false)throw new DomainException('Game configuration is missing.',409);
  $legacy=GameEngine::validateConfig(json_decode($config,true,512,JSON_THROW_ON_ERROR));
  $default=hash('md5','lucky:spin-wheel:default');
  $q=$db->prepare('SELECT id FROM prize_packages WHERE game_id=? ORDER BY sort_order,id LIMIT 1');
  $q->execute([$game]);$existing=$q->fetchColumn();
  if($existing===false){
   $insert=$db->prepare('INSERT IGNORE INTO prize_packages(id,game_id,name,config_json,enabled,player_selectable) VALUES (?,?,?, ?,1,1)');
   $insert->execute([$default,$game,'Original Prizes',json_encode($legacy,JSON_THROW_ON_ERROR)]);
  }else $default=(string)$existing;
  $q=$db->prepare('INSERT IGNORE INTO prize_package_selection(game_id,package_id) VALUES (?,?)');
  $q->execute([$game,$default]);
 }
 public static function rows(PDO $db,string $game='spin-wheel',bool $admin=false):array {
  self::requireReady($db);
  $q=$db->prepare('SELECT id,name,enabled,player_selectable,publication_status,version,config_json,budget_cents,image_url,phone_camera_enabled FROM prize_packages WHERE game_id=?'.($admin?'': " AND enabled=1 AND player_selectable=1 AND publication_status='published'"). ' ORDER BY sort_order ASC,id ASC');
  $q->execute([$game]);$rows=[];
  foreach($q as $r){
   $item=['id'=>$r['id'],'name'=>$r['name'],'enabled'=>(bool)$r['enabled'],
    'playerSelectable'=>(bool)$r['player_selectable'],'publicationStatus'=>$r['publication_status'],'version'=>(int)$r['version'],
     'budgetCents'=>$r['budget_cents']===null?null:(int)$r['budget_cents'],'image'=>$r['image_url']??'',
     'phoneCameraEnabled'=>(bool)$r['phone_camera_enabled']];
   if($admin)$item['config']=json_decode($r['config_json'],true,512,JSON_THROW_ON_ERROR);
   $rows[]=$item;
  }
  return $rows;
 }
 public static function chosen(PDO $db,string $game='spin-wheel'):string {
  $q=$db->prepare('SELECT package_id FROM prize_package_selection WHERE game_id=?');$q->execute([$game]);
  $id=$q->fetchColumn();if(!is_string($id))throw new DomainException('No selected prize package. Run database upgrade.',409);
  return $id;
 }
 public static function currentConfig(PDO $db,string $game='spin-wheel'):array {
  self::requireReady($db);
  $id=self::chosen($db,$game);
  $q=$db->prepare('SELECT name,config_json,enabled,publication_status,phone_camera_enabled FROM prize_packages WHERE id=? AND game_id=?');
  $q->execute([$id,$game]);$r=$q->fetch();
  if(!$r||(int)$r['enabled']!==1||$r['publication_status']!=='published')throw new DomainException('Publish and enable a prize package before starting.',409);
  return ['id'=>$id,'name'=>$r['name'],'config'=>GameEngine::validateConfig(json_decode($r['config_json'],true,512,JSON_THROW_ON_ERROR)),
   'phoneCameraEnabled'=>(bool)$r['phone_camera_enabled']];
 }
 public static function read(PDO $db,bool $admin=false):array {
  self::requireReady($db);
  $owned=!$db->inTransaction();if($owned)$db->beginTransaction();
  try{
   self::lockGame($db);
   $selected=self::chosen($db);
   $list=self::rows($db,'spin-wheel',$admin);
   if($owned)$db->commit();
   return ['selected'=>$selected,'packages'=>$list];
  }catch(Throwable $e){if($owned&&$db->inTransaction())$db->rollBack();throw $e;}
 }
 public static function noLive(PDO $db):void {
  $q=$db->query("SELECT id FROM game_sessions WHERE game_id='spin-wheel' AND status IN ('active','paused') LIMIT 1 FOR UPDATE");
  if($q->fetchColumn()!==false)throw new DomainException('Finish or save the current game before changing prize packages.',409);
 }
 public static function select(PDO $db,string $id,bool $player=false):void {
  self::requireReady($db);self::lockGame($db);self::noLive($db);self::id($id);
  $q=$db->prepare('SELECT enabled,player_selectable,publication_status FROM prize_packages WHERE id=? AND game_id=? FOR UPDATE');
  $q->execute([$id,'spin-wheel']);$r=$q->fetch();
  if(!$r||(int)$r['enabled']!==1||$r['publication_status']!=='published'||($player&&(int)$r['player_selectable']!==1))
   throw new DomainException('This prize package is not available for selection.',403);
  $q=$db->prepare('UPDATE prize_package_selection SET package_id=? WHERE game_id=?');$q->execute([$id,'spin-wheel']);
 }
 public static function create(PDO $db,string $name,?string $cloneFrom=null,mixed $budgetCents=null,bool $overrideBudget=false):array {
  self::requireReady($db);self::lockGame($db);
  $name=self::label($name);
  $count=(int)$db->query("SELECT COUNT(*) FROM prize_packages WHERE game_id='spin-wheel'")->fetchColumn();
  if($count>=16)throw new DomainException('Maximum 16 prize packages.',422);
  $source=$cloneFrom??self::chosen($db);
  self::id($source);
  $q=$db->prepare("SELECT config_json,budget_cents,phone_camera_enabled FROM prize_packages WHERE game_id='spin-wheel' AND id=?");
  $q->execute([$source]);$sourceRow=$q->fetch(PDO::FETCH_ASSOC);
  if(!$sourceRow)throw new DomainException('Package to duplicate is unavailable.',404);
  $config=GameEngine::validateConfig(json_decode($sourceRow['config_json'],true,512,JSON_THROW_ON_ERROR));
  $budget=$sourceRow['budget_cents']===null?null:(int)$sourceRow['budget_cents'];
  if($overrideBudget)$budget=self::budget($budgetCents,$config);
  $id=bin2hex(random_bytes(16));
  $phoneEnabled=(bool)$sourceRow['phone_camera_enabled'];
  $q=$db->prepare("INSERT INTO prize_packages (id,game_id,name,config_json,budget_cents,enabled,player_selectable,phone_camera_enabled,publication_status,sort_order) VALUES (?,'spin-wheel',?,?,?,1,1,?,'draft',?)");
  $q->execute([$id,$name,json_encode($config,JSON_THROW_ON_ERROR),$budget,(int)$phoneEnabled,$count]);
  return ['id'=>$id,'name'=>$name,'enabled'=>true,'playerSelectable'=>true,'phoneCameraEnabled'=>$phoneEnabled,'publicationStatus'=>'draft','version'=>1,'config'=>$config,'budgetCents'=>$budget,'image'=>''];
 }
 public static function save(PDO $db,string $id,int $version,string $name,array $cfg,bool $enabled,bool $player,mixed $budgetCents=null,mixed $image='',?bool $phoneCameraEnabled=null):array {
  self::requireReady($db);self::lockGame($db);self::id($id);$name=self::label($name);
  $cfg=GameEngine::validateConfig($cfg);
  $budgetCents=self::budget($budgetCents,$cfg);$image=self::image($image);
  $q=$db->prepare("SELECT version,enabled,publication_status,phone_camera_enabled FROM prize_packages WHERE id=? AND game_id='spin-wheel' FOR UPDATE");
  $q->execute([$id]);$r=$q->fetch();
  if(!$r)throw new DomainException('Prize package not found.',404);
  if((int)$r['version']!==$version)throw new DomainException('This package was changed elsewhere. Reload before saving.',409);
  if(!$enabled&&self::chosen($db)===$id)throw new DomainException('Select another enabled package before disabling this one.',409);
  $phoneCameraEnabled=$phoneCameraEnabled??(bool)$r['phone_camera_enabled'];
  $q=$db->prepare("UPDATE prize_packages SET name=?,config_json=?,budget_cents=?,image_url=?,enabled=?,player_selectable=?,phone_camera_enabled=?,version=version+1 WHERE id=? AND game_id='spin-wheel' AND version=?");
  $q->execute([$name,json_encode($cfg,JSON_THROW_ON_ERROR),$budgetCents,$image,(int)$enabled,(int)$player,(int)$phoneCameraEnabled,$id,$version]);
  if($q->rowCount()!==1)throw new DomainException('Prize package update conflict.',409);
  return ['id'=>$id,'name'=>$name,'enabled'=>$enabled,'playerSelectable'=>$player,'publicationStatus'=>$r['publication_status'],'version'=>$version+1,'config'=>$cfg,'budgetCents'=>$budgetCents,'image'=>$image,'phoneCameraEnabled'=>$phoneCameraEnabled];
 }
 public static function publish(PDO $db,string $id,int $version,bool $published):array {
  self::requireReady($db);self::lockGame($db);self::id($id);
  $q=$db->prepare("SELECT name,config_json,budget_cents,enabled,publication_status,version FROM prize_packages WHERE id=? AND game_id='spin-wheel' FOR UPDATE");
  $q->execute([$id]);$r=$q->fetch();
  if(!$r)throw new DomainException('Prize package not found.',404);
  if((int)$r['version']!==$version)throw new DomainException('This package changed. Reload before changing publication status.',409);
  $next=$published?'published':'draft';
  if($r['publication_status']===$next)throw new DomainException('The package already has this publication status.',409);
  if(!$published&&self::chosen($db)===$id)throw new DomainException('Choose a different published package before moving this one to Draft.',409);
  if($published){
   $cfg=GameEngine::validateConfig(json_decode($r['config_json'],true,512,JSON_THROW_ON_ERROR));
    self::budget($r['budget_cents']===null?null:(int)$r['budget_cents'],$cfg);
   if(count($cfg['pools']['tier1']['prizes'])===0)throw new DomainException('Add at least one Tier 1 prize before publishing.',422);
  }
  $q=$db->prepare("UPDATE prize_packages SET publication_status=?,version=version+1 WHERE id=? AND game_id='spin-wheel' AND version=?");
  $q->execute([$next,$id,$version]);
  if($q->rowCount()!==1)throw new DomainException('Publication status conflict.',409);
  return ['id'=>$id,'publicationStatus'=>$next,'version'=>$version+1];
 }
 public static function delete(PDO $db,string $id):void {
  self::requireReady($db);self::lockGame($db);self::id($id);
  if(self::chosen($db)===$id)throw new DomainException('Select a different package before deleting this one.',409);
  $q=$db->prepare("DELETE FROM prize_packages WHERE id=? AND game_id='spin-wheel'");$q->execute([$id]);
  if(!$q->rowCount())throw new DomainException('Prize package not found.',404);
 }
}
