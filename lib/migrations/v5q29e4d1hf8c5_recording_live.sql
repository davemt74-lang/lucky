-- HF8C5: private, short-lived live previews from armed phones to their recording host.
-- Program publication continues through director_broadcasts, never this table.
CREATE TABLE IF NOT EXISTS recording_live_streams (
 pair_id CHAR(32) NOT NULL PRIMARY KEY,
 recording_session_id CHAR(32) NOT NULL,
 nonce CHAR(32) NOT NULL,
 offer_sdp MEDIUMTEXT NOT NULL,
 answer_sdp MEDIUMTEXT NULL,
 created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
 updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
 INDEX idx_recording_live_session(recording_session_id,updated_at),
 CONSTRAINT fk_recording_live_pair FOREIGN KEY(pair_id) REFERENCES controller_pairs(id) ON DELETE CASCADE,
 CONSTRAINT fk_recording_live_session FOREIGN KEY(recording_session_id) REFERENCES recording_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
