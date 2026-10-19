# V5Q.10 — game-first navigation and session-scoped claims

- The global navigation contains Games, Shop, Devices, Admin Studio and Products. Host Game is launched from a game tile or Admin Studio, not repeated as a global tab.
- `game.php` contains only Game and Music tabs, with the current session's claimed prizes in the right-hand panel. The phone-pairing UI now lives prominently on `devices.php`; the game page continues to receive remote inputs through the authenticated `ui-receiver.js`.
- `claims.php` requires an explicit 32-character hexadecimal `session` parameter; otherwise it redirects to `game.php`. It never falls back to the latest session or aggregates all games. CSV exports, proof links and return navigation remain session-specific.
- The video editor sets its Session Claims link from the selected game session.
- Existing database schema is unchanged. Back up media/database and deploy all changed files, including updated JS/CSS assets.
- Hardware phone pairing and MySQL field validation remain open until verified against the actual host.
