<?php
declare(strict_types=1);
require_once __DIR__.'/../lib/GamePluginCapabilities.php';
require_once __DIR__.'/../lib/GamePackageInstaller.php';
function check24(bool $ok,string $label):void {if(!$ok)throw new RuntimeException($label);}
function refuse24(callable $operation,string $label,int $code=422):void {
 try{$operation();}catch(DomainException $e){check24($e->getCode()===$code,$label.': '.$e->getMessage());return;}
 throw new RuntimeException('Expected rejection: '.$label);
}
$all=GamePluginCapabilities::SUPPORTED;
check24(count($all)===19&&count(array_unique($all))===13,'Explicit finite SDK permissions');
check24(GamePluginCapabilities::validateDeclarations([])===[],'Legacy game permissions default denied');
check24(GamePluginCapabilities::validateDeclarations(['storage.read','scores.write'])===['storage.read','scores.write'],'Valid capability set');
refuse24(fn()=>GamePluginCapabilities::validateDeclarations(['database.query']),'SQL never a granted capability');
refuse24(fn()=>GamePluginCapabilities::validateDeclarations(['scores.write','scores.write']),'Duplicate permissions rejected');
refuse24(fn()=>GamePluginCapabilities::validateDeclarations(['network.fetch']),'No network plugin permission');
refuse24(fn()=>GamePluginCapabilities::validateDeclarations('storage.read'),'Untrusted scalar permissions');
check24(!GamePluginCapabilities::changesState('scores.list')&&GamePluginCapabilities::changesState('scores.add'),'Read vs write recognized');
refuse24(fn()=>GamePluginCapabilities::validateArgs('db.query',[]),'Unregistered SDK operation',400);
refuse24(fn()=>GamePluginCapabilities::validateArgs('storage.set',['value'=>str_repeat('x',9000)]),'Oversized SDK payload',413);
$base=['schema'=>'lucky.game-package.v1','id'=>'ci-sdk','version'=>'1.0.0',
 'title'=>'SDK Test','publisher'=>'Test','host'=>'host.html','phone'=>null,
 'files'=>['host.html'=>hash('sha256','<html></html>')]];
$m=GamePackageInstaller::validateManifest($base);
check24($m['capabilities']===[]&&$m['dataVersion']===1,'Old manifests gain no privileges');
$m=GamePackageInstaller::validateManifest([...$base,'dataVersion'=>3,'capabilities'=>['scores.write','storage.read']]);
check24($m['dataVersion']===3&&count($m['capabilities'])===2,'Versioned capability declarations survive manifest normalization');
refuse24(fn()=>GamePackageInstaller::validateManifest([...$base,'dataVersion'=>-2]),'Invalid negative data version');
refuse24(fn()=>GamePackageInstaller::validateManifest([...$base,'capabilities'=>['admin.delete']]),'Arbitrary plugins cannot declare backend actions');
echo "V5Q.24: finite declared capabilities, defaults, schema versions and permissions PASSED\n";
