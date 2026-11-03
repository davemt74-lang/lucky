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
  $setup=file_get_contents(dirname(__DIR__,2).'/lib/InstallationSetup.php');
  if($setup===false)throw new RuntimeException('Missing shared installation service.');
  return self::sourceDelegates($source,$setup);
 }

 /** Pure source recognizer: synthetic negative controls cannot mutate repository code. */
 public static function sourceDelegates(string $source,string $setupSource):bool {
  $tokens=self::tokens($source);
  if(self::imports($tokens,'SchemaMigrations')&&self::calls($tokens,'SchemaMigrations','ensure',false)===1)return true;
  if(!self::imports($tokens,'InstallationSetup')||self::calls($tokens,'InstallationSetup','run',true)!==1)return false;
  $setup=self::tokens($setupSource);
  return self::imports($setup,'SchemaMigrations')&&self::calls($setup,'SchemaMigrations','ensure',false)===1;
 }

 private static function tokens(string $source):array {
  return array_values(array_filter(token_get_all($source),static fn($token):bool=>
   !is_array($token)||!in_array($token[0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true)));
 }
 private static function imports(array $tokens,string $class):bool {
  foreach($tokens as $i=>$token){
   if(is_array($token)&&in_array($token[0],[T_REQUIRE,T_REQUIRE_ONCE],true)){
    for($j=$i+1;isset($tokens[$j])&&$tokens[$j]!==';';$j++){
     $part=$tokens[$j];
     if(is_array($part)&&$part[0]===T_CONSTANT_ENCAPSED_STRING&&in_array(substr($part[1],1,-1),
        ['/lib/'.$class.'.php','/../lib/'.$class.'.php','/'.$class.'.php'],true))return true;
    }
   }
  }
  return false;
 }
 private static function calls(array $tokens,string $class,string $method,bool $moreArguments):int {
  $count=0;
  foreach($tokens as $i=>$token){
   if(self::token($token,T_STRING,$class)&&
      self::token($tokens[$i+1]??null,T_DOUBLE_COLON,'::')&&
      self::token($tokens[$i+2]??null,T_STRING,$method)&&($tokens[$i+3]??null)==='('&&
      (self::token($tokens[$i+4]??null,T_VARIABLE,'$db')||self::token($tokens[$i+4]??null,T_VARIABLE,'$pdo'))&&
      ($moreArguments?($tokens[$i+5]??null)===',':(($tokens[$i+5]??null)===')'&&($tokens[$i+6]??null)===';')))++$count;
  }
  return $count;
 }

 private static function token(mixed $token,int $kind,string $text):bool {
  return is_array($token)&&$token[0]===$kind&&$token[1]===$text;
 }
}
