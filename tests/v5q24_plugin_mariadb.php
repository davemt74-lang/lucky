<?php
declare(strict_types=1);
if(getenv('CI')!=='true')throw new RuntimeException('Run the destructive MariaDB SDK fixture in isolated CI only.');
define('LUCKY_TESTING',true);
$home=sys_get_temp_dir().'/lucky-v24-'.bin2hex(random_bytes(8));
mkdir($home,0700,true);putenv('LUCKY_TEST_GAME_PACKAGES='.$home.'/packages');
require_once __DIR__.'/../lib/Bootstrap.php';
require_once __DIR__.'/../lib/GamePluginCapabilities.php';
require_once __DIR__.'/../lib/GameSessionLifecycle.php';
require_once __DIR__.'/../lib/MigrationSql.php';
function ck24(bool $pass,string $label):void {if(!$pass)throw new RuntimeException('FAIL '.$label);echo "PASS $label\n";}
function err24(callable $fn,string $label,int $status):void {
 try{$fn();}catch(DomainException $e){ck24($e->getCode()===$status,$label);return;}
 throw new RuntimeException('Expected refusal: '.$label);
}
function clean24(string $path):void {
 if(!is_dir($path))return;
 foreach(new FilesystemIterator($path) as $child) {
  if($child->isDir()&&!$child->isLink())clean24($child->getPathname());
  else unlink($child->getPathname());
 }
 rmdir($path);
}
function zip24(string $path,string $game,string $version,array $caps):void {
 $files=['host.html'=>'<!doctype html><h1>SDK CI-only fixture</h1>'];
 $manifest=['schema'=>'lucky.game-package.v1','id'=>$game,'version'=>$version,
  'title'=>'SDK Fixture','publisher'=>'CI','host'=>'host.html','phone'=>null,
  'capabilities'=>$caps,'dataVersion'=>2,
  'files'=>array_map(fn($v)=>hash('sha256',$v),$files)];
 $zip=new ZipArchive();
 if($zip->open($path,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)throw new RuntimeException('ZIP failure');
 $zip->addFromString('manifest.json',json_encode($manifest,JSON_THROW_ON_ERROR));
 foreach($files as $n=>$b)$zip->addFromString($n,$b);
 $zip->close();
}
$db=new PDO('mysql:host=127.0.0.1;port=3306;dbname=lucky_test;charset=utf8mb4','root','luckytestpassword',
 [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec("SET time_zone='+00:00'");
foreach(MigrationSql::statements((string)file_get_contents(__DIR__.'/../lib/schema.sql')) as $query)$db->exec($query);
GamePluginCapabilities::ensureSchema($db);
$slug=bin2hex(random_bytes(5));$game='sdk-full-'.$slug;$other='sdk-read-'.$slug;
$id=bin2hex(random_bytes(16));$otherId=bin2hex(random_bytes(16));
try{
 $zip=$home.'/game.zip';
 zip24($zip,$game,'1.0.0',GamePluginCapabilities::SUPPORTED);
 GamePackageInstaller::install($zip);GamePackageActivation::activate($game,'1.0.0',null,true);
 zip24($zip,$other,'1.0.0',['storage.read','players.read']);
 GamePackageInstaller::install($zip);GamePackageActivation::activate($other,'1.0.0',null,true);
 ck24(count(GamePluginCapabilities::available($game,'1.0.0'))===13,'Installed package has explicit granted capabilities');
 ck24(GamePluginCapabilities::available($other,'1.0.0')===['storage.read','players.read'],'Another package has restricted capabilities');
 foreach([[$game,$id],[$other,$otherId]] as [$g,$sid]){
  $db->beginTransaction();
  $settings=GameSessionLifecycle::lockGame($db,$g);
  GameSessionLifecycle::assertVacant($db,$g);
  $module=GameRegistry::module($g);$state=$module->createSession($settings,$sid);
  $q=$db->prepare('INSERT INTO game_sessions (id,game_id,status,version,state_json,viewer_token) VALUES (?,?,?,?,?,?)');
  $q->execute([$sid,$g,'active',1,json_encode($state,JSON_THROW_ON_ERROR),bin2hex(random_bytes(24))]);
  $db->commit();
 }
 $run=function(string $target,string $op,array $args=[],?string $nonce=null,?int $expected=null)
   use($db):array{
  $db->beginTransaction();
  try{
   $record=sessionRow($db,$target);GameSessionLifecycle::lockGame($db,$record['game_id']);
   $row=sessionRow($db,$target,true);$state=stateFromRow($row);
   if($nonce!==null){
    $q=$db->prepare('SELECT payload_json FROM game_events WHERE session_id=? AND idempotency_key=?');
    $q->execute([$target,$nonce]);
    if($entry=$q->fetch()){$db->commit();return ['repeat'=>true,'result'=>json_decode($entry['payload_json'],true,64,JSON_THROW_ON_ERROR)];}
    if($expected!==null && (int)$row['version']!==$expected)
     throw new DomainException('SDK optimistic version mismatch.',409);
   }
   $event=GamePluginCapabilities::execute($db,$state,$op,$args);
   if(GamePluginCapabilities::changesState($op)){
    saveState($db,$state);
    $response=['capability'=>$event,'view'=>GameRegistry::module($state['game'])->publicView($state)];
    if($nonce!==null){
     $q=$db->prepare('INSERT INTO game_events(session_id,idempotency_key,event_type,payload_json) VALUES (?,?,?,?)');
     $q->execute([$target,$nonce,'sdk',json_encode($response,JSON_THROW_ON_ERROR)]);
    }
   }
   $db->commit();return ['repeat'=>false,'result'=>$event];
  }catch(Throwable $e){$db->rollBack();throw $e;}
 };
 $first=$run($id,'storage.set',['key'=>'level','value'=>5,'expectedRevision'=>0],'storage-start-001',1);
 ck24($first['result']['value']['revision']===1,'Game-scoped storage persists with revision 1');
 $dupe=$run($id,'storage.set',['key'=>'level','value'=>5,'expectedRevision'=>0],'storage-start-001',1);
 ck24($dupe['repeat']===true,'Duplicate write idempotent after database commit');
 ck24($run($id,'storage.get',['key'=>'level'])['result']['value']['value']===5,'Game-scoped key reads original persisted JSON');
 err24(fn()=>$run($id,'storage.set',['key'=>'level','value'=>7,'expectedRevision'=>0]),'Stale key revision rejected',409);
 ck24($run($id,'storage.set',['key'=>'level','value'=>7,'expectedRevision'=>1])['result']['value']['revision']===2,'Optimistic storage version increments');
 ck24($run($id,'storage.set',['key'=>'level','value'=>20,'expectedRevision'=>0,'scope'=>'session'])['result']['value']['revision']===1,'Session-scoped storage is separate');
 ck24($run($id,'storage.get',['key'=>'level','scope'=>'session'])['result']['value']['value']===20,'Session scope read');
 ck24($run($id,'storage.get',['key'=>'level'])['result']['value']['value']===7,'Session write cannot alter global game scope');
 ck24($run($otherId,'storage.get',['key'=>'level'])['result']['value']['revision']===0,'Another game cannot read first game storage');
 err24(fn()=>$run($otherId,'storage.set',['key'=>'level','value'=>'stolen','expectedRevision'=>0]),'Other game denied storage.write',403);
 err24(fn()=>$run($otherId,'scores.add',['playerId'=>'one','delta'=>1]),'Other game denied score modifications',403);
 $run($id,'players.add',['id'=>'one','name'=>'Player One']);
 $run($id,'players.add',['id'=>'two','name'=>'Player Two']);
 ck24(count($run($id,'players.list')['result']['value'])===2,'Players persist as structured server-owned data');
 err24(fn()=>$run($id,'players.add',['id'=>'one','name'=>'Duplicate']),'Duplicate player rejected',409);
 $run($id,'scores.add',['playerId'=>'one','delta'=>25]);
 ck24($run($id,'scores.list')['result']['value']['one']===25,'Score mutation is authoritative and persistent');
 err24(fn()=>$run($id,'scores.add',['playerId'=>'one','delta'=>10001]),'Unbounded scoring rejected',422);
 $run($id,'rounds.next');
 ck24($run($id,'rounds.get')['result']['value']['round']===1,'Round increment persisted');
 $run($id,'turns.set',['playerId'=>'two']);
 ck24($run($id,'turns.get')['result']['value']['playerId']==='two','Turn ownership persisted');
 err24(fn()=>$run($id,'turns.set',['playerId'=>'missing']),'Unknown player cannot take turn',404);
 $run($id,'timers.start',['name'=>'countdown','seconds'=>60]);
 ck24($run($id,'timers.get',['name'=>'countdown'])['result']['value']['remainingSeconds']>0,'Timer uses wall-clock expiration');
 $run($id,'timers.stop',['name'=>'countdown']);
 ck24($run($id,'timers.get',['name'=>'countdown'])['result']['value']['active']===false,'Stopped timer persists');
 $row=sessionRow($db,$id);$version=(int)$row['version'];
 err24(fn()=>$run($id,'scores.add',['playerId'=>'one','delta'=>1],'stale-scores-001',$version-1),'Old desktop state cannot write over new version',409);
 $before=stateFromRow($row);ck24(($before['plugin']['scores']['one']??null)===25,'Plugin state survives independent SQL reload');
 $db->beginTransaction();$saved=stateFromRow(sessionRow($db,$id,true));
 GameSessionLifecycle::transition($saved,'save');saveState($db,$saved);$db->commit();
 err24(fn()=>$run($id,'scores.add',['playerId'=>'one','delta'=>1]),'Saved sessions cannot mutate',409);
 zip24($zip,$game,'1.1.0',GamePluginCapabilities::SUPPORTED);
 GamePackageInstaller::install($zip);GamePackageActivation::activate($game,'1.1.0','1.0.0',true);
 ck24($run($id,'storage.get',['key'=>'level'])['result']['value']['value']===7,'Game storage survives package upgrade');
 ck24(stateFromRow(sessionRow($db,$id))['packageVersion']==='1.0.0','Saved session retains its original package permissions/version');
 $db->beginTransaction();$resumed=stateFromRow(sessionRow($db,$id,true));
 GameSessionLifecycle::transition($resumed,'resume_saved');saveState($db,$resumed);$db->commit();
 ck24($run($id,'scores.list')['result']['value']['one']===25,'Game scores survive HomeServer restart/resume');
 GamePackageActivation::activate($game,'1.0.0','1.1.0',true);
 ck24($run($id,'storage.get',['key'=>'level'])['result']['value']['value']===7,'Game storage survives rollback');
 echo "V5Q.24: real MariaDB scoped KV, CAS, permissions, players, scores, rounds, turns, timers, replay and recovery PASSED\n";
}finally{
 if($db->inTransaction())$db->rollBack();
 $q=$db->prepare('DELETE FROM game_plugin_storage WHERE game_id IN (?,?)');$q->execute([$game,$other]);
 $q=$db->prepare('DELETE FROM game_sessions WHERE id IN (?,?)');$q->execute([$id,$otherId]);
 $q=$db->prepare('DELETE FROM game_settings WHERE game_id IN (?,?)');$q->execute([$game,$other]);
 putenv('LUCKY_TEST_GAME_PACKAGES');clean24($home);
}
