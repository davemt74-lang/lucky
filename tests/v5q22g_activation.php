<?php
declare(strict_types=1);
require_once __DIR__.'/../lib/PackageGameSandbox.php';
require_once __DIR__.'/../lib/PackageGameModule.php';
function okG(bool $value,string $what):void {if(!$value)throw new RuntimeException($what);}
function failG(callable $callback,string $what):void {
 try{$callback();}catch(DomainException $e){return;}
 throw new RuntimeException('Expected refusal: '.$what);
}
function zipG(string $path,string $id,string $version,?string $phone):void {
 $assets=[
  'host.html'=>'<!doctype html><html><body><button data-game-input="answer" data-game-value="a">A</button><script src="scripts/main.js"></script><link rel="stylesheet" href="css/game.css"></body></html>',
  'scripts/main.js'=>'window.gameMounted=true;',
  'css/game.css'=>'body{color:white}'];
 if($phone!==null)$assets['phone.html']='<!doctype html><body><button data-game-input="buzz">Buzz</button></body>';
 $manifest=['schema'=>'lucky.game-package.v1','id'=>$id,'version'=>$version,
  'title'=>'Test Game','publisher'=>'Test Publisher','host'=>'host.html','phone'=>$phone,
  'files'=>array_map(fn($content)=>hash('sha256',$content),$assets)];
 $zip=new ZipArchive();if($zip->open($path,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)throw new RuntimeException('ZIP failure');
 $zip->addFromString('manifest.json',json_encode($manifest));
 foreach($assets as $name=>$value)$zip->addFromString($name,$value);
 $zip->close();
}
function cleanG(string $dir):void {
 if(!is_dir($dir))return;
 foreach(new FilesystemIterator($dir) as $child){
  if($child->isDir()&&!$child->isLink())cleanG($child->getPathname());
  else unlink($child->getPathname());
 }
 rmdir($dir);
}
$home=sys_get_temp_dir().'/lucky-g-'.bin2hex(random_bytes(8));mkdir($home,0700,true);
try{
 $root=$home.'/storage';$zip=$home.'/demo.zip';
 zipG($zip,'couch-trivia','1.0.0','phone.html');
 $staged=GamePackageInstaller::install($zip,$root);
 okG($staged['status']==='staged','Staged package is not active');
 okG(GamePackageActivation::active('couch-trivia',$root)===null,'Offline package remains inactive by default');
 failG(fn()=>GamePackageActivation::activate('couch-trivia','1.0.0',null,false,$root),'Owner authorization');
 failG(fn()=>GamePackageActivation::activate('spin-wheel','1.0.0',null,true,$root),'Built-in game protected');
 $activated=GamePackageActivation::activate('couch-trivia','1.0.0',null,true,$root);
 okG($activated['version']==='1.0.0'&&$activated['phone']===true,'Custom phone installed');
 okG(GamePackageActivation::active('couch-trivia',$root)['version']==='1.0.0','Active version survives independent reload');
 $doc=PackageGameSandbox::document('couch-trivia','1.0.0','host',$root);
 okG(str_contains($doc,'window.gameMounted=true')&&str_contains($doc,'body{color:white}'),'Sandbox inlines local JavaScript and CSS');
 okG(str_contains($doc,'lucky:input')&&!str_contains($doc,'<script src='),'Sandbox bridge and no external script reference');
 okG(str_contains(PackageGameSandbox::document('couch-trivia','1.0.0','phone',$root),'Buzz'),'Custom phone HTML entry isolated');
 failG(fn()=>GamePackageActivation::file('couch-trivia','1.0.0','../storage/config.php',$root),'Traversal forbidden');
 failG(fn()=>GamePackageActivation::activate('couch-trivia','1.0.0',null,true,$root),'Stale expected version');
 $package=new PackageGameModule(['id'=>'couch-trivia','version'=>'1.0.0','title'=>'Couch Trivia','publisher'=>'Test','phone'=>true]);
 $session=$package->createSession($package->defaults(),str_repeat('a',32));
 okG($session['packageVersion']==='1.0.0'&&$session['status']==='active','Session pinned to immutable version');
 $event=$package->action($session,'input',['name'=>'answer','value'=>'a']);
 okG($event['version']===2&&$package->publicView($session)['lastInput']['name']==='answer','Generic server input persisted');
 failG(fn()=> $package->action($session,'input',['name'=>'/bad','value'=>1]),'Unsafe action name');
 $package->action($session,'pause');
 failG(fn()=> $package->action($session,'input',['name'=>'answer','value'=>1]),'Paused game inputs rejected');
 $package->action($session,'resume');$package->action($session,'end');
 okG($session['status']==='ended'&&$session['version']===5,'Standard game lifecycle and history');
 zipG($zip,'couch-trivia','1.1.0',null);
 GamePackageInstaller::install($zip,$root);
 $up=GamePackageActivation::activate('couch-trivia','1.1.0','1.0.0',true,$root);
 okG($up['previous']==='1.0.0'&&$up['phone']===false,'No-phone game version upgrade supported');
 failG(fn()=>PackageGameSandbox::document('couch-trivia','1.1.0','phone',$root),'No phone controller optional');
 // Save/resume can keep old code, even after new version is made active.
 okG(str_contains(PackageGameSandbox::document('couch-trivia','1.0.0','host',$root),'gameMounted'),'Old session package remains present');
 failG(fn()=>GamePackageActivation::file('couch-trivia','1.0.0','host.html',$root),'Old version not public without a pinned session');
 okG(GamePackageActivation::file('couch-trivia','1.0.0','host.html',$root,true)['bytes']>20,'Pinned historical session reads its own version');
 $back=GamePackageActivation::activate('couch-trivia','1.0.0','1.1.0',true,$root);
 okG($back['previous']==='1.1.0'&&GamePackageActivation::active('couch-trivia',$root)['version']==='1.0.0','Atomic owner rollback');
 $noPhone=new PackageGameModule(['id'=>'no-phone','version'=>'1.0.0','title'=>'No Phone','publisher'=>'Test','phone'=>false]);
 okG($noPhone->hasPhone()===false,'Phone controller genuinely optional');
 okG(GameRegistry::module('spin-wheel')->id()==='spin-wheel','Bundled Wheel remains accessible');
 echo "V5Q.22G: activation, rollback, immutable sessions, sandbox isolation and optional phone contract passed\n";
}finally{cleanG($home);}
