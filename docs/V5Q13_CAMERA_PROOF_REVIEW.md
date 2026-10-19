# V5Q.13 — Camera-first prize proof and phone review

## Workflow

1. The desktop host chooses **Start Game**. This user action requests browser camera permission early. Browsers cannot grant permissions in response to a remote phone command: if the game is started from a phone, camera activation still requires a click on the host.
2. A winning-prize popup opens directly onto live USB/built-in camera previews. Camera names, angle rotations, countdowns and capture actions are below the preview; the winner detail and Done/Spin Again are at the bottom. Camera enable/refresh/off remain available below the feeds.
3. Recording requires explicit consent. Stopped video is held **locally in browser memory** until approved. The review surface replaces Record with Play, Approve, Re-record and Delete for every unapproved clip.
4. **Approve** uploads only that clip to the verified claim/session and adds it to the game's timeline. **Delete** discards the blob and its object URL; no server upload. **Re-record** discards the rejected draft and starts capture again. Closing the popup discards unapproved local drafts.
5. An approved phone displays a default-closed **Prize proof controls** accordion only while the desktop proof popup or Edit Proof page advertises an active claim. Buttons are Record, Stop, Play, Approve, Re-record and Delete. Phone proof UI commands require an approved pairing, host-side execution and an acknowledgment; the phone cannot directly write claims or upload media.

## Deployment

- Back up your database and `storage` media directory.
- Upload the V5Q.13 full deployment; retain the existing `storage/config.php` and media.
- **Run `upgrade.php`** as an administrator to create `controller_proof_state` before using phone-side proof controls. Upgrades are idempotent. Existing game/claim/proof history is preserved.
- Serve over HTTPS and grant the browser camera permission on Start Game. USB device previews and WebM/MP4 MediaRecorder support depend on browser and hardware.
- Phones must be paired/approved and the host game/proof tab must remain open and visible to receive UI commands.

## Checks

- Automated camera-first flow with simulated webcam and video recorder: staged video is not uploaded; Play/Delete/Approve work; approved upload carries exact session and claim.
- Phone proof accordion checks confirm visibility only during live claim, collapsed by default, command delivery, and hiding after close.
- Existing game engine, pairing, proof history, camera claim URL, and keyboard navigation regression suites remain required in CI.

## Limitations

Tests use simulated cameras. Real USB recording, simultaneous cameras, webcam resource contention, live MySQL upgrades, and full phone↔host round trips must be verified on deployment. Local unapproved recordings are intentionally not recoverable after leaving the page.
