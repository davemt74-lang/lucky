<?php
declare(strict_types=1);
/** Independent T1 titles and B1 background music, stored apart from claim-aware V1 clips. */
final class MontageLayers {
 public static function normalize(array $raw,int $totalMs,array $availableSongs=[]):array {
  if($totalMs<0||$totalMs>1200000)throw new DomainException('Timeline duration outside render limits.',422);
  $titles=$raw['titles']??[];
  if(!is_array($titles)||count($titles)>24)throw new DomainException('A timeline supports up to 24 titles.',422);
  $out=[];$seen=[];
  foreach($titles as $title){
   if(!is_array($title))throw new DomainException('Invalid title track item.',422);
   $id=(string)($title['id']??'');$text=(string)($title['text']??'');
   $pos=(string)($title['position']??'bottom');$size=(string)($title['size']??'medium');
   $start=$title['startMs']??0;$length=$title['durationMs']??3000;
   if(!preg_match('/^[a-zA-Z0-9-]{1,80}$/D',$id)||isset($seen[$id]))throw new DomainException('Invalid or duplicate title segment.',422);
   if(!is_numeric($start)||!is_numeric($length)||$start<0||$length<300||$length>1200000||
       $start>=max(1,$totalMs)||$start+$length>$totalMs)throw new DomainException('Title must fit inside the video timeline.',422);
   require_once __DIR__.'/MontageVisuals.php';
   MontageVisuals::validate('none',$text,$pos,$size);
   if(trim($text)==='')throw new DomainException('Title text cannot be empty.',422);
   $seen[$id]=1;
   $out[]=['id'=>$id,'text'=>$text,'position'=>$pos,'size'=>$size,'startMs'=>(int)$start,'durationMs'=>(int)$length];
  }
  $bg=$raw['background']??null;$music=null;
  if($bg!==null){
   if(!is_array($bg))throw new DomainException('Invalid background audio track.',422);
   $song=(string)($bg['trackId']??'');
   $start=$bg['startMs']??0;$end=$bg['endMs']??$totalMs;$offset=$bg['offsetMs']??0;$gain=$bg['volume']??18;
   if(!preg_match('/^[a-f0-9]{32}$/D',$song)||!isset($availableSongs[$song]))
    throw new DomainException('Choose a saved uploaded audio track from the library.',422);
   foreach([$start,$end,$offset,$gain] as $v)if(!is_numeric($v))throw new DomainException('Invalid music timing.',422);
   if($start<0||$end<=$start||$end>$totalMs||$offset<0||$offset>1200000||$gain<0||$gain>100)
    throw new DomainException('Background audio timing or volume outside limits.',422);
   $music=['trackId'=>$song,'startMs'=>(int)$start,'endMs'=>(int)$end,'offsetMs'=>(int)$offset,'volume'=>(int)$gain];
  }
  return ['titles'=>$out,'background'=>$music];
 }
 public static function apply(array $clips,array $layers):array {
  $elapsed=0;
  foreach($clips as &$clip){
   $duration=MontagePlan::ms($clip);$clip['timeline_titles']=[];
   foreach($layers['titles']??[] as $t){
    $from=max($elapsed,(int)$t['startMs']);$to=min($elapsed+$duration,(int)$t['startMs']+(int)$t['durationMs']);
    if($to<=$from)continue;
    $clip['timeline_titles'][]=['text'=>$t['text'],'position'=>$t['position'],'size'=>$t['size'],
     'startMs'=>$from-$elapsed,'endMs'=>$to-$elapsed];
   }
   $elapsed+=$duration;
  }unset($clip);
  if($clips)$clips[0]['music_layer']=$layers['background']??null;
  return $clips;
 }
}
