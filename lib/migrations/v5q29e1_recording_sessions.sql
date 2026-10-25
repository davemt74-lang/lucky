-- V5Q.29E1: universal recording groups, independent of games and prize claims.
-- All statements are rerunnable. Existing media, claims, and timelines are untouched.
CREATE TABLE IF NOT EXISTS recording_sessions (
 id CHAR(32) NOT NULL PRIMARY KEY,
 owner_admin_id INT UNSIGNED NOT NULL,
 recording_type VARCHAR(24) NOT NULL,
 title VARCHAR(160) NOT NULL,
 description VARCHAR(2000) NOT NULL DEFAULT '',
 game_session_id CHAR(32) NULL,
 status VARCHAR(16) NOT NULL DEFAULT 'draft',
 revision INT UNSIGNED NOT NULL DEFAULT 1,
 create_nonce VARCHAR(80) NOT NULL,
 request_hash CHAR(64) NOT NULL,
 started_at DATETIME(3) NULL,
 ended_at DATETIME(3) NULL,
 created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
 updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
 UNIQUE KEY uq_recording_create (owner_admin_id,create_nonce),
 INDEX idx_recording_owner (owner_admin_id,created_at,id),
 INDEX idx_recording_game (game_session_id,created_at),
 FOREIGN KEY fk_recording_owner (owner_admin_id) REFERENCES admins(id) ON DELETE CASCADE,
 FOREIGN KEY fk_recording_game (game_session_id) REFERENCES game_sessions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS recording_takes (
 id CHAR(32) NOT NULL PRIMARY KEY,
 recording_session_id CHAR(32) NOT NULL,
 take_index INT UNSIGNED NOT NULL,
 title VARCHAR(120) NOT NULL,
 status VARCHAR(16) NOT NULL DEFAULT 'recording',
 client_nonce VARCHAR(80) NOT NULL,
 request_hash CHAR(64) NOT NULL,
 started_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
 ended_at DATETIME(3) NULL,
 created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
 UNIQUE KEY uq_recording_take_number (recording_session_id,take_index),
 UNIQUE KEY uq_recording_take_nonce (recording_session_id,client_nonce),
 FOREIGN KEY fk_recording_take_session (recording_session_id) REFERENCES recording_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS recording_sources (
 id CHAR(32) NOT NULL PRIMARY KEY,
 recording_session_id CHAR(32) NOT NULL,
 source_key VARCHAR(64) NOT NULL,
 source_kind VARCHAR(16) NOT NULL,
 display_name VARCHAR(120) NOT NULL,
 paired_device_id CHAR(32) NULL,
 created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
 UNIQUE KEY uq_recording_source (recording_session_id,source_key),
 FOREIGN KEY fk_recording_source_session (recording_session_id) REFERENCES recording_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A clip is referenced, never copied or relocated. A future direct camera upload
-- uses storage_key; only server-generated opaque identifiers may populate it.
CREATE TABLE IF NOT EXISTS recording_assets (
 id CHAR(32) NOT NULL PRIMARY KEY,
 recording_session_id CHAR(32) NOT NULL,
 take_id CHAR(32) NULL,
 source_id CHAR(32) NULL,
 claim_media_id CHAR(32) NULL,
 storage_key CHAR(32) NULL,
 kind VARCHAR(12) NOT NULL,
 start_offset_ms INT NOT NULL DEFAULT 0,
 sync_confidence TINYINT UNSIGNED NOT NULL DEFAULT 0,
 created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
 UNIQUE KEY uq_recording_claim_media (claim_media_id),
 UNIQUE KEY uq_recording_storage (storage_key),
 INDEX idx_recording_assets (recording_session_id,take_id,created_at,id),
 FOREIGN KEY fk_recording_asset_session (recording_session_id) REFERENCES recording_sessions(id) ON DELETE CASCADE,
 FOREIGN KEY fk_recording_asset_take (take_id) REFERENCES recording_takes(id) ON DELETE SET NULL,
 FOREIGN KEY fk_recording_asset_source (source_id) REFERENCES recording_sources(id) ON DELETE SET NULL,
 FOREIGN KEY fk_recording_asset_claim (claim_media_id) REFERENCES claim_media(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
