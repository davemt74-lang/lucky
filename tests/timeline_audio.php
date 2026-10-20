<?php
declare(strict_types=1);
require __DIR__.'/../lib/MontagePlan.php';
function pass(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
function rejects(callable $action,string $label):void{
 try{$action();throw new RuntimeException($label.' was permitted');}catch(DomainException $e){pass(true,$label);}
}
$videoId=str_repeat('a',32);$voiceId=str_repeat('b',32);$foreignId=str_repeat('c',32);
$claim=str_repeat('d',32);$other=str_repeat('e',32);
$source=[
 $videoId=>['id'=>$videoId,'kind'=>'video','claim_id'=>$claim,'duration_ms'=>10000,'trim_start_ms'=>0,'trim_end_ms'=>0,'photo_duration_ms'=>4000,'title'=>'Prize reaction'],
 $voiceId=>['id'=>$voiceId,'kind'=>'audio','claim_id'=>$claim,'duration_ms'=>3000,'trim_start_ms'=>0,'trim_end_ms'=>0,'photo_duration_ms'=>4000,'title'=>'Microphone'],
 $foreignId=>['id'=>$foreignId,'kind'=>'audio','claim_id'=>$other,'duration_ms'=>3000,'trim_start_ms'=>0,'trim_end_ms'=>0,'photo_duration_ms'=>4000,'title'=>'Foreign mic']
];
$make=static fn(array $audio=[]):array=>[['id'=>$videoId,'instanceId'=>'clip-a','title'=>'Original video','trimStartMs'=>1500,'trimEndMs'=>7000,'photoDurationMs'=>4000,'transition'=>'cut']+$audio];
$segments=MontagePlan::materialize($source,$make(['audioMode'=>'detached','detachedOffsetMs'=>600,'voiceoverId'=>$voiceId,'voiceoverOffsetMs'=>1200,'voiceoverMuted'=>false]));
pass(count($segments)===1,'audio edits keep one video timeline segment');
pass($segments[0]['audio_mode']==='detached','detached track persists into render snapshot');
pass($segments[0]['detached_offset_ms']===600,'detached audio offset is retained');
pass($segments[0]['voiceover_id']===$voiceId&&$segments[0]['voiceover_offset_ms']===1200,'recorded voiceover is attached to selected claim and timed');
pass(MontagePlan::validate($segments)['estimatedMs']===5500,'trim boundaries change export duration');
$muted=MontagePlan::materialize($source,$make(['audioMode'=>'muted']));
pass($muted[0]['audio_mode']==='muted','mute choice is retained in MP4 snapshot');
rejects(fn()=>MontagePlan::materialize($source,$make(['audioMode'=>'detached','voiceoverId'=>$foreignId])),'cross-claim audio source is rejected');
rejects(fn()=>MontagePlan::materialize($source,$make(['voiceoverId'=>str_repeat('f',32)])),'unknown voiceover is rejected');
rejects(fn()=>MontagePlan::materialize($source,$make(['audioMode'=>'impossible'])),'unsupported audio mode rejected');
rejects(fn()=>MontagePlan::materialize($source,$make(['detachedOffsetMs'=>-100])),'negative offsets rejected');
rejects(fn()=>MontagePlan::materialize($source,$make(['voiceoverOffsetMs'=>120100])),'offset exceeding two minutes rejected');
