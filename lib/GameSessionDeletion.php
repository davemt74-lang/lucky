<?php
declare(strict_types=1);
final class GameSessionDeletion {
 public static function confirm(string $id,string $input):bool {
  return preg_match('/^[a-f0-9]{32}$/D',$id)===1 && hash_equals('DELETE '.$id,$input);
 }
}
