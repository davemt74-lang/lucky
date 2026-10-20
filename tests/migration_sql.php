<?php
declare(strict_types=1);
require __DIR__.'/../lib/MigrationSql.php';

function verify(bool $ok,string $name):void {
 if(!$ok)throw new RuntimeException('FAIL '.$name);
 echo 'PASS '.$name."\n";
}

$regression=file_get_contents(__DIR__.'/../lib/migrations/v5q15b_prize_decisions.sql');
verify($regression!==false,'V5Q.15B bundled migration exists');
verify(str_contains($regression,'evidence; track'),'regression includes semicolon in SQL comment');
$parsed=MigrationSql::statements($regression);
verify(count($parsed)===1,'semicolon in migration comment is not a statement delimiter');
verify(str_starts_with($parsed[0],'CREATE TABLE IF NOT EXISTS prize_decisions'),'statement starts at valid CREATE TABLE, not comment fragment');
verify(!str_contains($parsed[0],'track the explicit'),'comment excluded from executable SQL');

$sample="-- note; with a semicolon\n".
 "CREATE TABLE \x60my;table\x60 (txt VARCHAR(20) DEFAULT 'a;b');\n".
 "# another; comment\n".
 "INSERT INTO \x60my;table\x60 (txt) VALUES ('x;--keep'); /* comment; with semicolon */";
$two=MigrationSql::statements($sample);
verify(count($two)===2,'semicolons inside quoted strings, backticks and comments preserved correctly');
verify(str_contains($two[0],"'a;b'")&&str_contains($two[0],"\x60my;table\x60"),'literal text and quoted identifiers preserved');
verify(str_contains($two[1],"'x;--keep'"),'quoted SQL comment sequence remains literal');
verify(MigrationSql::statements(" -- only; a comment\n")===[],'comment-only script has no executable statements');

foreach([
 'lib/schema.sql',
 'lib/migrations/v5n.sql',
 'lib/migrations/v5p.sql',
 'lib/migrations/v5p_sync.sql',
 'lib/migrations/v5q_claim_media.sql',
 'lib/migrations/v5q3_renders.sql',
 'lib/migrations/v5q6_proof_revisions.sql',
 'lib/migrations/v5q13_capture_ui.sql',
 'lib/migrations/v5q15b_prize_decisions.sql',
 'lib/migrations/v5q15m_layers.sql',
 'lib/migrations/v5q15n_templates.sql'
] as $path) {
 $text=file_get_contents(__DIR__.'/../'.$path);
 verify($text!==false,'bundled SQL found: '.$path);
 $sql=MigrationSql::statements($text);
 verify(count($sql)>0,'bundled SQL has executable statements: '.$path);
 foreach($sql as $statement){
  verify((bool)preg_match('/^(CREATE|ALTER|INSERT|UPDATE)\s/i',$statement),'statement starts with SQL operation: '.$path);
 }
}
echo "V5Q.15D migration comment regression passed.\n";
