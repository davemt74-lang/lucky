<?php
declare(strict_types=1);
require_once __DIR__.'/../lib/MontagePlan.php';
require_once __DIR__.'/../lib/MontageLayers.php';
function checkLayer(bool $v,string $name):void{if(!$v)throw new RuntimeException('FAIL '.$name);echo "PASS ".$name."\n";}
function failLayer(callable $cb,string $name):void{$failed=false;try{$cb();}catch(DomainException $e){$failed=true;}checkLayer($failed,$name);}
$id=str_repeat('a',32);$song=str_repeat('d',32);$foreign=str_repeat('e',32);
$source=[$id=>['id'=>$id,'kind'=>'video','duration_ms'=>10000,'trim_start_ms'=>0,'trim_end_ms'=>0,'photo_duration_ms'=>4000,'title'=>'Prize']];
$edit=[['id'=>$id,'instanceId'=>'clip-1','trimStartMs'=>0,'trimEndMs'=>10000,'title'=>'Prize']];
$clips=MontagePlan::materialize($source,$edit);$total=MontagePlan::validate($clips)['estimatedMs'];
$layers=MontageLayers::normalize(['titles'=>[
 ['id'=>'title-1','text'=>'Prize awarded','startMs'=>2000,'durationMs'=>4000,'position'=>'top','size'=>'large'],
 ['id'=>'title-2','text'=>'Next spin','startMs'=>6000,'durationMs'=>2500,'position'=>'bottom','size'=>'small']
 ],'background'=>['trackId'=>$song,'startMs'=>1000,'endMs'=>8000,'offsetMs'=>500,'volume'=>22]],$total,[$song=>true]);
checkLayer(count($layers['titles'])===2,'title tracks independently validated');
checkLayer($layers['background']['trackId']===$song&&$layers['background']['volume']===22,'uploaded library soundtrack selected with own volume');
$render=MontageLayers::apply($clips,$layers);
checkLayer(count($render[0]['timeline_titles'])===2,'T1 text materialized into render segment');
checkLayer($render[0]['timeline_titles'][0]['startMs']===2000&&$render[0]['timeline_titles'][0]['endMs']===6000,'title has independent absolute in/out');
checkLayer($render[0]['music_layer']['startMs']===1000&&$render[0]['music_layer']['endMs']===8000,'B1 music timing retained');
checkLayer($render[0]['trim_start_ms']===0&&$render[0]['trim_end_ms']===10000,'V1 claim clip remains unmodified');
failLayer(fn()=>MontageLayers::normalize(['titles'=>[['id'=>'bad','text'=>'hi','startMs'=>9000,'durationMs'=>3000]]],$total,[]),'reject text beyond video end');
failLayer(fn()=>MontageLayers::normalize(['background'=>['trackId'=>$foreign,'startMs'=>0,'endMs'=>5000]],$total,[$song=>true]),'reject unknown or external song');
failLayer(fn()=>MontageLayers::normalize(['titles'=>[['id'=>'bad','text'=>"Hi\nAgain",'startMs'=>0,'durationMs'=>4000]]],$total,[]),'reject unsafe title newlines');
