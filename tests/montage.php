<?php
declare(strict_types=1);
require __DIR__.'/../lib/MontagePlan.php';
function ok(bool $condition,string $message):void{if(!$condition)throw new RuntimeException('FAIL: '.$message);echo "PASS $message\n";}
function rejects(callable $cb,string $msg):void{$rejected=false;try{$cb();}catch(DomainException $e){$rejected=true;}ok($rejected,$msg);}
$id1=str_repeat('a',32);$id2=str_repeat('b',32);
$clips=[['id'=>$id1,'instanceId'=>'segment-1','kind'=>'video','duration_ms'=>12000,'trim_start_ms'=>0,'trim_end_ms'=>10000,'claim_id'=>'x','angle_index'=>2],['id'=>$id2,'instanceId'=>'segment-2','kind'=>'photo','photo_duration_ms'=>4000,'claim_id'=>'x','angle_index'=>1]];
ok(MontagePlan::validate($clips)['clipCount']===2,'two real media clips');
ok(MontagePlan::validate($clips)['estimatedMs']===14000,'render duration estimation');
ok(MontagePlan::autoOrder($clips)[0]['id']===$id1,'auto montage prioritizes video');
ok(MontagePlan::profile('high')['width']===1920,'1080p profile');
ok(MontagePlan::musicVolume(18)===0.18,'soundtrack gain');
rejects(fn()=>MontagePlan::profile('4k'),'unsupported encoding rejected');
rejects(fn()=>MontagePlan::validate([$clips[0],$clips[0]]),'duplicate segment rejected');
rejects(fn()=>MontagePlan::musicVolume(150),'audio overflow rejected');
$source=[$id1=>['id'=>$id1,'kind'=>'video','duration_ms'=>12000,'trim_start_ms'=>0,'trim_end_ms'=>0,'photo_duration_ms'=>4000,'title'=>'A']];
$segments=MontagePlan::materialize($source,[['id'=>$id1,'instanceId'=>'segment-1','trimStartMs'=>0,'trimEndMs'=>5000],['id'=>$id1,'instanceId'=>'segment-2','trimStartMs'=>5000,'trimEndMs'=>12000]]);
ok(count($segments)===2,'split segments allowed against same source');
ok($segments[1]['trim_start_ms']===5000,'split boundaries applied');
rejects(fn()=>MontagePlan::materialize($source,[['id'=>$id2,'instanceId'=>'segment-1']]),'cross-session clips rejected');
rejects(fn()=>MontagePlan::materialize($source,[['id'=>$id1,'instanceId'=>'segment-1','trimStartMs'=>0,'trimEndMs'=>13000]]),'trim past full duration rejected');
rejects(fn()=>MontagePlan::materialize($source,[['id'=>$id1,'instanceId'=>'segment-1','trimStartMs'=>5000,'trimEndMs'=>2000]]),'inverted clip rejected');
rejects(fn()=>MontagePlan::materialize($source,[['id'=>$id1,'instanceId'=>'../../file']]),'bad virtual segment ID rejected');
for($i=0;$i<81;$i++)$many[]=['id'=>$id1,'instanceId'=>'part-'.$i,'kind'=>'photo','photo_duration_ms'=>4000];
rejects(fn()=>MontagePlan::validate($many),'clip count bounded');
echo 'Montage plan checks all green.\n';
