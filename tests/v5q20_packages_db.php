<?php
declare(strict_types=1);
define('LUCKY_TESTING',true);
require_once __DIR__.'/../lib/GameEngine.php';
require_once __DIR__.'/../lib/PrizePackages.php';
require_once __DIR__.'/../lib/PrizePublicationMigration.php';
require_once __DIR__.'/../lib/MigrationSql.php';
function check20(bool $ok,string $label):void {
 if(!$ok)throw new RuntimeException('FAIL '.$label);
 echo "PASS ".$label."\n";
}
$db=new PDO('mysql:host=127.0.0.1;port=3306;dbname=lucky_test;charset=utf8mb4','root','luckytestpassword',
 [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(MigrationSql::statements((string)file_get_contents(__DIR__.'/../lib/schema.sql')) as $sql)$db->exec($sql);
foreach(MigrationSql::statements((string)file_get_contents(__DIR__.'/../lib/migrations/v5q20_prize_packages.sql')) as $sql)$db->exec($sql);
$original=GameEngine::defaults();
check20(PrizePublicationMigration::ensure($db)===false,'fresh schema already contains publication status');

$q=$db->prepare('INSERT INTO game_settings(game_id,config_json,intro_json,theme_json) VALUES (?,?,?,?)');
$q->execute(['spin-wheel',json_encode($original,JSON_THROW_ON_ERROR),'{}','{}']);
try{
 $first=PrizePackages::read($db,true);
 check20(count($first['packages'])===1&&$first['packages'][0]['publicationStatus']==='published'&&$first['packages'][0]['name']==='Original Prizes','migrate existing prize setup to first package without changes');
 check20(GameEngine::validateConfig($first['packages'][0]['config'])==GameEngine::validateConfig($original),'original tiers, odds and budgets preserved');
 $id1=$first['selected'];
 $db->beginTransaction();
 $new=PrizePackages::create($db,'Holiday Prizes');
 $db->commit();
 check20($new['publicationStatus']==='draft','new plus-tab package starts as Draft');
 check20(count(PrizePackages::read($db,false)['packages'])===1,'drafts hidden from player-visible package list');
 check20(GameEngine::validateConfig($new['config'])==GameEngine::validateConfig($first['packages'][0]['config']),'plus tab clones all existing tier and award settings');
 $config=$new['config'];$config['pools']['tier1']['prizes'][0]['name']='Holiday Winner';
 $config['odds']['bonusChance']=22;
 $db->beginTransaction();$updated=PrizePackages::save($db,$new['id'],1,'Holiday Prizes',$config,true,true);$db->commit();
 check20($updated['version']===2,'package edits versioned for concurrent administrator safety');
 $db->beginTransaction();
 try{PrizePackages::save($db,$new['id'],1,'Holiday Prizes',$config,true,true);throw new RuntimeException('Allowed outdated package update');}
 catch(DomainException $e){check20($e->getCode()===409,'outdated package update rejected');}
 finally{$db->rollBack();}
 $again=PrizePackages::read($db,true);
 check20(count($again['packages'])===2,'multiple package layers persisted');
 check20($again['packages'][0]['id']===$id1 && $again['packages'][1]['id']===$new['id'],'new prize tab stays to the right of original');
 check20((array_values(array_filter($again['packages'],fn($p)=>$p['id']===$id1))[0]['config']['pools']['tier1']['prizes'][0]['name']??null)!=='Holiday Winner','editing one tab does not modify original package');
 $db->beginTransaction();
 try{PrizePackages::select($db,$new['id'],true);throw new RuntimeException('Player selected unpublished draft');}
 catch(DomainException $e){check20($e->getCode()===403,'neither admin nor player may select an unpublished draft');}
 finally{$db->rollBack();}
 $db->beginTransaction();$published=PrizePackages::publish($db,$new['id'],2,true);$db->commit();
 check20($published['publicationStatus']==='published'&&$published['version']===3,'explicit Publish promotes saved Draft');
 check20(count(PrizePackages::read($db,false)['packages'])===2,'published package becomes visible to players');
 $db->beginTransaction();PrizePackages::select($db,$new['id'],true);$db->commit();
 check20(PrizePackages::chosen($db)===$new['id'],'player may select enabled and player-selectable package');
 $chosen=PrizePackages::currentConfig($db);$game=GameEngine::newSession($chosen['config'],str_repeat('a',32));
 $game['prizePackage']=['id'=>$chosen['id'],'name'=>$chosen['name']];
 $old=$game['pools']['tier1']['prizes'][0]['name'];
 $q=$db->prepare('INSERT INTO game_sessions(id,game_id,status,version,state_json,viewer_token) VALUES (?,?,?,?,?,?)');
 $q->execute([$game['id'],'spin-wheel','active',1,json_encode($game,JSON_THROW_ON_ERROR),str_repeat('b',48)]);
 $db->beginTransaction();
 try{PrizePackages::select($db,$id1);throw new RuntimeException('Package changed during active game');}
 catch(DomainException $e){check20($e->getCode()===409,'cannot switch packages while a game is running');}
 finally{$db->rollBack();}
 $changed=$config;$changed['pools']['tier1']['prizes'][0]['name']='Third Version';
 $db->beginTransaction();PrizePackages::save($db,$new['id'],5,'Holiday Prizes',$changed,true,true);$db->commit();
 $q=$db->prepare('SELECT state_json FROM game_sessions WHERE id=?');$q->execute([$game['id']]);
 $stored=json_decode($q->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
 check20($stored['pools']['tier1']['prizes'][0]['name']===$old,'running game keeps immutable package snapshot after edits');
 check20($stored['prizePackage']['id']===$new['id'],'session retains package identity');
 $db->exec("UPDATE game_sessions SET status='ended' WHERE game_id='spin-wheel'");
 $db->beginTransaction();PrizePackages::select($db,$id1);$db->commit();
 $db->beginTransaction();PrizePackages::save($db,$new['id'],3,'Holiday Prizes',$changed,true,false);$db->commit();
 $db->beginTransaction();
 try{PrizePackages::select($db,$new['id'],true);throw new RuntimeException('Player selected private package');}
 catch(DomainException $e){check20($e->getCode()===403,'admin-only package hidden from phone selection');}
 finally{$db->rollBack();}
 $db->beginTransaction();PrizePackages::select($db,$new['id'],false);$db->commit();
 check20(PrizePackages::chosen($db)===$new['id'],'admin can select enabled private package');
 $db->beginTransaction();
 try{PrizePackages::save($db,$new['id'],4,'Holiday Prizes',$changed,false,false);throw new RuntimeException('Disabled selected package');}
 catch(DomainException $e){check20($e->getCode()===409,'selected package cannot be disabled without switching');}
 finally{$db->rollBack();}
 $db->beginTransaction();
 try{PrizePackages::publish($db,$new['id'],5,false);throw new RuntimeException('Selected package moved to Draft');}
 catch(DomainException $e){check20($e->getCode()===409,'selected Published package cannot be moved to Draft');}
 finally{$db->rollBack();}
 $db->beginTransaction();PrizePackages::select($db,$id1);$db->commit();
 $db->beginTransaction();$drafted=PrizePackages::publish($db,$new['id'],5,false);$db->commit();
 check20($drafted['publicationStatus']==='draft','admin moves unselected Published package to Draft');
 $db->beginTransaction();
 try{PrizePackages::select($db,$new['id'],false);throw new RuntimeException('Draft allowed in next game');}
 catch(DomainException $e){check20($e->getCode()===403,'admins also cannot select Draft packages');}
 finally{$db->rollBack();}
 $db->beginTransaction();PrizePackages::delete($db,$new['id']);$db->commit();
 check20(count(PrizePackages::read($db,true)['packages'])===1,'non-selected package can be deleted without touching past game data');
 $q=$db->query("SELECT state_json FROM game_sessions WHERE game_id='spin-wheel' LIMIT 1");
 check20(json_decode($q->fetchColumn(),true)['prizePackage']['id']===$new['id'],'archived games retain a deleted package snapshot');
 echo "V5Q.20 REAL MariaDB prize packages certification PASSED\n";
}finally{
 $db->exec('SET FOREIGN_KEY_CHECKS=0');
 foreach(['game_events','game_claims','game_sessions','prize_package_selection','prize_packages','game_settings'] as $table)$db->exec('DELETE FROM '.$table);
 $db->exec('SET FOREIGN_KEY_CHECKS=1');
}
