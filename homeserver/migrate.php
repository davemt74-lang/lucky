<?php
declare(strict_types=1);
// Run only from the local bundled PHP CLI, never from a browser.
if(PHP_SAPI!=='cli'||getenv('LUCKY_BOOTSTRAP')!=='homeserver'){
 fwrite(STDERR,"HomeServer CLI only.\n");exit(3);
}
require_once __DIR__.'/../lib/Bootstrap.php';
require_once __DIR__.'/../lib/PrizePublicationMigration.php';
$db=db();
require_once __DIR__.'/../lib/MigrationSql.php';
foreach(MigrationSql::statements(sql('lib/migrations/v5q26_movie_preview.sql')) as $statement)$db->exec($statement);
// Keep offline first installs and upgrades at the full Recording Studio schema.
foreach(['v5q29e1_recording_sessions.sql','v5q29e2_phone_camera.sql',
         'v5q29e3_multicamera_coordination.sql','v5q29e4a_multicamera_timeline.sql',
         'v5q29e4b_multicamera_exports.sql','v5q29e4c_recording_profiles.sql',
         'v5q29e4d1hf8c4_director_recordings.sql'] as $migration)
 foreach(MigrationSql::statements(sql('lib/migrations/'.$migration)) as $statement)$db->exec($statement);
foreach(['recording_sessions','recording_takes','recording_sources','recording_assets',
         'recording_phone_permissions','recording_media_files','recording_camera_arms',
         'recording_capture_sync','recording_timeline_edits','recording_exports','recording_session_profiles',
         'director_broadcast_recordings','director_recording_scenes'] as $table)
 if(!$db->query("SHOW TABLES LIKE ".$db->quote($table))->fetch())
  throw new RuntimeException('HomeServer recording schema not ready: '.$table);
PrizePublicationMigration::ensure($db);
require_once __DIR__.'/../lib/PrizePackages.php';
PrizePackages::ensureBudgetColumn($db);
PrizePackages::ensurePhoneCameraColumn($db);
require_once __DIR__.'/../lib/GamePluginCapabilities.php';
GamePluginCapabilities::ensureSchema($db);
echo "HomeServer schema current.\n";
