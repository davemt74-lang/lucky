<?php
require __DIR__.'/../lib/GameEngine.php';
$n=0;
function proofCheck(string $name,callable $fn):void{global $n;$fn();$n++;echo "PASS $name\n";}
$config=GameEngine::defaults();
$config['pools']['tier1']['prizes'][0]['proofAngles']=[['label'=>'Front','seconds'=>6],['label'=>'Reaction','seconds'=>8]];
$clean=GameEngine::validateConfig($config);
proofCheck('per-prize proof settings survive validation',fn()=>assertSame($clean['pools']['tier1']['prizes'][0]['proofAngles'][0]['label'],'Front'));
$session=GameEngine::newSession($clean,str_repeat('a',32));
proofCheck('proof settings survive active session construction',fn()=>assertSame($session['pools']['tier1']['prizes'][0]['proofAngles'][1]['seconds'],8));
$old=GameEngine::defaults();$validated=GameEngine::validateConfig($old);
proofCheck('legacy prizes receive empty optional proof settings',fn()=>assertSame($validated['pools']['tier1']['prizes'][1]['proofAngles'],[]));
foreach([['label'=>'','seconds'=>8],['label'=>'Angle','seconds'=>2],['label'=>'Angle','seconds'=>31],['label'=>'Angle','seconds'=>3.5]] as $invalid){$test=$config;$test['pools']['tier1']['prizes'][0]['proofAngles']=[$invalid];$caught=false;try{GameEngine::validateConfig($test);}catch(DomainException $e){$caught=true;}if(!$caught)throw new Exception('Accepted malformed camera profile');$n++;echo "PASS invalid proof angle blocked\n";}
$test=$config;$test['pools']['tier1']['prizes'][0]['proofAngles']=array_fill(0,5,['label'=>'Front','seconds'=>8]);$caught=false;try{GameEngine::validateConfig($test);}catch(DomainException $e){$caught=true;}if(!$caught)throw new Exception('Accepted too many cameras');$n++;echo "PASS over-four camera profile rejected\n";
function assertSame($a,$b){if($a!==$b)throw new Exception('Mismatch '.var_export($a,true));}
echo "Proof configurations: $n PASS / 0 FAIL\n";
