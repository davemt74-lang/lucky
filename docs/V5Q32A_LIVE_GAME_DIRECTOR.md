# V5Q32A — Spin the Wheel Live Game Director

This release supports **two separate browsers/computers** during the same Spin the Wheel session:

1. Open `game.php` on the gameplay machine, complete player/host setup, and start a game.
2. From the game menu, click **Open Live Game Director**. Move this page to a separate computer, or open `director.php?game=<game-session-id>` directly after signing in as an admin.
3. The Director sidebar has a **Game** tab; the status strip remains above the live camera/program monitors. The two pages poll the same authenticated PHP/MySQL game session; direct browser networking and shared local storage are unnecessary.
4. In Game Director, cue turn introductions, prize reveals, reactions, costume close-ups, celebrations, and scene changes. Select a specific awarded prize and a player with an approved paired phone to assign its cameraman. **Skip to next player** explicitly advances the shared turn; ordinary prize spins continue to advance turns through existing gameplay logic.
5. An enabled camera prize package automatically creates the game's recording session. The Director opens that recording in its existing Camera Director workspace, where desktop cameras and paired phones can be armed. Recording and broadcasting remain **manual, permissioned actions**. Assigning a cameraman never grants remote microphone/camera access.
6. Start and stop group takes with explicit clicks. Director events, costume-layer actions, and awarded prizes appear as editor-linked moments. Timings are correlated with the server's recording-take clock and labeled **estimated**, not frame-accurate; the multicamera editor remains the place for final alignment and export.

## Data and API behavior

`GET api/index.php?action=director_context&session=<id>` requires admin authentication. When the session ID is omitted, it finds the current Spin the Wheel game. The response includes the current player, prize claims, paired-player camera approval status, event history, the game-linked recording session, takes, and estimated video offsets.

`POST api/index.php?action=director_action` requires admin authentication and CSRF. Accepts `session`, `expectedVersion`, `requestKey`, `action`, and action-specific fields:

- `cue`: `cue`, optional `claimId`, optional `note`.
- `assign_camera`: `claimId`, `pairId` (empty clears assignment).
- `next_player`: no `claimId`; guarded against a pending wheel reveal.

Server transactions lock the game row, reject stale versions, and persist an idempotent, timestamped event with a request signature. A replay of an identical request is safe; reusing a key for a different payload is rejected. The public wheel view does not expose private phone pair IDs, Director events, or claim identifiers.

No database migration was required for this release; the prior recording, controller-pair, and game-state schemas are reused.

## Verification

- PHP core test: `tests/v5q32a_live_game_director.php`
- MariaDB integration: `tests/v5q32a_live_game_director_db.php`
- Two independent Chromium browser contexts: `tests/v5q32a_live_game_director_browser.py`
- Existing Lucky game, phone, Camera Director, and production recording regression suites remain in CI.

Deployment is the normal root-level HostGator PHP/MySQL ZIP. No nested ZIP or extra installer is required; use the existing installer/upgrade flow, and retain current customer data and uploads.
