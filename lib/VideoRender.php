<?php
declare(strict_types=1);
require_once __DIR__.'/MontagePlan.php';
require_once __DIR__.'/ClaimMedia.php';
/** Command-array FFmpeg invocation: never interpolates user strings into a shell. */
final class VideoRender {
 private string $ffmpeg;
 private string $ffprobe;
 public function __construct(?string $ffmpeg=null,?string $ffprobe=null){
  $this->ffmpeg=$ffmpeg??(getenv('LUCKY_FFMPEG_BIN')?:'ffmpeg');
  $this->ffprobe=$ffprobe??(getenv('LUCKY_FFPROBE_BIN')?:'ffprobe');
 }
 public function runProcess(array $argv,string $log,int $timeout=180):string {
  if(!function_exists('proc_open'))throw new RuntimeException('Server cannot start the FFmpeg worker (proc_open is disabled).');
  if($timeout<1||$timeout>900)throw new InvalidArgumentException('Invalid process timeout');
  $proc=@proc_open($argv,[0=>['file','/dev/null','r'],1=>['file',$log,'w'],2=>['file',$log,'a']],$pipes,null,null,['bypass_shell'=>true]);
  if(!is_resource($proc))throw new RuntimeException('FFmpeg process could not start.');
  $at=microtime(true);$status=proc_get_status($proc);$lastExit=-1;
  while($status['running']){
   if(microtime(true)-$at>$timeout){proc_terminate($proc,9);proc_close($proc);throw new RuntimeException('Video render stage exceeded its timeout.');}
   usleep(100000);$status=proc_get_status($proc);
  }
  $lastExit=(int)$status['exitcode'];$close=proc_close($proc);
  if($lastExit!==0&&$close!==0)throw new RuntimeException('Video processing failed. Verify FFmpeg codecs and uploaded media.');
  return is_file($log)?(string)file_get_contents($log):'';
 }
 private function probe(string $input,string $log):array {
  $content=$this->runProcess([$this->ffprobe,'-v','error','-show_entries','format=duration:stream=codec_type','-of','json',$input],$log,30);
  $info=json_decode($content,true);
  if(!is_array($info))throw new RuntimeException('Could not inspect uploaded media.');
  $duration=(float)($info['format']['duration']??0);$audio=false;$video=false;
  foreach($info['streams']??[] as $s){$audio=$audio||($s['codec_type']??'')==='audio';$video=$video||($s['codec_type']??'')==='video';}
  return ['duration'=>$duration,'audio'=>$audio,'video'=>$video];
 }
 public function render(array $clips,string $output,string $tempDir,string $profile='standard',?string $soundtrack=null,float $musicVolume=0.18):array {
  MontagePlan::validate($clips);$size=MontagePlan::profile($profile);
  if($musicVolume<0||$musicVolume>1)throw new DomainException('Invalid soundtrack volume.',422);
  if(!is_dir($tempDir)&&!mkdir($tempDir,0700,true))throw new RuntimeException('Could not create temporary render directory.');
  $segments=[];$total=0;
  foreach($clips as $i=>$c){
   $id=(string)$c['id'];if(!ClaimMedia::validId($id))throw new RuntimeException('Invalid input clip.');
   $input=LUCKY_ROOT.'/storage/claim-media/'.$id.'.bin';
   if(!is_file($input))throw new RuntimeException('The source clip for this render no longer exists.');
   $kind=$c['kind'];$duration=0;$start=0;$meta=[];
   $audioMode=(string)($c['audio_mode']??'normal');$voiceoverId=(string)($c['voiceover_id']??'');
   $voiceMuted=($c['voiceover_muted']??false)===true;
   if($kind==='photo'){$duration=(int)$c['photo_duration_ms']/1000;$meta=['video'=>true,'audio'=>false,'duration'=>$duration];}
   else {
    $meta=$this->probe($input,$tempDir.'/probe-'.$i.'.log');
    if($kind==='video'&&!$meta['video'])throw new RuntimeException('This video clip does not contain a playable video track.');
    if($kind==='audio'&&!$meta['audio'])throw new RuntimeException('This audio clip does not contain a playable audio track.');
    $start=(int)$c['trim_start_ms']/1000;
    $end=(int)$c['trim_end_ms']/1000;
    if(!$end)$end=min((float)$meta['duration'],120+$start);
    $duration=$end-$start;
   }
   if($duration<0.1||$duration>120)throw new RuntimeException('Clip duration is unknown, invalid or longer than 120 seconds.');
   $total+=$duration;if($total>1200)throw new RuntimeException('Montage exceeds the 20-minute render limit.');
   $dest=$tempDir.'/segment-'.sprintf('%03d',$i).'.mp4';
   $vf="scale={$size['width']}:{$size['height']}:force_original_aspect_ratio=decrease,pad={$size['width']}:{$size['height']}:(ow-iw)/2:(oh-ih)/2:color=0x141e32,setsar=1,fps=30,format=yuv420p";
   if($kind==='photo'){
    $argv=[$this->ffmpeg,'-hide_banner','-nostdin','-v','error','-y','-loop','1','-framerate','30','-i',$input,'-f','lavfi','-i','anullsrc=channel_layout=stereo:sample_rate=48000'];
    $videoInput='0:v:0';$audioInput='1:a:0';$nextInput=2;
   }elseif($kind==='audio'){
    $argv=[$this->ffmpeg,'-hide_banner','-nostdin','-v','error','-y','-f','lavfi','-i',"color=c=0x141e32:s={$size['width']}x{$size['height']}:r=30",'-ss',sprintf('%.3f',$start),'-i',$input];
    $videoInput='0:v:0';$audioInput='1:a:0';$nextInput=2;
    if($audioMode==='muted'){$argv=array_merge($argv,['-f','lavfi','-i','anullsrc=channel_layout=stereo:sample_rate=48000']);$audioInput='2:a:0';$nextInput=3;}
   }else{
    $argv=[$this->ffmpeg,'-hide_banner','-nostdin','-v','error','-y','-ss',sprintf('%.3f',$start),'-i',$input];
    $videoInput='0:v:0';$audioInput='0:a:0';$nextInput=1;
    if(!$meta['audio']||$audioMode!=='normal'){
     $argv=array_merge($argv,['-f','lavfi','-i','anullsrc=channel_layout=stereo:sample_rate=48000']);
     $audioInput='1:a:0';$nextInput=2;
    }
   }
   $detached=$kind==='video'&&$audioMode==='detached'&&!empty($meta['audio']);
   $voiceInput=null;
   if($voiceoverId!==''&&!$voiceMuted){
    if(!ClaimMedia::validId($voiceoverId))throw new RuntimeException('Invalid voiceover ID.');
    $voiceFile=LUCKY_ROOT.'/storage/claim-media/'.$voiceoverId.'.bin';
    if(!is_file($voiceFile))throw new RuntimeException('Selected clip voiceover is missing.');
    $voiceMeta=$this->probe($voiceFile,$tempDir.'/voice-probe-'.$i.'.log');
    if(!$voiceMeta['audio'])throw new RuntimeException('Selected recording has no audio.');
    $voiceInput=$nextInput++;
    $argv=array_merge($argv,['-i',$voiceFile]);
   }
   $audioMap=$audioInput;$filters=[];$mix=[];
   if($detached||$voiceInput!==null){
    $filters[]="[{$audioInput}]aformat=channel_layouts=stereo:sample_rates=48000[base]";
    $mix[]='[base]';
    if($detached){
     $offset=(int)($c['detached_offset_ms']??0);
     $filters[]="[0:a:0]aformat=channel_layouts=stereo:sample_rates=48000,adelay={$offset}:all=1[detached]";
     $mix[]='[detached]';
    }
    if($voiceInput!==null){
     $offset=(int)($c['voiceover_offset_ms']??0);
     $filters[]="[{$voiceInput}:a:0]aformat=channel_layouts=stereo:sample_rates=48000,adelay={$offset}:all=1[voice]";
     $mix[]='[voice]';
    }
    $n=count($mix);$dur=number_format($duration,3,'.','');
    $filters[]=implode('',$mix)."amix=inputs={$n}:duration=first:dropout_transition=0,atrim=duration={$dur},asetpts=N/SR/TB[mixed]";
    $audioMap='[mixed]';
   }
   if(($c['transition']??'fade')==='fade'&&$duration>=0.6){
    $fadeAt=number_format(max(0,$duration-0.18),3,'.','');
    $vf.=',fade=t=in:st=0:d=0.18,fade=t=out:st='.$fadeAt.':d=0.18';
   }
   if($filters)$argv=array_merge($argv,['-filter_complex',implode(';',$filters)]);
   $argv=array_merge($argv,['-t',sprintf('%.3f',$duration),'-map',$videoInput,'-map',$audioMap,'-vf',$vf,'-c:v','libx264','-preset','veryfast','-crf',(string)$size['crf'],'-pix_fmt','yuv420p','-r','30','-c:a','aac','-ac','2','-ar','48000','-b:a','128k','-shortest','-movflags','+faststart',$dest]);
   $this->runProcess($argv,$tempDir.'/segment-'.$i.'.log',180);
   if(!is_file($dest)||filesize($dest)===0)throw new RuntimeException('Video segment was not created.');
   $segments[]=$dest;
  }
  $concat=$tempDir.'/list.txt';$list='';foreach($segments as $seg)$list.="file '".str_replace("'","'\\''",$seg)."'\n";file_put_contents($concat,$list);
  $combined=$tempDir.'/combined.mp4';
  $this->runProcess([$this->ffmpeg,'-hide_banner','-nostdin','-v','error','-y','-f','concat','-safe','0','-i',$concat,'-c','copy','-movflags','+faststart',$combined],$tempDir.'/concat.log',180);
  if($soundtrack!==null&&$musicVolume>0){
   if(!is_file($soundtrack))throw new RuntimeException('Selected soundtrack is missing.');
   $audio=$this->probe($soundtrack,$tempDir.'/music-probe.log');if(!$audio['audio'])throw new RuntimeException('Selected music file has no audio stream.');
   $vol=number_format($musicVolume,2,'.','');
   // Dynamic sidechain ducking under speech; retains original recorded reactions.
   $filter="[1:a]volume={$vol}[music];[music][0:a]sidechaincompress=threshold=0.025:ratio=8:attack=30:release=400[duck];[0:a][duck]amix=inputs=2:duration=first:dropout_transition=0,alimiter=limit=0.9[final]";
   $this->runProcess([$this->ffmpeg,'-hide_banner','-nostdin','-v','error','-y','-i',$combined,'-stream_loop','-1','-i',$soundtrack,'-filter_complex',$filter,'-map','0:v:0','-map','[final]','-c:v','copy','-c:a','aac','-b:a','160k','-movflags','+faststart','-shortest',$output],$tempDir.'/music.log',360);
  }else{
   if(!rename($combined,$output))throw new RuntimeException('Cannot finalize video file.');
  }
  if(!is_file($output)||filesize($output)<1024)throw new RuntimeException('Exported video is empty.');
  return ['bytes'=>filesize($output),'durationMs'=>(int)round($total*1000),'segments'=>count($segments)];
 }
}
