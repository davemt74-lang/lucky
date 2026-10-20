<?php
declare(strict_types=1);
/** Validated visual template vocabulary, shared by project save and rendering. */
final class MontageVisuals {
 public const LOOKS=['none','vivid','warm','mono','cinematic','vintage'];
 public const POSITIONS=['top','center','bottom'];
 public const SIZES=['small','medium','large'];
 public static function validate(string $look,string $text,string $position,string $size):void {
  if(!in_array($look,self::LOOKS,true))throw new DomainException('Unsupported video filter preset.',422);
  if(strlen($text)>480||preg_match('//u',$text)!==1||preg_match('/[\x00-\x1f\x7f]/u',$text))
   throw new DomainException('Overlay text must be valid UTF-8, up to 120 characters, without control characters.',422);
  if(function_exists('mb_strlen')&&mb_strlen($text,'UTF-8')>120)throw new DomainException('Overlay text is limited to 120 characters.',422);
  if(!in_array($position,self::POSITIONS,true)||!in_array($size,self::SIZES,true))
   throw new DomainException('Unsupported overlay position or size.',422);
 }
 public static function filters(string $look):array {
  return match($look){
   'none'=>[],
   'vivid'=>['eq=contrast=1.16:saturation=1.35'],
   'warm'=>['eq=contrast=1.06:saturation=1.16','colorbalance=rs=0.09:gs=0.02:bs=-0.08'],
   'mono'=>['hue=s=0','eq=contrast=1.12'],
   'cinematic'=>['eq=contrast=1.22:saturation=0.78:brightness=-0.06'],
   'vintage'=>['eq=contrast=0.92:saturation=0.76','colorbalance=rs=0.18:gs=0.09:bs=-0.12'],
   default=>throw new DomainException('Unsupported video filter preset.',422)
  };
 }
 public static function overlay(array $clip,string $tempDir,int $index,int $width):string {
  $text=(string)($clip['overlay_text']??'');
  if($text==='')return '';
  $pos=(string)($clip['overlay_position']??'bottom');
  $size=(string)($clip['overlay_size']??'medium');
  self::validate((string)($clip['look_preset']??'none'),$text,$pos,$size);
  $filename='overlay-'.sprintf('%03d',$index).'.txt';
  $path=$tempDir.'/'.$filename;
  if(file_put_contents($path,$text,LOCK_EX)===false)throw new RuntimeException('Cannot create caption text for the render.');
  @chmod($path,0600);
  // FFmpeg filtergraph escaping is independent from shell quoting.
  $escaped=strtr($path,['\\'=>'\\\\',':'=>'\\:',"'"=>"\\'",','=>'\\,','['=>'\\[',']'=>'\\]']);
  $scale=match($size){'small'=>0.65,'large'=>1.4,default=>1.0};
  $font=(int)round(max(18,$width/37)*$scale);
  $y=match($pos){'top'=>'h*0.08','center'=>'(h-text_h)/2',default=>'h-text_h-h*0.08'};
  return 'drawtext=textfile='.$escaped.':fontcolor=white:fontsize='.$font.':box=1:boxcolor=black@0.58:boxborderw=12:x=(w-text_w)/2:y='.$y;
 }
 /** Overlay T1 titles at their own times, independently of V1 clip boundaries. */
 public static function timelineOverlays(array $clip,string $tempDir,int $clipIndex,int $width):array {
  $out=[];
  foreach($clip['timeline_titles']??[] as $index=>$t){
   if(!is_array($t)||$index>=24)throw new DomainException('Too many T1 titles per segment.',422);
   $start=(int)($t['startMs']??0);$end=(int)($t['endMs']??0);
   if($start<0||$end<=$start)throw new DomainException('Invalid title timeline range.',422);
   $record=['look_preset'=>'none','overlay_text'=>(string)$t['text'],'overlay_position'=>(string)$t['position'],'overlay_size'=>(string)$t['size']];
   self::validate('none',$record['overlay_text'],$record['overlay_position'],$record['overlay_size']);
   $filter=self::overlay($record,$tempDir,1000+$clipIndex*24+$index,$width);
   $startSec=number_format($start/1000,3,'.','');$endSec=number_format($end/1000,3,'.','');
   $out[]=$filter.':enable=between(t\\,'.$startSec.'\\,'.$endSec.')';
  }
  return $out;
 }

}
