<?php
declare(strict_types=1);
require_once __DIR__.'/../lib/MontageClaimTimeline.php';
function verify(bool $ok,string $name):void{if(!$ok)throw new RuntimeException($name);echo "PASS $name\n";}
$a=str_repeat('a',32);$b=str_repeat('b',32);$c=str_repeat('c',32);
$old=str_repeat('1',32);$other=str_repeat('2',32);$fresh=str_repeat('3',32);
$rows=[
 ['id'=>$old,'claim_id'=>$a,'kind'=>'video','title'=>'A original'],
 ['id'=>$other,'claim_id'=>$b,'kind'=>'video','title'=>'B original'],
 ['id'=>$fresh,'claim_id'=>$a,'kind'=>'video','title'=>'A re-record']
];
$edited=[
 ['id'=>$old,'instanceId'=>'segment-one','transition'=>'cut','trimStartMs'=>1000],
 ['id'=>$other,'instanceId'=>'segment-two','transition'=>'fade']
];
$replaced=MontageClaimTimeline::apply($edited,$rows,$rows[2],[$a,$b],$old);
verify(count($replaced)===2,'re-record leaves timeline length unchanged');
verify($replaced[0]['id']===$fresh&&$replaced[1]['id']===$other,'re-record replaces precise claim position without moving another prize');
verify($replaced[0]['instanceId']==='segment-one'&&$replaced[0]['transition']==='cut','re-record retains timeline identity and transition');
verify($replaced[0]['trimStartMs']===0,'re-record resets invalid old video trims');
verify($edited[0]['id']===$old,'old video and manual edits not mutated');
try{MontageClaimTimeline::apply($edited,$rows,$rows[2],[$a,$b],$other);throw new RuntimeException('cross claim replacement allowed');}
catch(DomainException $e){verify($e->getCode()===409,'replacement cannot target unrelated claimed prize');}
$new=MontageClaimTimeline::apply([['id'=>$other,'instanceId'=>'b1'] ],$rows,$rows[2],[$a,$b]);
verify(count($new)===2&&$new[0]['id']===$fresh&&$new[1]['id']===$other,'new earlier-claimed prize inserted ahead of later prize');
$new=MontageClaimTimeline::apply($edited,$rows,$rows[2],[$a,$b]);
verify(count($new)===3&&$new[1]['id']===$fresh,'new angle appends inside correct prize group');
$full=array_fill(0,80,['id'=>$other,'instanceId'=>'x']);
try{MontageClaimTimeline::apply($full,$rows,$rows[2],[$a,$b]);throw new RuntimeException('full timeline allowed');}
catch(DomainException $e){verify($e->getCode()===409,'full timeline rejects upload instead of silently losing clip');}
echo "Claim timeline: 8/8 pass\n";
