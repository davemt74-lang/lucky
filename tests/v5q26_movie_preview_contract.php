<?php
declare(strict_types=1);
require __DIR__.'/../lib/MoviePreview.php';
require __DIR__.'/../lib/MigrationSql.php';
function check(bool $v,string $label):void {if(!$v)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label."\n";}
$session=str_repeat('a',32);
check(MoviePreview::public(null)===null,'No active preview is public');
check(MoviePreview::public(['state'=>'stopped','session_id'=>$session,'revision'=>2,'updated_at'=>gmdate('Y-m-d H:i:s')])===null,'Stopped preview cannot appear on paired phone');
check(MoviePreview::public(['state'=>'playing','session_id'=>$session,'revision'=>2,'updated_at'=>gmdate('Y-m-d H:i:s',time()-45)])===null,'Abandoned desktop preview expires');
$valid=MoviePreview::public(['state'=>'paused','session_id'=>$session,'revision'=>3,'updated_at'=>gmdate('Y-m-d H:i:s')]);
check($valid===['session'=>$session,'state'=>'paused','revision'=>3],'Phone sees bounded state without media URL or tokens');
$sql=file_get_contents(__DIR__.'/../lib/migrations/v5q26_movie_preview.sql');
$steps=MigrationSql::statements($sql);
check(count($steps)===1&&str_contains($steps[0],'CREATE TABLE IF NOT EXISTS movie_preview_state'),'Idempotent database upgrade provided');
check(str_contains($steps[0],'FOREIGN KEY fk_movie_preview_owner')&&str_contains($steps[0],'FOREIGN KEY fk_movie_preview_session'),'Preview state belongs to host and game session');
$home=file_get_contents(__DIR__.'/../homeserver/initialize.php');
check(str_contains($home,'v5q26_movie_preview.sql'),'Windows first-time HomeServer installs same preview schema');
$upgrade=file_get_contents(__DIR__.'/../upgrade.php');
check(str_contains($upgrade,'v5q26_movie_preview.sql'),'Cloud upgrades same preview schema');
$controller=file_get_contents(__DIR__.'/../api/controller.php');
check(str_contains($controller,"ControllerPairing::status(\$locked)!=='paired'")&&str_contains($controller,'MoviePreview::control($db,$owner,$session,$verb)'),'Phone playback commands remain owner-bound and paired');
