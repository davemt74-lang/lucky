<?php
declare(strict_types=1);
require __DIR__.'/../lib/PrizeSmartText.php';
require __DIR__.'/../lib/GameEngine.php';
require __DIR__.'/../lib/PrizePresentation.php';
function ok(bool $condition,string $message):void {if(!$condition)throw new RuntimeException('FAIL: '.$message);echo 'PASS '.$message."\n";}
$prize=['name'=>'Super Pizza Party','cents'=>12500,'tier'=>'tier2'];
ok(PrizeSmartText::render('Win {prize_name}, worth {prize_value}, in {prize_tier}.',$prize)==='Win Super Pizza Party, worth $125.00, in Tier 2.','Smart tags resolve server-side for phone speech');
ok(PrizeSmartText::render('',$prize)==='Congratulations! You won Super Pizza Party!','Empty description has safe default');
try {PrizeSmartText::validate('{unsafe_expression}');throw new RuntimeException('Invalid tag accepted');}catch(DomainException $e){ok($e->getCode()===422,'Unrecognized smart tag rejected');}
$cfg=['pools'=>['tier1'=>['budgetCents'=>12500,'prizes'=>[['id'=>'test-prize','name'=>'Super Pizza Party','cents'=>12500,'url'=>'','image'=>'https://example.org/photo.jpg','videoUrl'=>'https://lucky.example/api/index.php?action=prize_video&id=eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee','description'=>'Welcome! {prize_name} is worth {prize_value}.']]],'tier2'=>['budgetCents'=>0,'prizes'=>[]],'bonus'=>['budgetCents'=>0,'prizes'=>[]]],'odds'=>GameEngine::DEFAULT_ODDS];
$normalized=GameEngine::validateConfig($cfg);
ok($normalized['pools']['tier1']['prizes'][0]['description']==='Welcome! {prize_name} is worth {prize_value}.','Prize voice template survives validation');
$claim=['id'=>'a','name'=>'Super Pizza Party','cents'=>12500,'tier'=>'tier1','image'=>'https://example.org/photo.jpg','videoUrl'=>'https://lucky.example/api/index.php?action=prize_video&id=eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee','description'=>'Welcome {prize_name} ({prize_value})'];
foreach(['pending','accepted'] as $status){$result=PrizePresentation::decorate(['status'=>$status,'claimId'=>'a'],['claims'=>[$claim]]);ok($result['image']===$claim['image']&&$result['videoUrl']==='api/index.php?action=prize_video&id=eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee'&&$result['description']==='Welcome Super Pizza Party ($125.00)','Offer '.$status.' includes photo/video/resolved speech independently');}
ok(PrizePresentation::videoUrl(['videoUrl'=>'https://www.youtube.com/watch?v=abc123def45'])==='', 'Legacy external links never become uploaded videos');
echo "V5Q25 metadata tests green.\n";
