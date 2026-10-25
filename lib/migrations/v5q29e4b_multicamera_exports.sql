-- V5Q.29E4B: immutable export jobs for shared multicamera edit decisions.
CREATE TABLE IF NOT EXISTS recording_exports (
 id CHAR(32) NOT NULL PRIMARY KEY,
 recording_session_id CHAR(32) NOT NULL,
 owner_admin_id INT UNSIGNED NOT NULL,
 client_nonce VARCHAR(80) NOT NULL,
 request_hash CHAR(64) NOT NULL,
 timeline_revision INT UNSIGNED NOT NULL,
 audio_asset_id CHAR(32) NULL,
 profile VARCHAR(20) NOT NULL DEFAULT 'standard',
 snapshot_json MEDIUMTEXT NOT NULL,
 status ENUM('queued','running','ready','failed','cancelled') NOT NULL DEFAULT 'queued',
 output_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
 duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
 error_message VARCHAR(300) NOT NULL DEFAULT '',
 created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
 started_at DATETIME(3) NULL,
 finished_at DATETIME(3) NULL,
 UNIQUE KEY uq_recording_export_nonce (recording_session_id,client_nonce),
 INDEX idx_recording_export_queue (status,created_at,id),
 INDEX idx_recording_export_owner (owner_admin_id,recording_session_id,created_at),
 FOREIGN KEY fk_recording_export_session (recording_session_id) REFERENCES recording_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
