<?php
declare(strict_types=1);
define('LUCKY_TESTING',true);
define('LUCKY_ROOT',sys_get_temp_dir().'/lucky-media-ai-'.bin2hex(random_bytes(6)));
require_once __DIR__.'/../lib/MediaIntelligence.php';

function verifyCue(bool $ok,string $label):void {
 if(!$ok)throw new RuntimeException('FAIL '.$label);
 echo "PASS {$label}\n";
}
$id=str_repeat('a',32);$second=str_repeat('b',32);
try {
 if(!mkdir(LUCKY_ROOT,0700))throw new RuntimeException('Failed to create isolated test data.');
 $scene='[Parsed_showinfo_1] n: 0 pts: 15360 pts_time: 1.000 pos: 42';
 verifyCue(MediaIntelligence::sceneCuts("$scene\n$scene\n",5000)===[1000],'Scene parser de-duplicates nearby cuts');
 $quiet="[silencedetect] silence_start: 0.200\n[silencedetect] silence_end: 1.800 | silence_duration: 1.600";
 verifyCue(MediaIntelligence::silences($quiet,5000)===[['startMs'=>200,'endMs'=>1800]],'Silence parser preserves bounded intervals');
 $safe=MediaIntelligence::promptSignals([
  'durationMs'=>5000,'sceneCutsMs'=>[1000,-1,5500,'4000'],'silenceRanges'=>[['startMs'=>200,'endMs'=>1800],['startMs'=>-1,'endMs'=>22]],
  'privateTranscript'=>'A player said a private phrase','rawMedia'=>'private://clip-file',
 ]);
 verifyCue($safe===['sceneCutsMs'=>[1000],'silenceRanges'=>[['startMs'=>200,'endMs'=>1800]]],'Only approved numeric timings survive the AI boundary');
 $context=MediaIntelligence::timelineCues([
  ['id'=>$id,'instanceId'=>'first-cut','kind'=>'video','title'=>'Secret source title'],
  ['id'=>$second,'instanceId'=>'second-cut','kind'=>'audio'],
  ['id'=>$id,'instanceId'=>'photo-cut','kind'=>'photo'],
 ],static fn(string $requested):?array=>$requested===$id?['durationMs'=>5000,'sceneCutsMs'=>[1000],'silenceRanges'=>[['startMs'=>200,'endMs'=>1800]],'transcript'=>'private text']:null);
 verifyCue(count($context)===1&&$context[0]['instanceId']==='first-cut'&&!str_contains(json_encode($context,JSON_THROW_ON_ERROR),'private'),'Only previously cached cues for timeline clips are shareable');
 try{MediaIntelligence::cachePath('../secret');throw new RuntimeException('Path traversal was accepted');}
 catch(DomainException $e){verifyCue($e->getCode()===422,'Analysis cache path rejects traversal');}
 echo "V5Q.29B LOCAL ANALYSIS BOUNDARY PASSED\n";
}finally{
 $dir=LUCKY_ROOT.'/storage/media-analysis';
 if(is_dir($dir)){foreach(scandir($dir) as $entry){if($entry!=='.'&&$entry!=='..')@unlink($dir.'/'.$entry);}@rmdir($dir);}
 @rmdir(LUCKY_ROOT.'/storage');@rmdir(LUCKY_ROOT);
}
