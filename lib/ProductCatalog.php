<?php
declare(strict_types=1);
/** Commerce-ready catalog data only. No charges, orders or implied payment fulfillment. */
final class ProductCatalog {
 public const CATEGORIES=['game','hardware','accessory','other'];
 public const STATUSES=['draft','available','coming_soon'];
 public static function httpsUrl(mixed $candidate):string {
  $url=trim((string)$candidate);
  if($url==='')return '';
  if(strlen($url)>2048||preg_match('/[\x00-\x1f\x7f]/',$url))throw new DomainException('Invalid product URL.',422);
  $parts=parse_url($url);
  if(!is_array($parts)||strtolower($parts['scheme']??'')!=='https'||empty($parts['host'])||isset($parts['user'])||isset($parts['pass']))
   throw new DomainException('Product links must use HTTPS and cannot contain passwords.',422);
  return $url;
 }
 public static function validate(array $in):array {
  $title=trim((string)($in['title']??''));
  $summary=trim((string)($in['summary']??''));
  $category=(string)($in['category']??'other');$status=(string)($in['status']??'draft');
  $price=$in['priceCents']??null;
  if($title===''||mbSafeLen($title)>120||mbSafeLen($summary)>600)throw new DomainException('Product title or description is invalid.',422);
  if(!in_array($category,self::CATEGORIES,true)||!in_array($status,self::STATUSES,true))throw new DomainException('Unknown product category or status.',422);
  if(!is_int($price)||$price<0||$price>999999999)throw new DomainException('Product price must be an integer number of cents.',422);
  $image=self::httpsUrl($in['imageUrl']??'');$purchase=self::httpsUrl($in['purchaseUrl']??'');
  if($status==='available' && !$purchase)throw new DomainException('Available products require a secure purchase link.',422);
  $sort=$in['sortOrder']??0;
  if(!is_int($sort)||$sort<0||$sort>9999)throw new DomainException('Invalid display order.',422);
  return ['title'=>$title,'summary'=>$summary,'category'=>$category,'status'=>$status,'priceCents'=>$price,'imageUrl'=>$image,'purchaseUrl'=>$purchase,'sortOrder'=>$sort];
 }
}
function mbSafeLen(string $text):int {return function_exists('mb_strlen')?mb_strlen($text,'UTF-8'):strlen($text);}
