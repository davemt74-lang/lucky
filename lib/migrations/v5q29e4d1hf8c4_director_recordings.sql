-- HF8C4: Director uses existing universal recording sessions. Rerunnable; no proof data changed.
CREATE TABLE IF NOT EXISTS director_broadcast_recordings (
 broadcast_id CHAR(32) NOT NULL PRIMARY KEY,
 recording_session_id CHAR(32) NOT NULL,
 CONSTRAINT fk_director_broadcast_recording FOREIGN KEY(broadcast_id) REFERENCES director_broadcasts(id) ON DELETE CASCADE,
 CONSTRAINT fk_director_recording_session FOREIGN KEY(recording_session_id) REFERENCES recording_sessions(id) ON DELETE CASCADE,
 INDEX idx_director_recording_session(recording_session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS director_recording_scenes (
 id CHAR(32) NOT NULL PRIMARY KEY,
 recording_session_id CHAR(32) NOT NULL,
 host_admin_id INT UNSIGNED NOT NULL,
 name VARCHAR(60) NOT NULL,
 source_id VARCHAR(100) NOT NULL,
 transition_kind VARCHAR(8) NOT NULL DEFAULT 'cut',
 overlay_text VARCHAR(100) NOT NULL DEFAULT '',
 overlay_sticker VARCHAR(16) NOT NULL DEFAULT '',
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_director_recording_scene(recording_session_id,host_admin_id,name),
 CONSTRAINT fk_director_recording_scene FOREIGN KEY(recording_session_id) REFERENCES recording_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
