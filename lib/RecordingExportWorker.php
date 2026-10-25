<?php
declare(strict_types=1);
require_once __DIR__.'/MulticameraEncoder.php';

/** Shared CLI processor. Flock prevents concurrent or replayed recording export jobs. */
final class RecordingExportWorker {
 public static function run(PDO $db):void {
  $root=LUCKY_ROOT.'/storage/recording-exports';
  if(!is_dir($root)&&!mkdir($root,0700,true))throw new RuntimeException('Cannot create private recording export storage.');
  $lock=fopen($root.'/.worker.lock','c');
  if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){if($lock)fclose($lock);echo "Recording export worker already active.\n";return;}
  $id=null;$temp=null;
  try{
   $db->exec("UPDATE recording_exports SET status='failed',error_message='Worker interrupted; queue a new export.',finished_at=UTC_TIMESTAMP(3) WHERE status='running' AND started_at < UTC_TIMESTAMP() - INTERVAL 60 MINUTE");
   $db->beginTransaction();
   $q=$db->query("SELECT * FROM recording_exports WHERE status='queued' ORDER BY created_at,id LIMIT 1 FOR UPDATE");
   $job=$q->fetch(PDO::FETCH_ASSOC);
   if(!$job){$db->commit();echo "No pending multicamera exports.\n";return;}
   $id=(string)$job['id'];
   $q=$db->prepare("UPDATE recording_exports SET status='running',started_at=UTC_TIMESTAMP(3) WHERE id=? AND status='queued'");
   $q->execute([$id]);$db->commit();
   $plan=json_decode((string)$job['snapshot_json'],true,512,JSON_THROW_ON_ERROR);
   if(!is_array($plan))throw new RuntimeException('Missing immutable export snapshot.');
   $needed=[];foreach($plan['segments']??[] as $s)if(is_array($s)&&!empty($s['assetId']))$needed[$s['assetId']]=true;
   if(!empty($plan['audioAssetId']))$needed[$plan['audioAssetId']]=true;
   $q=$db->prepare("SELECT a.id,a.storage_key,a.kind FROM recording_assets a
      JOIN recording_media_files m ON m.asset_id=a.id WHERE a.recording_session_id=?");
   $q->execute([$job['recording_session_id']]);$paths=[];
   foreach($q->fetchAll(PDO::FETCH_ASSOC) as $asset){
    if(!isset($needed[$asset['id']]))continue;
    $key=(string)($asset['storage_key']??'');
    if(!preg_match('/^[a-f0-9]{32}$/D',$key))throw new RuntimeException('Recording source has no private storage key.');
    $paths[$asset['id']]=LUCKY_ROOT.'/storage/recording-assets/'.$key.'.bin';
   }
   if(count($paths)!==count($needed))throw new RuntimeException('One or more source recordings are missing.');
   $temp=$root.'/work-'.$id;
   $out=$root.'/'.$id.'.mp4';
   $result=(new MulticameraEncoder())->render($plan,$paths,$out,$temp);
   $q=$db->prepare("UPDATE recording_exports SET status='ready',output_bytes=?,duration_ms=?,error_message='',finished_at=UTC_TIMESTAMP(3) WHERE id=? AND status='running'");
   $q->execute([$result['bytes'],$result['durationMs'],$id]);if($q->rowCount()!==1)throw new RuntimeException('Export state was changed while rendering.');
   echo "Multicamera export ready: {$id} ({$result['bytes']} bytes)\n";
  }catch(Throwable $e){
   if($db->inTransaction())$db->rollBack();
   error_log('Lucky multicamera export worker: '.$e->getMessage());
   if($id){
    $db->prepare("UPDATE recording_exports SET status='failed',error_message=?,finished_at=UTC_TIMESTAMP(3) WHERE id=? AND status='running'")->execute([substr($e->getMessage(),0,300),$id]);
    @unlink($root.'/'.$id.'.mp4');
   }
   fwrite(STDERR,"Multicamera export failed: ".$e->getMessage()."\n");
  }finally{
   if($temp&&is_dir($temp)){
    foreach(glob($temp.'/*')?:[] as $file)if(is_file($file))@unlink($file);
    @rmdir($temp);
   }
   flock($lock,LOCK_UN);fclose($lock);
  }
 }
}
