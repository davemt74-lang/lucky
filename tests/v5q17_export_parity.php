<?php
declare(strict_types=1);
$root=sys_get_temp_dir().'/lucky-export-parity-'.bin2hex(random_bytes(4));
define('LUCKY_ROOT',$root);
require __DIR__.'/../lib/VideoRender.php';
require __DIR__.'/../lib/MontageLayers.php';
@mkdir($root.'/storage/claim-media',0700,true);
function command(array $args):string {
 $p=proc_open($args,[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);
 if(!is_resource($p))throw new RuntimeException('FFmpeg unavailable');
 $output=stream_get_contents($pipes[1]);fclose($pipes[1]);
 $error=stream_get_contents($pipes[2]);fclose($pipes[2]);
 if(proc_close($p)!==0)throw new RuntimeException($error);
 return $output;
}
function checkParity(bool $ok,string $message):void {if(!$ok)throw new RuntimeException('FAIL '.$message);echo "PASS ".$message."\n";}
function frame(string $path,float $at):string {
 $rgb=command(['ffmpeg','-hide_banner','-v','error','-ss',sprintf('%.3f',$at),'-i',$path,'-frames:v','1','-vf','scale=320:180,format=rgb24','-f','rawvideo','-']);
 checkParity(strlen($rgb)===320*180*3,'decoded RGB frame at '.sprintf('%.2f',$at).'s');
 return $rgb;
}
function whiteCenter(string $rgb):int{
 $n=0;
 for($y=70;$y<111;$y++)for($x=65;$x<255;$x++){
  $i=($y*320+$x)*3;
  if(ord($rgb[$i])>190&&ord($rgb[$i+1])>190&&ord($rgb[$i+2])>190)$n++;
 }
 return $n;
}
function chroma(string $rgb):float{
 $sum=0;$samples=0;
 for($y=15;$y<45;$y+=3)for($x=15;$x<50;$x+=3){
  $i=($y*320+$x)*3;
  $sum+=abs(ord($rgb[$i])-ord($rgb[$i+2]));$samples++;
 }
 return $sum/max(1,$samples);
}
function musicLevel(string $path,float $at):float{
 $stderr=command(['ffmpeg','-hide_banner','-loglevel','info','-ss',sprintf('%.3f',$at),'-t','0.25','-i',$path,'-vn','-af','bandpass=f=900:width_type=h:width=65,volumedetect','-f','null','-']);
 if(!preg_match('/mean_volume:\s*(-?inf|-?[0-9.]+) dB/',$stderr,$m))throw new RuntimeException('Missing volume measurement');
 return $m[1]==='-inf'?-100:(float)$m[1];
}
try{
 $id=str_repeat('a',32);$song=str_repeat('d',32);
 command(['ffmpeg','-hide_banner','-v','error','-y','-f','lavfi','-i','color=c=blue:s=320x180:r=30','-f','lavfi','-i','sine=frequency=220:sample_rate=48000','-t','1.2','-c:v','libx264','-c:a','aac','-f','mp4',$root.'/storage/claim-media/'.$id.'.bin']);
 $music=$root.'/music.wav';
 command(['ffmpeg','-hide_banner','-v','error','-y','-f','lavfi','-i','sine=frequency=900:sample_rate=48000','-t','2.2',$music]);
 $base=['id'=>$id,'kind'=>'video','duration_ms'=>1200,'trim_start_ms'=>0,'trim_end_ms'=>1000,'photo_duration_ms'=>4000,'transition'=>'cut','audio_mode'=>'normal'];
 $clips=[array_replace($base,['instanceId'=>'clip-one','look_preset'=>'mono','overlay_text'=>'PRIZE CAPTURED','overlay_position'=>'bottom','overlay_size'=>'medium']),
         array_replace($base,['instanceId'=>'clip-two','look_preset'=>'vivid'])];
 $layers=MontageLayers::normalize(['titles'=>[['id'=>'t1','text'=>'WINNER','position'=>'center','size'=>'large','startMs'=>600,'durationMs'=>800]],
  'background'=>['trackId'=>$song,'startMs'=>450,'endMs'=>1450,'offsetMs'=>0,'volume'=>90]],2000,[$song=>true]);
 $segments=MontageLayers::apply($clips,$layers);
 $out=$root.'/final.mp4';$res=(new VideoRender())->render($segments,$out,$root.'/work','standard',$music,.90);
 checkParity($res['segments']===2&&$res['durationMs']===2000,'export matches exact V1 clip order and duration');
 checkParity(file_get_contents($root.'/work/overlay-000.txt')==='PRIZE CAPTURED','V1 overlay is present in the render snapshot');
 checkParity(file_get_contents($root.'/work/overlay-1000.txt')==='WINNER'&&file_get_contents($root.'/work/overlay-1024.txt')==='WINNER','T1 title crosses clip boundary in both rendered segments');
 $before=frame($out,.2);$active=frame($out,.8);$active2=frame($out,1.2);$after=frame($out,1.8);
 checkParity(chroma($before)<20&&chroma($after)>65,'selected clip monochrome filter and next clip vivid colors rendered');
 checkParity(whiteCenter($active)>whiteCenter($before)+10&&whiteCenter($active2)>whiteCenter($after)+10,'T1 title visible only in configured time window across two clips');
 $quiet=musicLevel($out,.10);$playing=musicLevel($out,.80);$finished=musicLevel($out,1.70);
 checkParity($playing>$quiet+6&&$playing>$finished+6,'B1 background song audibly present only in its selected time window');
 echo "LEVELS quiet=$quiet playing=$playing finished=$finished\n";
}finally{
 $walk=function(string $dir)use(&$walk):void{foreach(glob($dir.'/*')?:[] as $file){if(is_dir($file)){$walk($file);@rmdir($file);}else @unlink($file);}};
 if(is_dir($root)){$walk($root);@rmdir($root);}
}
