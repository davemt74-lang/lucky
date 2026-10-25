<?php
declare(strict_types=1);
require_once __DIR__.'/../lib/RecordingSessions.php';
require_once __DIR__.'/../lib/MigrationSql.php';
function certifyRec(bool $ok,string $label):void{if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label."\n";}
certifyRec(RecordingSessions::TYPES===['game','live_event','production','general'],'Universal recording types are explicit and bounded');
certifyRec(RecordingSessions::SOURCE_KINDS===['desktop','phone','camera','upload'],'Sources span desktop, phone, and independent recordings');
certifyRec(RecordingSessions::id(str_repeat('a',32))===str_repeat('a',32),'Opaque recording ID accepted');
foreach(['','../secret',str_repeat('z',32)] as $invalid){
 try{RecordingSessions::id($invalid);throw new RuntimeException('Invalid ID accepted');}
 catch(DomainException $e){certifyRec($e->getCode()===422,'Malformed recording identifier rejected');}
}
$sql=(string)file_get_contents(__DIR__.'/../lib/migrations/v5q29e1_recording_sessions.sql');
$statements=MigrationSql::statements($sql);
certifyRec(count($statements)===4,'Four idempotent recording tables are bundled');
certifyRec(count(array_filter($statements,static fn(string $stmt):bool=>(bool)preg_match('/^CREATE TABLE IF NOT EXISTS recording_/',trim($stmt))))===4,'No destructive data migration');
certifyRec(str_contains($sql,'FOREIGN KEY fk_recording_asset_claim')&&str_contains($sql,'ON DELETE CASCADE'),'Original claim assets stay referenced through foreign keys');
$api=(string)file_get_contents(__DIR__.'/../api/recording-sessions.php');
certifyRec(str_contains($api,'requireAdmin()')&&str_contains($api,'assertCsrf()'),'Recording writes require admin authorization and CSRF');
certifyRec(str_contains($api,"'import_game'")&&str_contains($api,"'take_start'")&&str_contains($api,"'attach_claim'"),'Recording API offers non-destructive import and grouped takes');
certifyRec(str_contains((string)file_get_contents(__DIR__.'/../install.php'),'v5q29e1_recording_sessions.sql')&&str_contains((string)file_get_contents(__DIR__.'/../upgrade.php'),'v5q29e1_recording_sessions.sql'),'Both first install and upgrade load recording migration');
echo 'V5Q.29E1 RECORDING SESSIONS CONTRACT PASSED'."\n";
