# V5Q.5 — Post-game highlight review and bounded auto drafts

- An ended Spin the Wheel session displays a **Review highlights** action right on the game page. It links to `editor.php?session=<id>` and is hidden while active or paused.
- The same link reappears after refreshing an ended game or returning from another screen, and it tracks the active session ID.
- The editor continues to support manual trimming, reordering, soundtrack selection, and optional MP4 export. No background render occurs at end-game time.
- Automatic drafts retain per-claim chronological order and prioritize video before photos and audio for each claim.
- Existing manually saved montage projects are never replaced. Duplicate project creation is prevented.
- Automatic drafts choose at most 80 source clips and 20 minutes of footage. Recordings exceeding the individual 120-second render cap are trimmed *only in the edit decisions*, leaving captured originals intact. If the montage is full, a later short clip may be included while an overlong one is skipped.
- An empty session remains empty, without fake or placeholder clips.

## Verification

`php tests/auto_draft.php`, `php tests/montage.php`, PHP syntax, `node --check public/game.js`, and `python tests/v5q5_postgame_browser.py`. Full GitHub CI gates before merge. Actual host MySQL, camera and FFmpeg still require deployment tests.
