-- Universal Game/Plugin SDK v2: application-owned private KV namespace.
-- Games never submit SQL or define their own database tables.
CREATE TABLE IF NOT EXISTS game_plugin_storage (
 game_id VARCHAR(64) NOT NULL,
 scope_id CHAR(32) NOT NULL,
 storage_key VARCHAR(64) NOT NULL,
 value_json LONGTEXT NOT NULL,
 revision INT UNSIGNED NOT NULL DEFAULT 1,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY (game_id,scope_id,storage_key),
 INDEX idx_plugin_storage_game (game_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
