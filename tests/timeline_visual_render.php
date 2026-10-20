<?php
declare(strict_types=1);
$root=sys_get_temp_dir().'/lucky-visual-'.bin2hex(random_bytes(4));
define('LUCKY_ROOT',$root);
require __DIR__.'/../lib/VideoRender.php';
@mkdir($root.'/storage/claim-media',0700,true);
$id=str_repeat('a',32);
function runVisual(array $args):void{
 $p=proc_open($args,[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);
 if(!is_resource($p))throw new RuntimeException('Cannot start FFmpeg.');
 $out=stream_get_contents($pipes[1]);fclose($pipes[1]);
 $error=stream_get_contents($pipes[2]);fclose($pipes[2]);
 if(proc_close($p)!==0)throw new RuntimeException($error.' '.$out);
}
function passVisual(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo "PASS ".$label."\n";}
try{
 runVisual(['ffmpeg','-hide_banner','-v','error','-y','-f','lavfi','-i','testsrc2=s=320x180:r=15','-f','lavfi','-i','sine=frequency=330:sample_rate=48000','-t','1.2','-c:v','libx264','-c:a','aac','-f','mp4',$root.'/storage/claim-media/'.$id.'.bin']);
 $render=new VideoRender();
 $segment=['id'=>$id,'instanceId'=>'one','kind'=>'video','duration_ms'=>1200,'trim_start_ms'=>0,'trim_end_ms'=>1000,'photo_duration_ms'=>4000,'transition'=>'cut','audio_mode'=>'normal','look_preset'=>'vintage',
  'overlay_text'=>"Grand Prize: Winner's!",'overlay_position'=>'bottom','overlay_size'=>'large'];
 $out=$root.'/render.mp4';
 $render->render([$segment],$out,$root.'/work');
 passVisual(is_file($out)&&filesize($out)>1000,'FFmpeg renders captioned video with vintage filter');
 passVisual(file_get_contents($root.'/work/overlay-000.txt')==="Grand Prize: Winner's!",'caption text round-trips without shell interpolation');
 $log=[];$status=0;exec('ffprobe -v error -show_entries stream=codec_type -of csv=p=0 '.escapeshellarg($out).' 2>&1',$log,$status);
 passVisual($status===0&&in_array('video',$log,true)&&in_array('audio',$log,true),'filtered output has playable video and audio');
}finally{
 $remove=function(string $folder)use(&$remove):void{
  foreach(glob($folder.'/*')?:[] as $p){if(is_dir($p)){$remove($p);@rmdir($p);}else @unlink($p);}
 };
 if(is_dir($root)){$remove($root);@rmdir($root);}
}
