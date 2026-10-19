-- V5Q: Claim-linked private uploads and per-session editable story timeline.
CREATE TABLE IF NOT EXISTS claim_media (
 id CHAR(32) NOT NULL PRIMARY KEY,
 session_id CHAR(32) NOT NULL,
 claim_id CHAR(32) NOT NULL,
 kind VARCHAR(8) NOT NULL,
 mime VARCHAR(60) NOT NULL,
 title VARCHAR(120) NOT NULL,
 source_label VARCHAR(120) NOT NULL DEFAULT '',
 angle_label VARCHAR(80) NOT NULL DEFAULT '',
 angle_index TINYINT UNSIGNED NOT NULL DEFAULT 0,
 bytes BIGINT UNSIGNED NOT NULL,
 duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
 trim_start_ms INT UNSIGNED NOT NULL DEFAULT 0,
 trim_end_ms INT UNSIGNED NOT NULL DEFAULT 0,
 photo_duration_ms INT UNSIGNED NOT NULL DEFAULT 4000,
 sort_order INT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_claim_media_order (session_id,sort_order,id),
 INDEX idx_claim_media_claim (claim_id),
 CONSTRAINT fk_claim_media_session FOREIGN KEY (session_id) REFERENCES game_sessions(id) ON DELETE CASCADE,
 CONSTRAINT fk_claim_media_claim FOREIGN KEY (claim_id) REFERENCES game_claims(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
