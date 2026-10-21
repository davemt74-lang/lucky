-- Owner-authorized phone dashboard → desktop game launch requests.
CREATE TABLE IF NOT EXISTS controller_game_launches (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 host_admin_id BIGINT NOT NULL,
 pair_id CHAR(32) NOT NULL,
 nonce VARCHAR(80) NOT NULL,
 game_id VARCHAR(64) NOT NULL,
 status VARCHAR(12) NOT NULL DEFAULT 'queued',
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 accepted_at TIMESTAMP NULL,
 UNIQUE KEY uq_phone_dashboard_command(pair_id,nonce),
 INDEX idx_host_dashboard_launch(host_admin_id,status,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
