<?php
declare(strict_types=1);
if(getenv('CI')!=='true')throw new RuntimeException('CI MariaDB only.');
define('LUCKY_TESTING',true);
require_once __DIR__.'/../lib/Bootstrap.php';
require_once __DIR__.'/../lib/MigrationSql.php';
require_once __DIR__.'/../lib/PartyStudio.php';
function checkParty(bool $ok,string $message):void {if(!$ok)throw new RuntimeException('FAIL '.$message);echo "PASS $message\n";}
function rejectParty(callable $f):bool {try{$f();return false;}catch(DomainException){return true;}}
$db=new PDO('mysql:host=127.0.0.1;port=3306;dbname=lucky_test;charset=utf8mb4','root','luckytestpassword',[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(MigrationSql::statements((string)file_get_contents(__DIR__.'/../lib/schema.sql')) as $statement)$db->exec($statement);
$sql=(string)file_get_contents(__DIR__.'/../lib/migrations/v5q30_party_studio.sql');
foreach(MigrationSql::statements($sql) as $statement)$db->exec($statement);
foreach(MigrationSql::statements($sql) as $statement)$db->exec($statement);
$host=0;$ids=[];$phone=bin2hex(random_bytes(16));$session=bin2hex(random_bytes(16));
try{
 $db->prepare('INSERT INTO admins(username,password_hash) VALUES (?,?)')->execute(['party-'.bin2hex(random_bytes(6)),password_hash('test',PASSWORD_DEFAULT)]);
 $host=(int)$db->lastInsertId();$_SESSION['admin_id']=$host;
 $db->prepare("INSERT INTO controller_pairs(id,host_admin_id,invite_hash,controller_hash,device_name,status,invite_expires_at,paired_expires_at) VALUES (?,?,?,?,?,'paired',UTC_TIMESTAMP(),UTC_TIMESTAMP()+INTERVAL 2 HOUR)")
  ->execute([$phone,$host,str_repeat('a',64),str_repeat('b',64),'Ada phone']);
 $costume=PartyStudio::save($db,'costumes',['name'=>'Astronaut','description'=>'Space suit','items'=>[
  ['name'=>'Helmet','category'=>'Prop','notes'=>'Visor reveal'],['name'=>'Boots','category'=>'Footwear','notes'=>'']]]);
 $ids['costume']=$costume['id'];
 checkParty(count(PartyStudio::catalog($db)['costumes'][0]['items'])===2,'Save, reload and preserve ordered costume items');
 $theme=PartyStudio::save($db,'themes',['name'=>'Space party','costumeIds'=>[$ids['costume']],'accent'=>'#ffaa11']);$ids['theme']=$theme['id'];
 checkParty(in_array($ids['costume'],PartyStudio::catalog($db)['themes'][0]['costumeIds'],true),
  'Theme references saved costume');
 $party=PartyStudio::save($db,'parties',['name'=>'Game night','themeId'=>$ids['theme'],'attendees'=>[
  ['role'=>'player','name'=>'Ada','costumeId'=>$ids['costume']],['role'=>'host','name'=>'','costumeId'=>''],['role'=>'guest','name'=>'Visitor','costumeId'=>'']]]);
 $ids['party']=$party['id'];
 $roster=PartyStudio::roster($db,['partyId'=>$ids['party'],'players'=>[['name'=>'Ada','costumeId'=>$ids['costume'],'phonePairId'=>$phone]]]);
 checkParty($roster['theme']['name']==='Space party'&&$roster['party']['name']==='Game night','Party loads linked theme into game snapshot');
 checkParty(count($roster['players'])===1&&count($roster['hosts'])===1&&$roster['hosts'][0]['name']==='Host 1',
  'Players and hosts load; guest does not enter turn queue; unnamed host gets generic ID');
 checkParty($roster['players'][0]['phonePairId']===$phone,'Approved phone assigned to player');
 checkParty(count($roster['players'][0]['costume']['items'])===2,'Player attachment keeps itemized layers');
 checkParty(rejectParty(static fn()=>PartyStudio::roster($db,['players'=>[['name'=>'Ada','phonePairId'=>$phone],['name'=>'Ben','phonePairId'=>$phone]]])),
  'A phone cannot be assigned to multiple players');
 checkParty(rejectParty(static fn()=>PartyStudio::delete($db,'costumes',$ids['costume'])),'Attached costume cannot be deleted accidentally');
 PartyStudio::save($db,'costumes',['id'=>$ids['costume'],'name'=>'Astronaut','items'=>[['name'=>'Updated helmet']]]);
 checkParty($roster['players'][0]['costume']['items'][0]['name']==='Helmet','Existing game snapshot survives library edits');
 $state=GameEngine::newSession(GameEngine::defaults(),$session);$state['multiplayer']=$roster;
 $db->prepare("INSERT INTO game_sessions(id,game_id,status,version,state_json,viewer_token) VALUES (?,'spin-wheel','active',1,?,?)")
  ->execute([$session,json_encode($state,JSON_THROW_ON_ERROR),bin2hex(random_bytes(24))]);
 checkParty(PartyStudio::cameraOperator($db,$session,$phone)['name']==='Ada','Player phone identified as a camera source');
 $state['multiplayer']['players'][0]['phonePairId']='';
 $db->prepare('UPDATE game_sessions SET state_json=? WHERE id=?')->execute([json_encode($state),$session]);
 checkParty(PartyStudio::cameraOperator($db,$session,$phone)===null,'Removing phone assignment removes cameraman attribution');
 checkParty(rejectParty(static fn()=>PartyStudio::roster($db,['players'=>array_fill(0,65,['name'=>''])])),'Player count capped');
 echo "V5Q30 PARTY STUDIO MARIADB: 12/12\n";
}finally{
 $db->prepare('DELETE FROM game_sessions WHERE id=?')->execute([$session]);
 if(isset($ids['party']))$db->prepare('DELETE FROM lucky_parties WHERE id=?')->execute([$ids['party']]);
 if(isset($ids['theme']))$db->prepare('DELETE FROM lucky_themes WHERE id=?')->execute([$ids['theme']]);
 if(isset($ids['costume']))$db->prepare('DELETE FROM lucky_costumes WHERE id=?')->execute([$ids['costume']]);
 $db->prepare('DELETE FROM controller_pairs WHERE id=?')->execute([$phone]);
 if($host)$db->prepare('DELETE FROM admins WHERE id=?')->execute([$host]);
}
