<?php
declare(strict_types=1);
require_once __DIR__.'/../lib/PrizePublicationMigration.php';
$db=new PDO('mysql:host=127.0.0.1;port=3306;dbname=lucky_test;charset=utf8mb4','root','luckytestpassword',
 [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
function ensure21(bool $condition,string $name):void {
 if(!$condition)throw new RuntimeException('FAIL '.$name);
 echo "PASS ".$name."\n";
}
$db->exec('DELETE FROM prize_package_selection');
$db->exec('DELETE FROM prize_packages');
$db->exec('ALTER TABLE prize_packages DROP COLUMN publication_status');
$id=str_repeat('a',32);
$q=$db->prepare("INSERT INTO prize_packages(id,game_id,name,config_json,enabled,player_selectable,version,sort_order) VALUES (?,'spin-wheel','Legacy Prizes','{}',1,1,1,0)");
$q->execute([$id]);
ensure21(PrizePublicationMigration::ensure($db),'old database column created by shared migration');
$q=$db->prepare('SELECT publication_status FROM prize_packages WHERE id=?');$q->execute([$id]);
ensure21($q->fetchColumn()==='published','existing packages are kept Published without data loss');
ensure21(PrizePublicationMigration::ensure($db)===false,'schema change is safe to run repeatedly at Windows startup');
$db->exec('DELETE FROM prize_packages');
echo "V5Q.21 migration compatibility PASSED\n";
