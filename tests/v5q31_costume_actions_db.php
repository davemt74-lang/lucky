<?php
declare(strict_types=1);
if(getenv('CI')!=='true')throw new RuntimeException('CI MariaDB only.');
define('LUCKY_TESTING',true);
require_once __DIR__.'/../lib/Bootstrap.php';
require_once __DIR__.'/../lib/MigrationSql.php';
require_once __DIR__.'/../lib/PartyStudio.php';
function checkActionDb(bool $ok,string $message):void {if(!$ok)throw new RuntimeException('FAIL '.$message);echo "PASS $message\n";}
function rejectActionDb(callable $fn):bool {try{$fn();return false;}catch(DomainException){return true;}}
$db=new PDO('mysql:host=127.0.0.1;port=3306;dbname=lucky_test;charset=utf8mb4','root','luckytestpassword',[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(MigrationSql::statements((string)file_get_contents(__DIR__.'/../lib/schema.sql')) as $statement)$db->exec($statement);
$sql=(string)file_get_contents(__DIR__.'/../lib/migrations/v5q31_costume_actions.sql');
foreach(MigrationSql::statements($sql) as $statement)$db->exec($statement);
foreach(MigrationSql::statements($sql) as $statement)$db->exec($statement);
$action=null;$costume=null;
try{
 $action=PartyStudio::saveAction($db,['name'=>'Visor reveal','description'=>'Hold for camera']);
 $catalog=PartyStudio::catalog($db);
 checkActionDb(count(array_filter($catalog['actions'],fn($a)=>$a['id']===$action['id']))===1,'Master costume action persists and is returned in catalog');
 $costume=PartyStudio::save($db,'costumes',['name'=>'Astronaut','items'=>[[
  'id'=>'visor-unique','name'=>'Visor','category'=>'Prop','actionIds'=>['equip','remove',$action['id']]]]]);
 $items=PartyStudio::catalog($db)['costumes'];
 $saved=null;foreach($items as $entry)if($entry['id']===$costume['id'])$saved=$entry;
 checkActionDb($saved['items'][0]['id']==='visor-unique'&&count($saved['items'][0]['actionIds'])===3,
  'Costume layer saves stable ID and multiple selected master actions');
 $roster=PartyStudio::roster($db,['players'=>[['name'=>'Ada','costumeId'=>$costume['id']]]]);
 checkActionDb(count($roster['players'][0]['costume']['items'][0]['actions'])===3,'Game roster snapshots all action names and kinds');
 $config=['pools'=>['tier1'=>['prizes'=>[['id'=>'p1','costumeItem'=>['costumeId'=>$costume['id'],'itemId'=>'visor-unique']]]]]];
 $links=PartyStudio::prizeCostumeMap($db,$config);
 checkActionDb($links['p1']['itemName']==='Visor'&&count($links['p1']['actions'])===3,'Prize collection links to costume layer and its selected actions');
 checkActionDb(rejectActionDb(static fn()=>PartyStudio::deleteAction($db,$action['id'])),'Cannot delete a master action in use');
 checkActionDb(rejectActionDb(static fn()=>PartyStudio::save($db,'costumes',['id'=>$costume['id'],'name'=>'Astronaut','items'=>[[
  'id'=>'visor-unique','name'=>'Visor','actionIds'=>['missing']]]])),'Invalid master action selection is rejected');
 PartyStudio::save($db,'costumes',['id'=>$costume['id'],'name'=>'Astronaut','items'=>[[
  'id'=>'visor-unique','name'=>'Visor','actionIds'=>['equip','remove']]]]);
 PartyStudio::deleteAction($db,$action['id']);$action=null;
 checkActionDb(count(PartyStudio::catalog($db)['actions'])===2,'Unreferenced custom action may be deleted; built-in actions remain');
 echo "V5Q31 ACTION LIBRARY MARIADB: 7/7\n";
}finally{
 if($costume)$db->prepare('DELETE FROM lucky_costumes WHERE id=?')->execute([$costume['id']]);
 if($action)$db->prepare('DELETE FROM lucky_costume_actions WHERE id=?')->execute([$action['id']]);
}
