-- V5Q32E1: stable, opaque public show addresses, independent of ephemeral broadcasts.
-- Does not expose recording IDs or private invitation secrets to viewers.
CREATE TABLE IF NOT EXISTS director_show_channels (
 public_id CHAR(32) NOT NULL PRIMARY KEY,
 recording_session_id CHAR(32) NOT NULL,
 host_admin_id INT UNSIGNED NOT NULL,
 created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
 UNIQUE KEY uq_director_show_recording (recording_session_id),
 INDEX idx_director_show_owner (host_admin_id,created_at),
 CONSTRAINT fk_director_show_recording FOREIGN KEY (recording_session_id) REFERENCES recording_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
