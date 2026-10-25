-- V5Q.29E3: explicit, revocable, per-device participation in coordinated takes.
-- Existing uploads and recording sessions remain untouched.
CREATE TABLE IF NOT EXISTS recording_camera_arms (
 recording_session_id CHAR(32) NOT NULL,
 pair_id CHAR(32) NOT NULL,
 capture_kind VARCHAR(8) NOT NULL,
 armed TINYINT(1) NOT NULL DEFAULT 0,
 last_take_id CHAR(32) NULL,
 last_state VARCHAR(16) NOT NULL DEFAULT 'standby',
 last_seen_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
 updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
 PRIMARY KEY (recording_session_id,pair_id),
 INDEX idx_recording_camera_arms_live (recording_session_id,armed,last_seen_at),
 FOREIGN KEY fk_recording_arm_session (recording_session_id) REFERENCES recording_sessions(id) ON DELETE CASCADE,
 FOREIGN KEY fk_recording_arm_pair (pair_id) REFERENCES controller_pairs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS recording_capture_sync (
 asset_id CHAR(32) NOT NULL PRIMARY KEY,
 recorded_start_ms BIGINT NOT NULL,
 recorded_end_ms BIGINT NOT NULL,
 clock_offset_ms INT NOT NULL,
 sync_method VARCHAR(24) NOT NULL DEFAULT 'controller_poll',
 FOREIGN KEY fk_recording_sync_asset (asset_id) REFERENCES recording_assets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
