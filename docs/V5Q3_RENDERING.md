# V5Q.3 — Video Studio / rendering guide

## Overview

The Prize Proof Studio remains private to logged-in administrators. Winner photos, videos, and audio recordings are saved under claim IDs, and a game session's Montage Editor now keeps a separate, non-destructive edit project. Recording files stay in `storage/claim-media/`. A rendered MP4 is stored in `storage/renders/`; both locations must remain inaccessible to direct HTTP access.

A saved project can split a source recording into multiple timeline segments, change titles and start/end timings, reorder by drag-and-drop or arrows, mix one uploaded soundtrack, and preview clips. **Auto assemble** starts with a video-first order within each winning claim, followed by photos and recorded audio. It never changes prize results or records a new claim.

## Install / upgrade

- Back up the MySQL database, `storage/config.php`, stored prize images, music and all captured media.
- Extract the **full** deployment archive over the existing installation, retaining your existing `storage/` contents. **Do not delete existing prize media.**
- Sign in as administrator and run `upgrade.php`. It creates the idempotent `montage_projects` and `montage_renders` tables as well as any missing V5Q.2 schema.
- Fresh installs (`install.php`) create these tables as part of initial setup.
- PHP 8.1+, PDO/MySQL and `proc_open` are required for MP4 encoding; FFmpeg and FFprobe must be available to the PHP CLI runtime. The encoder must have `libx264` and `aac` support.
- Create a CLI scheduled job **on your own host**, preferably once per minute, with an absolute path to `bin/render-worker.php` (e.g. `php /home/<your-account>/public_html/lucky/bin/render-worker.php`). Nothing is rendered inside a web request.
- If `ffmpeg`/`ffprobe` are not in the CLI PATH, set `LUCKY_FFMPEG_BIN` and `LUCKY_FFPROBE_BIN` to administrator-controlled absolute executable paths in the cron environment.
- Keep `storage/renders` and `storage/claim-media` writable by the PHP service and the CLI account. On Apache the bundled `.htaccess` files deny direct access to private media.

## Operation

1. Play Spin the Wheel and claim a prize. The full-screen modal offers optional proof footage and rotating, named USB cameras.
2. Open **Media timeline** from the game or winner popup.
3. Select the game session; a suggested video-first montage appears if no saved edit is present.
4. Trim, split, reorder, choose Cut or Fade transitions, or remove footage from the timeline, then **Save timeline**. Removing a segment does not delete its source file.
5. Optionally choose an uploaded Music Library track (external hosted URLs are not used for server renders), set the music level and choose 720p or 1080p.
6. **Export MP4** enqueues a job. The CLI worker processes one queue item at a time. Click **Refresh exports** for queued/running/ready/failed status.
7. **Watch MP4** or **Download MP4** after status becomes `ready`.

Export constraints: at most 80 timeline segments, at most 20 minutes of material, a single segment no longer than 120 seconds, and photos displayed for 1–20 seconds. Outputs use H.264 video with AAC audio and simple fade-through-black or direct-cut transitions; soundtracks loop and duck automatically under captured speech. Results are immutable snapshots of the edit at the moment of submission.

## Privacy, recovery and hosting limits

- The game continues running while rendering. Exports do not trigger a spin, award a prize, or change claim state.
- Browser-based clip preview requires a permitted play gesture in some browsers; production audio and USB cameras need local testing.
- If a host blocks PHP process creation or lacks FFmpeg, export stays pending until the worker runs on a compatible host. The JSON edit project can still be downloaded and the original clips remain accessible.
- If a render fails, the job shows an error to the administrator. Missing/deleted sources or unsupported media codecs can cause failures. Existing stored footage is never deleted by the worker.
- A worker process uses a file lock to prevent overlapping jobs and marks stale running exports as interrupted. Retry by queuing a **new** export.
- There is no automatic media-retention or disk-space cleanup policy. Administrators should monitor available disk space and back up recorded media.
- The local FFmpeg and browser tests do **not** certify HostGator permissions, server CPU capacity, real-device recording, production MySQL transactions or the unmerged GitHub repository.
