# V5Q.4 — Automatic session-end highlights

When `GameEngine` successfully changes a session status to `ended`, the shared `saveState()` function attempts to save an automatic montage project with footage already uploaded for that session. It never updates existing `montage_projects` rows and never changes `claim_media`, `game_claims`, or the prize sequence. An `INSERT IGNORE` protects against concurrent duplicate requests.

The resulting project contains up to 80 clips and video-first camera angles within each claim. An empty session creates no montage. The admin can open `editor.php?session=<id>`, refine the suggested cut, add background music and manually queue FFmpeg rendering. No expensive media work is done while ending a game.

Errors in optional montage-draft generation are logged without blocking game completion. A production MySQL test on your actual host remains required. This phase does not authorize uploading audio, opening camera streams, or making new claims without consent.
