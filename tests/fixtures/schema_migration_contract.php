<?php
declare(strict_types=1);
require_once __DIR__.'/../../lib/SchemaMigrations.php';

/** Source wiring only; real install/upgrade behavior is certified separately. */
final class SchemaMigrationSourceContract {
 public static function wired(array $entryPoints,string $migration):bool {
  if(!in_array($migration,SchemaMigrations::files(),true))return false;
  foreach($entryPoints as $entryPoint)if(!self::delegates($entryPoint))return false;
  return $entryPoints!==[];
 }

 public static function delegates(string $entryPoint):bool {
  $source=file_get_contents(dirname(__DIR__,2).'/'.$entryPoint);
  if($source===false)throw new RuntimeException('Missing migration entry point: '.$entryPoint);
  $tokens=array_values(array_filter(token_get_all($source),static fn($token):bool=>
   !is_array($token)||!in_array($token[0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true)));
  $required=false;$delegated=false;
  foreach($tokens as $i=>$token){
   if(is_array($token)&&in_array($token[0],[T_REQUIRE,T_REQUIRE_ONCE],true)){
    for($j=$i+1;isset($tokens[$j])&&$tokens[$j]!==';';$j++){
     $part=$tokens[$j];
     if(is_array($part)&&$part[0]===T_CONSTANT_ENCAPSED_STRING&&
        preg_match('~(?:^|/)lib/SchemaMigrations\\.php$~',substr($part[1],1,-1))===1)$required=true;
    }
   }
   if(self::token($token,T_STRING,'SchemaMigrations')&&
      self::token($tokens[$i+1]??null,T_DOUBLE_COLON,'::')&&
      self::token($tokens[$i+2]??null,T_STRING,'ensure')&&($tokens[$i+3]??null)==='('&&
      (self::token($tokens[$i+4]??null,T_VARIABLE,'$db')||self::token($tokens[$i+4]??null,T_VARIABLE,'$pdo'))&&
      ($tokens[$i+5]??null)===')'&&($tokens[$i+6]??null)===';')$delegated=true;
  }
  return $required&&$delegated;
 }

 private static function token(mixed $token,int $kind,string $text):bool {
  return is_array($token)&&$token[0]===$kind&&$token[1]===$text;
 }
}
