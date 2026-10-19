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
