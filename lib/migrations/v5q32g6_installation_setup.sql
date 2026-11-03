-- Durable setup identity. It contains no database or administrator passwords.
-- Application code accepts only the singleton id=1 and never rebinds a receipt.
CREATE TABLE IF NOT EXISTS installation_setup_receipt (
 id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
 deployment VARCHAR(16) NOT NULL,
 attempt_hash CHAR(64) NOT NULL,
 config_hmac CHAR(64) NOT NULL,
 admin_id INT UNSIGNED NULL,
 state VARCHAR(24) NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
