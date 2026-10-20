<?php
declare(strict_types=1);
require __DIR__.'/../lib/GameEngine.php';
require __DIR__.'/../lib/PrizePresentation.php';
function verify(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
$id=str_repeat('a',32);
$config=GameEngine::defaults();
$config['pools']['tier1']['prizes'][0]['videoUrl']='https://example.org/prize.mp4';
$config['pools']['tier1']['prizes'][0]['url']='https://example.org/prize-shop';
$validated=GameEngine::validateConfig($config);
verify($validated['pools']['tier1']['prizes'][0]['videoUrl']==='https://example.org/prize.mp4','prize video URL stored independently of shopping URL');
$state=GameEngine::newSession($config,$id);
$claim=['id'=>str_repeat('b',32),'name'=>'Gift Card','videoUrl'=>'https://example.org/prize.mp4','url'=>'https://example.org/prize-shop'];
$state['claims'][]=$claim;
$offer=['claimId'=>$claim['id'],'status'=>'pending','name'=>'Gift Card','cents'=>3750];
$out=PrizePresentation::decorate($offer,$state);
verify($out['videoUrl']==='https://example.org/prize.mp4','phone pending offer uses exact winning claim video');
verify(!isset(PrizePresentation::decorate(['claimId'=>$claim['id'],'status'=>'accepted'],$state)['videoUrl']),'accepted prize does not replay video on proof screen');
verify(PrizePresentation::decorate(['claimId'=>str_repeat('c',32),'status'=>'pending'],$state)['videoUrl']==='','other claim cannot inherit a video');
verify(PrizePresentation::videoUrl(['url'=>'https://www.youtube.com/watch?v=dQw4w9WgXcQ'])!=='','legacy prize video links recognized');
verify(PrizePresentation::videoUrl(['url'=>'https://example.org/shop'])==='','regular prize website never treated as a video');
verify(PrizePresentation::videoUrl(['videoUrl'=>'javascript:alert(1)'])==='','script URLs are blocked');
verify(PrizePresentation::videoUrl(['videoUrl'=>'http://example.org/prize.mp4'])==='','cleartext video never embedded on paired HTTPS phone');
verify(PrizePresentation::videoUrl(['videoUrl'=>'https://user:pass@example.org/prize.mp4'])==='','credentialed URLs cannot be shared to phone');
