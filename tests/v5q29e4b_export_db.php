<?php
declare(strict_types=1);
if(getenv('CI')!=='true')throw new RuntimeException('CI MariaDB only.');
define('LUCKY_TESTING',true);
require_once __DIR__.'/../lib/Bootstrap.php';
require_once __DIR__.'/../lib/MigrationSql.php';
require_once __DIR__.'/../lib/RecordingExports.php';
function exportDbAssert(bool $ok,string $label):void{if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label."\n";}
function exportDbReject(callable $fn,int $code,string $label):void{
 try{$fn();throw new RuntimeException('Expected failure: '.$label);}
 catch(DomainException $e){exportDbAssert($e->getCode()===$code,$label);}
}
$db=new PDO('mysql:host=127.0.0.1;port=3306;dbname=lucky_test;charset=utf8mb4','root','luckytestpassword',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(MigrationSql::statements((string)file_get_contents(__DIR__.'/../lib/schema.sql')) as $sql)$db->exec($sql);
foreach(['v5q_claim_media.sql','v5q29e1_recording_sessions.sql','v5q29e2_phone_camera.sql','v5q29e3_multicamera_coordination.sql','v5q29e4a_multicamera_timeline.sql','v5q29e4b_multicamera_exports.sql','v5q29e4b_multicamera_exports.sql'] as $file)
 foreach(MigrationSql::statements((string)file_get_contents(__DIR__.'/../lib/migrations/'.$file)) as $sql)$db->exec($sql);
$owner=0;$other=0;
try{
 foreach(['export-owner','export-other'] as $name){$db->prepare('INSERT INTO admins(username,password_hash) VALUES (?,?)')->execute([$name.'-'.bin2hex(random_bytes(5)),password_hash('demo',PASSWORD_DEFAULT)]);if(!$owner)$owner=(int)$db->lastInsertId();else $other=(int)$db->lastInsertId();}
 $session=RecordingSessions::create($db,$owner,['type'=>'live_event','title'=>'Rock show','nonce'=>'rock-export-create-01']);
 $take=RecordingSessions::startTake($db,$owner,$session['id'],['title'=>'Set one','nonce'=>'rock-export-take-01']);
 $camera=RecordingSessions::source($db,$owner,$session['id'],['key'=>'stage-cam','kind'=>'phone','name'=>'Stage']);
 $clip=bin2hex(random_bytes(16));
 $db->prepare("INSERT INTO recording_assets(id,recording_session_id,take_id,source_id,storage_key,kind,start_offset_ms) VALUES (?,?,?,?,?,'video',0)")
  ->execute([$clip,$session['id'],$take['id'],$camera['id'],$clip]);
 $db->prepare("INSERT INTO recording_media_files(asset_id,mime,title,bytes,sha256,duration_ms) VALUES (?,'video/webm','Stage',1000,?,3500)")
  ->execute([$clip,hash('sha256',$clip)]);
 $edit=['offsets'=>[],'switches'=>[['atMs'=>0,'assetId'=>$clip]]];
 RecordingTimeline::save($db,$owner,$session['id'],0,$edit);
 $first=RecordingExports::queue($db,$owner,$session['id'],1,null,'export-once-001');
 exportDbAssert($first['status']==='queued'&&$first['revision']===1,'Export queues immutable revision one');
 exportDbAssert(RecordingExports::queue($db,$owner,$session['id'],1,null,'export-once-001')['id']===$first['id'],'Retry with same token cannot duplicate export');
 exportDbReject(fn()=>RecordingExports::queue($db,$owner,$session['id'],1,'','export-once-001'),409,'Reused nonce cannot alter audio options');
 exportDbReject(fn()=>RecordingExports::queue($db,$owner,$session['id'],1,null,'export-next-002'),409,'Second active export is blocked');
 exportDbReject(fn()=>RecordingExports::get($db,$other,$first['id']),404,'Other administrator cannot fetch export');
 exportDbReject(fn()=>RecordingExports::list($db,$other,$session['id']),404,'Other administrator cannot list exports');
 RecordingTimeline::save($db,$owner,$session['id'],1,['offsets'=>[['assetId'=>$clip,'offsetMs'=>500]],'switches'=>[]]);
 $stored=RecordingExports::get($db,$owner,$first['id']);$frozen=json_decode($stored['snapshot_json'],true,512,JSON_THROW_ON_ERROR);
 exportDbAssert($stored['timeline_revision']==1&&$frozen['segments'][0]['sourceStartMs']===0,'Later timeline edits cannot alter queued export snapshot');
 exportDbReject(fn()=>RecordingExports::queue($db,$owner,$session['id'],1,null,'export-next-003'),409,'Stale revision cannot create export');
 $cancel=RecordingExports::cancel($db,$owner,$first['id']);
 exportDbAssert($cancel['status']==='cancelled','Queued export can be cancelled');
 exportDbReject(fn()=>RecordingExports::cancel($db,$owner,$first['id']),409,'Cannot cancel same export twice');
 $next=RecordingExports::queue($db,$owner,$session['id'],2,null,'export-next-004');
 exportDbAssert($next['id']!==$first['id']&&$next['revision']===2,'New revision queues after prior export cancellation');
 exportDbAssert(count(RecordingExports::list($db,$owner,$session['id']))===2,'Export history remains in recording library');
 echo "V5Q.29E4B MULTICAMERA EXPORT MARIADB CERTIFIED\n";
}finally{if($owner)$db->prepare('DELETE FROM admins WHERE id=?')->execute([$owner]);if($other)$db->prepare('DELETE FROM admins WHERE id=?')->execute([$other]);}
