-- V5Q.26 Preview state is owner-bound and contains no media URLs or private tokens.
CREATE TABLE IF NOT EXISTS movie_preview_state (
 admin_id INT UNSIGNED NOT NULL PRIMARY KEY,
 session_id CHAR(32) NOT NULL,
 state ENUM('playing','paused','stopped') NOT NULL DEFAULT 'stopped',
 revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY fk_movie_preview_owner(admin_id) REFERENCES admins(id) ON DELETE CASCADE,
 FOREIGN KEY fk_movie_preview_session(session_id) REFERENCES game_sessions(id) ON DELETE CASCADE,
 INDEX idx_movie_preview_session(session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
