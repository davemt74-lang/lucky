# V5Q.6 — Prize proof revisions and game screen tabs

## User experience

Each claimed prize on the live game page and on `claims.php` includes **Edit proof**. The editor opens `proof.php?session=<sessionId>&claim=<claimId>` as a full-screen camera/capture view with existing multi-camera previews, rotating five-second countdown, timed angle clips, and file import. Proof editing never re-awards a prize or spins the wheel.

A claimed prize can be marked **Needs revision** with a note. The revision request appears with that prize in the game ledger and claim history. **Save proof revision** saves the current immutable clip metadata snapshot with the note and clears the request. Prior revisions are visible and their original clip stream URLs remain available. The first time the proof editor opens, the system saves an idempotent **Original proof** snapshot before later edits. Historical source recordings referenced by a snapshot cannot be permanently deleted from the media editor. Remove them from the edit project instead, or retain the source in its historical revision.

The game screen now places **Claimed prizes** above the spinning wheel and puts the **Music** and **Connected Phones** controls in tabs, while preserving phone approvals and the host-facing music sliders and voice toggle. Phone pairing count updates through the existing pairing polling.

## Deployment

Backup MySQL and private `storage` media first. Upload this **full** package over V5Q.5 (keep your original `storage/config.php`), then sign in and run `upgrade.php`. Fresh installs automatically create the new three proof history tables from `lib/migrations/v5q6_proof_revisions.sql`. This upgrade does not change existing claims or previously uploaded media. Refresh phone and desktop pages to clear cached JS.

## Required acceptance gates

- PHP, JS syntax and claim proof model tests
- Host claimed-prize placement and phone/music tab interactions on mobile and desktop
- Claim-specific proof editor baseline, revision notes and revision history UI
- Old game, phone pairing, camera proof, montage and MP4 regression checks
- Real staging MySQL migration, USB camera and media retention, and browser access verification (field certification)

Actual production installation remains subject to a live MySQL and browser/hardware test. Automated CI is not a substitute for these field checks.
