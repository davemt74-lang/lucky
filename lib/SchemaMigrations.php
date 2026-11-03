<?php
declare(strict_types=1);
require_once __DIR__.'/MigrationSql.php';

/** One application schema for hosted installs, local installs, and both upgrade paths. */
final class SchemaMigrations {
 private const LOCK_WAIT_SECONDS=5;
 private const MAX_SQL_BYTES=1048576;
 /** Fixed, dependency-ordered bundle. Paths are relative to this lib directory. */
 private const CATALOG=[
  'schema.sql'=>['admins','game_settings','game_sessions','lucky_costumes','lucky_costume_actions','lucky_themes','lucky_parties',
   'director_broadcasts','director_viewers','director_scene_presets','game_claims','game_events','host_commands','media_tracks',
   'audit_log','legacy_imports','prize_images','catalog_products','controller_pairs','controller_commands','controller_ui_events',
   'controller_proof_state','controller_live_streams','montage_timeline_layers','montage_templates','prize_packages',
   'prize_package_selection','game_plugin_storage','movie_preview_state'],
  'migrations/v5n.sql'=>['catalog_products'],
  'migrations/v5p.sql'=>['controller_pairs','controller_commands'],
  'migrations/v5p_sync.sql'=>['controller_ui_events'],
  'migrations/v5q_claim_media.sql'=>['claim_media'],
  'migrations/v5q3_renders.sql'=>['montage_renders','montage_projects'],
  'migrations/v5q6_proof_revisions.sql'=>['claim_proof_state','claim_proof_revisions','claim_proof_revision_clips'],
  'migrations/v5q13_capture_ui.sql'=>['controller_proof_state'],
  'migrations/v5q15b_prize_decisions.sql'=>['prize_decisions'],
  'migrations/v5q15m_layers.sql'=>['montage_timeline_layers'],
  'migrations/v5q15n_templates.sql'=>['montage_templates'],
  'migrations/v5q20_prize_packages.sql'=>['prize_packages','prize_package_selection'],
  'migrations/v5q24_plugin_storage.sql'=>['game_plugin_storage'],
  'migrations/v5q26_movie_preview.sql'=>['movie_preview_state'],
  'migrations/v5q29e1_recording_sessions.sql'=>['recording_sessions','recording_takes','recording_sources','recording_assets'],
  'migrations/v5q29e2_phone_camera.sql'=>['recording_phone_permissions','recording_media_files'],
  'migrations/v5q29e3_multicamera_coordination.sql'=>['recording_camera_arms','recording_capture_sync'],
  'migrations/v5q29e4a_multicamera_timeline.sql'=>['recording_timeline_edits'],
  'migrations/v5q29e4b_multicamera_exports.sql'=>['recording_exports'],
  'migrations/v5q29e4c_recording_profiles.sql'=>['recording_session_profiles'],
  'migrations/v5q29e4d1hf7_phone_live.sql'=>['controller_live_streams'],
  'migrations/v5q29e4d1hf8b_director_live.sql'=>['director_broadcasts','director_viewers'],
  'migrations/v5q29e4d1hf8c2_director_scenes.sql'=>['director_scene_presets'],
  'migrations/v5q29e4d1hf8c4_director_recordings.sql'=>['director_broadcast_recordings','director_recording_scenes'],
  'migrations/v5q29e4d1hf8c5_recording_live.sql'=>['recording_live_streams'],
  'migrations/v5q30_party_studio.sql'=>['lucky_costumes','lucky_themes','lucky_parties'],
  'migrations/v5q31_costume_actions.sql'=>['lucky_costume_actions'],
  'migrations/v5q32e1_permanent_show_url.sql'=>['director_show_channels'],
 ];
 /** Presence checks are explicit; extra user/legacy fields are never removed. */
 private const REQUIRED_COLUMNS=[
  'admins'=>'id,username,password_hash,created_at',
  'game_settings'=>'game_id,config_json,intro_json,theme_json,updated_at',
  'game_sessions'=>'id,game_id,status,version,state_json,viewer_token,created_at,updated_at,ended_at',
  'lucky_costumes'=>'id,name,description,items_json,created_at,updated_at',
  'lucky_costume_actions'=>'id,name,description,created_at,updated_at',
  'lucky_themes'=>'id,name,description,accent,costume_ids_json,created_at,updated_at',
  'lucky_parties'=>'id,name,description,theme_id,attendees_json,created_at,updated_at',
  'director_broadcasts'=>'id,session_id,claim_id,host_admin_id,token_hash,visibility,updated_at,created_at',
  'director_viewers'=>'id,broadcast_id,offer_sdp,answer_sdp,updated_at,created_at',
  'director_scene_presets'=>'id,session_id,claim_id,host_admin_id,name,source_id,transition_kind,overlay_text,overlay_sticker,created_at,updated_at',
  'game_claims'=>'id,session_id,prize_id,tier,prize_name,cents,created_at',
  'game_events'=>'id,session_id,idempotency_key,event_type,payload_json,created_at',
  'host_commands'=>'id,session_id,command,created_at',
  'media_tracks'=>'id,title,kind,source,mime,bytes,created_at',
  'audit_log'=>'id,admin_id,event,session_id,details,created_at',
  'legacy_imports'=>'fingerprint,session_id,imported_at',
  'prize_images'=>'id,mime,bytes,created_at',
  'catalog_products'=>'id,title,summary,category,status,price_cents,image_url,purchase_url,sort_order,created_at,updated_at',
  'controller_pairs'=>'id,host_admin_id,invite_hash,controller_hash,device_name,status,invite_expires_at,paired_expires_at,created_at,approved_at,revoked_at',
  'controller_commands'=>'id,pair_id,client_nonce,command,created_at,delivered_at',
  'controller_ui_events'=>'command_id,session_id,acknowledged_at,failure_message,ack_source',
  'controller_proof_state'=>'session_id,host_admin_id,claim_id,is_open,review_count,consent_ack,phone_camera_enabled,capture_phase,updated_at',
  'controller_live_streams'=>'pair_id,session_id,claim_id,nonce,offer_sdp,answer_sdp,updated_at,created_at',
  'montage_timeline_layers'=>'session_id,layers_json,updated_at',
  'montage_templates'=>'id,admin_id,name,template_json,created_at,updated_at',
  'prize_packages'=>'id,game_id,name,config_json,budget_cents,image_url,enabled,publication_status,player_selectable,phone_camera_enabled,version,sort_order,created_at,updated_at',
  'prize_package_selection'=>'game_id,package_id,updated_at',
  'game_plugin_storage'=>'game_id,scope_id,storage_key,value_json,revision,updated_at',
  'movie_preview_state'=>'admin_id,session_id,state,revision,updated_at',
  'prize_decisions'=>'claim_id,session_id,status,decided_at',
  'recording_sessions'=>'id,owner_admin_id,recording_type,title,description,game_session_id,status,revision,create_nonce,request_hash,started_at,ended_at,created_at,updated_at',
  'recording_takes'=>'id,recording_session_id,take_index,title,status,client_nonce,request_hash,started_at,ended_at,created_at',
  'recording_sources'=>'id,recording_session_id,source_key,source_kind,display_name,paired_device_id,created_at',
  'recording_assets'=>'id,recording_session_id,take_id,source_id,claim_media_id,storage_key,kind,start_offset_ms,sync_confidence,created_at',
  'recording_phone_permissions'=>'recording_session_id,phone_enabled,updated_at',
  'recording_media_files'=>'asset_id,mime,title,bytes,sha256,duration_ms,uploaded_by_pair_id,created_at',
  'recording_camera_arms'=>'recording_session_id,pair_id,capture_kind,armed,last_take_id,last_state,last_seen_at,updated_at',
  'recording_capture_sync'=>'asset_id,recorded_start_ms,recorded_end_ms,clock_offset_ms,sync_method',
  'recording_timeline_edits'=>'recording_session_id,revision,timeline_json,created_at,updated_at',
  'recording_exports'=>'id,recording_session_id,owner_admin_id,client_nonce,request_hash,timeline_revision,audio_asset_id,profile,snapshot_json,status,output_bytes,duration_ms,error_message,created_at,started_at,finished_at',
  'recording_session_profiles'=>'recording_session_id,game_id,profile_json,created_at',
  'director_broadcast_recordings'=>'broadcast_id,recording_session_id',
  'director_recording_scenes'=>'id,recording_session_id,host_admin_id,name,source_id,transition_kind,overlay_text,overlay_sticker,created_at,updated_at',
  'recording_live_streams'=>'pair_id,recording_session_id,nonce,offer_sdp,answer_sdp,created_at,updated_at',
  'director_show_channels'=>'public_id,recording_session_id,host_admin_id,created_at',
  'montage_renders'=>'id,session_id,admin_id,status,profile,snapshot_json,soundtrack_id,music_volume,output_bytes,error_message,created_at,started_at,finished_at',
  'montage_projects'=>'session_id,edit_json,updated_at',
  'claim_proof_state'=>'claim_id,session_id,needs_revision,request_note,updated_at',
  'claim_proof_revisions'=>'id,claim_id,session_id,revision_no,note,clip_snapshot_json,created_at',
  'claim_proof_revision_clips'=>'revision_id,clip_id',
  'claim_media'=>'id,session_id,claim_id,kind,mime,title,source_label,angle_label,angle_index,bytes,duration_ms,trim_start_ms,trim_end_ms,photo_duration_ms,sort_order,created_at,updated_at',
 ];
 /** Remaining primary keys use the first column declared in REQUIRED_COLUMNS. */
 private const COMPOSITE_PRIMARY=[
  'game_plugin_storage'=>'game_id,scope_id,storage_key',
  'recording_camera_arms'=>'recording_session_id,pair_id',
  'claim_proof_revision_clips'=>'revision_id,clip_id',
 ];
 /** Exact column sequences, not index names, allow equivalent older constraint names. */
 private const UNIQUE_KEYS=[
  'admins'=>['username'], 'game_sessions'=>['viewer_token'], 'director_broadcasts'=>['session_id'],
  'director_scene_presets'=>['session_id,claim_id,host_admin_id,name'],
  'game_claims'=>['session_id,prize_id'], 'game_events'=>['session_id,idempotency_key'],
  'legacy_imports'=>['session_id'], 'controller_commands'=>['pair_id,client_nonce'],
  'prize_packages'=>['game_id,name'], 'recording_sessions'=>['owner_admin_id,create_nonce'],
  'recording_takes'=>['recording_session_id,take_index','recording_session_id,client_nonce'],
  'recording_sources'=>['recording_session_id,source_key'],
  'recording_assets'=>['claim_media_id','storage_key'],
  'recording_exports'=>['recording_session_id,client_nonce'],
  'director_recording_scenes'=>['recording_session_id,host_admin_id,name'],
  'director_show_channels'=>['recording_session_id'], 'claim_proof_revisions'=>['claim_id,revision_no'],
 ];
 /** Only known additive upgrades. Existing values are never reset or republished. */
 private const COLUMN_REPAIRS=[
  'director_broadcasts'=>[
   'visibility'=>"VARCHAR(12) NOT NULL DEFAULT 'private' AFTER token_hash",
  ],
  'prize_packages'=>[
   'publication_status'=>"VARCHAR(12) NOT NULL DEFAULT 'published' AFTER enabled",
   'budget_cents'=>'INT UNSIGNED NULL DEFAULT NULL AFTER config_json',
   'image_url'=>"VARCHAR(2048) NOT NULL DEFAULT '' AFTER budget_cents",
   'phone_camera_enabled'=>'TINYINT(1) NOT NULL DEFAULT 1 AFTER player_selectable',
  ],
  'claim_media'=>[
   'source_label'=>"VARCHAR(120) NOT NULL DEFAULT '' AFTER title",
   'angle_label'=>"VARCHAR(80) NOT NULL DEFAULT '' AFTER source_label",
   'angle_index'=>'TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER angle_label',
  ],
  'controller_proof_state'=>[
   'consent_ack'=>'TINYINT(1) NOT NULL DEFAULT 0 AFTER review_count',
   'phone_camera_enabled'=>'TINYINT(1) NOT NULL DEFAULT 0 AFTER consent_ack',
   'capture_phase'=>"VARCHAR(12) NOT NULL DEFAULT 'live' AFTER phone_camera_enabled",
  ],
 ];

 /** @return list<string> Fixed lib-relative paths; callers cannot supply SQL or a directory. */
 public static function files():array {return array_keys(self::CATALOG);}

 /** MySQL DDL commits implicitly: never accept an application's in-flight transaction. */
 public static function ensure(PDO $db):void {
  if($db->inTransaction())throw new RuntimeException('Finish the current database transaction before applying schema updates.');
  $statements=self::bundle(); // Validate every source before any database query or DDL.
  $database=self::scalar($db,'SELECT DATABASE()');
  if(!is_string($database)||$database==='')throw new RuntimeException('Select the application database before applying schema updates.');
  $lock='lucky-schema-'.substr(hash('sha256',$database),0,48);
  try{$acquired=self::scalar($db,'SELECT GET_LOCK(?, ?)',[$lock,self::LOCK_WAIT_SECONDS]);}
  catch(Throwable $e){throw new RuntimeException('Schema updates require MySQL/MariaDB advisory locks. Check database support and permissions, then retry.',0,$e);}
  if((string)$acquired!=='1')throw new RuntimeException('Could not acquire the application schema lock. Another upgrade may be running; retry shortly.');
  $failure=null;
  try{
   foreach($statements as $statement)if($db->exec($statement)===false)throw new RuntimeException('A bundled schema statement failed. Retry the upgrade after checking database logs.');
   $columns=self::columns($db);
   foreach(self::COLUMN_REPAIRS as $table=>$repairs)foreach($repairs as $column=>$definition){
    if(isset($columns[$table][$column]))continue;
    if($db->exec('ALTER TABLE `'.$table.'` ADD COLUMN `'.$column.'` '.$definition)===false)
     throw new RuntimeException('Could not add required schema column '.$table.'.'.$column.'.');
    $columns[$table][$column]=true;
   }
   self::assertReady($db);
  }catch(Throwable $e){$failure=$e;throw $e;}
  finally{
   try{
    if((string)self::scalar($db,'SELECT RELEASE_LOCK(?)',[$lock])!=='1')
     throw new RuntimeException('Could not release the application schema lock. Close this database connection before retrying.');
   }catch(Throwable $e){
    if($failure===null)throw $e;
    // Do not replace the actual migration failure or expose connection details.
    error_log('Lucky schema update also failed to release its advisory lock; close the connection.');
   }
  }
 }

 /** Read-only readiness: missing structures must not be reported as a successful upgrade. */
 public static function assertReady(PDO $db):void {
  $tables=[];
  foreach(self::rows($db,'SELECT TABLE_NAME AS table_name, ENGINE AS engine, TABLE_TYPE AS table_type FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()') as $row)
   $tables[$row['table_name']]=$row;
  $columns=self::columns($db);
  foreach(self::REQUIRED_COLUMNS as $table=>$required){
   if(!isset($tables[$table]))throw new RuntimeException('Database schema incomplete: missing table '.$table.'. Run the application upgrade.');
   if(strcasecmp((string)$tables[$table]['engine'],'InnoDB')!==0||$tables[$table]['table_type']!=='BASE TABLE')
    throw new RuntimeException('Database schema incompatible: '.$table.' must be an InnoDB table. Restore or repair it before continuing.');
   foreach(explode(',',$required) as $column)if(!isset($columns[$table][$column]))
    throw new RuntimeException('Database schema incomplete: missing column '.$table.'.'.$column.'. Restore or repair it before continuing.');
  }
  $indexes=[];
  foreach(self::rows($db,'SELECT TABLE_NAME AS table_name, INDEX_NAME AS index_name, NON_UNIQUE AS non_unique, COLUMN_NAME AS column_name, SUB_PART AS sub_part FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX') as $row){
   if((int)$row['non_unique']!==0)continue;
   $entry=&$indexes[$row['table_name']][$row['index_name']];
   if(!is_array($entry))$entry=['columns'=>[],'full'=>true];
   $entry['columns'][]=(string)$row['column_name'];
   $entry['full']=$entry['full']&&$row['sub_part']===null;
   unset($entry);
  }
  foreach(self::REQUIRED_COLUMNS as $table=>$required){
   $primary=self::COMPOSITE_PRIMARY[$table]??explode(',',$required)[0];
   $found=$indexes[$table]['PRIMARY']??null;
   if(!$found||!$found['full']||implode(',',$found['columns'])!==$primary)
    throw new RuntimeException('Database schema incomplete: invalid primary key on '.$table.'. Restore or repair it before continuing.');
  }
  foreach(self::UNIQUE_KEYS as $table=>$required)foreach($required as $columns){
   $present=false;
   foreach($indexes[$table]??[] as $index)if($index['full']&&implode(',',$index['columns'])===$columns){$present=true;break;}
   if(!$present)throw new RuntimeException('Database schema incomplete: missing unique key on '.$table.' ('.$columns.'). Restore or repair it before continuing.');
  }
 }

 /** Compile only the checked-in CREATE statements, never a request-supplied SQL file. */
 private static function bundle():array {
  $statements=[];$known=[];
  foreach(self::CATALOG as $relative=>$expected){
   $path=__DIR__.'/'.$relative;
   $sql=is_file($path)?@file_get_contents($path,false,null,0,self::MAX_SQL_BYTES+1):false;
   if(!is_string($sql)||trim($sql)===''||strlen($sql)>self::MAX_SQL_BYTES||str_contains($sql,"\0"))
    throw new RuntimeException('Bundled schema source is missing, empty or invalid: '.$relative.'. Restore the complete deployment package.');
   try{$compiled=MigrationSql::statements($sql);}
   catch(Throwable $e){throw new RuntimeException('Bundled schema source cannot be parsed: '.$relative.'. Restore the complete deployment package.',0,$e);}
   $actual=[];
   foreach($compiled as $statement){
    if(!preg_match('/\ACREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+([a-z][a-z0-9_]*)\s*\(/i',$statement,$match)||
       !preg_match('/\)\s*ENGINE\s*=\s*InnoDB\b/i',$statement))
     throw new RuntimeException('Unexpected SQL in bundled schema source: '.$relative.'. Restore the complete deployment package.');
    $actual[]=strtolower($match[1]);$known[strtolower($match[1])]=true;
   }
   if($actual!==$expected)throw new RuntimeException('Bundled schema catalog mismatch: '.$relative.'. Restore the complete deployment package.');
   array_push($statements,...$compiled);
  }
  if(array_diff_key(self::REQUIRED_COLUMNS,$known)||array_diff_key($known,self::REQUIRED_COLUMNS))
   throw new RuntimeException('Bundled schema readiness catalog does not match its table definitions.');
  return $statements;
 }
 private static function columns(PDO $db):array {
  $columns=[];
  foreach(self::rows($db,'SELECT TABLE_NAME AS table_name, COLUMN_NAME AS column_name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()') as $row)
   $columns[$row['table_name']][$row['column_name']]=true;
  return $columns;
 }
 private static function rows(PDO $db,string $sql,array $params=[]):array {
  $query=$db->prepare($sql);
  if(!$query||!$query->execute($params))throw new RuntimeException('Could not inspect the application database schema.');
  return $query->fetchAll(PDO::FETCH_ASSOC);
 }
 private static function scalar(PDO $db,string $sql,array $params=[]):mixed {
  $query=$db->prepare($sql);
  if(!$query||!$query->execute($params))throw new RuntimeException('Could not inspect the application database schema.');
  return $query->fetchColumn();
 }
}
