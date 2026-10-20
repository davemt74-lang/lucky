<?php
declare(strict_types=1);
require_once __DIR__.'/MontageVisuals.php';
/** Reusable style-only templates: source recordings/claim IDs never enter templates. */
final class MontageTemplate {
 public static function validate(array $raw,array $songs=[]):array {
  $looks=$raw['looks']??[];
  if(!is_array($looks)||count($looks)>80)throw new DomainException('Invalid template look list.',422);
  $styles=[];
  foreach($looks as $look){if(!is_string($look)||!in_array($look,MontageVisuals::LOOKS,true))throw new DomainException('Unsupported template filter.',422);$styles[]=$look;}
  $titles=$raw['titles']??[];
  if(!is_array($titles)||count($titles)>24)throw new DomainException('Template supports at most 24 captions.',422);
  $clean=[];
  foreach($titles as $item){
   if(!is_array($item))throw new DomainException('Invalid template title.',422);
   $text=(string)($item['text']??'');$pos=(string)($item['position']??'bottom');$size=(string)($item['size']??'medium');
   MontageVisuals::validate('none',$text,$pos,$size);
   if(trim($text)==='')throw new DomainException('Empty template caption.',422);
   $start=$item['startPct']??0;$duration=$item['durationPct']??0;
   if(!is_numeric($start)||!is_numeric($duration)||$start<0||$duration<=0||$start+$duration>10000)
    throw new DomainException('Caption timing must fit in a relative timeline.',422);
   $clean[]=['text'=>$text,'position'=>$pos,'size'=>$size,'startPct'=>(int)$start,'durationPct'=>(int)$duration];
  }
  $music=$raw['background']??null;
  if($music!==null){
   if(!is_array($music))throw new DomainException('Invalid template music.',422);
   $song=(string)($music['trackId']??'');
   if(!preg_match('/^[a-f0-9]{32}$/D',$song)||!isset($songs[$song]))throw new DomainException('Template music must be an uploaded library track.',422);
   $start=$music['startPct']??0;$end=$music['endPct']??10000;$volume=$music['volume']??18;$offset=$music['offsetMs']??0;
   if(!is_numeric($start)||!is_numeric($end)||!is_numeric($volume)||!is_numeric($offset)||$start<0||$end<=$start||$end>10000||$volume<0||$volume>100||$offset<0||$offset>1200000)
    throw new DomainException('Invalid reusable soundtrack timing.',422);
   $music=['trackId'=>$song,'startPct'=>(int)$start,'endPct'=>(int)$end,'offsetMs'=>(int)$offset,'volume'=>(int)$volume];
  }
  return ['looks'=>$styles,'titles'=>$clean,'background'=>$music];
 }
}
