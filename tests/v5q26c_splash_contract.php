<?php
declare(strict_types=1);
require __DIR__.'/../lib/GameCatalog.php';
function must(bool $ok,string $name):void {if(!$ok)throw new RuntimeException($name);echo "PASS $name\n";}
$base=sys_get_temp_dir().'/lucky-splash-'.bin2hex(random_bytes(6));
try{
 $intro=['title'=>'Family Game Night','subtitle'=>'Pizza and prizes!','trackId'=>'','volume'=>45,'announcement'=>true];
 $theme=['accent'=>'#f6cb74','background'=>'#102030'];
 $draft=GameCatalog::save('game-next',['title'=>'Next game','playersMin'=>1,'playersMax'=>4,'splash'=>['intro'=>$intro,'theme'=>$theme]],$base);
 must(($draft['splash']['intro']['title']??'')==='Family Game Night','Draft stores independent splash settings');
 $other=GameCatalog::load('spin-wheel',$base);
 must(($other['splash']??null)===null,'Unconfigured game retains legacy settings');
 $again=GameCatalog::save('game-next',['title'=>'Updated listing','playersMin'=>1,'playersMax'=>4],$base);
 must($again['splash']['theme']['background']==='#102030','Saving game metadata preserves splash configuration');
 foreach ([['intro'=>['title'=>'','subtitle'=>'','volume'=>50],'theme'=>$theme],['intro'=>$intro,'theme'=>['accent'=>'red','background'=>'#102030']],['intro'=>array_merge($intro,['volume'=>101]),'theme'=>$theme]] as $bad){
  try{GameCatalog::splash($bad);throw new RuntimeException('Invalid splash was accepted');}catch(DomainException $expected){}
 }
 must(true,'Invalid splash title, colors and volume rejected');
 $img=tempnam(sys_get_temp_dir(),'splash-png');
 file_put_contents($img,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL/nwAAAABJRU5ErkJggg=='));
 $result=GameCatalog::saveImage('game-next','splash',$img,$base);
 unlink($img);
 must(isset($result['game']['images']['splash']),'Splash uploaded into per-game artwork slot');
 $meta=GameCatalog::artwork('game-next',$result['game']['images']['splash'],$base);
 must($meta['mime']==='image/png' && is_file($meta['path']),'Uploaded splash background is readable from approved artwork route');
 try{GameCatalog::artwork('spin-wheel',$result['game']['images']['splash'],$base);throw new RuntimeException('Cross-game image accepted');}catch(DomainException $expected){}
 must(true,'Another game cannot load the background asset');
}finally{
 if(is_dir($base)){foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $file){$file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());}rmdir($base);}
}
