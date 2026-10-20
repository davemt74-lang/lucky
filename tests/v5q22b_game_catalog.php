<?php
declare(strict_types=1);
require_once __DIR__.'/../lib/GameCatalog.php';
function checkV5Q22(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
function rejectsV5Q22(callable $run,string $message):void {try{$run();}catch(DomainException $e){return;}throw new RuntimeException('Expected rejection: '.$message);}
$root=sys_get_temp_dir().'/lucky-catalog-test-'.bin2hex(random_bytes(7));
if(!mkdir($root,0700,true))throw new RuntimeException('Unable to create test directory.');
try {
 $before=GameCatalog::all($root);
 $wheel=array_values(array_filter($before,fn($v)=>$v['id']==='spin-wheel'))[0]??null;
 checkV5Q22($wheel!==null&&$wheel['status']==='ready'&&$wheel['controllerMode']==='custom'&&$wheel['builtIn']===true&&$wheel['canDelete']===false,'Wheel must remain a playable game with custom phone controller.');
 $created=GameCatalog::save('example-game',['title'=>'Example Game','publisherName'=>'Example Publisher','publisherWebsite'=>'https://example.com',
  'tagline'=>'A new game','playersMin'=>2,'playersMax'=>6,'controllerMode'=>'none','enabled'=>true],$root);
 checkV5Q22($created['status']==='draft'&&$created['source']==='catalog-only'&&$created['builtIn']===false,'Unsanctioned drafts must never be executable.');
 checkV5Q22($created['controllerMode']==='none'&&$created['publisherName']==='Example Publisher','Independent controller mode and publisher metadata must persist.');
 $saved=GameCatalog::load('example-game',$root);
 checkV5Q22($saved['playersMin']===2&&$saved['playersMax']===6,'Player counts must survive reload.');
 checkV5Q22(count(GameCatalog::all($root))>=2,'Catalog should contain bundled and custom titles.');
 rejectsV5Q22(fn()=>GameCatalog::save('../escape',['title'=>'Bad'],$root),'directory traversal');
 rejectsV5Q22(fn()=>GameCatalog::save('example-game',['title'=>'Bad','publisherWebsite'=>'http://example.com'],$root),'insecure publisher link');
 rejectsV5Q22(fn()=>GameCatalog::save('example-game',['title'=>'Bad','playersMin'=>10,'playersMax'=>2],$root),'invalid player counts');
 $asset=$root.'/sample.png';
 $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL/nwAAAABJRU5ErkJggg==',true);
 file_put_contents($asset,$png);
 $image=GameCatalog::saveImage('example-game','cover',$asset,$root);
 $name=$image['game']['images']['cover'];
 checkV5Q22(is_string($name)&&str_ends_with($name,'.png'),'Catalog should generate opaque artwork name.');
 $served=GameCatalog::artwork('example-game',$name,$root);
 checkV5Q22($served['mime']==='image/png'&&is_file($served['path']),'Game artwork must resolve only through catalog lookup.');
 rejectsV5Q22(fn()=>GameCatalog::artwork('example-game','../sample.png',$root),'art traversal');
 rejectsV5Q22(fn()=>GameCatalog::artwork('example-game',str_repeat('a',32).'.png',$root),'unlisted asset');
 $wheelEdit=GameCatalog::save('spin-wheel',['title'=>'The Wheel','controllerMode'=>'none','enabled'=>true],$root);
 checkV5Q22($wheelEdit['controllerMode']==='custom'&&$wheelEdit['status']==='ready','Metadata must not override bundled phone capability.');
 echo "V5Q.22B offline game catalog, publisher metadata, artwork and draft safety passed\n";
} finally {
 $all=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
 foreach($all as $file){if($file->isDir())rmdir($file->getPathname());else unlink($file->getPathname());}
 rmdir($root);
}
