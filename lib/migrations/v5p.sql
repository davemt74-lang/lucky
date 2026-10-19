-- Idempotent controller tables. Run under the logged-in host account from upgrade.php.
CREATE TABLE IF NOT EXISTS controller_pairs (
 id CHAR(32) NOT NULL PRIMARY KEY,
 host_admin_id INT UNSIGNED NOT NULL,
 invite_hash CHAR(64) NOT NULL,
 controller_hash CHAR(64) NULL,
 device_name VARCHAR(48) NOT NULL DEFAULT '',
 status VARCHAR(16) NOT NULL DEFAULT 'inviting',
 invite_expires_at DATETIME NOT NULL,
 paired_expires_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 approved_at DATETIME NULL,
 revoked_at DATETIME NULL,
 INDEX idx_pair_host (host_admin_id,status,invite_expires_at),
 FOREIGN KEY fk_pair_admin (host_admin_id) REFERENCES admins(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS controller_commands (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 pair_id CHAR(32) NOT NULL,
 client_nonce VARCHAR(80) NOT NULL,
 command VARCHAR(24) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 delivered_at DATETIME NULL,
 UNIQUE KEY uq_phone_command (pair_id,client_nonce),
 INDEX idx_pending_commands (delivered_at,created_at,id),
 FOREIGN KEY fk_controller_command_pair (pair_id) REFERENCES controller_pairs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
