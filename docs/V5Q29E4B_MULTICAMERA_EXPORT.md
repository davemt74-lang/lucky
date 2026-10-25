# V5Q.29E4B — Multicamera MP4 export

This section builds on E3 paired-camera uploads and E4A's shared, non-destructive
multicamera timeline. A signed-in administrator can save camera cuts/offsets,
choose an audio mode, and queue a private H.264/AAC MP4 directly in the studio.
Approved phones still do not require separate website accounts.

## Export workflow

1. Open **Admin → Recordings → Edit multicamera timeline** for an existing
   recording with phone media. Run **upgrade.php** once after deployment.
2. Adjust camera alignment and click **Save alignment**.
3. Choose **Follow selected camera audio**, **Silent**, or an available
   audio-only source as the continuous master track.
4. Click **Render MP4**. The server snapshots the saved revision and its
   exact source/cut decisions. Stale revisions and simultaneous exports are
   rejected. Repeated POSTs with the same nonce return the same export job.
5. A CLI render worker converts the snapshot into segments, fills uncovered
   timeline gaps with black frames, joins them, optionally aligns one master
   audio recording, and atomically publishes the MP4. The editor polls for
   progress and shows a private play/download link on completion.

Each camera upload remains immutable and private in storage/recording-assets.
Only an authenticated administrator can request an export, list jobs, or
download/play the resulting video. A revoked controller cannot make new
recordings, and no phone controller can operate export endpoints.

## Running the worker

The existing Cloud CLI scheduler for bin/render-worker.php automatically
processes a pending multicamera export when no game montage is queued.
Alternatively schedule bin/recording-export-worker.php directly. Keep the
worker on the same machine and PHP configuration as the Lucky application,
with FFmpeg and FFprobe available. Nothing expensive runs in an HTTP request.

Windows HomeServer now invokes the existing CLI render worker in the background
on a bounded 15-second cadence while the HomeServer is running. The worker
has its own exclusive file lock and handles one queued job at a time.

## Initial render limits

- 1280×720, 30 fps, H.264/AAC MP4.
- 20-minute maximum output, 120 angle segments maximum.
- Controller polling is approximate; E4A manual offset edits are used by
  the renderer. It does not claim frame-accurate simultaneous start.
- A selected audio-only master replaces angle audio continuously. Follow
  mode uses each selected camera's audio, and silent mode has no program
  sound (an AAC silence track remains for stable concatenation).
- The E4B encoder does not composite several visible angles into a grid,
  nor does it render titles, captions, effects, or proxy streams. Those are
  separate editor enhancements.

## Certification

- Pure plan acceptance for source alignment, gaps, cuts, master audio, and limits.
- MariaDB for owner isolation, idempotency, concurrent-job prevention,
  immutable revision snapshots, stale edits, and cancellation.
- Browser acceptance for save-first gating, audio selection, queue, and links.
- FFmpeg integration for generated dual-camera media and master-audio MP4.
- Windows clean install/restart, packaged file parity, existing app regressions.
