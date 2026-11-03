<?php
declare(strict_types=1);
/**
 * Real MariaDB migration regression. Requires the existing isolated CI service:
 * CI=true, 127.0.0.1:3306, root/luckytestpassword. No skips and no production DB.
 * Own random databases and a private copied application are always cleaned up.
 */
if(getenv('CI')!=='true')throw new RuntimeException('Schema migration database certification requires CI=true and the isolated MariaDB service.');
if(!extension_loaded('pdo_mysql')||!extension_loaded('fileinfo'))throw new RuntimeException('Schema migration database certification requires pdo_mysql and fileinfo.');

$checks=0;
function schemaDbCheck(bool $ok,string $message):void {
 global $checks;
 if(!$ok)throw new RuntimeException('FAIL '.$message);
 ++$checks;echo 'PASS '.$message."\n";
}
function schemaDbThrows(callable $run,string $message):Throwable {
 try{$run();}catch(Throwable $e){schemaDbCheck(true,$message);return $e;}
 throw new RuntimeException('FAIL '.$message.' (no exception)');
}
function schemaDbCopy(string $from,string $to):void {
 if(!mkdir($to,0700,true)&&!is_dir($to))throw new RuntimeException('Cannot create isolated migration fixture.');
 foreach(new DirectoryIterator($from) as $item){
  if($item->isDot())continue;
  if($item->isLink())throw new RuntimeException('Unexpected source symlink.');
  $target=$to.'/'.$item->getFilename();
  if($item->isDir())schemaDbCopy($item->getPathname(),$target);
  elseif(!copy($item->getPathname(),$target))throw new RuntimeException('Cannot copy migration fixture.');
 }
}
function schemaDbRemove(string $path):void {
 if(is_link($path)||!is_dir($path)){if(file_exists($path)||is_link($path))unlink($path);return;}
 foreach(new DirectoryIterator($path) as $item)if(!$item->isDot())schemaDbRemove($item->getPathname());
 rmdir($path);
}
function schemaDbConnect(string $name=''):PDO {
 $db=new PDO('mysql:host=127.0.0.1;port=3306;'.($name!==''?'dbname='.$name.';':'').'charset=utf8mb4','root','luckytestpassword',[
  PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
 $db->exec("SET time_zone = '+00:00'");return $db;
}
function schemaDbTables(PDO $db):array {
 $names=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);sort($names,SORT_STRING);return $names;
}
function schemaDbColumns(PDO $db):array {
 $out=[];
 foreach(schemaDbTables($db) as $table){
  if($table==='user_fixture_notes')continue;
  if(!preg_match('/^[a-z][a-z0-9_]*$/D',$table))throw new RuntimeException('Unsafe fixture table identifier.');
  foreach($db->query('SHOW COLUMNS FROM `'.$table.'`')->fetchAll() as $column){
   // Column order/index names are not promises of a legacy ALTER migration.
   $out[$table][$column['Field']]=array_intersect_key($column,array_flip(['Type','Null','Default','Extra']));
  }
  ksort($out[$table]);
 }
 return $out;
}
function schemaDbSnapshot(PDO $db):array {
 $out=[];
 foreach(schemaDbTables($db) as $table){
  if(!preg_match('/^[a-z][a-z0-9_]*$/D',$table))throw new RuntimeException('Unsafe fixture table identifier.');
  $rows=[];
  foreach($db->query('SELECT * FROM `'.$table.'`')->fetchAll() as $row){ksort($row);$rows[]=json_encode($row,JSON_THROW_ON_ERROR);}
  sort($rows,SORT_STRING);$out[$table]=$rows;
 }
 return $out;
}
function schemaDbRow(PDO $db,string $table,string $key,string|int $id):array {
 if(!preg_match('/^[a-z][a-z0-9_]*$/D',$table)||!preg_match('/^[a-z][a-z0-9_]*$/D',$key))throw new RuntimeException('Unsafe fixture query.');
 $q=$db->prepare('SELECT * FROM `'.$table.'` WHERE `'.$key.'`=?');$q->execute([$id]);
 $row=$q->fetch();if(!$row)throw new RuntimeException('Fixture row missing: '.$table);return $row;
}
function schemaDbLockName(string $database):string {return 'lucky-schema-'.substr(hash('sha256',$database),0,48);}
function schemaDbLockFree(PDO $db,string $database):bool {
 $q=$db->prepare('SELECT IS_FREE_LOCK(?)');$q->execute([schemaDbLockName($database)]);return (int)$q->fetchColumn()===1;
}

$fixture=rtrim(sys_get_temp_dir(),DIRECTORY_SEPARATOR).'/lucky-schema-g4-'.bin2hex(random_bytes(8));
$databases=[];$root=null;$fresh=null;$legacy=null;$blocker=null;
try{
 mkdir($fixture,0700);schemaDbCopy(__DIR__.'/../lib',$fixture.'/lib');mkdir($fixture.'/storage',0700);
 define('LUCKY_TESTING',true);
 require $fixture.'/lib/Bootstrap.php';
 require_once $fixture.'/lib/SchemaMigrations.php';
 require_once $fixture.'/lib/ProofHandoff.php';
 require_once $fixture.'/lib/PhoneMediaChunks.php';
 require_once $fixture.'/lib/RecordingSessions.php';
 require_once $fixture.'/lib/RecordingPhone.php';
 require_once $fixture.'/lib/DirectorBroadcast.php';
 require_once $fixture.'/lib/PartyStudio.php';
 schemaDbCheck(hash_file('sha256',__DIR__.'/../lib/SchemaMigrations.php')===hash_file('sha256',$fixture.'/lib/SchemaMigrations.php'),
  'The isolated fixture executes the unchanged production migration service');
 $root=schemaDbConnect();
 foreach(['fresh','legacy'] as $suffix){
  $name='lucky_schema_g4_'.bin2hex(random_bytes(6)).'_'.$suffix;
  if(!preg_match('/^lucky_schema_g4_[a-f0-9]{12}_(fresh|legacy)$/D',$name))throw new RuntimeException('Unsafe temporary database name.');
  $root->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$databases[$suffix]=$name;
 }
 $fresh=schemaDbConnect($databases['fresh']);$legacy=schemaDbConnect($databases['legacy']);
 schemaDbCheck(schemaDbTables($fresh)===[],'Fresh certification begins with an actually empty database');
 SchemaMigrations::ensure($fresh);SchemaMigrations::assertReady($fresh);
 schemaDbCheck(schemaDbLockFree($fresh,$databases['fresh']),'Successful fresh migration releases its database advisory lock');
 foreach(['admins','game_settings','prize_packages','controller_pairs','recording_sessions','lucky_costumes','lucky_costume_actions','lucky_parties'] as $table)
  schemaDbCheck((int)$fresh->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn()===0,'Migration service does not invent or seed '.$table.' rows');
 $freshSchema=schemaDbColumns($fresh);$freshData=schemaDbSnapshot($fresh);
 SchemaMigrations::ensure($fresh);
 schemaDbCheck(schemaDbColumns($fresh)===$freshSchema&&schemaDbSnapshot($fresh)===$freshData,'Second fresh run is idempotent and does not seed data');

 // Frozen legacy DDL, deliberately NOT generated from current schema.sql. It
 // reproduces pre-phone-camera/media-angle/visibility and missing module tables.
 $legacySql=<<<'SQL'
CREATE TABLE admins (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,username VARCHAR(80) NOT NULL UNIQUE,password_hash VARCHAR(255) NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE game_settings (game_id VARCHAR(64) NOT NULL PRIMARY KEY,config_json LONGTEXT NOT NULL,intro_json LONGTEXT NOT NULL,theme_json LONGTEXT NOT NULL,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE game_sessions (id CHAR(32) NOT NULL PRIMARY KEY,game_id VARCHAR(64) NOT NULL,status VARCHAR(20) NOT NULL,version INT UNSIGNED NOT NULL,state_json LONGTEXT NOT NULL,viewer_token CHAR(48) NOT NULL UNIQUE,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,ended_at DATETIME NULL,INDEX idx_sessions_history(game_id,created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE game_claims (id CHAR(32) NOT NULL PRIMARY KEY,session_id CHAR(32) NOT NULL,prize_id VARCHAR(64) NOT NULL,tier VARCHAR(10) NOT NULL,prize_name VARCHAR(160) NOT NULL,cents BIGINT NOT NULL,created_at DATETIME NOT NULL,FOREIGN KEY fk_claim_session(session_id) REFERENCES game_sessions(id) ON DELETE CASCADE,UNIQUE KEY uq_award_once(session_id,prize_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE claim_media (id CHAR(32) NOT NULL PRIMARY KEY,session_id CHAR(32) NOT NULL,claim_id CHAR(32) NOT NULL,kind VARCHAR(8) NOT NULL,mime VARCHAR(60) NOT NULL,title VARCHAR(120) NOT NULL,bytes BIGINT UNSIGNED NOT NULL,duration_ms INT UNSIGNED NOT NULL DEFAULT 0,trim_start_ms INT UNSIGNED NOT NULL DEFAULT 0,trim_end_ms INT UNSIGNED NOT NULL DEFAULT 0,photo_duration_ms INT UNSIGNED NOT NULL DEFAULT 4000,sort_order INT UNSIGNED NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,INDEX idx_claim_media_order(session_id,sort_order,id),INDEX idx_claim_media_claim(claim_id),CONSTRAINT fk_claim_media_session FOREIGN KEY(session_id) REFERENCES game_sessions(id) ON DELETE CASCADE,CONSTRAINT fk_claim_media_claim FOREIGN KEY(claim_id) REFERENCES game_claims(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE controller_pairs (id CHAR(32) NOT NULL PRIMARY KEY,host_admin_id INT UNSIGNED NOT NULL,invite_hash CHAR(64) NOT NULL,controller_hash CHAR(64) NULL,device_name VARCHAR(48) NOT NULL DEFAULT '',status VARCHAR(16) NOT NULL DEFAULT 'inviting',invite_expires_at DATETIME NOT NULL,paired_expires_at DATETIME NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,approved_at DATETIME NULL,revoked_at DATETIME NULL,INDEX idx_pair_host(host_admin_id,status,invite_expires_at),FOREIGN KEY fk_pair_admin(host_admin_id) REFERENCES admins(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE controller_proof_state (session_id CHAR(32) NOT NULL PRIMARY KEY,host_admin_id INT UNSIGNED NOT NULL,claim_id CHAR(32) NULL,is_open TINYINT(1) NOT NULL DEFAULT 0,review_count TINYINT UNSIGNED NOT NULL DEFAULT 0,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX idx_proof_host(host_admin_id,is_open,updated_at),FOREIGN KEY fk_controller_proof_host(host_admin_id) REFERENCES admins(id) ON DELETE CASCADE,FOREIGN KEY fk_controller_proof_session(session_id) REFERENCES game_sessions(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE prize_packages (id CHAR(32) NOT NULL PRIMARY KEY,game_id VARCHAR(64) NOT NULL,name VARCHAR(100) NOT NULL,config_json LONGTEXT NOT NULL,enabled TINYINT(1) NOT NULL DEFAULT 1,player_selectable TINYINT(1) NOT NULL DEFAULT 1,version INT UNSIGNED NOT NULL DEFAULT 1,sort_order INT UNSIGNED NOT NULL DEFAULT 0,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,INDEX idx_packages_game(game_id,sort_order),UNIQUE KEY uq_package_name(game_id,name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE director_broadcasts (id CHAR(32) NOT NULL PRIMARY KEY,session_id CHAR(32) NOT NULL UNIQUE,claim_id CHAR(32) NOT NULL,host_admin_id INT NOT NULL,token_hash CHAR(64) NOT NULL,updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX idx_director_owner(host_admin_id,updated_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE user_fixture_notes (id INT NOT NULL PRIMARY KEY,note VARCHAR(120) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;
 foreach(MigrationSql::statements($legacySql) as $sql)$legacy->exec($sql);
 $hostPassword=password_hash('schema-regression-fixture-only',PASSWORD_DEFAULT);
 $legacy->prepare('INSERT INTO admins(username,password_hash) VALUES (?,?)')->execute(['legacy-owner',$hostPassword]);
 $owner=(int)$legacy->lastInsertId();$_SESSION['admin_id']=$owner;
 $session=bin2hex(random_bytes(16));$claim=bin2hex(random_bytes(16));$oldClip=bin2hex(random_bytes(16));
 $pairId=bin2hex(random_bytes(16));$token=bin2hex(random_bytes(32));$packageId=bin2hex(random_bytes(16));$oldBroadcast=bin2hex(random_bytes(16));
 $state=GameEngine::newSession(GameEngine::defaults(),$session);$state['claims']=[['id'=>$claim,'playerName'=>'Legacy player','turnNumber'=>1]];
 $legacy->prepare("INSERT INTO game_settings(game_id,config_json,intro_json,theme_json) VALUES ('spin-wheel',?,?,?)")
  ->execute(['{"custom":"keep","pools":[]}','{"title":"Keep my intro"}','{"accent":"#123456"}']);
 $legacy->prepare("INSERT INTO game_sessions(id,game_id,status,version,state_json,viewer_token) VALUES (?,'spin-wheel','active',7,?,?)")
  ->execute([$session,json_encode($state,JSON_THROW_ON_ERROR),bin2hex(random_bytes(24))]);
 $legacy->prepare("INSERT INTO game_claims(id,session_id,prize_id,tier,prize_name,cents,created_at) VALUES (?,?,'legacy-prize','A','Preserved prize',1299,'2026-01-02 03:04:05')")
  ->execute([$claim,$session]);
 $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL/nwAAAABJRU5ErkJggg==',true);
 if(!is_string($png))throw new RuntimeException('Invalid legacy media fixture.');
 mkdir($fixture.'/storage/claim-media',0700);$oldPath=$fixture.'/storage/claim-media/'.$oldClip.'.bin';file_put_contents($oldPath,$png);
 $legacy->prepare("INSERT INTO claim_media(id,session_id,claim_id,kind,mime,title,bytes,sort_order) VALUES (?,?,?,'photo','image/png','Do not remove original',?,9)")
  ->execute([$oldClip,$session,$claim,strlen($png)]);
 $legacy->prepare("INSERT INTO controller_pairs(id,host_admin_id,invite_hash,controller_hash,device_name,status,invite_expires_at,paired_expires_at) VALUES (?,?,?,?,?,'paired',UTC_TIMESTAMP()+INTERVAL 1 HOUR,UTC_TIMESTAMP()+INTERVAL 1 HOUR)")
  ->execute([$pairId,$owner,hash('sha256','g4-fixture-invite'),ControllerPairing::tokenHash($token),'Legacy phone']);
 $legacy->prepare('INSERT INTO controller_proof_state(session_id,host_admin_id,claim_id,is_open,review_count) VALUES (?,?,?,1,2)')->execute([$session,$owner,$claim]);
 $legacy->prepare("INSERT INTO prize_packages(id,game_id,name,config_json,enabled,player_selectable,version,sort_order) VALUES (?,'spin-wheel','Keep this prize package','{\"custom\":true}',1,1,11,6)")->execute([$packageId]);
 $legacy->prepare('INSERT INTO director_broadcasts(id,session_id,claim_id,host_admin_id,token_hash) VALUES (?,?,?,?,?)')->execute([$oldBroadcast,$session,$claim,$owner,hash('sha256','legacy-private-token')]);
 $legacy->exec("INSERT INTO user_fixture_notes(id,note) VALUES (1,'Unknown user table must survive')");
 schemaDbCheck($legacy->query("SHOW COLUMNS FROM controller_proof_state LIKE 'phone_camera_enabled'")->fetch()===false&&
  $legacy->query("SHOW COLUMNS FROM claim_media LIKE 'source_label'")->fetch()===false&&
  $legacy->query("SHOW COLUMNS FROM director_broadcasts LIKE 'visibility'")->fetch()===false,
  'Frozen legacy fixture really lacks the three previously omitted permission/media/privacy repairs');
 foreach(['director_show_channels','recording_sessions','lucky_parties','lucky_costume_actions'] as $table)
  schemaDbCheck(!in_array($table,schemaDbTables($legacy),true),'Frozen legacy fixture really lacks '.$table);
 $legacyRows=[];
 foreach([['admins','id',$owner],['game_settings','game_id','spin-wheel'],['game_sessions','id',$session],['game_claims','id',$claim],
  ['claim_media','id',$oldClip],['controller_pairs','id',$pairId],['controller_proof_state','session_id',$session],
  ['prize_packages','id',$packageId],['director_broadcasts','id',$oldBroadcast],['user_fixture_notes','id',1]] as [$table,$key,$id])
  $legacyRows[]=[$table,$key,$id,schemaDbRow($legacy,$table,$key,$id)];
 $before=schemaDbSnapshot($legacy);
 schemaDbThrows(static fn()=>SchemaMigrations::assertReady($legacy),'Readiness rejects the incomplete legacy database before claiming success');
 schemaDbCheck(schemaDbSnapshot($legacy)===$before,'Readiness inspection does not mutate legacy rows');
 SchemaMigrations::ensure($legacy);SchemaMigrations::assertReady($legacy);
 schemaDbCheck(schemaDbLockFree($legacy,$databases['legacy']),'Legacy migration releases its advisory lock');
 schemaDbCheck(schemaDbColumns($legacy)===$freshSchema,'Legacy repair and fresh install produce matching application column definitions');
 foreach($legacyRows as [$table,$key,$id,$beforeRow]){
  $after=schemaDbRow($legacy,$table,$key,$id);
  schemaDbCheck(array_intersect_key($after,$beforeRow)===$beforeRow,'Legacy '.$table.' values, identities, and timestamps survive repair');
 }
 schemaDbCheck(file_get_contents($oldPath)===$png,'Legacy original recording bytes are untouched by migration');
 $proof=schemaDbRow($legacy,'controller_proof_state','session_id',$session);
 schemaDbCheck((int)$proof['phone_camera_enabled']===0&&(int)$proof['consent_ack']===0&&$proof['capture_phase']==='live',
  'New phone-camera/consent permissions fail closed for existing proofs');
 $media=schemaDbRow($legacy,'claim_media','id',$oldClip);
 schemaDbCheck($media['source_label']===''&&$media['angle_label']===''&&(int)$media['angle_index']===0,'Legacy media gains empty camera labels without invented attribution');
 schemaDbCheck(schemaDbRow($legacy,'director_broadcasts','id',$oldBroadcast)['visibility']==='private','An existing broadcast stays private by default');
 $package=schemaDbRow($legacy,'prize_packages','id',$packageId);
 schemaDbCheck($package['publication_status']==='published'&&$package['budget_cents']===null&&$package['image_url']===''&&(int)$package['phone_camera_enabled']===1,
  'Legacy playable package publication and established new-field defaults are preserved');

 // Exercise the real phone chunk-to-media pipeline on the upgraded schema.
 // Only the transport receipt predicate is injected: HTTP is certified separately.
 ProofHandoff::activate($legacy,$session,$claim,$owner);
 $legacy->prepare("UPDATE controller_proof_state SET phone_camera_enabled=1,consent_ack=1,capture_phase='live',updated_at=UTC_TIMESTAMP() WHERE session_id=?")->execute([$session]);
 $pair=ControllerPairing::authenticate($legacy,$pairId,$token,true);
 PhoneCapturePolicy::verify($pair,schemaDbRow($legacy,'controller_proof_state','session_id',$session),$session,$claim,time());
 schemaDbCheck(true,'An upgraded proof can explicitly authorize the existing paired-phone capture policy');
 $webm=base64_decode('GkXfo59ChoEBQveBAULygQRC84EIQoKEd2VibUKHgQJChYECGFOAZwH/////////EU2bdKtNu4tTq4QVSalmU6yBoU27i1OrhBZUrmtTrIHNTbuMU6uEElTDZ1OsggET7AEAAAAAAABoAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAVSalmpyrXsYMPQkBNgI1MYXZmNjAuMTYuMTAwV0GNTGF2ZjYwLjE2LjEwMBZUrmvBrgEAAAAAAAA414EBc8WIyLsJ6DC8PDqcgQAitZyDdW5kiIEAhoVWX1ZQOIOBASPjg4Q7msoA4ImwgRC6gRCagQISVMNn2HNzoGPAgGfImkWjh0VOQ09ERVJEh41MYXZmNjAuMTYuMTAwc3OyY8CLY8WIyLsJ6DC8PDpnyKFFo4dFTkNPREVSRIeUTGF2YzYwLjMxLjEwMiBsaWJ2cHgfQ7Z1wueBAKO9gQAAgLACAJ0BKhAAEAAARwiFhYiZhIgCAgJ1qgP4Agz9+wD+/00S//xKp/Eqn8Sqf/Eqn/0Yfww/hh/eEA==',true);
 if(!is_string($webm))throw new RuntimeException('Invalid WebM fixture.');
 $piece=$fixture.'/phone-piece.webm';file_put_contents($piece,$webm);
 $nonce=bin2hex(random_bytes(16));$receipt=PhoneCapturePolicy::mediaId($pairId,$nonce);
 $form=['nonce'=>$nonce,'session'=>$session,'claim'=>$claim,'kind'=>'video','title'=>'Upgraded phone video','durationMs'=>'1000','bytes'=>(string)strlen($webm),'total'=>'1'];
 $ack=PhoneMediaChunks::append($legacy,$pair,$form+['index'=>'0'],['tmp_name'=>$piece,'size'=>strlen($webm),'error'=>UPLOAD_ERR_OK],static fn(string $p):bool=>is_file($p));
 schemaDbCheck($ack['next']===1&&$ack['receivedBytes']===strlen($webm),'Real phone upload pipeline stages and acknowledges video bytes after migration');
 $authorize=static function(PDO $db,string $sessionId,string $claimId)use($pairId,$token):void {
  $locked=schemaDbRow($db,'controller_pairs','id',$pairId);
  if(!hash_equals((string)$locked['controller_hash'],ControllerPairing::tokenHash($token)))throw new DomainException('Fixture pairing revoked.',403);
  PhoneCapturePolicy::verify($locked,schemaDbRow($db,'controller_proof_state','session_id',$sessionId),$sessionId,$claimId,time());
 };
 $clip=PhoneMediaChunks::finish($legacy,$pair,$form,$authorize,$receipt);
 $saved=schemaDbRow($legacy,'claim_media','id',$receipt);
 schemaDbCheck($clip['id']===$receipt&&$clip['kind']==='video'&&$saved['claim_id']===$claim&&$saved['source_label']==='Paired phone · Legacy phone'&&$saved['angle_label']==='Phone · Legacy phone',
  'Upgraded phone video commits with its original claim and camera labels');
 schemaDbCheck(file_get_contents($fixture.'/storage/claim-media/'.$receipt.'.bin')===$webm,'Committed phone video retains its exact bytes in private local storage');
 $q=$legacy->prepare('SELECT edit_json FROM montage_projects WHERE session_id=?');$q->execute([$session]);$timeline=json_decode((string)$q->fetchColumn(),true,32,JSON_THROW_ON_ERROR);
 schemaDbCheck(in_array($receipt,array_column($timeline,'id'),true)&&in_array($oldClip,array_column($timeline,'id'),true),
  'Phone video is appended to the real montage while the existing clip remains');

 $recording=RecordingSessions::create($legacy,$owner,['type'=>'production','title'=>'Migrated recording','nonce'=>'schema-g4-production-001']);
 RecordingPhone::grant($legacy,$owner,$recording['id'],true);
 $recording=RecordingSessions::setStatus($legacy,$owner,$recording['id'],'active',$recording['revision']);
 schemaDbCheck(in_array($recording['id'],array_column(RecordingPhone::sessions($legacy,$pair),'id'),true),
  'Real recording creation/profile/phone-grant/listing operations work on the upgraded database');
 $take=RecordingSessions::startTake($legacy,$owner,$recording['id'],['title'=>'Migrated take','nonce'=>'schema-g4-take-001']);
 schemaDbCheck(RecordingSessions::finishTake($legacy,$owner,$recording['id'],$take['id'])['status']==='completed','Real recording take can start and finish after migration');
 $show=DirectorBroadcast::ensureShow($legacy,$recording['id'],$owner);
 $broadcast=DirectorBroadcast::startRecording($legacy,$recording['id'],$owner);
 schemaDbCheck($broadcast['showId']===$show&&$broadcast['visibility']==='private'&&!DirectorBroadcast::resolveShow($legacy,$show)['live'],
  'Repaired show-channel tables support private publication without public leakage');
 $public=DirectorBroadcast::startRecording($legacy,$recording['id'],$owner,'public',true);
 schemaDbCheck($public['showId']===$show&&DirectorBroadcast::resolveShow($legacy,$show)['broadcastId']===$public['id'],
  'Explicit public publication resolves the same permanent show address');
 DirectorBroadcast::stop($legacy,$public['id'],$owner);
 schemaDbCheck(!DirectorBroadcast::resolveShow($legacy,$show)['live'],'Stopping the migrated recording preserves the show address but takes it off air');
 $action=PartyStudio::saveAction($legacy,['name'=>'Wave to camera','description'=>'Migration fixture']);
 $costume=PartyStudio::save($legacy,'costumes',['name'=>'Legacy space outfit','items'=>[['id'=>'g4-helmet','name'=>'Helmet','actionIds'=>['equip',$action['id']]]]]);
 $theme=PartyStudio::save($legacy,'themes',['name'=>'Migrated party theme','costumeIds'=>[$costume['id']],'accent'=>'#123abc']);
 $party=PartyStudio::save($legacy,'parties',['name'=>'Migrated party','themeId'=>$theme['id'],'attendees'=>[['role'=>'player','name'=>'Dave','costumeId'=>$costume['id']]]]);
 $roster=PartyStudio::roster($legacy,['partyId'=>$party['id']]);
 schemaDbCheck($roster['party']['id']===$party['id']&&$roster['theme']['id']===$theme['id']&&count($roster['players'][0]['costume']['items'][0]['actions'])===2,
  'Real party/theme/costume/action library operations work after legacy repair');

 $before=schemaDbSnapshot($legacy);$beforeSchema=schemaDbColumns($legacy);
 SchemaMigrations::ensure($legacy);SchemaMigrations::assertReady($legacy);
 schemaDbCheck(schemaDbSnapshot($legacy)===$before&&schemaDbColumns($legacy)===$beforeSchema,
  'Repeated migration preserves all old and newly exercised feature rows, including identities and timestamps');
 schemaDbCheck(file_get_contents($oldPath)===$png&&file_get_contents($fixture.'/storage/claim-media/'.$receipt.'.bin')===$webm,
  'Repeated migration preserves both original and newly uploaded media bytes');

 $legacy->beginTransaction();$legacy->exec("INSERT INTO user_fixture_notes(id,note) VALUES (2,'Must remain uncommitted')");
 schemaDbThrows(static fn()=>SchemaMigrations::ensure($legacy),'Migration rejects an active caller transaction instead of implicitly committing it');
 schemaDbCheck($legacy->inTransaction(),'Rejected migration leaves the caller transaction active');
 $legacy->rollBack();
 schemaDbCheck((int)$legacy->query('SELECT COUNT(*) FROM user_fixture_notes WHERE id=2')->fetchColumn()===0,'Caller can roll back its data after transaction rejection');

 // Contention is a real second MariaDB connection, not a fake lock result.
 $blocker=schemaDbConnect($databases['legacy']);$lockName=schemaDbLockName($databases['legacy']);
 $q=$blocker->prepare('SELECT GET_LOCK(?,0)');$q->execute([$lockName]);
 schemaDbCheck((int)$q->fetchColumn()===1,'Independent connection acquires the migration lock for contention control');
 try{
  $before=schemaDbSnapshot($legacy);$start=microtime(true);
  schemaDbThrows(static fn()=>SchemaMigrations::ensure($legacy),'Contended migration fails closed after its bounded advisory-lock wait');
  $elapsed=microtime(true)-$start;
  schemaDbCheck($elapsed>=4.0&&$elapsed<12.0,'Advisory-lock wait is bounded and actually observes native contention');
  schemaDbCheck(schemaDbSnapshot($legacy)===$before,'Failed lock acquisition does not change application data');
 }finally{$q=$blocker->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$lockName]);}
 SchemaMigrations::ensure($legacy);
 schemaDbCheck(schemaDbLockFree($legacy,$databases['legacy']),'Migration succeeds and releases its lock after contention is removed');

 // Missing unknown core columns must not be silently accepted as "current".
 $fresh->exec('ALTER TABLE lucky_costumes DROP COLUMN items_json');
 schemaDbThrows(static fn()=>SchemaMigrations::assertReady($fresh),'Readiness rejects a malformed existing feature table');
 schemaDbThrows(static fn()=>SchemaMigrations::ensure($fresh),'Unrepairable schema corruption propagates failure rather than a false ready result');
 schemaDbCheck(schemaDbLockFree($fresh,$databases['fresh']),'Failure during readiness still releases the acquired advisory lock');
 $fresh->exec('ALTER TABLE lucky_costumes ADD COLUMN items_json LONGTEXT NOT NULL');
 SchemaMigrations::ensure($fresh);SchemaMigrations::assertReady($fresh);
 schemaDbCheck(true,'A repaired schema can be migrated again after the previous failure');
 $fresh->exec('ALTER TABLE admins DROP INDEX username');
 schemaDbThrows(static fn()=>SchemaMigrations::assertReady($fresh),'Readiness rejects a missing administrator uniqueness guarantee');
 schemaDbThrows(static fn()=>SchemaMigrations::ensure($fresh),'Missing uniqueness is not silently reported as a completed migration');
 schemaDbCheck(schemaDbLockFree($fresh,$databases['fresh']),'Failed key readiness also releases the acquired migration lock');
 $fresh->exec('ALTER TABLE admins ADD UNIQUE KEY fixture_renamed_username (username)');
 SchemaMigrations::ensure($fresh);SchemaMigrations::assertReady($fresh);
 schemaDbCheck(true,'An equivalent existing unique key is accepted regardless of its legacy index name');
 echo 'SHARED SCHEMA MIGRATIONS MARIADB: '.$checks.'/'.$checks."\n";
}finally{
 $blocker=null;$fresh=null;$legacy=null;
 try{
  if($root instanceof PDO)foreach(array_reverse($databases) as $name){
   if(!preg_match('/^lucky_schema_g4_[a-f0-9]{12}_(fresh|legacy)$/D',$name))throw new RuntimeException('Refusing unsafe database cleanup.');
   $root->exec('DROP DATABASE `'.$name.'`');
  }
 }finally{
  if(is_dir($fixture)&&preg_match('~/lucky-schema-g4-[a-f0-9]{16}$~',$fixture))schemaDbRemove($fixture);
 }
}
