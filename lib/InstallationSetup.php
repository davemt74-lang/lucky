<?php
declare(strict_types=1);
require_once __DIR__.'/SchemaMigrations.php';
require_once __DIR__.'/GameEngine.php';

/**
 * Resumable application setup, not an account reset or data import.
 *
 * The caller holds SetupConfiguration's directory lock and stages a complete
 * private configuration before calling run(). Database DDL precedes the DML
 * transaction. The committed receipt survives a failed/crashed publication;
 * a retry must prove the same local attempt, configuration and administrator.
 */
final class InstallationSetup {
 private const LOCK_WAIT_SECONDS=5;

 /** Read-only proof before the caller creates private staging/attempt metadata. */
 public static function authorizeExistingAdministrator(PDO $db,array $credentials):void {
  self::validateAdministrator($credentials);
  $kind=self::scalar($db,"SELECT TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='admins'");
  if($kind===false)return;
  if($kind!=='BASE TABLE')throw new RuntimeException('The administrator table is incompatible. Restore the original database before setup.');
  if((int)self::scalar($db,'SELECT COUNT(*) FROM admins')===0)return;
  $matches=self::rows($db,'SELECT password_hash FROM admins WHERE username=?',[$credentials['username']]);
  if(!isset($matches[0])||!password_verify($credentials['password'],(string)$matches[0]['password_hash']))
   throw new DomainException('Existing administrator credentials are required to recover this installation.',403);
  // run() must repeat this proof under its setup lock and DML transaction.
 }

 /**
  * @param array{username:string,password:string}|null $adminCredentials
  * @return array{deployment:string,admin_id:?int,resumed:bool,state:string}
  */
 public static function run(PDO $db,string $deployment,string $attemptToken,string $configHmac,
  ?array $adminCredentials,callable $publish):array {
  if($db->inTransaction())throw new RuntimeException('Finish the current transaction before starting installation.');
  if(!in_array($deployment,['cloud','homeserver'],true)||
     !preg_match('/\A[a-f0-9]{64}\z/D',$attemptToken)||!preg_match('/\A[a-f0-9]{64}\z/D',$configHmac))
   throw new RuntimeException('Invalid installation attempt identity.');
  if($deployment==='cloud')self::validateAdministrator($adminCredentials);
  elseif($adminCredentials!==null)throw new RuntimeException('HomeServer initialization must not create or import an administrator.');

  $database=self::scalar($db,'SELECT DATABASE()');
  if(!is_string($database)||$database==='')throw new RuntimeException('Select the application database before starting installation.');
  $lock='lucky-setup-'.substr(hash('sha256',$database),0,48);
  try{$acquired=self::scalar($db,'SELECT GET_LOCK(?, ?)',[$lock,self::LOCK_WAIT_SECONDS]);}
  catch(Throwable $e){throw new RuntimeException('Installation requires MySQL/MariaDB advisory locks. Check database support and permissions.',0,$e);}
  if((string)$acquired!=='1')throw new RuntimeException('Another installation is using this database. Retry shortly.');

  $failure=null;
  try{
   // Serialize setup before any DDL: a second installer must not request
   // metadata locks against another attempt's live seed transaction.
   SchemaMigrations::ensure($db);
   // Older MySQL variants cannot retain more than one named lock. Do not
   // silently proceed without our setup lock after the schema helper releases
   // its own independent lock; fail before any account/default DML instead.
   if((string)self::scalar($db,'SELECT IS_USED_LOCK(?) = CONNECTION_ID()',[$lock])!=='1')
    throw new RuntimeException('The database did not retain the installation lock during schema setup. Use a MySQL/MariaDB version that supports multiple named locks.');
   if(!$db->beginTransaction())throw new RuntimeException('Could not start the installation transaction.');
   $receipts=self::rows($db,'SELECT id,deployment,attempt_hash,config_hmac,admin_id,state FROM installation_setup_receipt FOR UPDATE');
   if(count($receipts)>1||(isset($receipts[0])&&(int)$receipts[0]['id']!==1))
    throw new RuntimeException('Installation receipt is inconsistent. Restore its original state before continuing.');
   $receipt=$receipts[0]??null;
   $attemptHash=hash('sha256',$attemptToken);
   if($receipt!==null){
    if($receipt['deployment']!==$deployment||!hash_equals((string)$receipt['attempt_hash'],$attemptHash)||
       !hash_equals((string)$receipt['config_hmac'],$configHmac)||
       !in_array($receipt['state'],['database_ready','published'],true))
     throw new RuntimeException('This database belongs to a different installation attempt. Restore the matching local setup identity; no accounts or configuration were replaced.');
    if(($deployment==='cloud'&&(!is_numeric($receipt['admin_id'])||(int)$receipt['admin_id']<1))||
       ($deployment==='homeserver'&&$receipt['admin_id']!==null))
     throw new RuntimeException('Installation administrator receipt is inconsistent. No account was changed.');
   }

   $adminId=null;
   if($deployment==='cloud'){
    $existing=(int)self::scalar($db,'SELECT COUNT(*) FROM admins');
    if($existing>0){
     $matches=self::rows($db,'SELECT id,password_hash FROM admins WHERE username=? FOR UPDATE',[$adminCredentials['username']]);
     $admin=$matches[0]??null;
     if($admin===null||!password_verify($adminCredentials['password'],(string)$admin['password_hash'])||
        ($receipt!==null&&(int)$receipt['admin_id']!==(int)$admin['id']))
      throw new DomainException('Existing administrator credentials are required to recover this installation.',403);
     $adminId=(int)$admin['id'];
    }else{
     if($receipt!==null)throw new RuntimeException('The original installation administrator is missing. Restore the database; setup will not create a replacement.');
     $hash=password_hash($adminCredentials['password'],PASSWORD_DEFAULT);
     self::execute($db,'INSERT INTO admins(username,password_hash) VALUES (?,?)',[$adminCredentials['username'],$hash]);
     $adminId=(int)$db->lastInsertId();
     if($adminId<1)throw new RuntimeException('Could not identify the new installation administrator.');
    }
   }

   // Existing game settings are authoritative. Only a genuinely absent default
   // is added; do not use INSERT IGNORE to hide unrelated SQL/constraint errors.
   foreach(self::defaults() as $settings){
    if(self::scalar($db,'SELECT game_id FROM game_settings WHERE game_id=? FOR UPDATE',[$settings[0]])!==false)continue;
    self::execute($db,'INSERT INTO game_settings(game_id,config_json,intro_json,theme_json) VALUES (?,?,?,?)',$settings);
   }
   if($receipt===null)self::execute($db,
    'INSERT INTO installation_setup_receipt(id,deployment,attempt_hash,config_hmac,admin_id,state) VALUES (1,?,?,?,?,?)',
    [$deployment,$attemptHash,$configHmac,$adminId,'database_ready']);
   if(!$db->commit())throw new RuntimeException('Could not commit installation data. Retry with the same setup details.');

   // Never publish a configuration before setup DML is durable. If publication
   // fails, the receipt and account remain intact for the same authenticated
   // attempt. Keep the setup lock until publication/bookkeeping has completed.
   $publish();
   if($receipt===null||$receipt['state']==='database_ready'){
    $marked=self::execute($db,
     "UPDATE installation_setup_receipt SET state='published' WHERE id=1 AND deployment=? AND attempt_hash=? AND config_hmac=? AND state='database_ready'",
     [$deployment,$attemptHash,$configHmac]);
    if($marked->rowCount()!==1)throw new RuntimeException('Configuration was published, but setup bookkeeping could not be confirmed. Preserve the existing configuration and database.');
   }
   return ['deployment'=>$deployment,'admin_id'=>$adminId,'resumed'=>$receipt!==null,'state'=>'published'];
  }catch(Throwable $e){
   $failure=$e;
   if($db->inTransaction()){
    try{$db->rollBack();}catch(Throwable $rollback){error_log('Lucky installation transaction rollback failed; close the database connection.');}
   }
   throw $e;
  }finally{
   try{
    if((string)self::scalar($db,'SELECT RELEASE_LOCK(?)',[$lock])!=='1')
     throw new RuntimeException('Could not release the installation lock. Close the database connection before retrying.');
   }catch(Throwable $e){
    if($failure===null)throw $e;
    error_log('Lucky installation also failed to release its database lock; close the connection.');
   }
  }
 }

 private static function validateAdministrator(?array $credentials):void {
  if($credentials===null||!is_string($credentials['username']??null)||!is_string($credentials['password']??null)||
     !preg_match('/\A[A-Za-z0-9_.-]{3,80}\z/D',$credentials['username'])||strlen($credentials['password'])<12||
     strlen($credentials['password'])>256||str_contains($credentials['password'],"\0"))
   throw new DomainException('Use a 3+ character administrator name and a 12+ character password.',422);
 }

 /** @return list<array{string,string,string,string}> */
 private static function defaults():array {
  return [
   ['spin-wheel',json_encode(GameEngine::defaults(),JSON_THROW_ON_ERROR),
    json_encode(['title'=>'Spin the Wheel','subtitle'=>'Let the prizes begin!','trackId'=>'','volume'=>70,'announcement'=>true],JSON_THROW_ON_ERROR),
    json_encode(['accent'=>'#f6cb74','background'=>'#0d1423'],JSON_THROW_ON_ERROR)],
   ['game-2','{"pools":[]}',
    json_encode(['title'=>'Game 2','subtitle'=>'Coming soon','trackId'=>'','volume'=>70,'announcement'=>true],JSON_THROW_ON_ERROR),
    json_encode(['accent'=>'#91b4ff','background'=>'#0d1423'],JSON_THROW_ON_ERROR)],
  ];
 }
 private static function execute(PDO $db,string $sql,array $parameters=[]):PDOStatement {
  $query=$db->prepare($sql);
  if(!$query||!$query->execute($parameters))throw new RuntimeException('Could not persist or inspect installation state.');
  return $query;
 }
 private static function rows(PDO $db,string $sql,array $parameters=[]):array {
  return self::execute($db,$sql,$parameters)->fetchAll(PDO::FETCH_ASSOC);
 }
 private static function scalar(PDO $db,string $sql,array $parameters=[]):mixed {
  return self::execute($db,$sql,$parameters)->fetchColumn();
 }
}
