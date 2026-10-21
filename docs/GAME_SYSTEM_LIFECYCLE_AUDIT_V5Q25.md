# Lucky Game Hub V5Q.25 — Game lifecycle and ownership contract

**Scope:** the full local HomeServer console: package chooser, built-in Spin the Wheel,
packaged games, paired phones, media/proof, session history and administration.
This describes the proposed V5Q.25 candidate, not a production-certified release.

## Source of truth

- **HomeServer database** is authoritative for game session ID, module ID,
  lifecycle status, state version, immutable active-session package snapshot,
  claim/award/decision records, device pairing, durable controller commands,
  session events and archived history. Phone and browser state are projections.
- **Session identity is mandatory** for awards, proof, live events, edits and
  media. A claim is identified by both its claim ID and its originating game
  session. Old claims cannot be copied into a newly started game.
- **One live game on the HomeServer at a time** (`active` or `paused`), regardless
  of module. Start and resume must serialize on the same global console row
  before checking vacancy. Saved and finished sessions remain in history.
- **Package editing is not the same as package selection.** Eligible packages
  are enabled, published and player-selectable. At Start, the package's
  validated configuration is copied into the new session; subsequent edits
  cannot silently alter this snapshot.
- **Display and phone controls are not authorities.** Every input is validated
  on the server with pairing/access checks. Mutating requests are versioned
  and/or idempotent as appropriate; the owner-only desktop follows persisted
  outcomes rather than computing its own prize results.

## System state matrix

| State | Game view | Claimed-prize sidebar | Available actions | Historical records |
|---|---|---|---|---|
| No live session (selection/ready) | Package chooser (if 2+ eligible packages), otherwise ready wheel | Hidden, zero live claims | Choose package, Start | Separate History/Sessions only |
| `active` | Running game | Only that session's claims | Spin/select, pause, end, proof | Recorded under current session |
| `paused` | Paused game | Current session's claims | Resume, save, end, existing proof | Recorded under current session |
| `saved` | No live session; available under Saved Sessions | Hidden on chooser | Explicit resume/finish saved | Retained |
| `ended` (`GameSessionLifecycle::FINISHED`) | No live session | Hidden on chooser | View history, proof/review where permitted | Immutable game state and award ledger |

A pending prize offer is a **sub-state of the current game**, not a new game
session. Spin must not award a second prize until the previous award is
revealed and its accept/decline decision has been resolved.

## End-to-end transitions

1. **Enter dashboard → choose game → choose prize package.** The default
   desktop live lookup is `current`, never `last`. No historical game can
   populate the live claimed-prizes list. The phone shows its own dashboard
   when no live game is running. Package selection is not itself a session.
2. **Start.** Shared console lock → check *global* vacancy → verify chosen
   game/package is installed and eligible → create server session/version 1,
   immutable configuration snapshot, and viewer token. Display becomes active.
3. **Spin or packaged game input.** Authenticated request → server checks
   session status and pending award gates → idempotent state mutation → one
   persisted result and event. Desktop/TV animates that result; it cannot
   mint a separate prize.
4. **Prize won.** Server saves award/claim to current session → result is
   revealed → phone gets the prize information, uploaded photo if present,
   description resolved with permitted smart tags, and a Play Video action
   when the prize contains playable video. Video playback is a separate
   owner-bound command to the desktop, not a phone iframe.
5. **Accept/decline.** Pending prize cannot be claimed twice. Accept opens
   the proof workspace for that exact claim/session; consent gates capture.
   Decline closes the offer and ends the game under current wheel policy.
6. **Proof.** Countdown → MediaRecorder active only after countdown → stop →
   review/retake → save evidence to the claim's revision timeline. Camera
   permissions and media stay private to the host; the red REC represents
   actual recording state, not merely a phone request.
7. **Pause/save/resume.** A pause keeps the current game live. Save removes it
   from live, preserving its state. Resume takes the shared console lock and
   refuses while *any other* game is active/paused.
8. **End Game.** Server transitions once to ended, preserves all claims and
   immutable history; phone returns to its game dashboard; desktop leaves
   the live session. Live claimed-prize sidebar empties. Past sessions live
   in Game Sessions, Claimed Prizes and editor/history links, not in chooser.
9. **Restart/reconnect.** Phone retries reuse the same nonce; a delayed
   response cannot create a duplicate prize or session. Removed or expired
   pairing cannot send new game actions. The host rehydrates from `current`.

## Current implementation corrections in this candidate

- Added `lib/CurrentGame.php`: one reusable live-only session reader.
- Added authenticated `api/index.php?action=current`; retained `last` only
  for compatibility/history clients that explicitly want a historical game.
- Switched desktop live polling to `current`, phone snapshot to `current`,
  and concealed the claims panel unless its session is active/paused.
- Added global HomeServer console lock/vacancy enforcement to desktop Start,
  phone Start, packaged-game launch, and saved-session resume.
- Created PHP transition tests, Playwright historical-claim isolation checks,
  and cross-entry-point source-contract assertions.

## Open certification gates (NOT validated here)

- **Real MariaDB concurrency:** race two different game Start requests (and
  a saved-session Resume) against the same database; assert *exactly one*
  succeeds and the other receives 409, with no orphan claim/session/event.
- **Real device reconnect:** host/phone network loss, stale version,
  repeated End/Start and replayed nonce, package activation/rollback while
  idle versus live, and pairing revocation mid-command.
- **Physical proof/media:** multiple USB cameras, permission denial,
  consent, recorded-countdown alignment, recording failure, replacement,
  uploaded-photo and uploaded-video playback in the desktop browser.
- **Full existing GitHub suite and Windows clean-install/recovery:** the
  local candidate cannot replace CI certification. Do not merge/deploy
  without matching green checks and tested upgrade/migration paths.
- **Pending UI requests:** package details and prize editing modals plus
  video-upload-only controls need separate confirmation in the next UI/QA
  build; the local candidate still contains legacy URL fields. Do not
  describe those earlier design requests as finished merely because the
  session-scope fix passes.
