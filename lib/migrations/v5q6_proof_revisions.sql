-- V5Q.6: durable per-prize proof review and immutable revision history.
CREATE TABLE IF NOT EXISTS claim_proof_state (
 claim_id CHAR(32) NOT NULL PRIMARY KEY,
 session_id CHAR(32) NOT NULL,
 needs_revision TINYINT(1) NOT NULL DEFAULT 0,
 request_note VARCHAR(250) NOT NULL DEFAULT '',
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_proof_session (session_id),
 CONSTRAINT fk_proof_state_claim FOREIGN KEY (claim_id) REFERENCES game_claims(id) ON DELETE CASCADE,
 CONSTRAINT fk_proof_state_session FOREIGN KEY (session_id) REFERENCES game_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS claim_proof_revisions (
 id CHAR(32) NOT NULL PRIMARY KEY,
 claim_id CHAR(32) NOT NULL,
 session_id CHAR(32) NOT NULL,
 revision_no INT UNSIGNED NOT NULL,
 note VARCHAR(250) NOT NULL DEFAULT '',
 clip_snapshot_json LONGTEXT NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_claim_proof_version (claim_id,revision_no),
 INDEX idx_proof_revisions_session (session_id,claim_id),
 CONSTRAINT fk_proof_revision_claim FOREIGN KEY (claim_id) REFERENCES game_claims(id) ON DELETE CASCADE,
 CONSTRAINT fk_proof_revision_session FOREIGN KEY (session_id) REFERENCES game_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS claim_proof_revision_clips (
 revision_id CHAR(32) NOT NULL,
 clip_id CHAR(32) NOT NULL,
 PRIMARY KEY (revision_id,clip_id),
 CONSTRAINT fk_proof_clip_revision FOREIGN KEY (revision_id) REFERENCES claim_proof_revisions(id) ON DELETE CASCADE,
 CONSTRAINT fk_proof_clip_media FOREIGN KEY (clip_id) REFERENCES claim_media(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
