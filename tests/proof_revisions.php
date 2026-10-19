<?php
declare(strict_types=1);
require __DIR__.'/../lib/ClaimProof.php';
$checks=0;
function yes(bool $cond,string $message):void {global $checks;if(!$cond)throw new RuntimeException($message);++$checks;}
yes(ClaimProof::note('  Corrected camera 2 ') === 'Corrected camera 2','Trims revision notes');
yes(ClaimProof::note('') === '','Notes optional');
try{ClaimProof::note(str_repeat('x',251));throw new RuntimeException('Too-long notes accepted');}catch(DomainException $e){yes($e->getCode()===422,'Oversize notes rejected');}
try{ClaimProof::note("Invalid\x00Note");throw new RuntimeException('Control accepted');}catch(DomainException $e){yes($e->getCode()===422,'Control characters rejected');}
$id=str_repeat('a',32);$r=['id'=>$id,'session_id'=>str_repeat('b',32),'claim_id'=>str_repeat('c',32),'kind'=>'video','mime'=>'video/webm','title'=>'Original','source_label'=>'Side USB','angle_label'=>'Left','angle_index'=>2,'bytes'=>1024,'duration_ms'=>5000,'trim_start_ms'=>500,'trim_end_ms'=>4400,'photo_duration_ms'=>4000,'sort_order'=>3,'created_at'=>'2026-10-19 01:00:00'];
$s=ClaimProof::snapshot([$r]);$data=json_decode($s,true,512,JSON_THROW_ON_ERROR);yes(count($data)===1,'Snapshot has one clip');yes($data[0]['angleLabel']==='Left','Angle identity saved');yes($data[0]['trimStartMs']===500,'Edit boundaries preserved');yes($data[0]['src']==='api/claim-media.php?action=stream&id='.$id,'Private media link only');yes(!isset($data[0]['sessionId']),'Minimal snapshot shape');
try{ClaimProof::snapshot(array_fill(0,ClaimMedia::MAX_CLIPS_PER_SESSION+1,$r));throw new RuntimeException('Too many clips accepted');}catch(DomainException $e){yes($e->getCode()===422,'Clip limit enforced');}
echo "Proof revision model: $checks/$checks passed\n";
