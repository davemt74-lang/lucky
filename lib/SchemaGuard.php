<?php
declare(strict_types=1);
/** Explicit upgrade diagnostics for existing installs; never creates tables from a phone or read-only request. */
final class SchemaGuard {
 public const PHONE=['controller_pairs','controller_commands','controller_ui_events'];
 public const PROOF=['claim_media','claim_proof_state','claim_proof_revisions','claim_proof_revision_clips'];
 public static function missing(PDO $db,array $names):array {
  if(!$names)return [];
  foreach($names as $name)if(!is_string($name)||!preg_match('/^[a-z][a-z0-9_]{0,63}$/D',$name))throw new InvalidArgumentException('Invalid schema table name.');
  $q=$db->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()');
  $available=array_fill_keys($q->fetchAll(PDO::FETCH_COLUMN),true);
  return array_values(array_filter($names,fn($name)=>!isset($available[$name])));
 }
 public static function require(PDO $db,array $names,string $feature):void {
  $missing=self::missing($db,$names);
  if($missing)throw new DomainException($feature.' needs a database upgrade. Sign in as the host, open upgrade.php and click Run Database Repair. Missing: '.implode(', ',$missing),409);
 }
}
