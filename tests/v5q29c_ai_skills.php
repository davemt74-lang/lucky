<?php
declare(strict_types=1);
define('LUCKY_TESTING',true);
require_once __DIR__.'/../lib/VideoEditorSkills.php';

$catalog=VideoEditorSkills::catalog();$map=[];
foreach($catalog as $skill)$map[$skill['id']]=$skill;
foreach(['scene','quiet'] as $id){
 if(!isset($map[$id])||!in_array('clip.trim',$map[$id]['tools'],true)||in_array('clip.delete',$map[$id]['tools'],true))
  throw new RuntimeException('New specialist is not bound to non-destructive tools: '.$id);
 echo "PASS {$id} uses existing non-destructive edit tools\n";
}
$context=VideoEditorSkills::context([
 ['instanceId'=>'clip-a','kind'=>'video','title'=>'Sample','durationMs'=>9000,'trimStartMs'=>0,'trimEndMs'=>9000],
],[]);
$suggestion=['summary'=>'Concentrate the reaction','operations'=>[['tool'=>'clip.trim','args'=>['instanceId'=>'clip-a','startMs'=>1500,'endMs'=>5000]]]];
foreach(['scene','quiet'] as $skill){
 $normalized=VideoEditorSkills::normalize($skill,$suggestion,$context);
 if($normalized['operations'][0]['args']['startMs']!==1500)throw new RuntimeException('Specialist did not validate source-time trim.');
 echo "PASS {$skill} produces owner-reviewed trims\n";
 try{VideoEditorSkills::normalize($skill,['operations'=>[['tool'=>'clip.delete','args'=>['instanceId'=>'clip-a']]]],$context);throw new RuntimeException('Deletion accepted.');}
 catch(DomainException $e){if($e->getCode()!==422)throw $e;echo "PASS {$skill} rejects deletion\n";}
}
echo "V5Q.29C SPECIALIST SKILLS CONTRACT PASSED\n";
