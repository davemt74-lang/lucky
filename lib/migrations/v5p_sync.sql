-- V5P.1 reliable UI broadcast receipts. Idempotent and preserves all earlier phone commands.
CREATE TABLE IF NOT EXISTS controller_ui_events (
 command_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 session_id CHAR(32) NULL,
 acknowledged_at DATETIME NULL,
 failure_message VARCHAR(180) NULL,
 ack_source VARCHAR(16) NULL,
 INDEX idx_ui_session (session_id,command_id),
 FOREIGN KEY fk_ui_command (command_id) REFERENCES controller_commands(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
