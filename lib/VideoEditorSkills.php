<?php
declare(strict_types=1);
require_once __DIR__.'/MontagePlan.php';

/** The tools Claude may suggest; execution stays in the existing, validated editor. */
final class VideoEditorSkills {
 public const TOOLS=['timeline.reorder','clip.trim','clip.look','clip.transition','clip.overlay','clip.mute','timeline.title','audio.background'];
 private const SKILLS=[
  'highlight'=>['name'=>'Highlight reel','description'=>'Improve pacing, claim order, clip length and transitions.','tools'=>['timeline.reorder','clip.trim','clip.transition']],
  'cinematic'=>['name'=>'Cinematic finish','description'=>'Polish color, tone, transitions and restrained overlays.','tools'=>['clip.look','clip.transition','clip.overlay']],
  'story'=>['name'=>'Story & titles','description'=>'Build a readable recap using titles and clip labels.','tools'=>['timeline.title','clip.overlay','timeline.reorder']],
  'sound'=>['name'=>'Music & sound','description'=>'Balance clip audio with an already uploaded soundtrack.','tools'=>['audio.background','clip.mute','clip.transition']],
 ];
 public static function catalog():array {
  $out=[];foreach(self::SKILLS as $id=>$skill)$out[]=['id'=>$id]+$skill;
  return $out;
 }
 public static function skill(string $id):array {
  if(!isset(self::SKILLS[$id]))throw new DomainException('Choose an available video editor skill.',422);
  return self::SKILLS[$id];
 }
 public static function context(array $clips,array $tracks):array {
  if(!$clips||count($clips)>80)throw new DomainException('Choose 1–80 timeline clips before asking Claude.',422);
  $rows=[];$ids=[];$total=0;
  foreach($clips as $c){
   if(!is_array($c))throw new DomainException('Invalid timeline clip.',422);
   $id=(string)($c['instanceId']??'');
   if(!preg_match('/^[A-Za-z0-9-]{1,80}$/D',$id)||isset($ids[$id]))throw new DomainException('Duplicate or invalid timeline clip.',422);
   $ids[$id]=true;
   $kind=(string)($c['kind']??'');if(!in_array($kind,['video','photo','audio'],true))throw new DomainException('Invalid clip type.',422);
   $start=(int)($c['trim_start_ms']??$c['trimStartMs']??0);
   $end=(int)($c['trim_end_ms']??$c['trimEndMs']??0);
   $full=(int)($c['duration_ms']??$c['durationMs']??0);
   $photo=(int)($c['photo_duration_ms']??$c['photoDurationMs']??4000);
   $duration=$kind==='photo'?$photo:($end>0?$end-$start:max(0,$full-$start));
   $total+=$duration>0?$duration:5000;
   $rows[]=['instanceId'=>$id,'kind'=>$kind,'title'=>(string)($c['title']??''),
     'sourceDurationMs'=>$full,'trimStartMs'=>$start,'trimEndMs'=>$end,'photoDurationMs'=>$photo];
  }
  if($total>MontagePlan::MAX_TOTAL_MS)throw new DomainException('Timeline exceeds the editor duration limit.',422);
  $songs=[];foreach($tracks as $track){if(!is_array($track))continue;
   $id=(string)($track['id']??'');if(preg_match('/^[a-f0-9]{32}$/D',$id))$songs[]=['id'=>$id,'title'=>substr((string)($track['title']??''),0,100)];
  }
  return ['clips'=>$rows,'tracks'=>$songs,'totalMs'=>$total];
 }
 private static function validText(mixed $v,int $max=120):string {
  if(!is_string($v)||strlen($v)>$max||preg_match('//u',$v)!==1||preg_match('/[\x00-\x1F\x7F]/u',$v))throw new DomainException('Claude suggested invalid text.',422);
  return trim($v);
 }
 public static function normalize(string $skillId,array $proposal,array $context):array {
  $skill=self::skill($skillId);$operations=$proposal['operations']??null;
  if(!is_array($operations)||!array_is_list($operations)||count($operations)>12)throw new DomainException('Claude suggested an invalid edit plan.',422);
  $byId=[];foreach($context['clips'] as $c)$byId[$c['instanceId']]=$c;
  $trackIds=array_fill_keys(array_column($context['tracks'],'id'),true);$validated=[];
  foreach($operations as $step){
   if(!is_array($step))throw new DomainException('Invalid edit instruction.',422);
   $tool=(string)($step['tool']??'');if(!in_array($tool,$skill['tools'],true))throw new DomainException('Claude suggested a tool outside the selected skill.',422);
   $args=$step['args']??null;if(!is_array($args))throw new DomainException('Invalid edit tool arguments.',422);
   if($tool==='timeline.reorder'){
    $order=$args['order']??null;$existing=array_keys($byId);
    if(!is_array($order)||!array_is_list($order)||count($order)!==count($existing))throw new DomainException('Claude must preserve every clip when reordering.',422);
    foreach($order as $id)if(!is_string($id)||!isset($byId[$id]))throw new DomainException('Claude reordered an unknown clip.',422);
    if(count(array_unique($order))!==count($existing))throw new DomainException('Claude duplicated a clip.',422);
    $safe=['order'=>$order];
   }elseif($tool==='timeline.title'){
    $text=self::validText($args['text']??null);$start=$args['startMs']??null;$duration=$args['durationMs']??null;
    if($text===''||!is_int($start)||!is_int($duration)||$start<0||$duration<500||$duration>30000||$start+$duration>$context['totalMs'])throw new DomainException('Invalid title timing.',422);
    $position=$args['position']??'bottom';$size=$args['size']??'medium';
    if(!in_array($position,['top','center','bottom'],true)||!in_array($size,['small','medium','large'],true))throw new DomainException('Invalid title style.',422);
    $safe=['text'=>$text,'startMs'=>$start,'durationMs'=>$duration,'position'=>$position,'size'=>$size];
   }elseif($tool==='audio.background'){
    $id=(string)($args['trackId']??'');$volume=$args['volume']??null;
    if(!isset($trackIds[$id])||!is_int($volume)||$volume<0||$volume>100)throw new DomainException('Claude must select an uploaded song and safe volume.',422);
    $safe=['trackId'=>$id,'volume'=>$volume,'startMs'=>0,'endMs'=>$context['totalMs']];
   }else{
    $id=(string)($args['instanceId']??'');if(!isset($byId[$id]))throw new DomainException('Claude referenced an unknown clip.',422);
    $clip=$byId[$id];$safe=['instanceId'=>$id];
    if($tool==='clip.look'){
     if(!in_array($args['preset']??null,['none','vivid','warm','mono','cinematic','vintage'],true))throw new DomainException('Invalid filter preset.',422);
     $safe['preset']=$args['preset'];
    }elseif($tool==='clip.transition'){
     if(!in_array($args['transition']??null,['cut','fade'],true))throw new DomainException('Invalid transition.',422);
     $safe['transition']=$args['transition'];
    }elseif($tool==='clip.overlay'){
     $text=self::validText($args['text']??null);$position=$args['position']??'bottom';$size=$args['size']??'medium';
     if(!in_array($position,['top','center','bottom'],true)||!in_array($size,['small','medium','large'],true))throw new DomainException('Invalid overlay style.',422);
     $safe+=['text'=>$text,'position'=>$position,'size'=>$size];
    }elseif($tool==='clip.mute'){
     if($clip['kind']==='photo'||!is_bool($args['muted']??null))throw new DomainException('Invalid audio change.',422);
     $safe['muted']=$args['muted'];
    }elseif($tool==='clip.trim'){
     $start=$args['startMs']??null;$end=$args['endMs']??null;$full=$clip['sourceDurationMs'];
     if($clip['kind']==='photo'||!is_int($start)||!is_int($end)||$start<0||$end<=$start||$end-$start<500||$end-$start>120000||($full>0&&$end>$full))throw new DomainException('Claude suggested an invalid trim.',422);
     $safe+=['startMs'=>$start,'endMs'=>$end];
    }
   }
   $validated[]=['tool'=>$tool,'args'=>$safe];
  }
  return ['summary'=>self::validText($proposal['summary']??'Suggested video edits',300),'operations'=>$validated,'skill'=>$skillId];
 }
 public static function systemPrompt(string $skillId):string {
  $skill=self::skill($skillId);
  return 'You are the Lucky Game Hub video editor planning assistant. Propose NON-DESTRUCTIVE edits only. '.
   'You receive clip metadata and possibly approximate timing cues extracted locally by FFmpeg (sceneCutsMs and silenceRanges, in ORIGINAL SOURCE milliseconds). '.
   'You have not watched, heard or transcribed any file: never claim to have seen footage, recognized a person, understood speech, or identified events. '.
   'If timing cues are supplied, adjust proposed clip trims within the existing source bounds; an automatic scene change is not proof of a highlight. '.
   'Use only these tools: '.implode(', ',$skill['tools']).'. '.
   'Return only a JSON object {"summary":"brief reason","operations":[{"tool":"...","args":{...}}]}. '.
   'At most 12 operations. Every referenced instanceId and trackId must exist in the provided context. '.
   'Tool arguments: timeline.reorder {order:[all instanceIds exactly once]}; clip.trim {instanceId,startMs,endMs}; '.
   'clip.look {instanceId,preset:none|vivid|warm|mono|cinematic|vintage}; clip.transition {instanceId,transition:cut|fade}; '.
   'clip.overlay {instanceId,text,position:top|center|bottom,size:small|medium|large}; '.
   'clip.mute {instanceId,muted:boolean}; timeline.title {text,startMs,durationMs,position,size}; '.
   'audio.background {trackId,volume:0..100}. Choose no operations if there is insufficient context.';
 }
}
