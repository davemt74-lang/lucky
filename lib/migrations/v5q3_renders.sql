-- Render jobs keep an immutable edit snapshot, so later edits cannot silently alter an in-progress export.
CREATE TABLE IF NOT EXISTS montage_renders (
 id CHAR(32) NOT NULL PRIMARY KEY,
 session_id CHAR(32) NOT NULL,
 admin_id INT UNSIGNED NULL,
 status VARCHAR(16) NOT NULL DEFAULT 'queued',
 profile VARCHAR(16) NOT NULL DEFAULT 'standard',
 snapshot_json LONGTEXT NOT NULL,
 soundtrack_id CHAR(32) NULL,
 music_volume TINYINT UNSIGNED NOT NULL DEFAULT 18,
 output_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
 error_message VARCHAR(300) NOT NULL DEFAULT '',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 started_at DATETIME NULL,
 finished_at DATETIME NULL,
 INDEX idx_render_next (status,created_at),
 INDEX idx_render_session (session_id,created_at),
 CONSTRAINT fk_render_game FOREIGN KEY (session_id) REFERENCES game_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS montage_projects (
 session_id CHAR(32) NOT NULL PRIMARY KEY,
 edit_json LONGTEXT NOT NULL,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_montage_project_session FOREIGN KEY (session_id) REFERENCES game_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
