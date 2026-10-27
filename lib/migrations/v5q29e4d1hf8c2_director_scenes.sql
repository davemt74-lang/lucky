CREATE TABLE IF NOT EXISTS director_scene_presets (
 id CHAR(32) NOT NULL PRIMARY KEY,
 session_id CHAR(32) NOT NULL,
 claim_id CHAR(32) NOT NULL,
 host_admin_id INT NOT NULL,
 name VARCHAR(60) NOT NULL,
 source_id VARCHAR(100) NOT NULL,
 transition_kind VARCHAR(8) NOT NULL DEFAULT 'cut',
 overlay_text VARCHAR(100) NOT NULL DEFAULT '',
 overlay_sticker VARCHAR(16) NOT NULL DEFAULT '',
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_director_scene_name(session_id,claim_id,host_admin_id,name),
 INDEX idx_director_scenes_host(host_admin_id,session_id),
 CONSTRAINT fk_director_scene_session FOREIGN KEY(session_id) REFERENCES game_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
