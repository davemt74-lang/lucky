<?php
declare(strict_types=1);
require_once __DIR__.'/../lib/MulticameraEncoder.php';
function renderAssert(bool $ok,string $name):void{if(!$ok)throw new RuntimeException('FAIL '.$name);echo 'PASS '.$name."\n";}
$root=sys_get_temp_dir().'/lucky-e4b-'.bin2hex(random_bytes(7));
if(!mkdir($root,0700,true))throw new RuntimeException('Cannot create FFmpeg fixture folder.');
$a=str_repeat('a',32);$b=str_repeat('b',32);$audio=str_repeat('c',32);
$ffmpeg=getenv('LUCKY_FFMPEG_BIN')?:'ffmpeg';$ffprobe=getenv('LUCKY_FFPROBE_BIN')?:'ffprobe';
$runner=new VideoRender($ffmpeg,$ffprobe);
try{
 foreach([[$a,'blue','440'],[$b,'red','880']] as [$id,$color,$frequency]){
  $runner->runProcess([$ffmpeg,'-hide_banner','-nostdin','-v','error','-y','-f','lavfi','-i',"color=c={$color}:s=160x90:r=30",
   '-f','lavfi','-i',"sine=frequency={$frequency}:sample_rate=48000",'-t','1.0','-map','0:v','-map','1:a',
   '-c:v','libx264','-pix_fmt','yuv420p','-c:a','aac',$root.'/'.$id.'.mp4'],$root.'/fixture-'.$id.'.log',30);
 }
 $runner->runProcess([$ffmpeg,'-hide_banner','-nostdin','-v','error','-y','-f','lavfi','-i','sine=frequency=240:sample_rate=48000',
  '-t','1','-c:a','aac',$root.'/'.$audio.'.m4a'],$root.'/fixture-master.log',30);
 $media=[
  ['id'=>$a,'kind'=>'video','duration_ms'=>1000,'initial_global_offset_ms'=>0],
  ['id'=>$b,'kind'=>'video','duration_ms'=>1000,'initial_global_offset_ms'=>0],
  ['id'=>$audio,'kind'=>'audio','duration_ms'=>1000,'initial_global_offset_ms'=>200],
 ];
 $timeline=['offsets'=>[],'switches'=>[['atMs'=>500,'assetId'=>$b]]];
 $plan=RecordingExportPlan::build($media,$timeline,$audio);
 $paths=[$a=>$root.'/'.$a.'.mp4',$b=>$root.'/'.$b.'.mp4',$audio=>$root.'/'.$audio.'.m4a'];
 $result=(new MulticameraEncoder($ffmpeg,$ffprobe))->render($plan,$paths,$root.'/final.mp4',$root.'/work');
 renderAssert($result['segments']===2&&$result['durationMs']===1000,'Two camera angles render at their shared one-second timeline');
 renderAssert($result['bytes']>1024&&is_file($root.'/final.mp4'),'Encoded MP4 finalized from immutable source clips');
 $info=$runner->runProcess([$ffprobe,'-v','error','-show_entries','format=duration:stream=codec_type','-of','json',$root.'/final.mp4'],$root.'/verify.log',30);
 $decoded=json_decode($info,true,512,JSON_THROW_ON_ERROR);
 $types=array_column($decoded['streams']??[],'codec_type');
 renderAssert(in_array('video',$types,true)&&in_array('audio',$types,true),'Export contains playable video and selected master audio');
 renderAssert(abs((float)$decoded['format']['duration']-1.0)<.25,'FFmpeg output duration is aligned');
 echo "V5Q.29E4B MULTICAMERA MP4 ENCODER CERTIFIED\n";
}catch(Throwable $e){
 foreach(['audio-master.log','concat.log','segment-0.log','segment-1.log'] as $log){
  $path=$root.'/work/'.$log;
  if(is_file($path))fwrite(STDERR,$log.":\n".(string)file_get_contents($path)."\n");
 }
 throw $e;
}finally{
 foreach(glob($root.'/work/*')?:[] as $file)if(is_file($file))@unlink($file);
 if(is_dir($root.'/work'))@rmdir($root.'/work');
 foreach(glob($root.'/*')?:[] as $file)if(is_file($file))@unlink($file);
 @rmdir($root);
}
