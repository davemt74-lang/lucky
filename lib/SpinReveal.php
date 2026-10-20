<?php
declare(strict_types=1);
/** A persisted spin is only publishable after the host wheel has stopped.
 * A bounded fallback permits recovery if the host browser closed mid-spin.
 */
final class SpinReveal {
 public const MAX_PENDING_SECONDS=15;
 public static function pending(array $state,?int $now=null):bool {
  $spin=$state['lastSpin']??null;
  if(!is_array($spin)||!in_array($spin['type']??null,['award','bonus_trigger'],true)||!empty($spin['revealedAt']))return false;
  $at=strtotime((string)($spin['at']??''));
  if($at===false)return false;
  return ($now??time())-$at<self::MAX_PENDING_SECONDS;
 }
 public static function complete(array &$state,int $spinCount):bool {
  $spin=$state['lastSpin']??null;
  if(!is_array($spin)||($spin['spinCount']??null)!==$spinCount)throw new DomainException('This spin is no longer current.',409);
  if(!empty($spin['revealedAt']))return false;
  $state['lastSpin']['revealedAt']=gmdate('c');
  $state['version']++;
  return true;
 }
}
