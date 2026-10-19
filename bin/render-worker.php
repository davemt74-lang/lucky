<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit('CLI only');}
require __DIR__.'/../lib/Bootstrap.php';
require __DIR__.'/../lib/VideoRender.php';
if(!installed()){fwrite(STDERR,"Platform is not installed.\n");exit(1);}
$root=LUCKY_ROOT.'/storage/renders';if(!is_dir($root)&&!mkdir($root,0700,true))exit(1);
$lock=fopen($root.'/.worker.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){fwrite(STDERR,"Another render worker is active.\n");exit(0);}
$id=null;$temp=null;$result=null;
try{
 $db=db();
 // A previous crashed worker may leave an abandoned job. Only recover after acquiring the exclusive worker lock.
 $db->exec("UPDATE montage_renders SET status='failed',error_message='Render worker interrupted; queue a new export.',finished_at=UTC_TIMESTAMP() WHERE status='running' AND started_at < UTC_TIMESTAMP() - INTERVAL 60 MINUTE");
 $db->beginTransaction();
 $q=$db->query("SELECT * FROM montage_renders WHERE status='queued' ORDER BY created_at,id LIMIT 1 FOR UPDATE");$job=$q->fetch();
 if(!$job){$db->commit();echo "No pending videos.\n";exit(0);}
 $id=$job['id'];$q=$db->prepare("UPDATE montage_renders SET status='running',started_at=UTC_TIMESTAMP() WHERE id=? AND status='queued'");$q->execute([$id]);$db->commit();
 $clips=json_decode($job['snapshot_json'],true,512,JSON_THROW_ON_ERROR);MontagePlan::validate($clips);
 $music=null;if($job['soundtrack_id']){
  $q=$db->prepare("SELECT id FROM media_tracks WHERE id=? AND kind='upload'");$q->execute([$job['soundtrack_id']]);
  if(!$q->fetch())throw new RuntimeException('Soundtrack was deleted after queuing.');
  $music=LUCKY_ROOT.'/storage/audio/'.$job['soundtrack_id'].'.audio';
 }
 $temp=$root.'/work-'.$id;
 $target=$root.'/'.$id.'.mp4';
 $renderer=new VideoRender();$result=$renderer->render($clips,$target,$temp,$job['profile'],$music,(int)$job['music_volume']/100);
 $db->prepare("UPDATE montage_renders SET status='ready',output_bytes=?,error_message='',finished_at=UTC_TIMESTAMP() WHERE id=? AND status='running'")->execute([$result['bytes'],$id]);
 echo "Ready: $id (".$result['bytes']." bytes)\n";
}catch(Throwable $e){
 error_log('Lucky render worker: '.$e->getMessage());
 if(isset($db)&&$db->inTransaction())$db->rollBack();
 if($id){
  if(isset($db))$db->prepare("UPDATE montage_renders SET status='failed',error_message=?,finished_at=UTC_TIMESTAMP() WHERE id=? AND status='running'")->execute([substr($e->getMessage(),0,300),$id]);
  @unlink($root.'/'.$id.'.mp4');
 }
 fwrite(STDERR,"Render failed: ".$e->getMessage()."\n");
}finally{
 // Only the worker-created temporary directory; never delete private input media.
 if($temp&&is_dir($temp)){$files=glob($temp.'/*');if($files!==false)foreach($files as $f)if(is_file($f))@unlink($f);@rmdir($temp);}
 flock($lock,LOCK_UN);fclose($lock);
}
