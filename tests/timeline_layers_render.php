<?php
declare(strict_types=1);
$root=sys_get_temp_dir().'/lucky-multitrack-'.bin2hex(random_bytes(4));
define('LUCKY_ROOT',$root);
require __DIR__.'/../lib/VideoRender.php';
require __DIR__.'/../lib/MontageLayers.php';
@mkdir($root.'/storage/claim-media',0700,true);
function runAudio(array $args):void{$p=proc_open($args,[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);
 if(!is_resource($p))throw new RuntimeException('FFmpeg not available.');stream_get_contents($pipes[1]);fclose($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[2]);if(proc_close($p)!==0)throw new RuntimeException($err);
}
function checkAudio(bool $v,string $label):void{if(!$v)throw new RuntimeException($label);echo "PASS $label\n";}
try{
 $id=str_repeat('a',32);$song=str_repeat('d',32);
 runAudio(['ffmpeg','-hide_banner','-v','error','-y','-f','lavfi','-i','color=c=blue:s=320x180:r=15','-f','lavfi','-i','sine=frequency=220:sample_rate=48000','-t','2.1','-c:v','libx264','-c:a','aac','-f','mp4',$root.'/storage/claim-media/'.$id.'.bin']);
 $music=$root.'/music.wav';
 runAudio(['ffmpeg','-hide_banner','-v','error','-y','-f','lavfi','-i','sine=frequency=900:sample_rate=48000','-t','2.1',$music]);
 $clips=[['id'=>$id,'instanceId'=>'clip-1','kind'=>'video','duration_ms'=>2100,'trim_start_ms'=>0,'trim_end_ms'=>2000,'photo_duration_ms'=>4000,'transition'=>'cut','audio_mode'=>'normal']];
 $layers=MontageLayers::normalize(['titles'=>[['id'=>'label-1','text'=>'WINNER','position'=>'center','size'=>'large','startMs'=>400,'durationMs'=>1000]],'background'=>['trackId'=>$song,'startMs'=>200,'endMs'=>1700,'offsetMs'=>0,'volume'=>38]],2000,[$song=>true]);
 $segments=MontageLayers::apply($clips,$layers);
 $output=$root.'/movie.mp4';$renderer=new VideoRender();
 $renderer->render($segments,$output,$root.'/work','standard',$music,.38);
 checkAudio(is_file($output)&&filesize($output)>1024,'MP4 created with T1 title and B1 soundtrack');
 checkAudio(file_get_contents($root.'/work/overlay-1000.txt')==='WINNER','title text file generated for time-bound overlay');
 $info=[];$ret=0;exec('ffprobe -v error -show_entries stream=codec_type -of csv=p=0 '.escapeshellarg($output).' 2>&1',$info,$ret);
 checkAudio($ret===0&&in_array('video',$info,true)&&in_array('audio',$info,true),'export includes playable audio and video streams');
}finally{
 $remove=function(string $folder)use(&$remove):void{foreach(glob($folder.'/*')?:[] as $p){if(is_dir($p)){$remove($p);@rmdir($p);}else @unlink($p);}};
 if(is_dir($root)){$remove($root);@rmdir($root);}
}
