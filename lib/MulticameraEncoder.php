<?php
declare(strict_types=1);
require_once __DIR__.'/VideoRender.php';
require_once __DIR__.'/RecordingExportPlan.php';

/** Server-owned, bounded FFmpeg composition from a frozen multicamera edit list. */
final class MulticameraEncoder {
 private VideoRender $runner;
 private string $ffmpeg;
 private string $ffprobe;
 public function __construct(?string $ffmpeg=null,?string $ffprobe=null) {
  $this->ffmpeg=$ffmpeg??(getenv('LUCKY_FFMPEG_BIN')?:'ffmpeg');
  $this->ffprobe=$ffprobe??(getenv('LUCKY_FFPROBE_BIN')?:'ffprobe');
  $this->runner=new VideoRender($this->ffmpeg,$this->ffprobe);
 }
 private function contains(string $path,string $type,string $log):bool {
  $json=$this->runner->runProcess([$this->ffprobe,'-v','error','-show_entries','stream=codec_type','-of','json',$path],$log,30);
  $data=json_decode($json,true);
  if(!is_array($data))throw new RuntimeException('Could not inspect multicamera source.');
  foreach($data['streams']??[] as $stream)if(($stream['codec_type']??'')===$type)return true;
  return false;
 }
 public function render(array $plan,array $paths,string $output,string $tempDir):array {
  $segments=$plan['segments']??null;$total=(int)($plan['durationMs']??0);$audioMode=$plan['audioMode']??'follow';
  if(!is_array($segments)||!array_is_list($segments)||!$segments||count($segments)>120||$total<100||$total>RecordingExportPlan::MAX_DURATION_MS)
   throw new DomainException('Invalid or oversized multicamera export.',422);
  if(!in_array($audioMode,['follow','silent','master'],true))throw new DomainException('Unknown export audio mode.',422);
  if(!is_dir($tempDir)&&!mkdir($tempDir,0700,true))throw new RuntimeException('Cannot create private export work folder.');
  $files=[];$mediaHasAudio=[];$pos=0;$durationSeconds=number_format($total/1000,3,'.','');
  foreach($segments as $i=>$segment){
   if(!is_array($segment)||($segment['startMs']??null)!==$pos||!is_int($segment['endMs']??null)||$segment['endMs']<=$pos||$segment['endMs']>$total)
    throw new DomainException('Export segments are not continuous and ordered.',422);
   $id=$segment['assetId']??null;$kind=$segment['kind']??'';$from=(int)($segment['sourceStartMs']??0);
   if(!in_array($kind,['video','photo','gap'],true)||($id!==null&&!preg_match('/^[a-f0-9]{32}$/D',(string)$id))||($kind==='gap')!==($id===null)||$from<0)
    throw new DomainException('Invalid export segment source.',422);
   $path=$id!==null?($paths[$id]??null):null;
   if($id!==null&&(!is_string($path)||!is_file($path)))throw new RuntimeException('Export source was removed or is unavailable.');
   $seconds=number_format(($segment['endMs']-$pos)/1000,3,'.','');$seek=number_format($from/1000,3,'.','');
   $dest=$tempDir.'/segment-'.sprintf('%03d',$i).'.mp4';
   $vf='scale=1280:720:force_original_aspect_ratio=decrease,pad=1280:720:(ow-iw)/2:(oh-ih)/2:color=0x101720,setsar=1,fps=30,format=yuv420p';
   if($kind==='gap'){$argv=[$this->ffmpeg,'-hide_banner','-nostdin','-v','error','-y','-f','lavfi','-i','color=c=0x101720:s=1280x720:r=30','-f','lavfi','-i','anullsrc=channel_layout=stereo:sample_rate=48000'];$video='0:v:0';$audio='1:a:0';}
   elseif($kind==='photo'){$argv=[$this->ffmpeg,'-hide_banner','-nostdin','-v','error','-y','-loop','1','-framerate','30','-i',$path,'-f','lavfi','-i','anullsrc=channel_layout=stereo:sample_rate=48000'];$video='0:v:0';$audio='1:a:0';}
   else{
    if(!array_key_exists($id,$mediaHasAudio)){
     if(!$this->contains($path,'video',$tempDir.'/probe-video-'.$i.'.log'))throw new RuntimeException('Camera clip has no playable video.');
     $mediaHasAudio[$id]=$this->contains($path,'audio',$tempDir.'/probe-audio-'.$i.'.log');
    }
    $argv=[$this->ffmpeg,'-hide_banner','-nostdin','-v','error','-y','-ss',$seek,'-i',$path];
    $video='0:v:0';$audio='0:a:0';
    if($audioMode!=='follow'||!$mediaHasAudio[$id]){
     $argv=array_merge($argv,['-f','lavfi','-i','anullsrc=channel_layout=stereo:sample_rate=48000']);$audio='1:a:0';
    }
   }
   $argv=array_merge($argv,['-t',$seconds,'-map',$video,'-map',$audio,'-vf',$vf,
    '-c:v','libx264','-preset','veryfast','-crf','25','-pix_fmt','yuv420p','-r','30',
    '-c:a','aac','-ac','2','-ar','48000','-b:a','128k','-shortest','-movflags','+faststart',$dest]);
   $this->runner->runProcess($argv,$tempDir.'/segment-'.$i.'.log',240);
   if(!is_file($dest)||filesize($dest)<1024)throw new RuntimeException('FFmpeg did not produce a camera segment.');
   $files[]=$dest;$pos=$segment['endMs'];
  }
  if($pos!==$total)throw new DomainException('Multicamera export does not cover its declared duration.',422);
  $list='';foreach($files as $f){$normalized=str_replace('\\','/',$f);$list.="file '".str_replace("'","'\\''",$normalized)."'\n";}
  if(file_put_contents($tempDir.'/list.txt',$list)===false)throw new RuntimeException('Cannot prepare multicamera merge.');
  $combined=$tempDir.'/combined.mp4';
  $this->runner->runProcess([$this->ffmpeg,'-hide_banner','-nostdin','-v','error','-y','-f','concat','-safe','0','-i',$tempDir.'/list.txt',
   '-c','copy','-movflags','+faststart',$combined],$tempDir.'/concat.log',240);
  $tempOutput=$output.'.part';
  try{
   if($audioMode==='master'){
    $audioId=$plan['audioAssetId']??null;$offset=(int)($plan['masterOffsetMs']??0);
    $audioFile=is_string($audioId)?($paths[$audioId]??null):null;
    if(!is_string($audioFile)||!is_file($audioFile)||!$this->contains($audioFile,'audio',$tempDir.'/master-probe.log'))
     throw new RuntimeException('Selected master audio recording is unavailable.');
    $args=[$this->ffmpeg,'-hide_banner','-nostdin','-v','error','-y','-i',$combined];
    if($offset<0)$args=array_merge($args,['-ss',number_format(-$offset/1000,3,'.','')]);
    $args=array_merge($args,['-i',$audioFile]);
    $delay=max(0,$offset);
    $filter="[1:a:0]aresample=48000,adelay={$delay}:all=1,apad=whole_dur={$durationSeconds},atrim=duration={$durationSeconds},asetpts=N/SR/TB[master]";
    $args=array_merge($args,['-filter_complex',$filter,'-map','0:v:0','-map','[master]',
     '-t',$durationSeconds,'-c:v','copy','-c:a','aac','-b:a','160k','-movflags','+faststart',$tempOutput]);
    $this->runner->runProcess($args,$tempDir.'/audio-master.log',240);
   }else{
    if(!copy($combined,$tempOutput))throw new RuntimeException('Cannot stage finished multicamera video.');
   }
   if(!is_file($tempOutput)||filesize($tempOutput)<1024||!rename($tempOutput,$output))
    throw new RuntimeException('Cannot publish the completed multicamera export.');
  }catch(Throwable $e){@unlink($tempOutput);throw $e;}
  return ['bytes'=>(int)filesize($output),'durationMs'=>$total,'segments'=>count($files)];
 }
}
