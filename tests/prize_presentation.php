<?php
declare(strict_types=1);
require __DIR__.'/../lib/GameEngine.php';
require __DIR__.'/../lib/PrizePresentation.php';
function verify(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
$id=str_repeat('a',32);$mediaId=str_repeat('e',32);
$uploaded='api/index.php?action=prize_video&id='.$mediaId;
$config=GameEngine::defaults();
$config['pools']['tier1']['prizes'][0]['videoUrl']=$uploaded;
$config['pools']['tier1']['prizes'][0]['url']='https://example.org/prize-shop';
$validated=GameEngine::validateConfig($config);
verify($validated['pools']['tier1']['prizes'][0]['videoUrl']===$uploaded,'uploaded prize video reference persists separately from shopping link');
$state=GameEngine::newSession($config,$id);
$claim=['id'=>str_repeat('b',32),'name'=>'Gift Card','videoUrl'=>$uploaded,'url'=>'https://example.org/prize-shop'];
$state['claims'][]=$claim;
$offer=['claimId'=>$claim['id'],'status'=>'pending','name'=>'Gift Card','cents'=>3750];
$out=PrizePresentation::decorate($offer,$state);
verify($out['videoUrl']===$uploaded,'phone pending offer gets exact uploaded video');
verify(PrizePresentation::decorate(['claimId'=>$claim['id'],'status'=>'accepted'],$state)['videoUrl']===$uploaded,'accepted prize retains video for proof controls');
verify(PrizePresentation::decorate(['claimId'=>str_repeat('c',32),'status'=>'pending'],$state)['videoUrl']==='','another claim cannot inherit video');
verify(PrizePresentation::videoUrl(['url'=>'https://www.youtube.com/watch?v=dQw4w9WgXcQ'])==='','legacy website links not playable');
verify(PrizePresentation::videoUrl(['videoUrl'=>'https://example.org/prize.mp4'])==='','external video URLs not playable');
verify(PrizePresentation::videoUrl(['videoUrl'=>'javascript:alert(1)'])==='','script URLs blocked');
verify(PrizePresentation::videoUrl(['videoUrl'=>'http://example.org/prize.mp4'])==='','cleartext URLs blocked');
verify(PrizePresentation::videoUrl(['videoUrl'=>$uploaded.'&other=1'])==='','extra route parameters rejected');
verify(PrizePresentation::videoUrl(['videoUrl'=>$uploaded])===$uploaded,'same-origin uploaded route accepted');
