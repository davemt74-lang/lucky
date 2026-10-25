<?php
declare(strict_types=1);
define('LUCKY_TESTING',true);
define('LUCKY_ROOT',sys_get_temp_dir().'/lucky-media-ai-ffmpeg-'.bin2hex(random_bytes(6)));
require_once __DIR__.'/../lib/MediaIntelligence.php';

$id=str_repeat('c',32);$audioId=str_repeat('d',32);
if(!mkdir(LUCKY_ROOT,0700))throw new RuntimeException('Cannot create local FFmpeg test fixture.');
try {
 $worker=new VideoRender();
 $video=LUCKY_ROOT.'/demo.mp4';$audio=LUCKY_ROOT.'/demo.wav';
 $worker->runProcess(['ffmpeg','-hide_banner','-nostdin','-v','error','-y',
  '-f','lavfi','-i','color=c=black:s=160x90:r=15:d=1',
  '-f','lavfi','-i','color=c=white:s=160x90:r=15:d=1',
  '-filter_complex','[0:v][1:v]concat=n=2:v=1:a=0[out]',
  '-map','[out]','-c:v','libx264','-pix_fmt','yuv420p',$video],LUCKY_ROOT.'/video.log',20);
 $worker->runProcess(['ffmpeg','-hide_banner','-nostdin','-v','error','-y',
  '-f','lavfi','-i','anullsrc=r=16000:cl=mono','-t','2',
  '-c:a','pcm_s16le',$audio],LUCKY_ROOT.'/audio.log',20);
 $v=MediaIntelligence::analyze($id,'video',$video,2000);
 if(count($v['sceneCutsMs'])<1||abs($v['sceneCutsMs'][0]-1000)>250)throw new RuntimeException('Scene cut missing from black/white fixture.');
 echo "PASS Local FFmpeg scene change at 1 second\n";
 $a=MediaIntelligence::analyze($audioId,'audio',$audio,2000);
 if(!$a['silenceRanges']||$a['silenceRanges'][0]['endMs']<1500)throw new RuntimeException('Silent recording was not detected.');
 echo "PASS Local FFmpeg detects silence in an audio-only source\n";
 if(MediaIntelligence::cached($id,$video)!==$v)throw new RuntimeException('Analysis cache was not recovered.');
 echo "PASS Cached cues are reused without processing\n";
 $original=MediaIntelligence::cached($id,$video);file_put_contents($video,'invalid footage');
 if(MediaIntelligence::cached($id,$video)!==null||$original===null)throw new RuntimeException('Replaced source used stale analysis cache.');
 echo "PASS Replaced video invalidates original cache\n";
 echo "V5Q.29B FFMPEG MEDIA INTELLIGENCE PASSED\n";
}finally{
 foreach(['demo.mp4','demo.wav','video.log','audio.log'] as $name)@unlink(LUCKY_ROOT.'/'.$name);
 $dir=LUCKY_ROOT.'/storage/media-analysis';
 if(is_dir($dir)){foreach(scandir($dir) as $item){if($item!=='.'&&$item!=='..')@unlink($dir.'/'.$item);}@rmdir($dir);}
 @rmdir(LUCKY_ROOT.'/storage');@rmdir(LUCKY_ROOT);
}
