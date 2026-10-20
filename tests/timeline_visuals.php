<?php
declare(strict_types=1);
require_once __DIR__.'/../lib/MontagePlan.php';
function yes(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
function blocked(callable $call,string $label):void{try{$call();throw new RuntimeException('Invalid edit was allowed: '.$label);}catch(DomainException $e){yes($e->getCode()===422,$label);}}
$id=str_repeat('a',32);$claim=str_repeat('b',32);
$source=[$id=>['id'=>$id,'kind'=>'video','claim_id'=>$claim,'title'=>'Prize Camera','duration_ms'=>5000,'trim_start_ms'=>0,'trim_end_ms'=>0,'photo_duration_ms'=>4000]];
$base=['id'=>$id,'instanceId'=>'clip-1','title'=>'Prize Camera','trimStartMs'=>0,'trimEndMs'=>4500,'photoDurationMs'=>4000,'transition'=>'cut'];
$edit=[$base+['lookPreset'=>'mono','overlayText'=>"Grand Prize: Winner's!",'overlayPosition'=>'bottom','overlaySize'=>'large']];
$segments=MontagePlan::materialize($source,$edit);
yes(count($segments)===1,'one video segment retains existing timeline');
yes($segments[0]['look_preset']==='mono','black-and-white filter saved in project plan');
yes($segments[0]['overlay_text']==="Grand Prize: Winner's!",'caption punctuation survives validation without shell escaping');
yes($segments[0]['overlay_position']==='bottom'&&$segments[0]['overlay_size']==='large','overlay placement and size retained');
yes(MontagePlan::validate($segments)['estimatedMs']===4500,'visual effects do not change clip trim');
yes(MontageVisuals::filters('warm')!==[]&&MontageVisuals::filters('vivid')!==[],'starter filter presets have render instructions');
blocked(fn()=>MontagePlan::materialize($source,[$base+['lookPreset'=>'malicious']]),'unknown filters rejected');
blocked(fn()=>MontagePlan::materialize($source,[$base+['overlayText'=>"line1\nline2"]]),'caption control characters rejected');
blocked(fn()=>MontagePlan::materialize($source,[$base+['overlayText'=>str_repeat('x',481)]]),'oversized text rejected');
blocked(fn()=>MontagePlan::materialize($source,[$base+['overlayPosition'=>'outside']]),'invalid caption positions rejected');
blocked(fn()=>MontagePlan::materialize($source,[$base+['overlaySize'=>'giant']]),'invalid caption sizes rejected');
yes(MontageVisuals::filters('none')===[],'original look renders without added filters');
$custom=sys_get_temp_dir().'/lucky-overlay-'.bin2hex(random_bytes(3));mkdir($custom,0700);
try{
 $filter=MontageVisuals::overlay($segments[0],$custom,0,1280);
 yes(str_contains($filter,'drawtext=textfile=')&&str_contains($filter,'box=1'),'overlay rendered from safely written text file');
 yes(file_get_contents($custom.'/overlay-000.txt')==="Grand Prize: Winner's!",'caption text supplied in separate file, not shell command');
}finally{@unlink($custom.'/overlay-000.txt');@rmdir($custom);}
