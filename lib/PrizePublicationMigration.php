<?php
declare(strict_types=1);

/**
 * Idempotent schema change shared by Cloud upgrades and local HomeServer boot.
 * Existing packages are published so upgrades never silently remove playable prizes.
 */
final class PrizePublicationMigration {
 public static function ensure(PDO $db):bool {
  $q=$db->query("SHOW TABLES LIKE 'prize_packages'");
  if(!$q->fetchColumn())return false; // Older installation: base migration runs first.
  $q=$db->query("SHOW COLUMNS FROM prize_packages LIKE 'publication_status'");
  if($q->fetch())return false;
  $db->exec("ALTER TABLE prize_packages ADD COLUMN publication_status VARCHAR(12) NOT NULL DEFAULT 'published' AFTER enabled");
  return true;
 }
}
