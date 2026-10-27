<?php
declare(strict_types=1);
if(getenv('CI')!=='true')throw new RuntimeException('CI MariaDB only.');
define('LUCKY_TESTING',true);
require_once __DIR__.'/../lib/Bootstrap.php';
require_once __DIR__.'/../lib/MigrationSql.php';
require_once __DIR__.'/../lib/ProofHandoff.php';
require_once __DIR__.'/../lib/DirectorScenes.php';
function assertScene(bool $ok,string $label):void {if(!$ok)throw new RuntimeException('FAIL '.$label);echo "PASS $label\n";}
function sceneReject(callable $fn):bool {try{$fn();return false;}catch(DomainException){return true;}}
$db=new PDO('mysql:host=127.0.0.1;port=3306;dbname=lucky_test;charset=utf8mb4','root','luckytestpassword',[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(MigrationSql::statements((string)file_get_contents(__DIR__.'/../lib/schema.sql')) as $sql)$db->exec($sql);
foreach(MigrationSql::statements((string)file_get_contents(__DIR__.'/../lib/migrations/v5q_claim_media.sql')) as $sql)$db->exec($sql);
foreach(MigrationSql::statements((string)file_get_contents(__DIR__.'/../lib/migrations/v5q29e4d1hf8c2_director_scenes.sql')) as $sql)$db->exec($sql);
$session=bin2hex(random_bytes(16));$claim=bin2hex(random_bytes(16));$host=0;
try{
 $db->prepare('INSERT INTO admins(username,password_hash) VALUES (?,?)')->execute(['scenes-'.bin2hex(random_bytes(6)),password_hash('test',PASSWORD_DEFAULT)]);
 $host=(int)$db->lastInsertId();
 $db->prepare("INSERT INTO game_sessions(id,game_id,status,version,state_json,viewer_token) VALUES (?,'spin-wheel','active',1,'{}',?)")
  ->execute([$session,bin2hex(random_bytes(24))]);
 $db->prepare('INSERT INTO game_claims(id,session_id,prize_id,tier,prize_name,cents,created_at) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP())')
  ->execute([$claim,$session,'prize-scenes','A','Director Scene Proof',0]);
 $row=['name'=>'Winner','source'=>'camera:desktop1','transition'=>'wipe','title'=>'Prize Winner','sticker'=>'🏆'];
 assertScene(sceneReject(static fn()=>DirectorScenes::save($db,$session,$claim,$host,$row)),'Cannot save scenes without active proof');
 ProofHandoff::activate($db,$session,$claim,$host);
 $saved=DirectorScenes::save($db,$session,$claim,$host,$row);
 assertScene(strlen($saved['id'])===32&&$saved['transition']==='wipe','Host can save a scene with transition and overlay');
 assertScene(count(DirectorScenes::listing($db,$session,$claim,$host))===1,'Saved scene is persisted and read back');
 $updated=DirectorScenes::save($db,$session,$claim,$host,array_replace($row,['transition'=>'dip','title'=>'Updated']));
 assertScene($updated['id']===$saved['id']&&count(DirectorScenes::listing($db,$session,$claim,$host))===1,'Same-name save updates instead of duplicating');
 assertScene(sceneReject(static fn()=>DirectorScenes::listing($db,$session,$claim,$host+10)),'Different administrator cannot list scene');
 assertScene(sceneReject(static fn()=>DirectorScenes::save($db,$session,$claim,$host,array_replace($row,['transition'=>'unknown']))),'Unknown transition rejected');
 assertScene(sceneReject(static fn()=>DirectorScenes::save($db,$session,$claim,$host,array_replace($row,['sticker'=>'<script>']))),'Unapproved sticker rejected');
 assertScene(sceneReject(static fn()=>DirectorScenes::save($db,$session,$claim,$host,array_replace($row,['source'=>'clip:'.str_repeat('a',32)]))),'Clip not present in active proof is rejected');
 for($i=1;$i<16;$i++)DirectorScenes::save($db,$session,$claim,$host,array_replace($row,['name'=>'Scene '.$i]));
 assertScene(count(DirectorScenes::listing($db,$session,$claim,$host))===16,'Scene limit allows 16 scoped presets');
 assertScene(sceneReject(static fn()=>DirectorScenes::save($db,$session,$claim,$host,array_replace($row,['name'=>'Overflow']))),'17th scene rejected');
 assertScene(sceneReject(static fn()=>DirectorScenes::remove($db,$session,$claim,$host+10,$saved['id']))),'Different administrator cannot remove a scene');
 DirectorScenes::remove($db,$session,$claim,$host,$saved['id']);
 assertScene(count(DirectorScenes::listing($db,$session,$claim,$host))===15,'Owner can remove scene without affecting others');
 $db->prepare('UPDATE controller_proof_state SET is_open=0,updated_at=UTC_TIMESTAMP() WHERE session_id=?')->execute([$session]);
 assertScene(sceneReject(static fn()=>DirectorScenes::listing($db,$session,$claim,$host)),'Closed proof blocks scene retrieval');
 echo "HF8C2 SCENES DATABASE: 13/13\n";
}finally{
 $db->prepare('DELETE FROM director_scene_presets WHERE session_id=?')->execute([$session]);
 $db->prepare('DELETE FROM controller_proof_state WHERE session_id=?')->execute([$session]);
 $db->prepare('DELETE FROM game_sessions WHERE id=?')->execute([$session]);
 if($host)$db->prepare('DELETE FROM admins WHERE id=?')->execute([$host]);
}
