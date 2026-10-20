<?php
declare(strict_types=1);
// Run only from the local bundled PHP CLI, never from a browser.
if(PHP_SAPI!=='cli'||getenv('LUCKY_BOOTSTRAP')!=='homeserver'){
 fwrite(STDERR,"HomeServer CLI only.\n");exit(3);
}
require_once __DIR__.'/../lib/Bootstrap.php';
require_once __DIR__.'/../lib/PrizePublicationMigration.php';
$db=db();
PrizePublicationMigration::ensure($db);
echo "HomeServer schema current.\n";
