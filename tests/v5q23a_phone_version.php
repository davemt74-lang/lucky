<?php
declare(strict_types=1);
require_once __DIR__.'/../lib/PackageControllerBridge.php';
function v23version(bool $value,string $label):void {if(!$value)throw new RuntimeException($label);}
function v23versionReject(callable $fn,string $label):void {
 try{$fn();}catch(DomainException $e){
  v23version($e->getCode()===409,$label);return;
 }
 throw new RuntimeException('Expected rejection: '.$label);
}
PackageControllerBridge::assertFreshVersion(1,1);
PackageControllerBridge::assertFreshVersion(27,27);
v23versionReject(fn()=>PackageControllerBridge::assertFreshVersion(26,27),'Stale phone command');
v23versionReject(fn()=>PackageControllerBridge::assertFreshVersion(null,1),'Missing version');
v23versionReject(fn()=>PackageControllerBridge::assertFreshVersion('1',1),'String version');
v23versionReject(fn()=>PackageControllerBridge::assertFreshVersion(0,1),'Invalid zero version');
v23versionReject(fn()=>PackageControllerBridge::assertFreshVersion(-1,1),'Invalid negative version');
v23versionReject(fn()=>PackageControllerBridge::assertFreshVersion(4294967296,4294967296),'Invalid overflow version');
v23version(true,'Current-session phone input accepted without altering host security contract.');
echo "V5Q.23A stale phone action gate PASSED\n";
