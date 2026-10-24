<?php
declare(strict_types=1);
define('LUCKY_TESTING',true);
require_once __DIR__.'/../lib/ClaudeVault.php';
require_once __DIR__.'/../lib/VideoEditorSkills.php';
function checkAi(bool $condition,string $label):void {if(!$condition)throw new RuntimeException('FAIL: '.$label);echo 'PASS '.$label."\n";}
function rejectAi(callable $fn,string $label):void {
 try{$fn();}catch(DomainException $e){checkAi($e->getCode()===422,$label);return;}
 throw new RuntimeException('Expected rejection: '.$label);
}
$path=sys_get_temp_dir().'/lucky-claude-contract-'.bin2hex(random_bytes(8));
if(!mkdir($path,0700))throw new RuntimeException('Could not set up isolated key test.');
putenv('LUCKY_CLAUDE_TEST_DIR='.$path);
try {
 checkAi(ClaudeVault::status()['configured']===false,'Claude starts disconnected');
 rejectAi(fn()=>ClaudeVault::store('bad','claude-haiku-5-5'),'Short API key rejected');
 rejectAi(fn()=>ClaudeVault::store(str_repeat('x',34),'invalid-model'),'Unknown model rejected');
 $key='sk-ant-'.str_repeat('example',10);
 $saved=ClaudeVault::store($key,'claude-haiku-5-5');
 checkAi($saved['configured']&&$saved['model']==='claude-haiku-5-5','Secure key created');
 $cipher=(string)file_get_contents($path.'/.claude-settings.php');
 checkAi(!str_contains($cipher,$key),'Ciphertext does not contain the API key');
 checkAi(ClaudeVault::apiKey()===$key,'AES-GCM decrypts exact API key');
 checkAi(!array_key_exists('apiKey',ClaudeVault::status()),'Public status never exposes key');
 ClaudeVault::store('sk-ant-'.str_repeat('new',18),'claude-sonnet-5-5');
 checkAi(ClaudeVault::status()['model']==='claude-sonnet-5-5','Selected model persists after rotation');
 $ctx=VideoEditorSkills::context([
  ['instanceId'=>'seg-a','kind'=>'video','title'=>'First prize','durationMs'=>10000,'trimStartMs'=>0,'trimEndMs'=>6000],
  ['instanceId'=>'seg-b','kind'=>'photo','title'=>'Second prize','photoDurationMs'=>3000],
 ],[['id'=>str_repeat('d',32),'title'=>'Demo soundtrack','kind'=>'upload']]);
 checkAi($ctx['totalMs']===9000&&count($ctx['clips'])===2,'Metadata-only context has exact timeline timing');
 checkAi(!str_contains(json_encode($ctx,JSON_THROW_ON_ERROR),'src'),'No private media URL in model context');
 checkAi(count(VideoEditorSkills::catalog())===4,'Four safe video editing skills registered');
 $plan=VideoEditorSkills::normalize('highlight',['summary'=>'Tighten pacing','operations'=>[
  ['tool'=>'timeline.reorder','args'=>['order'=>['seg-b','seg-a']]],
  ['tool'=>'clip.trim','args'=>['instanceId'=>'seg-a','startMs'=>500,'endMs'=>4500]],
 ]],$ctx);
 checkAi(count($plan['operations'])===2&&$plan['operations'][0]['args']['order'][0]==='seg-b','Validated reorder and trim plan');
 rejectAi(fn()=>VideoEditorSkills::normalize('highlight',['operations'=>[['tool'=>'clip.delete','args'=>['instanceId'=>'seg-a']]]],$ctx),'Deletion tool cannot be proposed');
 rejectAi(fn()=>VideoEditorSkills::normalize('highlight',['operations'=>[['tool'=>'timeline.reorder','args'=>['order'=>['seg-a','seg-a']]]]],$ctx),'Reorder cannot duplicate/drop clips');
 rejectAi(fn()=>VideoEditorSkills::normalize('highlight',['operations'=>[['tool'=>'clip.trim','args'=>['instanceId'=>'seg-a','startMs'=>0,'endMs'=>25000]]]],$ctx),'Trim cannot exceed actual source duration');
 rejectAi(fn()=>VideoEditorSkills::normalize('sound',['operations'=>[['tool'=>'audio.background','args'=>['trackId'=>str_repeat('f',32),'volume'=>50]]]],$ctx),'Unowned/unavailable soundtrack rejected');
 rejectAi(fn()=>VideoEditorSkills::normalize('cinematic',['operations'=>[['tool'=>'clip.look','args'=>['instanceId'=>'unknown','preset'=>'mono']]]],$ctx),'Unknown clip rejected');
 checkAi(ClaudeVault::disconnect()['configured']===false,'Key removal disconnects provider');
 echo "V5Q.28A VIDEO AI SECURITY AND TOOL CONTRACTS PASSED\n";
}finally{
 foreach(['.claude-settings.php','.claude-key.php'] as $file)if(is_file($path.'/'.$file))unlink($path.'/'.$file);
 rmdir($path);putenv('LUCKY_CLAUDE_TEST_DIR');
}
