-- Ephemeral, capability-scoped WebRTC signaling for live paired-phone proof previews.
-- SDP contains connectivity information, never media. Offers expire if the phone stops polling.
CREATE TABLE IF NOT EXISTS controller_live_streams (
 pair_id CHAR(32) NOT NULL PRIMARY KEY,
 session_id CHAR(32) NOT NULL,
 claim_id CHAR(32) NOT NULL,
 nonce CHAR(32) NOT NULL,
 offer_sdp MEDIUMTEXT NOT NULL,
 answer_sdp MEDIUMTEXT NULL,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_phone_live_session (session_id,claim_id,updated_at),
 CONSTRAINT fk_live_phone_pair FOREIGN KEY (pair_id) REFERENCES controller_pairs(id) ON DELETE CASCADE,
 CONSTRAINT fk_live_phone_session FOREIGN KEY (session_id) REFERENCES game_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
