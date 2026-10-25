# V5Q.29E1 — Universal Recording Sessions foundation

This is the first independently certified section of the Recording Sessions roadmap.
It creates recording groups without changing existing games, prize proof clips, or
the legacy per-game Video Studio timelines. The full-screen phone camera and
session-based capture UI follow only after this foundation is green and merged.

## Model

- **Recording Session**: administrator-owned group, one of `game`, `live_event`,
  `production`, or `general`. A game link is optional except for `game` mode.
  Session states are `draft`, `active`, `completed`, `archived`; revision checks
  prevent stale lifecycle changes.
- **Recording Take**: numbered, idempotently created part of a session; a
  recording may have several takes. An active take prevents completing a session.
- **Recording Source**: stable, human-readable camera identity within a
  recording. A device key cannot be silently reassigned to a different device.
- **Recording Asset**: a file reference that may link to existing `claim_media`
  without copying or relocating footage. Each legacy clip is associated with at
  most one recording session. The future camera upload pipeline will use opaque
  `storage_key` references instead of public filesystem paths.

The model supports game recordings linked to the existing `game_sessions` table,
but general, production, and live-event sessions can exist without a game or
claimed prize. It is compatible with multiple cameras and different recording
types using the same underlying timeline grouping.

## Existing media and upgrade safety

- Both `install.php` and `upgrade.php` apply the new `v5q29e1_recording_sessions.sql`
  migration. All `CREATE TABLE IF NOT EXISTS` statements are repeatable.
- Existing game, claim, proof, and editor rows and media files remain unchanged.
- The authorized `import_game` operation links existing clips into a recording
  session for the chosen game with an idempotent, resumable import. It never
  deletes, renames, or duplicates the original media.
- Existing Video Studio and proof editing routes continue using their present
  sources until the later editor integration section is certified.

## API (administrator access only)

`api/recording-sessions.php` supports `GET action=list` and `GET action=detail&id=…`,
plus `POST` actions `create`, `import_game`, `status`, `take_start`,
`take_stop`, `source`, `attach_claim` using JSON input and the existing CSRF
header. Session creation and take starts require unique client nonces; exact
retries return the same object while conflicting retries are rejected. All
operations scope the recording to the logged-in administrator. The API does not
allow a phone token to bypass that gate; paired-phone capture uses a separate
governed route in the next section.

## Explicitly deferred to V5Q.29E2+

- No automatic camera activation, no replacement of the existing prize-proof UI.
- No long-form chunked phone uploads, live streaming, or browser session camera UI.
- No false claim of frame-accurate synchronization. Shared capture timing,
  audio alignment, timeline angle switching, and session-based rendering are
  later sections with their own tests and PRs.

## Verification

`tests/v5q29e1_recording_sessions_contract.php` validates migration wiring and
authorization. `tests/v5q29e1_recording_sessions_db.php` runs on real MariaDB
and checks idempotency, isolation, status transitions, matching game/media
links, repeated legacy imports, and preservation of original claim rows.
