-- V5Q.29E4A: non-destructive shared-angle timeline edit projects.
CREATE TABLE IF NOT EXISTS recording_timeline_edits (
 recording_session_id CHAR(32) NOT NULL PRIMARY KEY,
 revision INT UNSIGNED NOT NULL DEFAULT 1,
 timeline_json MEDIUMTEXT NOT NULL,
 created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
 updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
 FOREIGN KEY fk_recording_timeline_session (recording_session_id) REFERENCES recording_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
