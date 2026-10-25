<?php
declare(strict_types=1);
require __DIR__.'/../lib/Bootstrap.php';
require_once __DIR__.'/../lib/ClaudeVault.php';
require_once __DIR__.'/../lib/ClaudeBridge.php';
require_once __DIR__.'/../lib/VideoEditorSkills.php';
require_once __DIR__.'/../lib/MontagePlan.php';
require_once __DIR__.'/../lib/MediaIntelligence.php';
headerSecurity();header('Referrer-Policy: no-referrer');header('X-Robots-Tag: noindex, nofollow');
try {
 if(!installed())throw new DomainException('Install Lucky Game Hub first.',503);
 requireAdmin();$action=(string)($_GET['action']??'');$method=$_SERVER['REQUEST_METHOD']??'GET';
 if($method==='GET'&&$action==='status')success(['claude'=>ClaudeVault::status(),'skills'=>VideoEditorSkills::catalog()]);
 if($method!=='POST')throw new DomainException('Unknown editor AI endpoint.',404);
 assertCsrf();$input=jsonInput();$db=db();
 if($action==='settings_save'){
  $key=(string)($input['apiKey']??'');
  if(trim($key)===''&&ClaudeVault::status()['configured'])$key=ClaudeVault::apiKey();
  $result=ClaudeVault::store($key,(string)($input['model']??ClaudeVault::MODELS[0]));
  audit($db,'video_ai.claude_configured',null,['model'=>$result['model']]);success(['claude'=>$result]);
 }
 if($action==='settings_remove'){
  if(($input['confirm']??false)!==true)throw new DomainException('Confirm removal of the Claude API key.',403);
  $result=ClaudeVault::disconnect();audit($db,'video_ai.claude_removed');success(['claude'=>$result]);
 }
 if($action==='test'){
  $text=ClaudeBridge::ask('Return exactly the word READY.','Connection check.',32);
  audit($db,'video_ai.claude_tested');success(['connected'=>trim($text)!=='','claude'=>ClaudeVault::status()]);
 }
 if($action==='analyze'){
  $session=(string)($input['session']??'');sessionRow($db,$session);
  $id=(string)($input['id']??'');
  if(!ClaimMedia::validId($id))throw new DomainException('Choose a valid recording.',422);
  $q=$db->prepare('SELECT id,kind,duration_ms FROM claim_media WHERE id=? AND session_id=?');
  $q->execute([$id,$session]);$clip=$q->fetch(PDO::FETCH_ASSOC);
  if(!$clip)throw new DomainException('Recording is not part of this game.',404);
  if(!in_array($clip['kind'],['video','audio'],true))throw new DomainException('Still photos have no scene or silence timing.',422);
  $file=LUCKY_ROOT.'/storage/claim-media/'.$id.'.bin';
  $signals=MediaIntelligence::analyze($id,$clip['kind'],$file,(int)$clip['duration_ms']);
  audit($db,'video_ai.media_analyzed',$session,['kind'=>$clip['kind'],'id'=>$id,'remote'=>false]);
  success(['id'=>$id,'signals'=>MediaIntelligence::promptSignals($signals),'localOnly'=>true]);
 }
 if($action==='plan'){
  $session=(string)($input['session']??'');sessionRow($db,$session);
  $skill=(string)($input['skill']??'');VideoEditorSkills::skill($skill);
  $instruction=trim((string)($input['instruction']??''));
  if(strlen($instruction)>500||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/',$instruction))throw new DomainException('Editor instructions must be under 500 characters.',422);
  $edits=$input['edits']??null;if(!is_array($edits))throw new DomainException('Invalid timeline context.',422);
  $q=$db->prepare('SELECT * FROM claim_media WHERE session_id=?');$q->execute([$session]);$source=[];
  foreach($q->fetchAll(PDO::FETCH_ASSOC) as $item)$source[$item['id']]=$item;
  $clips=MontagePlan::materialize($source,$edits);
  $songs=$db->query("SELECT id,title FROM media_tracks WHERE kind='upload' ORDER BY created_at DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
  $context=VideoEditorSkills::context($clips,$songs);
  // Explicit, per-proposal permission. Raw files, transcripts and analysis logs
  // stay on the installation; only cached, bounded numerical timings can travel.
  $shareCues=($input['includeTimingCues']??false)===true;
  if($shareCues){
   $context['timingCues']=MediaIntelligence::timelineCues($clips,static function(string $id):?array {
    return MediaIntelligence::cached($id,LUCKY_ROOT.'/storage/claim-media/'.$id.'.bin');
   });
  }
  $now=time();if(($now-(int)($_SESSION['video_ai_last_request']??0))<4)throw new DomainException('Please wait a few seconds between AI plans.',429);
  $_SESSION['video_ai_last_request']=$now;
  $prompt=json_encode(['skill'=>$skill,'instruction'=>$instruction,'context'=>$context],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
  $text=trim(ClaudeBridge::ask(VideoEditorSkills::systemPrompt($skill),$prompt));
  if(preg_match('/^```(?:json)?\s*(.*?)\s*```$/is',$text,$m))$text=$m[1];
  try{$response=json_decode($text,true,512,JSON_THROW_ON_ERROR);}catch(JsonException $e){throw new DomainException('Claude did not return a valid edit proposal. Try again.',502);}
  if(!is_array($response))throw new DomainException('Claude did not return an edit proposal.',502);
  $proposal=VideoEditorSkills::normalize($skill,$response,$context);
  audit($db,'video_ai.proposed',$session,['skill'=>$skill,'operations'=>count($proposal['operations']),'sharedTimingCues'=>$shareCues,'analyzedClips'=>$shareCues?count($context['timingCues']):0]);
  success(['proposal'=>$proposal,'timingCuesShared'=>$shareCues?count($context['timingCues']):0]);
 }
 throw new DomainException('Unknown editor AI endpoint.',404);
}catch(Throwable $e){errorOut($e);}
