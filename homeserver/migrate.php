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
PrizePublicationMigration::ensure($db);
require_once __DIR__.'/../lib/PrizePackages.php';
PrizePackages::ensureBudgetColumn($db);
require_once __DIR__.'/../lib/GamePluginCapabilities.php';
GamePluginCapabilities::ensureSchema($db);
echo "HomeServer schema current.\n";
