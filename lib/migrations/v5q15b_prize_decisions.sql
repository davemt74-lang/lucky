-- Keep award evidence; track the explicit Accept/Decline decision separately.
CREATE TABLE IF NOT EXISTS prize_decisions (
 claim_id CHAR(32) NOT NULL PRIMARY KEY,
 session_id CHAR(32) NOT NULL,
 status VARCHAR(12) NOT NULL DEFAULT 'pending',
 decided_at DATETIME NULL,
 INDEX idx_prize_decision_session (session_id,status),
 CONSTRAINT fk_prize_decision_claim FOREIGN KEY(claim_id) REFERENCES game_claims(id) ON DELETE CASCADE,
 CONSTRAINT fk_prize_decision_session FOREIGN KEY(session_id) REFERENCES game_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
