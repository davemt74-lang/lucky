-- E4C: immutable recording profile snapshots. Legacy recordings continue without rows.
CREATE TABLE IF NOT EXISTS recording_session_profiles (
 recording_session_id CHAR(32) NOT NULL PRIMARY KEY,
 game_id VARCHAR(64) NULL,
 profile_json LONGTEXT NOT NULL,
 created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
 INDEX idx_recording_profile_game (game_id,created_at),
 CONSTRAINT fk_recording_profile_session FOREIGN KEY (recording_session_id)
  REFERENCES recording_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
