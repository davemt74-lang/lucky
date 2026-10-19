<?php
declare(strict_types=1);
require __DIR__.'/../lib/MontageAutoDraft.php';
function assertDraft($yes,string $label):void{if(!$yes)throw new RuntimeException($label);echo "PASS $label\n";}
$id1=str_repeat('1',32);$id2=str_repeat('2',32);
$source=[
 ['id'=>$id1,'claim_id'=>'claim-a','kind'=>'photo','title'=>'Reward photo','trim_start_ms'=>0,'trim_end_ms'=>0,'photo_duration_ms'=>3000,'angle_index'=>1],
 ['id'=>$id2,'claim_id'=>'claim-a','kind'=>'video','title'=>'Celebration','duration_ms'=>8000,'trim_start_ms'=>0,'trim_end_ms'=>0,'photo_duration_ms'=>4000,'angle_index'=>2]
];
$d=MontageAutoDraft::decisions($source);
assertDraft(count($d)===2,'both reward proof assets kept');
assertDraft($d[0]['id']===$id2,'video prioritized within the prize claim');
assertDraft($d[0]['transition']==='fade','auto transitions set');
assertDraft($d[1]['photoDurationMs']===3000,'photo timing retained');
assertDraft(MontageAutoDraft::decisions([])===[],'no media produces no draft');
assertDraft(count(array_unique(array_column($d,'instanceId')))===2,'stable per-media instance ids');
echo 'Auto-draft pure checks passed.\n';
// Long captures and crowded prize nights still produce a valid editable draft.
$long=$source[1];$long['duration_ms']=300000;
$trimmed=MontageAutoDraft::decisions([$long]);
assertDraft($trimmed[0]['trimEndMs']===120000,'long source non-destructively capped to two minutes');
$large=[];
for($i=1;$i<=95;$i++){
 $item=$source[$i%2];$item['id']=str_pad(dechex($i),32,'0',STR_PAD_LEFT);
 $item['claim_id']='claim-a';$item['duration_ms']=$item['kind']==='video'?3000:0;
 $large[]=$item;
}
$limited=MontageAutoDraft::decisions($large);
assertDraft(count($limited)===80,'long sessions capped to 80 clips');
assertDraft($limited[0]['id']===$large[0]['id'],'video-first selection precedes clip limit');
$budget=[];
for($i=1;$i<=16;$i++){
 $item=$source[1];$item['id']=str_pad(dechex($i),32,'0',STR_PAD_LEFT);
 $item['duration_ms']=120000;$item['claim_id']='game-night';$budget[]=$item;
}
$within=MontageAutoDraft::decisions($budget);
assertDraft(count($within)===10,'20-minute budget still creates highlight draft');
assertDraft(MontagePlan::validate(array_map(static fn($r)=>['id'=>$r['id'],'instanceId'=>$r['instanceId'],'kind'=>'video','durationMs'=>120000,'trimStartMs'=>$r['trimStartMs'],'trimEndMs'=>$r['trimEndMs']],$within))['estimatedMs']<=MontagePlan::MAX_TOTAL_MS,'draft fits renderer budget');
