<?php
declare(strict_types=1);
/** Bounded, deterministic prize announcement templates; no expressions or HTML. */
final class PrizeSmartText {
 public static function validate(mixed $text):string {
  if(!is_string($text)||strlen($text)>600||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$text))
   throw new DomainException('Prize description must be plain text up to 600 characters.',422);
  preg_match_all('/\{([^{}]+)\}/',$text,$tags);
  foreach($tags[1] as $name)if(!in_array($name,['prize_name','prize_value','prize_tier'],true))
   throw new DomainException('Unknown smart tag: {'.$name.'}',422);
  return trim($text);
 }
 public static function render(string $text,array $prize):string {
  $text=self::validate($text);
  if($text==='')$text='Congratulations! You won {prize_name}!';
  $tier=['tier1'=>'Tier 1','tier2'=>'Tier 2','bonus'=>'Bonus'][$prize['tier']??'']??'Prize';
  return strtr($text,['{prize_name}'=>(string)($prize['name']??'Prize'),
    '{prize_value}'=>'$'.number_format((int)($prize['cents']??0)/100,2),'{prize_tier}'=>$tier]);
 }
}
