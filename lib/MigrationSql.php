<?php
declare(strict_types=1);

/**
 * Split bundled SQL migrations at statement delimiters only, never within a
 * comment or quoted value. Migrations are fixed files shipped with the app.
 */
final class MigrationSql {
 public static function statements(string $sql):array {
  $statements=[];$buffer='';$quote=null;$length=strlen($sql);
  for($i=0;$i<$length;$i++){
   $char=$sql[$i];$next=$sql[$i+1]??'';
   if($quote!==null){
    $buffer.=$char;
    if($char==='\\'&&$i+1<$length){$buffer.=$sql[++$i];continue;}
    if($char===$quote){
     if($next===$quote){$buffer.=$sql[++$i];continue;}
     $quote=null;
    }
    continue;
   }
   if($char==="'"||$char==='"'||$char==='\x60'){$quote=$char;$buffer.=$char;continue;}
   if($char==='-'&&$next==='-'&&($i+2===$length||ctype_space($sql[$i+2]))){
    $i+=2;
    while($i<$length&&$sql[$i]!=="\n")$i++;
    $buffer.="\n";
    continue;
   }
   if($char==='#'){
    while($i<$length&&$sql[$i]!=="\n")$i++;
    $buffer.="\n";
    continue;
   }
   if($char==='/'&&$next==='*'){
    $start=$i;
    $end=strpos($sql,'*/',$i+2);
    if($end===false)throw new RuntimeException('Unterminated SQL comment in migration at byte '.$start);
    $i=$end+1;$buffer.=' ';continue;
   }
   if($char===';'){
    $statement=trim($buffer);
    if($statement!=='')$statements[]=$statement;
    $buffer='';continue;
   }
   $buffer.=$char;
  }
  if($quote!==null)throw new RuntimeException('Unterminated quoted value in SQL migration.');
  $tail=trim($buffer);
  if($tail!=='')$statements[]=$tail;
  return $statements;
 }
}
