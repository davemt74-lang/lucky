# V5Q32B — Live Program switching and synchronized game presentation

## Two-screen operation

1. Start **Spin the Wheel** on the host's `game.php`. Share the existing read-only `display.php` invitation with the TV/audience computer if you want a dedicated display.
2. On a second computer, open `director.php?game=<game-session-id>` as an authorized admin and let Live Game Director load the linked recording workspace.
3. Connect cameras or approved player phones using the existing Camera Director tools. In **Live Program camera switching**, choose a source, then **Cue Preview** to inspect it without changing Program.
4. Pick Cut, Fade, Wipe or Dip to Black; press **Take to Program**. The existing Director compositor performs the real camera switch. The server records a timestamped `program_cut` moment for the synchronized timeline and video editor.
5. Use **Show controls** to introduce players, reveal prizes, spotlight one itemized costume layer, show a reaction or celebration, or change scenes. Gameplay and audience screens show the corresponding safe, expiring lower-third. **Clear on-screen cue** removes it early.
6. Existing manual grouped recording and authorized Live View broadcasting remain separate controls. Select **Manage authorized Live View broadcasting** if you want to publish the Program feed to *authorized Live View viewers*.

## Security and consent

- A **Take** changes the Director's real local Program compositor; it does **not** secretly start recording, publish the stream, request a microphone permission, or activate a player phone. Only sources already connected and authorized in Camera Director are selectable.
- The shared public game-view response contains only `showPresentation` (`id`, `cue`, `headline`, `detail`, `expiresAtMs`) and the pre-existing gameplay fields. It never contains private broadcast invitation keys, camera-pair credentials, recording IDs, or Director event history.
- Presentation uses `textContent` and has a 15-second server expiry. It is read-only on both gameplay and audience screens and automatically clears after its expiry, on manual clear, or when a game ends.
- Costume spotlight item IDs are validated against the active player's actual costume layers and their awarded prize-costume layers. Other players' items cannot be selected by supplying an arbitrary ID.
- Director commands require authorized admin API access, active/paused game state, an expected server version, and an idempotency key. Program cuts are logged **after** a successful compositor Take. These game markers are approximate, not frame-accurate timecode.

## Scope of this release

This release makes the Director's Program cuts real **inside the existing Camera Director Program feed**. It does **not** automatically embed the WebRTC Program video into `game.php` or `display.php`: that needs a separate, explicitly permissioned viewer-connection design so private bearer invitations are never disclosed through the public game state. For now, game/audience browsers show the wheel and synchronized lower-thirds; authorized Live View viewers can watch the separately published Program video.

## Verification

- `tests/v5q32b_program_stage.php`: live cues, player-specific costume layers, idempotency, allowed transitions, private view boundaries, clear, next turn, game-end behavior.
- `tests/v5q32b_program_stage_browser.py`: separate Director and audience browser contexts, real Program mock calls, previews, timeline logging, XSS-safe show cards, clear, expiry, and narrow mobile viewport.
- Existing V5Q32A Live Game Director tests continue to run in CI.
