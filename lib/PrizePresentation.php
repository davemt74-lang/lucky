<?php
declare(strict_types=1);
/** Phone-only metadata for a pending prize, never attached to public wheel state. */
final class PrizePresentation {
 public static function videoUrl(array $claim):string {
  foreach(['videoUrl','url'] as $field){
   $candidate=(string)($claim[$field]??'');
   if($candidate===''||strlen($candidate)>2048||preg_match('/[\\x00-\\x20\\x7f]/',$candidate))continue;
   $parts=parse_url($candidate);
   if(!is_array($parts)||strtolower($parts['scheme']??'')!=='https'||empty($parts['host'])||isset($parts['user'])||isset($parts['pass']))continue;
   $host=strtolower((string)$parts['host']);
   $path=(string)($parts['path']??'');
   $direct=(bool)preg_match('/\\.(?:mp4|m4v|webm|ogv)$/i',$path);
   $youtube=in_array($host,['youtube.com','www.youtube.com','m.youtube.com','youtu.be','www.youtu.be','youtube-nocookie.com','www.youtube-nocookie.com'],true);
   $vimeo=in_array($host,['vimeo.com','www.vimeo.com','player.vimeo.com'],true);
   if($direct||$youtube||$vimeo)return $candidate;
  }
  return '';
 }
 public static function decorate(?array $offer,array $state):?array {
  if(!$offer||($offer['status']??'')!=='pending')return $offer;
  $claimId=(string)($offer['claimId']??'');
  foreach($state['claims']??[] as $claim){
   if(($claim['id']??'')!==$claimId)continue;
   $offer['videoUrl']=self::videoUrl($claim);
   return $offer;
  }
  $offer['videoUrl']='';
  return $offer;
 }
}
