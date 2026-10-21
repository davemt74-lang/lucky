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
verify($validated['pools']['tier1']['prizes'][0]['videoUrl']===$uploaded,'private uploaded video reference persists separately from shopping link');
$state=GameEngine::newSession($config,$id);
$claim=['id'=>str_repeat('b',32),'name'=>'Gift Card','videoUrl'=>$uploaded,'url'=>'https://example.org/prize-shop'];
$state['claims'][]=$claim;
$offer=['claimId'=>$claim['id'],'status'=>'pending','name'=>'Gift Card','cents'=>3750];
$out=PrizePresentation::decorate($offer,$state);
verify($out['videoUrl']===$uploaded,'phone pending offer receives the exact private uploaded video route');
verify(PrizePresentation::decorate(['claimId'=>$claim['id'],'status'=>'accepted'],$state)['videoUrl']===$uploaded,'accepted prize can retain video for proof controls');
verify(PrizePresentation::decorate(['claimId'=>str_repeat('c',32),'status'=>'pending'],$state)['videoUrl']==='','another claim never inherits a video');
verify(PrizePresentation::videoUrl(['url'=>'https://www.youtube.com/watch?v=dQw4w9WgXcQ'])==='','legacy website links never become playable prize videos');
verify(PrizePresentation::videoUrl(['videoUrl'=>'https://example.org/prize.mp4'])==='','external video URLs never become playable prize videos');
verify(PrizePresentation::videoUrl(['videoUrl'=>'javascript:alert(1)'])==='','script URL rejected');
verify(PrizePresentation::videoUrl(['videoUrl'=>'http://example.org/prize.mp4'])==='','HTTP URLs rejected');
verify(PrizePresentation::videoUrl(['videoUrl'=>'api/index.php?action=prize_video&id='.$mediaId.'&other=1'])==='','extra route parameters are rejected');
verify(PrizePresentation::videoUrl(['videoUrl'=>'api/index.php?action=prize_video&id='.$mediaId])===$uploaded,'same-origin uploaded media route accepted');
