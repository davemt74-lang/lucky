<?php
declare(strict_types=1);
$root=sys_get_temp_dir().'/lucky-audio-test-'.bin2hex(random_bytes(4));
define('LUCKY_ROOT',$root);
require __DIR__.'/../lib/VideoRender.php';
@mkdir($root.'/storage/claim-media',0700,true);
$video=str_repeat('a',32);$voice=str_repeat('b',32);
function runCmd(array $cmd):void{
 $p=proc_open($cmd,[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
 if(!is_resource($p))throw new RuntimeException('Cannot run FFmpeg.');
 stream_get_contents($pipes[1]);fclose($pipes[1]);
 $err=stream_get_contents($pipes[2]);fclose($pipes[2]);
 if(proc_close($p)!==0)throw new RuntimeException($err);
}
function pass(bool $b,string $message):void{if(!$b)throw new RuntimeException($message);echo "PASS $message\n";}
try{
 runCmd(['ffmpeg','-hide_banner','-v','error','-y','-f','lavfi','-i','color=c=blue:s=320x180:r=15','-f','lavfi','-i','sine=frequency=440:sample_rate=48000','-t','1.1','-c:v','libx264','-c:a','aac','-f','mp4',$root.'/storage/claim-media/'.$video.'.bin']);
 runCmd(['ffmpeg','-hide_banner','-v','error','-y','-f','lavfi','-i','sine=frequency=900:sample_rate=48000','-t','1.1','-c:a','libopus','-f','webm',$root.'/storage/claim-media/'.$voice.'.bin']);
 $webmFile=$root.'/storage/claim-media/'.$voice.'.bin';
 $webmMime=(new finfo(FILEINFO_MIME_TYPE))->file($webmFile);
 pass(ClaimMedia::captureKind($webmMime,(int)filesize($webmFile),null,'audio',$webmFile)==='audio',
  'Chrome-style audio-only WebM accepted as microphone audio');
 $disguised=$root.'/video.webm';
 runCmd(['ffmpeg','-hide_banner','-v','error','-y','-f','lavfi','-i','color=c=red:s=160x90:r=15','-f','lavfi','-i','sine=frequency=800','-t','0.6','-c:v','libvpx','-c:a','libopus','-f','webm',$disguised]);
 $rejected=false;
 try{ClaimMedia::captureKind('video/webm',(int)filesize($disguised),null,'audio',$disguised);}
 catch(DomainException $e){$rejected=true;}
 pass($rejected,'WebM containing a video track cannot impersonate audio-only microphone recording');
 $engine=new VideoRender();
 $base=['id'=>$video,'instanceId'=>'clip-a','kind'=>'video','duration_ms'=>1100,'trim_start_ms'=>0,'trim_end_ms'=>1000,'photo_duration_ms'=>4000,'transition'=>'cut','audio_mode'=>'normal'];
 $normal=$root.'/normal.mp4';$silent=$root.'/muted.mp4';$mixed=$root.'/mixed.mp4';
 $engine->render([$base],$normal,$root.'/normal-work');
 $engine->render([array_replace($base,['audio_mode'=>'muted'])],$silent,$root.'/muted-work');
 $engine->render([array_replace($base,['audio_mode'=>'detached','detached_offset_ms'=>100,'voiceover_id'=>$voice,'voiceover_offset_ms'=>150,'voiceover_muted'=>false])],$mixed,$root.'/mixed-work');
 foreach([$normal,$silent,$mixed] as $path)pass(is_file($path)&&filesize($path)>1000,'MP4 video + audio export created '.basename($path));
 $volume=static function(string $path):float {
  $out=[];$code=0;
  exec('ffmpeg -hide_banner -i '.escapeshellarg($path).' -vn -af volumedetect -f null - 2>&1',$out,$code);
  if($code!==0)throw new RuntimeException('Failed to inspect audio output.');
  $text=implode("\n",$out);
  if(!preg_match('/mean_volume:\s*(-?inf|-?[0-9.]+) dB/',$text,$m))throw new RuntimeException('No audio volume in rendered video.');
  return $m[1]==='-inf'?-999:(float)$m[1];
 };
 $a=$volume($normal);$b=$volume($silent);$c=$volume($mixed);
 pass($a>-70,'normal video preserves its sound');
 pass($b < $a-25,'Mute audio silences original recording in rendered MP4');
 pass($c>-70,'detached audio and microphone are audible in exported MP4');
 echo "AUDIO RENDER: normal=$a dB muted=$b dB mixed=$c dB\n";
}finally{
 $walk=function(string $folder)use(&$walk):void{
  foreach(glob($folder.'/*')?:[] as $file){if(is_dir($file)){$walk($file);@rmdir($file);}else @unlink($file);}
 };
 if(is_dir($root)){$walk($root);@rmdir($root);}
}
