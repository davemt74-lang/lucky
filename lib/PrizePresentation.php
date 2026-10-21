<?php
declare(strict_types=1);
/** Phone-only metadata for a pending prize, never attached to public wheel state. */
final class PrizePresentation {
 /** Only videos uploaded to the local private prize-video store are playable. */
 public static function videoUrl(array $claim):string {
  $raw=$claim['videoUrl']??'';
  if(!is_string($raw)||$raw===''||strlen($raw)>2048||preg_match('/[\x00-\x20\x7f]/',$raw))return '';
  $url=parse_url($raw);
  if(!is_array($url)||!preg_match('~(?:^|/)api/index\.php$~',$url['path']??''))return '';
  if(isset($url['user'])||isset($url['pass'])||isset($url['fragment']))return '';
  parse_str($url['query']??'',$query);
  if(($query['action']??'')!=='prize_video'||!is_string($query['id']??null)||
     !preg_match('/^[a-f0-9]{32}$/D',$query['id'])||count($query)!==2)return '';
  // Emit a relative route, so both desktop and phone access the current HomeServer.
  return 'api/index.php?action=prize_video&id='.$query['id'];
 }
 public static function decorate(?array $offer,array $state):?array {
  require_once __DIR__.'/PrizeSmartText.php';
  if(!$offer||!in_array(($offer['status']??''),['pending','accepted'],true))return $offer;
  $claimId=(string)($offer['claimId']??'');
  foreach($state['claims']??[] as $claim){
   if(($claim['id']??'')!==$claimId)continue;
   $offer['videoUrl']=self::videoUrl($claim);
   $offer['image']=(string)($claim['image']??'');
   $offer['description']=PrizeSmartText::render((string)($claim['description']??''),$claim);
   return $offer;
  }
  $offer['videoUrl']='';$offer['image']='';$offer['description']='';
  return $offer;
 }
}
