-- Current host popup visibility, refreshed frequently and scoped by session.
CREATE TABLE IF NOT EXISTS controller_proof_state (
 session_id CHAR(32) NOT NULL PRIMARY KEY,
 host_admin_id INT UNSIGNED NOT NULL,
 claim_id CHAR(32) NULL,
 is_open TINYINT(1) NOT NULL DEFAULT 0,
 review_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_proof_host (host_admin_id,is_open,updated_at),
 FOREIGN KEY fk_controller_proof_host (host_admin_id) REFERENCES admins(id) ON DELETE CASCADE,
 FOREIGN KEY fk_controller_proof_session (session_id) REFERENCES game_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
