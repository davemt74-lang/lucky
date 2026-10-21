# V5Q.24 — Phone Game Dashboard and Live Proof REC

## End Game → Phone Game Dashboard

When a wheel session ends (on the desktop or a paired phone), the phone
stops showing the old game buttons and opens **Choose a game** instead. The
game list contains only the built-in wheel and verified, activated packages
installed locally on HomeServer. It is not the commerce catalog.

When a packaged-game session ends or is saved, its dedicated controller
unloads the ended iframe and automatically returns to the phone dashboard.
The already approved phone pairing is retained until its normal expiry or
manual revocation. The ended game's viewer token is **not** reused.

A phone may explicitly Start a game from its dashboard. The HomeServer
checks its existing approved bearer pairing, limits launch frequency,
requires a one-time nonce, verifies the installed game, and creates one
server-authoritative session. Repeated network submissions with the same
nonce return the original session instead of creating another.

The signed-in owner's desktop dashboard checks for recently started
owner-bound phone sessions and opens the matching game screen. Phone
credentials and private game data are never sent to unrelated clients.
For a game declaring a custom phone controller, the approved phone loads
that game's own sandboxed controller. For a desktop-only game, the phone
stays on the chooser and tells the player to use desktop controls.

No new cloud dependency, new consumer game or new game database migration
is required.

## Desktop claimed-prize proof recording

A red **REC · Recording** button appears in the upper-right of the
desktop video only while a video/audio MediaRecorder is actively recording.
Tap it to stop. The button is hidden during idle camera preview,
countdowns, processing, review, saved clips and after leaving the page.
Its visibility uses actual browser MediaRecorder state rather than just
the phone recording-phase notification.

## Certification

- `tests/v5q24_phone_dashboard_db.php`: real MariaDB install, paired
  phone session launch, owner-scoped desktop handoff, duplicate nonce
  protection, refusal of conflicting live games and switching to wheel.
- `tests/v5q24_phone_dashboard_browser.py`: paired phone game list,
  three game-controller modes, client authorization, mobile layout and
  host dashboard handoff.
- Updated `tests/v5q22h_phone_browser.py`: packaged controller End Game
  must return automatically to phone game selection while preserving
  approved pair credentials and unloading the original game's sandbox.
- Updated `tests/v5q13_camera_review_browser.py`: live red REC appears
  only while recording and disappears immediately when stopped.
