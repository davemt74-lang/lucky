<?php
declare(strict_types=1);
require_once __DIR__.'/../lib/PackageControllerBridge.php';
function assertH(bool $good,string $name):void {if(!$good)throw new RuntimeException($name);}
function rejectH(callable $cb,string $name):void {
 try{$cb();}catch(DomainException $e){return;}
 throw new RuntimeException('Expected refusal: '.$name);
}
$session=str_repeat('b',32);
$viewer=str_repeat('c',48);
$pair=['id'=>str_repeat('a',32),'host_admin_id'=>17,'status'=>'paired',
 'paired_expires_at'=>gmdate('Y-m-d H:i:s',time()+1800)];
$row=['id'=>$session,'viewer_token'=>$viewer,'game_id'=>'table-trivia','status'=>'active'];
PackageControllerBridge::bound($pair,$row,$viewer,17);
assertH(PackageControllerBridge::action('input',['name'=>'answer','value'=>'C'],false)['payload']['value']==='C','Custom input values allowed');
assertH(PackageControllerBridge::action('end',[],true)['verb']==='end','Explicitly confirmed End allowed');
rejectH(fn()=>PackageControllerBridge::action('end',[],false),'Unconfirmed End rejected');
rejectH(fn()=>PackageControllerBridge::action('start',[],false),'No arbitrary game creation');
rejectH(fn()=>PackageControllerBridge::action('input',['name'=>'../../secret','value'=>1],false),'Invalid command field');
rejectH(fn()=>PackageControllerBridge::action('input',['name'=>'vote','value'=>['admin'=>true]],false),'Nested unsafe payload');
rejectH(fn()=>PackageControllerBridge::action('input',['name'=>'vote','value'=>1,'extra'=>true],false),'Additional payload fields');
rejectH(fn()=>PackageControllerBridge::bound($pair,$row,str_repeat('d',48),17),'Viewer token mismatch');
rejectH(fn()=>PackageControllerBridge::bound($pair,$row,$viewer,18),'Cross-host phone permissions');
rejectH(fn()=>PackageControllerBridge::bound([...$pair,'status'=>'revoked'],$row,$viewer,17),'Revoked controller');
rejectH(fn()=>PackageControllerBridge::bound([...$pair,'paired_expires_at'=>gmdate('Y-m-d H:i:s',time()-5)],$row,$viewer,17),'Expired pairing');
assertH(PackageControllerBridge::key($pair['id'],'n'.str_repeat('x',16))===
 PackageControllerBridge::key($pair['id'],'n'.str_repeat('x',16)),'Duplicate nonce deterministic');
assertH(PackageControllerBridge::key($pair['id'],'n'.str_repeat('x',16))!==
 PackageControllerBridge::key($pair['id'],'n'.str_repeat('y',16)),'Different nonces isolated');
$hasPhone=new PackageGameModule(['id'=>'with-phone','version'=>'1.0.0','title'=>'With Phone','publisher'=>'Test','phone'=>true]);
$noPhone=new PackageGameModule(['id'=>'no-phone','version'=>'1.0.0','title'=>'Without Phone','publisher'=>'Test','phone'=>false]);
assertH(PackageControllerBridge::isPackage($hasPhone),'Game-specific controller module');
assertH(!PackageControllerBridge::isPackage($noPhone),'No-phone games cannot use phone actions');
require_once __DIR__.'/../lib/modules/SpinWheel.php';
assertH(!PackageControllerBridge::isPackage(new SpinWheel()),'Phone bridge does not bypass wheel prize logic');
echo "V5Q.22H: host binding, revocation, stale nonce, optional phones, game isolation and confirmation passed\n";
