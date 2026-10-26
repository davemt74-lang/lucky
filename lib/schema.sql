CREATE TABLE IF NOT EXISTS admins (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, username VARCHAR(80) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS game_settings (game_id VARCHAR(64) NOT NULL PRIMARY KEY, config_json LONGTEXT NOT NULL, intro_json LONGTEXT NOT NULL, theme_json LONGTEXT NOT NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS game_sessions (id CHAR(32) NOT NULL PRIMARY KEY, game_id VARCHAR(64) NOT NULL, status VARCHAR(20) NOT NULL, version INT UNSIGNED NOT NULL, state_json LONGTEXT NOT NULL, viewer_token CHAR(48) NOT NULL UNIQUE, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, ended_at DATETIME NULL, INDEX idx_sessions_history (game_id,created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS game_claims (id CHAR(32) NOT NULL PRIMARY KEY, session_id CHAR(32) NOT NULL, prize_id VARCHAR(64) NOT NULL, tier VARCHAR(10) NOT NULL, prize_name VARCHAR(160) NOT NULL, cents BIGINT NOT NULL, created_at DATETIME NOT NULL, FOREIGN KEY fk_claim_session(session_id) REFERENCES game_sessions(id) ON DELETE CASCADE, UNIQUE KEY uq_award_once (session_id,prize_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS game_events (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, session_id CHAR(32) NOT NULL, idempotency_key VARCHAR(80) NOT NULL, event_type VARCHAR(40) NOT NULL, payload_json LONGTEXT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_idempotent (session_id,idempotency_key), INDEX idx_event_seq(session_id,id), FOREIGN KEY fk_event_session(session_id) REFERENCES game_sessions(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS host_commands (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, session_id CHAR(32) NOT NULL, command VARCHAR(40) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY fk_command_session(session_id) REFERENCES game_sessions(id) ON DELETE CASCADE, INDEX idx_command_seq(session_id,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS media_tracks (id CHAR(32) NOT NULL PRIMARY KEY, title VARCHAR(160) NOT NULL, kind VARCHAR(20) NOT NULL, source VARCHAR(2048) NOT NULL, mime VARCHAR(100) NOT NULL, bytes INT UNSIGNED NOT NULL DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS audit_log (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, admin_id INT UNSIGNED NULL, event VARCHAR(80) NOT NULL, session_id CHAR(32) NULL, details LONGTEXT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_audit_time(created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS legacy_imports (fingerprint CHAR(64) NOT NULL PRIMARY KEY, session_id CHAR(32) NOT NULL UNIQUE, imported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY fk_legacy_session(session_id) REFERENCES game_sessions(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS prize_images (id CHAR(32) NOT NULL PRIMARY KEY, mime VARCHAR(50) NOT NULL, bytes INT UNSIGNED NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS catalog_products (id CHAR(32) NOT NULL PRIMARY KEY, title VARCHAR(120) NOT NULL, summary VARCHAR(600) NOT NULL DEFAULT '', category VARCHAR(20) NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'draft', price_cents BIGINT UNSIGNED NOT NULL DEFAULT 0, image_url VARCHAR(2048) NOT NULL DEFAULT '', purchase_url VARCHAR(2048) NOT NULL DEFAULT '', sort_order INT UNSIGNED NOT NULL DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, INDEX idx_catalog_public(status,sort_order,created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- Idempotent controller tables. Run under the logged-in host account from upgrade.php.
CREATE TABLE IF NOT EXISTS controller_pairs (
 id CHAR(32) NOT NULL PRIMARY KEY,
 host_admin_id INT UNSIGNED NOT NULL,
 invite_hash CHAR(64) NOT NULL,
 controller_hash CHAR(64) NULL,
 device_name VARCHAR(48) NOT NULL DEFAULT '',
 status VARCHAR(16) NOT NULL DEFAULT 'inviting',
 invite_expires_at DATETIME NOT NULL,
 paired_expires_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 approved_at DATETIME NULL,
 revoked_at DATETIME NULL,
 INDEX idx_pair_host (host_admin_id,status,invite_expires_at),
 FOREIGN KEY fk_pair_admin (host_admin_id) REFERENCES admins(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS controller_commands (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 pair_id CHAR(32) NOT NULL,
 client_nonce VARCHAR(80) NOT NULL,
 command VARCHAR(24) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 delivered_at DATETIME NULL,
 UNIQUE KEY uq_phone_command (pair_id,client_nonce),
 INDEX idx_pending_commands (delivered_at,created_at,id),
 FOREIGN KEY fk_controller_command_pair (pair_id) REFERENCES controller_pairs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- V5P.1 server-observed command deliveries for synchronized phone, host, and TV screens.
CREATE TABLE IF NOT EXISTS controller_ui_events (
 command_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 session_id CHAR(32) NULL,
 acknowledged_at DATETIME NULL,
 failure_message VARCHAR(180) NULL,
 ack_source VARCHAR(16) NULL,
 INDEX idx_ui_session (session_id,command_id),
 FOREIGN KEY fk_ui_command (command_id) REFERENCES controller_commands(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Current host popup visibility, refreshed frequently and scoped by session.
CREATE TABLE IF NOT EXISTS controller_proof_state (
 session_id CHAR(32) NOT NULL PRIMARY KEY,
 host_admin_id INT UNSIGNED NOT NULL,
 claim_id CHAR(32) NULL,
 is_open TINYINT(1) NOT NULL DEFAULT 0,
 review_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
 consent_ack TINYINT(1) NOT NULL DEFAULT 0,
 phone_camera_enabled TINYINT(1) NOT NULL DEFAULT 0,
 capture_phase VARCHAR(12) NOT NULL DEFAULT 'live',
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_proof_host (host_admin_id,is_open,updated_at),
 FOREIGN KEY fk_controller_proof_host (host_admin_id) REFERENCES admins(id) ON DELETE CASCADE,
 FOREIGN KEY fk_controller_proof_session (session_id) REFERENCES game_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- HF7: One ephemeral WebRTC offer per paired phone. Media travels peer-to-peer,
-- never through this database. Remove disconnected peers after 3 minutes.
CREATE TABLE IF NOT EXISTS controller_live_streams (
 pair_id CHAR(32) NOT NULL PRIMARY KEY,
 session_id CHAR(32) NOT NULL,
 claim_id CHAR(32) NOT NULL,
 nonce CHAR(32) NOT NULL,
 offer_sdp MEDIUMTEXT NOT NULL,
 answer_sdp MEDIUMTEXT NULL,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_phone_live_session (session_id,claim_id,updated_at),
 CONSTRAINT fk_live_phone_pair FOREIGN KEY (pair_id) REFERENCES controller_pairs(id) ON DELETE CASCADE,
 CONSTRAINT fk_live_phone_session FOREIGN KEY (session_id) REFERENCES game_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- V5Q.15M independent video timelines; maintains legacy claim-aware edit_json.
CREATE TABLE IF NOT EXISTS montage_timeline_layers (session_id CHAR(32) NOT NULL PRIMARY KEY, layers_json LONGTEXT NOT NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, FOREIGN KEY fk_timeline_layers_session(session_id) REFERENCES game_sessions(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- V5Q.15N reusable safe editing templates (no source media is copied).
CREATE TABLE IF NOT EXISTS montage_templates (id CHAR(32) NOT NULL PRIMARY KEY, admin_id INT UNSIGNED NOT NULL, name VARCHAR(100) NOT NULL, template_json LONGTEXT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, INDEX idx_montage_template_owner(admin_id,created_at), FOREIGN KEY fk_montage_template_owner(admin_id) REFERENCES admins(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- V5Q20 prize package layers (see idempotent migration).
CREATE TABLE IF NOT EXISTS prize_packages (id CHAR(32) NOT NULL PRIMARY KEY,game_id VARCHAR(64) NOT NULL,name VARCHAR(100) NOT NULL,config_json LONGTEXT NOT NULL,budget_cents INT UNSIGNED NULL DEFAULT NULL,image_url VARCHAR(2048) NOT NULL DEFAULT '',enabled TINYINT(1) NOT NULL DEFAULT 1,publication_status VARCHAR(12) NOT NULL DEFAULT 'published',player_selectable TINYINT(1) NOT NULL DEFAULT 1,phone_camera_enabled TINYINT(1) NOT NULL DEFAULT 1,version INT UNSIGNED NOT NULL DEFAULT 1,sort_order INT UNSIGNED NOT NULL DEFAULT 0,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,INDEX idx_packages_game(game_id,sort_order),UNIQUE KEY uq_package_name(game_id,name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS prize_package_selection (game_id VARCHAR(64) NOT NULL PRIMARY KEY,package_id CHAR(32) NOT NULL,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,FOREIGN KEY fk_selected_package(package_id) REFERENCES prize_packages(id) ON DELETE RESTRICT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Universal Game/Plugin SDK v2: application-owned private KV namespace.
-- Games never submit SQL or define their own database tables.
CREATE TABLE IF NOT EXISTS game_plugin_storage (
 game_id VARCHAR(64) NOT NULL,
 scope_id CHAR(32) NOT NULL,
 storage_key VARCHAR(64) NOT NULL,
 value_json LONGTEXT NOT NULL,
 revision INT UNSIGNED NOT NULL DEFAULT 1,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY (game_id,scope_id,storage_key),
 INDEX idx_plugin_storage_game (game_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS movie_preview_state (
 admin_id INT UNSIGNED NOT NULL PRIMARY KEY,
 session_id CHAR(32) NOT NULL,
 state ENUM('playing','paused','stopped') NOT NULL DEFAULT 'stopped',
 revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY fk_movie_preview_owner(admin_id) REFERENCES admins(id) ON DELETE CASCADE,
 FOREIGN KEY fk_movie_preview_session(session_id) REFERENCES game_sessions(id) ON DELETE CASCADE,
 INDEX idx_movie_preview_session(session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
