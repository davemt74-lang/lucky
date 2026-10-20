<?php
declare(strict_types=1);
require_once __DIR__.'/../lib/MontageTemplate.php';
function testTemplate(bool $ok,string $label):void{if(!$ok)throw new RuntimeException('FAIL '.$label);echo "PASS $label\n";}
function rejectTemplate(callable $fn,string $name):void{$bad=false;try{$fn();}catch(DomainException $e){$bad=true;}testTemplate($bad,$name);}
$song=str_repeat('d',32);$foreign=str_repeat('f',32);
$template=['looks'=>['warm','mono','cinematic'],'titles'=>[
 ['text'=>'WINNER','position'=>'top','size'=>'large','startPct'=>900,'durationPct'=>2500]
 ],'background'=>['trackId'=>$song,'startPct'=>500,'endPct'=>9200,'offsetMs'=>1000,'volume'=>22]];
$safe=MontageTemplate::validate($template,[$song=>true]);
testTemplate(count($safe['looks'])===3,'multiple clip looks retained across game templates');
testTemplate($safe['titles'][0]['durationPct']===2500,'title timing stored independent of absolute clip duration');
testTemplate($safe['background']['endPct']===9200,'B1 music end stored as reusable relative timeline position');
testTemplate(!str_contains(json_encode($safe), 'claimId')&&!str_contains(json_encode($safe),'sourceId'),'reusable template contains no claim IDs or original footage');
rejectTemplate(fn()=>MontageTemplate::validate(['looks'=>['super-secret-filter']],[]),'unknown video effect rejected');
rejectTemplate(fn()=>MontageTemplate::validate(['titles'=>[['text'=>'Hello','startPct'=>9500,'durationPct'=>1000]]],[]),'title beyond video end rejected');
rejectTemplate(fn()=>MontageTemplate::validate(['background'=>['trackId'=>$foreign,'startPct'=>0,'endPct'=>10000]],[$song=>true]),'foreign/unrenderable audio track rejected');
rejectTemplate(fn()=>MontageTemplate::validate(['titles'=>[['text'=>"a\nb",'startPct'=>0,'durationPct'=>1000]]],[]),'control characters rejected');
