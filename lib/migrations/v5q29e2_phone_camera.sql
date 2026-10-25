-- V5Q.29E2: explicit per-session phone-camera approval, with independent media metadata.
-- Existing prize proof media and controller tables are not altered.
CREATE TABLE IF NOT EXISTS recording_phone_permissions (
 recording_session_id CHAR(32) NOT NULL PRIMARY KEY,
 phone_enabled TINYINT(1) NOT NULL DEFAULT 0,
 updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
 FOREIGN KEY fk_rec_phone_permission (recording_session_id) REFERENCES recording_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS recording_media_files (
 asset_id CHAR(32) NOT NULL PRIMARY KEY,
 mime VARCHAR(60) NOT NULL,
 title VARCHAR(120) NOT NULL,
 bytes INT UNSIGNED NOT NULL,
 sha256 CHAR(64) NOT NULL,
 duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
 uploaded_by_pair_id CHAR(32) NULL,
 created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
 INDEX idx_recording_media_pair (uploaded_by_pair_id,created_at),
 FOREIGN KEY fk_rec_media_asset (asset_id) REFERENCES recording_assets(id) ON DELETE CASCADE,
 FOREIGN KEY fk_rec_media_pair (uploaded_by_pair_id) REFERENCES controller_pairs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
