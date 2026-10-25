<?php
declare(strict_types=1);
if(getenv('CI')!=='true')throw new RuntimeException('CI MariaDB only.');
define('LUCKY_TESTING',true);
require_once __DIR__.'/../lib/Bootstrap.php';
require_once __DIR__.'/../lib/MigrationSql.php';
require_once __DIR__.'/../lib/RecordingTimeline.php';
function timelineAssert(bool $ok,string $label):void{if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label."\n";}
function timelineReject(callable $fn,int $code,string $label):void{try{$fn();throw new RuntimeException('Expected failure: '.$label);}catch(DomainException $e){timelineAssert($e->getCode()===$code,$label);}}
$db=new PDO('mysql:host=127.0.0.1;port=3306;dbname=lucky_test;charset=utf8mb4','root','luckytestpassword',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(MigrationSql::statements((string)file_get_contents(__DIR__.'/../lib/schema.sql')) as $sql)$db->exec($sql);
foreach(['v5q_claim_media.sql','v5q29e1_recording_sessions.sql','v5q29e2_phone_camera.sql','v5q29e3_multicamera_coordination.sql','v5q29e4a_multicamera_timeline.sql','v5q29e4a_multicamera_timeline.sql'] as $file)foreach(MigrationSql::statements((string)file_get_contents(__DIR__.'/../lib/migrations/'.$file)) as $sql)$db->exec($sql);
$owner=0;$other=0;
try{
 foreach(['editor-owner','editor-other'] as $name){$db->prepare('INSERT INTO admins(username,password_hash) VALUES (?,?)')->execute([$name.'-'.bin2hex(random_bytes(6)),password_hash('demo',PASSWORD_DEFAULT)]);if(!$owner)$owner=(int)$db->lastInsertId();else $other=(int)$db->lastInsertId();}
 $session=RecordingSessions::create($db,$owner,['type'=>'live_event','title'=>'Festival multicamera','nonce'=>'editor-session-001']);
 $take=RecordingSessions::startTake($db,$owner,$session['id'],['title'=>'Stage','nonce'=>'editor-take-001']);
 $sourceA=RecordingSessions::source($db,$owner,$session['id'],['key'=>'stage-left','kind'=>'phone','name'=>'Stage left']);
 $sourceB=RecordingSessions::source($db,$owner,$session['id'],['key'=>'stage-right','kind'=>'phone','name'=>'Stage right']);
 $ids=[];
 foreach([$sourceA,$sourceB] as $i=>$source){$id=bin2hex(random_bytes(16));$ids[]=$id;
  $db->prepare("INSERT INTO recording_assets(id,recording_session_id,take_id,source_id,storage_key,kind,start_offset_ms,sync_confidence) VALUES (?,?,?,?,?,'video',?,1)")
   ->execute([$id,$session['id'],$take['id'],$source['id'],$id,300+300*$i]);
  $db->prepare("INSERT INTO recording_media_files(asset_id,mime,title,bytes,sha256,duration_ms) VALUES (?,'video/webm',?,500,?,5000)")
   ->execute([$id,'Stage angle '.($i+1),hash('sha256',$id)]);
 }
 $media=RecordingTimeline::media($db,$owner,$session['id']);
 timelineAssert(count($media)===2&&$media[0]['initial_global_offset_ms']>=0,'Both camera sources project onto session clock');
 timelineReject(fn()=>RecordingTimeline::media($db,$other,$session['id']),404,'Other admin cannot read media');
 $empty=RecordingTimeline::get($db,$owner,$session['id']);timelineAssert($empty['revision']===0&&$empty['timeline']['switches']===[],'New project starts with unmodified sources');
 $edit=['offsets'=>[['assetId'=>$ids[0],'offsetMs'=>100]],'switches'=>[['assetId'=>$ids[0],'atMs'=>0],['assetId'=>$ids[1],'atMs'=>1000]]];
 $saved=RecordingTimeline::save($db,$owner,$session['id'],0,$edit);timelineAssert($saved['revision']===1,'Initial edit is saved with revision 1');
 timelineAssert(RecordingTimeline::get($db,$owner,$session['id'])['timeline']===$edit,'Saved source offsets and angle cuts round trip');
 timelineReject(fn()=>RecordingTimeline::save($db,$owner,$session['id'],0,$edit),409,'Stale edit revision cannot overwrite newer edit');
 timelineReject(fn()=>RecordingTimeline::save($db,$other,$session['id'],1,$edit),404,'Non-owner cannot save timeline');
 timelineReject(fn()=>RecordingTimeline::save($db,$owner,$session['id'],1,['offsets'=>[['assetId'=>bin2hex(random_bytes(16)),'offsetMs'=>0]],'switches'=>[]]),422,'Foreign media cannot be placed on timeline');
 timelineReject(fn()=>RecordingTimeline::save($db,$owner,$session['id'],1,['offsets'=>[],'switches'=>[['assetId'=>$ids[0],'atMs'=>0],['assetId'=>$ids[1],'atMs'=>0]]]),422,'Duplicate cut position rejected');
 timelineReject(fn()=>RecordingTimeline::save($db,$owner,$session['id'],1,['offsets'=>[],'switches'=>[['assetId'=>$ids[0],'atMs'=>-1]]]),422,'Negative timeline cut rejected');
 $next=RecordingTimeline::save($db,$owner,$session['id'],1,['offsets'=>[],'switches'=>[]]);timelineAssert($next['revision']===2,'Explicitly clearing edits increments revision');
 echo "V5Q.29E4A MULTICAMERA TIMELINE MARIADB CERTIFICATION PASSED\n";
}finally{if($owner)$db->prepare('DELETE FROM admins WHERE id=?')->execute([$owner]);if($other)$db->prepare('DELETE FROM admins WHERE id=?')->execute([$other]);}
