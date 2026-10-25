<?php
declare(strict_types=1);
require_once __DIR__.'/ClaimMedia.php';
require_once __DIR__.'/VideoRender.php';

/** Local, bounded FFmpeg observations. Media bytes never leave this installation. */
final class MediaIntelligence {
 public const VERSION=1;
 public static function cachePath(string $id):string {
  if(!ClaimMedia::validId($id))throw new DomainException('Invalid media identity.',422);
  return LUCKY_ROOT.'/storage/media-analysis/'.$id.'.json';
 }
 private static function fingerprint(string $path):string {
  if(!is_file($path))throw new DomainException('Original recording is missing.',404);
  return hash('sha256',filesize($path).':'.filemtime($path));
 }
 public static function cached(string $id,string $path):?array {
  $file=self::cachePath($id);if(!is_file($file)||!is_file($path))return null;
  $raw=@file_get_contents($file);if(!is_string($raw)||strlen($raw)>20000)return null;
  $data=json_decode($raw,true);
  if(!is_array($data)||($data['version']??null)!==self::VERSION||!hash_equals((string)($data['fingerprint']??''),self::fingerprint($path)))return null;
  return is_array($data['signals']??null)?$data['signals']:null;
 }
 public static function sceneCuts(string $output,int $durationMs):array {
  $cut=[];
  foreach(preg_split('/\r?\n/',$output) as $line){
   if(strpos($line,'showinfo')===false||!preg_match('/pts_time:\s*([0-9]+(?:\.[0-9]+)?)/',$line,$m))continue;
   $time=(int)round((float)$m[1]*1000);
   if($time<400||$time>$durationMs-400||($cut&&$time-end($cut)<700))continue;
   $cut[]=$time;if(count($cut)>=24)break;
  }
  return $cut;
 }
 public static function silences(string $output,int $durationMs):array {
  $start=null;$segments=[];
  foreach(preg_split('/\r?\n/',$output) as $line){
   if(preg_match('/silence_start:\s*([0-9]+(?:\.[0-9]+)?)/',$line,$m))$start=max(0,(int)round((float)$m[1]*1000));
   if($start!==null&&preg_match('/silence_end:\s*([0-9]+(?:\.[0-9]+)?)/',$line,$m)){
    $end=min($durationMs,(int)round((float)$m[1]*1000));
    if($end>$start+300)$segments[]=['startMs'=>$start,'endMs'=>$end];$start=null;
    if(count($segments)>=24)break;
   }
  }
  if($start!==null&&$durationMs>$start+300&&count($segments)<24)$segments[]=['startMs'=>$start,'endMs'=>$durationMs];
  return $segments;
 }
 public static function analyze(string $id,string $kind,string $path,int $reportedDurationMs=0):array {
  if(!ClaimMedia::validId($id)||!in_array($kind,['video','audio','photo'],true))throw new DomainException('Invalid source media.',422);
  if($previous=self::cached($id,$path))return $previous;
  if(!function_exists('proc_open'))throw new RuntimeException('Local media analysis requires the FFmpeg worker.');
  if(!is_file($path)||filesize($path)<1)throw new DomainException('Original recording is missing.',404);
  $work=sys_get_temp_dir().'/lucky-analysis-'.bin2hex(random_bytes(8));
  if(!mkdir($work,0700))throw new RuntimeException('Cannot create private analysis workspace.');
  $ffmpeg=getenv('LUCKY_FFMPEG_BIN')?:'ffmpeg';$ffprobe=getenv('LUCKY_FFPROBE_BIN')?:'ffprobe';
  $runner=new VideoRender($ffmpeg,$ffprobe);
  try{
   // Uploaded containers must never cause FFmpeg to fetch remote media URLs.
   $probe=$runner->runProcess([$ffprobe,'-v','error','-protocol_whitelist','file,pipe','-show_entries','format=duration:stream=codec_type','-of','json',$path],$work.'/probe.log',15);
   $metadata=json_decode($probe,true);if(!is_array($metadata))throw new RuntimeException('Cannot inspect this recording.');
   $durationMs=min(120000,max(1000,(int)round((float)($metadata['format']['duration']??0)*1000),$reportedDurationMs));
   $hasVideo=false;$hasAudio=false;
   foreach($metadata['streams']??[] as $stream){$hasVideo=$hasVideo||($stream['codec_type']??'')==='video';$hasAudio=$hasAudio||($stream['codec_type']??'')==='audio';}
   $scene=[];$quiet=[];$limit=number_format($durationMs/1000,3,'.','');
   if($kind==='video'&&$hasVideo){
    $log=$runner->runProcess([$ffmpeg,'-hide_banner','-nostdin','-v','info','-protocol_whitelist','file,pipe','-t',$limit,'-i',$path,'-map','0:v:0','-vf','select=gt(scene\\,0.36),showinfo','-an','-f','null','-'],$work.'/scene.log',90);
    $scene=self::sceneCuts($log,$durationMs);
   }
   if($kind!=='photo'&&$hasAudio){
    $log=$runner->runProcess([$ffmpeg,'-hide_banner','-nostdin','-v','info','-protocol_whitelist','file,pipe','-t',$limit,'-i',$path,'-map','0:a:0','-af','silencedetect=noise=-32dB:d=0.5','-vn','-f','null','-'],$work.'/silence.log',90);
    $quiet=self::silences($log,$durationMs);
   }
   $signals=['durationMs'=>$durationMs,'sceneCutsMs'=>$scene,'silenceRanges'=>$quiet,'hasVideo'=>$hasVideo,'hasAudio'=>$hasAudio,'transcriptAvailable'=>false,'engine'=>'ffmpeg-local'];
   $dir=dirname(self::cachePath($id));if(!is_dir($dir)&&!mkdir($dir,0700,true))throw new RuntimeException('Cannot create private analysis cache.');
   $file=self::cachePath($id);$temp=$file.'.'.bin2hex(random_bytes(5)).'.tmp';
   $encoded=json_encode(['version'=>self::VERSION,'fingerprint'=>self::fingerprint($path),'signals'=>$signals],JSON_THROW_ON_ERROR);
   if(file_put_contents($temp,$encoded,LOCK_EX)===false)throw new RuntimeException('Cannot cache media observations.');
   @chmod($temp,0600);if(!rename($temp,$file)){@unlink($temp);throw new RuntimeException('Cannot publish media observations.');}
   return $signals;
  }finally{
   foreach(['probe.log','scene.log','silence.log'] as $name){$tmp=$work.'/'.$name;if(is_file($tmp))@unlink($tmp);}
   @rmdir($work);
  }
 }
 /** Restrict context to numerical observations; never send raw media or private transcripts. */
 public static function promptSignals(array $signals):array {
  $duration=max(0,min(120000,(int)($signals['durationMs']??0)));
  $cuts=[];foreach(is_array($signals['sceneCutsMs']??null)?$signals['sceneCutsMs']:[] as $v){if(is_int($v)&&$v>=0&&$v<=$duration)$cuts[]=$v;if(count($cuts)>=12)break;}
  $silence=[];foreach(is_array($signals['silenceRanges']??null)?$signals['silenceRanges']:[] as $range){if(!is_array($range))continue;$start=$range['startMs']??null;$end=$range['endMs']??null;
   if(is_int($start)&&is_int($end)&&$start>=0&&$end<=$duration&&$end>$start)$silence[]=['startMs'=>$start,'endMs'=>$end];
   if(count($silence)>=12)break;
  }
  return ['sceneCutsMs'=>$cuts,'silenceRanges'=>$silence];
 }
 /** Timings belong to the media source. The per-instance ID is the only identifier shared. */
 public static function timelineCues(array $clips,callable $cached):array {
  $out=[];
  foreach($clips as $clip){
   if(!in_array($clip['kind']??null,['video','audio'],true))continue;
   $id=(string)($clip['id']??'');$instance=(string)($clip['instanceId']??'');
   if(!ClaimMedia::validId($id)||!preg_match('/^[A-Za-z0-9-]{1,80}$/D',$instance))continue;
   $signals=$cached($id);
   if(!is_array($signals))continue;
   $safe=self::promptSignals($signals);
   if($safe['sceneCutsMs']||$safe['silenceRanges'])$out[]=['instanceId'=>$instance]+$safe;
   if(count($out)>=12)break;
  }
  return $out;
 }
}
