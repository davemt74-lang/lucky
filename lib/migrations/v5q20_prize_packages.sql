-- Separate reusable prize definitions from immutable game sessions.
CREATE TABLE IF NOT EXISTS prize_packages (
 id CHAR(32) NOT NULL PRIMARY KEY,
 game_id VARCHAR(64) NOT NULL,
 name VARCHAR(100) NOT NULL,
 config_json LONGTEXT NOT NULL,
 enabled TINYINT(1) NOT NULL DEFAULT 1,
 player_selectable TINYINT(1) NOT NULL DEFAULT 1,
 version INT UNSIGNED NOT NULL DEFAULT 1,
 sort_order INT UNSIGNED NOT NULL DEFAULT 0,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_packages_game (game_id,sort_order),
 UNIQUE KEY uq_package_name (game_id,name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS prize_package_selection (
 game_id VARCHAR(64) NOT NULL PRIMARY KEY,
 package_id CHAR(32) NOT NULL,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 FOREIGN KEY fk_selected_package (package_id) REFERENCES prize_packages(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
