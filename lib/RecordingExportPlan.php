<?php
declare(strict_types=1);

/** Pure, bounded edit-decision materialization. Does not read files or mutate recorded media. */
final class RecordingExportPlan {
 public const MAX_DURATION_MS=1200000; // 20-minute export, consistent with movie editor.
 public static function build(array $media,array $timeline,?string $masterAudio=null):array {
  if(!is_array($timeline['offsets']??null)||!is_array($timeline['switches']??null))
   throw new DomainException('Save a valid multicamera timeline before exporting.',422);
  $byId=[];$video=[];
  foreach($media as $row){
   if(!is_array($row)||!preg_match('/^[a-f0-9]{32}$/D',(string)($row['id']??'')))continue;
   if(!in_array($row['kind']??'', ['video','photo','audio'],true))continue;
   $length=(int)($row['duration_ms']??0);
   if($row['kind']==='photo'&&$length===0)$length=4000;
   if($length<100||$length>3600000)throw new DomainException('Source clip duration is missing or invalid.',422);
   $row['duration_ms']=$length;$row['offset_ms']=(int)($row['initial_global_offset_ms']??0);
   if(isset($byId[$row['id']]))throw new DomainException('Duplicate source clip.',422);
   $byId[$row['id']]=$row;
  }
  foreach($timeline['offsets'] as $offset){
   $id=(string)($offset['assetId']??'');
   if(!isset($byId[$id])||!is_int($offset['offsetMs']??null)||$offset['offsetMs'] < -600000||$offset['offsetMs'] > 86400000)
    throw new DomainException('Export source alignment is invalid.',422);
   $byId[$id]['offset_ms']=$offset['offsetMs'];
  }
  $audioMode=$masterAudio===null?'follow':($masterAudio===''?'silent':'master');
  if($audioMode==='master'&&($byId[$masterAudio]['kind']??'')!=='audio')throw new DomainException('Master audio must be an audio source in this recording.',422);
  foreach($byId as $id=>$row)if(in_array($row['kind'],['video','photo'],true))$video[$id]=$row;
  if(!$video)throw new DomainException('No camera video or photos available to export.',422);
  $end=0;$points=[0];
  foreach($video as $id=>$row){
   $start=max(0,$row['offset_ms']);$finish=$row['offset_ms']+$row['duration_ms'];
   if($finish<=0)continue;
   $end=max($end,$finish);
   $points[]=$start;$points[]=$finish;
  }
  if($end<100||$end>self::MAX_DURATION_MS)throw new DomainException('Export must be between 0.1 seconds and 20 minutes.',422);
  $cuts=[];$prior=-1;
  foreach($timeline['switches'] as $cut){
   $id=(string)($cut['assetId']??'');$at=$cut['atMs']??null;
   if(!isset($video[$id])||!is_int($at)||$at<0||$at<=$prior||$at>86400000)
    throw new DomainException('Export camera cut is invalid.',422);
   if($at<$end){$cuts[]=['atMs'=>$at,'assetId'=>$id];$points[]=$at;}
   $prior=$at;
  }
  $points[]=$end;$points=array_values(array_unique(array_filter($points,fn($n)=>$n>=0&&$n<=$end)));
  sort($points,SORT_NUMERIC);
  $segments=[];$activeCut='';$cursor=0;
  for($i=0;$i<count($points)-1;$i++){
   $start=$points[$i];$finish=$points[$i+1];if($finish<=$start)continue;
   while(isset($cuts[$cursor])&&$cuts[$cursor]['atMs']<=$start){$activeCut=$cuts[$cursor]['assetId'];$cursor++;}
   $available=[];
   foreach($video as $id=>$row)if($row['offset_ms']<=$start&&$row['offset_ms']+$row['duration_ms']>=$finish)$available[$id]=$row;
   $chosen=isset($available[$activeCut])?$available[$activeCut]:(reset($available)?:null);
   $clipId=$chosen['id']??null;
   $sourceStart=$chosen?($start-$chosen['offset_ms']):0;
   $n=count($segments);
   if($n&&$segments[$n-1]['assetId']===$clipId&&$segments[$n-1]['endMs']===$start){
    $segments[$n-1]['endMs']=$finish;
   }else{
    $segments[]=['assetId'=>$clipId,'kind'=>$chosen['kind']??'gap','startMs'=>$start,'endMs'=>$finish,'sourceStartMs'=>$sourceStart];
   }
  }
  if(!$segments||count($segments)>600)throw new DomainException('Export has too many cuts.',422);
  return ['durationMs'=>$end,'segments'=>$segments,'audioMode'=>$audioMode,'audioAssetId'=>$audioMode==='master'?$masterAudio:null,
   'masterOffsetMs'=>$audioMode==='master'?$byId[$masterAudio]['offset_ms']:null];
 }
}
