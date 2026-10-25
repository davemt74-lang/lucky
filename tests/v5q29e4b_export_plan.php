<?php
declare(strict_types=1);
require_once __DIR__.'/../lib/RecordingExportPlan.php';
function exportAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);echo "PASS {$message}\n";}
function exportReject(callable $fn,int $code,string $name):void {
 try{$fn();throw new RuntimeException('Expected rejection: '.$name);}
 catch(DomainException $e){exportAssert($e->getCode()===$code,$name);}
}
$a=str_repeat('a',32);$b=str_repeat('b',32);$voice=str_repeat('c',32);
$media=[
 ['id'=>$a,'kind'=>'video','duration_ms'=>3000,'initial_global_offset_ms'=>0],
 ['id'=>$b,'kind'=>'video','duration_ms'=>3000,'initial_global_offset_ms'=>500],
 ['id'=>$voice,'kind'=>'audio','duration_ms'=>4000,'initial_global_offset_ms'=>0],
];
$timeline=['offsets'=>[['assetId'=>$b,'offsetMs'=>600]],'switches'=>[['atMs'=>1000,'assetId'=>$b],['atMs'=>2000,'assetId'=>$a]]];
$plan=RecordingExportPlan::build($media,$timeline);
exportAssert($plan['durationMs']===3600&&$plan['audioMode']==='follow','Session duration follows aligned camera recordings');
exportAssert(array_column($plan['segments'],'assetId')===[$a,$b,$a,$b],'Angle cuts materialize into a continuous sequence with fallback');
exportAssert(array_column($plan['segments'],'startMs')===[0,1000,2000,3000],'Segment boundaries share the session clock');
exportAssert(array_column($plan['segments'],'sourceStartMs')===[0,400,2000,2400],'Source offsets are applied exactly once');
$master=RecordingExportPlan::build($media,$timeline,$voice);
exportAssert($master['audioMode']==='master'&&$master['audioAssetId']===$voice,'Audio-only source can be selected as master');
exportAssert(RecordingExportPlan::build($media,$timeline,'')['audioMode']==='silent','Silent output is explicit');
exportReject(fn()=>RecordingExportPlan::build($media,$timeline,str_repeat('d',32)),422,'Foreign master audio is rejected');
exportReject(fn()=>RecordingExportPlan::build($media,['offsets'=>[],'switches'=>[['atMs'=>1000,'assetId'=>$voice]]]),422,'Audio-only source cannot be used as video angle');
exportReject(fn()=>RecordingExportPlan::build([['id'=>$a,'kind'=>'video','duration_ms'=>1200001,'initial_global_offset_ms'=>0]],['offsets'=>[],'switches'=>[]]),422,'Twenty-minute output limit applies');
$photo=RecordingExportPlan::build([['id'=>$a,'kind'=>'photo','duration_ms'=>0,'initial_global_offset_ms'=>0]],['offsets'=>[],'switches'=>[]]);
exportAssert($photo['durationMs']===4000&&$photo['segments'][0]['kind']==='photo','Photo duration defaults to four seconds');
$gap=RecordingExportPlan::build([
 ['id'=>$a,'kind'=>'video','duration_ms'=>1000,'initial_global_offset_ms'=>0],
 ['id'=>$b,'kind'=>'video','duration_ms'=>1000,'initial_global_offset_ms'=>2000],
],['offsets'=>[],'switches'=>[]]);
exportAssert(array_column($gap['segments'],'kind')===['video','gap','video']&&$gap['durationMs']===3000,'Uncovered moments are explicitly rendered as timeline gaps');
echo "V5Q.29E4B MULTICAMERA EXPORT PLAN CERTIFIED\n";
