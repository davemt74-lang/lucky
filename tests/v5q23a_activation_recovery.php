<?php
declare(strict_types=1);
require_once __DIR__.'/../lib/GamePackageActivation.php';
function v23check(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
function v23reject(callable $f,string $label):void {
 try{$f();}catch(DomainException $e){return;}
 throw new RuntimeException('Expected refusal: '.$label);
}
function v23zip(string $path,string $game,string $version):void {
 $files=['host.html'=>'<!doctype html><h1>Certified fixture</h1>'];
 $manifest=['schema'=>'lucky.game-package.v1','id'=>$game,'version'=>$version,
 'title'=>'Certification Fixture','publisher'=>'Lucky CI','host'=>'host.html','phone'=>null,
 'files'=>['host.html'=>hash('sha256',$files['host.html'])]];
 $zip=new ZipArchive();
 if($zip->open($path,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)throw new RuntimeException('ZIP not available.');
 $zip->addFromString('manifest.json',json_encode($manifest,JSON_THROW_ON_ERROR));
 foreach($files as $name=>$source)$zip->addFromString($name,$source);
 $zip->close();
}
function v23clean(string $dir):void {
 if(!is_dir($dir))return;
 foreach(new FilesystemIterator($dir) as $item){
  if($item->isDir()&&!$item->isLink())v23clean($item->getPathname());
  else unlink($item->getPathname());
 }
 rmdir($dir);
}
$temporary=sys_get_temp_dir().'/lucky-v23a-'.bin2hex(random_bytes(8));
if(!mkdir($temporary,0700,true))throw new RuntimeException('Cannot prepare temporary game installation.');
try {
 $root=$temporary.'/packages';$archive=$temporary.'/fixture.zip';
 $one='cert-alpha';$two='cert-beta';
 foreach([$one,$two] as $name){
  v23zip($archive,$name,'1.0.0');
  GamePackageInstaller::install($archive,$root);
  GamePackageActivation::activate($name,'1.0.0',null,true,$root);
 }
 $first=GamePackageActivation::inspect($root);
 v23check(count($first['active'])===2&&$first['issues']===[],'Healthy installations are available.');
 v23check(GamePackageActivation::all($root)[$two]['phone']===false,'TV-only game requires no phone.');
 // Partial crash remnants must not appear as games.
 mkdir($root.'/.import-crashed',0700);
 file_put_contents($root.'/.import-crashed/incomplete.json','{');
 v23check(count(GamePackageActivation::all($root))===2,'Crash remnants are not registered.');
 $alphaFile=$root.'/'.$one.'/active.json';
 file_put_contents($alphaFile,'{corrupted');
 $bad=GamePackageActivation::inspect($root);
 v23check(!isset($bad['active'][$one])&&isset($bad['active'][$two]),'Damaged game is isolated from healthy game.');
 v23check(count($bad['issues'])===1&&$bad['issues'][0]['game']===$one,'Damaged activation is reported with a stable game id.');
 v23check(GameRegistry::module('spin-wheel')->id()==='spin-wheel','Corrupted external game cannot prevent Spin the Wheel.');
 v23reject(fn()=>GamePackageActivation::recover($one,'1.0.0',false,$root),'Unauthorized repair');
 v23reject(fn()=>GamePackageActivation::recover($two,'1.0.0',true,$root),'Healthy game cannot be overwritten by repair.');
 v23reject(fn()=>GamePackageActivation::recover('spin-wheel','1.0.0',true,$root),'Protected game recovery.');
 $repaired=GamePackageActivation::recover($one,'1.0.0',true,$root);
 v23check($repaired['recovered']===true&&$repaired['version']==='1.0.0','Owner can restore previously verified local version.');
 v23check(count(GamePackageActivation::all($root))===2&&GamePackageActivation::inspect($root)['issues']===[],'Recovery restores game discovery.');
 $backups=glob($root.'/'.$one.'/activation-damaged-*.json');
 v23check(count($backups)===1&&file_get_contents($backups[0])==='{corrupted','Damaged pointer retained for diagnostics.');
 v23reject(fn()=>GamePackageActivation::recover($one,'1.0.0',true,$root),'Recovery may not replace healthy activation.');
 v23check(GamePackageActivation::active($two,$root)['version']==='1.0.0','Unrelated game state preserved.');
 $receiptPath=$root.'/'.$two.'/versions/1.0.0/receipt.json';
 $previousReceipt=file_get_contents($receiptPath);
 $brokenReceipt=json_decode($previousReceipt,true,16,JSON_THROW_ON_ERROR);
 unset($brokenReceipt['title']);
 file_put_contents($receiptPath,json_encode($brokenReceipt,JSON_THROW_ON_ERROR));
 $isolated=GamePackageActivation::inspect($root);
 v23check(isset($isolated['active'][$one])&&!isset($isolated['active'][$two])
   &&count($isolated['issues'])===1,'Corrupted package metadata cannot damage unrelated game registry.');
 file_put_contents($receiptPath,$previousReceipt);
 v23check(count(GamePackageActivation::all($root))===2,'Restoring receipt repairs game discovery without reinstall.');
 echo "V5Q.23A: isolated game failures, explicit recovery, retained evidence and no-phone games PASSED\n";
}finally{v23clean($temporary);}
