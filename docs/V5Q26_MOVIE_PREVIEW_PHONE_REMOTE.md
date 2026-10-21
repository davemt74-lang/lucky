# Lucky Game Hub V5Q.26 — Full Movie Timeline Preview + Paired Phone Remote

## User experience

- **Video Studio → Preview Movie** opens a full-screen, centrally aligned preview with only the assembled movie and a Close control. It is separate from the render/export modal, and does not alter the saved project or export an MP4.
- The existing non-destructive preview engine uses the V1 clip order and trim boundaries, photos with timed durations, per-clip looks and captions, timed T1 titles, and B1 background audio with source/voiceover tracks where available.
- An **approved phone paired to the same host account** switches from regular gameplay to a dedicated Play / Pause / Stop remote. During Pause, playback, still-photo duration, captions, and soundtrack time are paused. Stop closes the movie preview on the host and returns the phone to its game-selection UI.
- The host can close the preview independently; closing or finishing it returns phones to their normal controls. Another host account cannot control this preview.
- The prize-claim phone UI has larger distinct visual prize cards with independent photo and desktop-play controls; prize acceptance and recording still follow their existing server-authoritative workflow.

## Architecture and permission boundary

- The same Cloud/HomeServer PHP, JS, CSS source is used for this feature.
- A single `movie_preview_state` record per admin/host tracks only `session_id`, `playing|paused|stopped`, a monotonic revision, and a liveness timestamp. It has no media URLs, database credentials, device IDs, or playable video bytes.
- The movie host writes start/heartbeat/close via the existing **signed-in Admin + CSRF** `api/montage.php` endpoints. The phone reads public preview status from its normal `api/controller.php?action=status` polling.
- Phone playback commands are POST requests using existing approved pairing, short random idempotency nonce, replay-safe `controller_commands` uniqueness, and the existing rate limiter. All commands are locked to the owner's currently active movie session; a mismatch, expiry, or revoked pairing is rejected.
- If the desktop or its browser exits, the heartbeat stops and the preview becomes unavailable after 12 seconds. No stale movie mode survives a restart.
- This feature has no cloud dependency once installed on the local Windows HomeServer. Admin and HomeServer bootstrap/upgrade paths both include fixed `v5q26_movie_preview.sql` schema migration; run `upgrade.php` after deploying to an existing Cloud installation.

## Media behavior and limitations

- This is a browser **timeline preview**, not a pre-rendered final MP4. FFmpeg's final output may differ in exact transition and encoding treatment. Render & Export remains the authoritative final movie output.
- Browser autoplay policy can require one tap on the central video to start audio. If a device has missing or unsupported media, existing per-clip errors are shown and the rest of the timeline continues.
- Phone control works only while the Video Studio page remains open in the host's browser. The phone does not stream or download private proof footage; playback happens only on the authenticated desktop.
- Existing locally uploaded, authorized media still require their normal authenticated session. Camera recording and prize acceptance have not been moved into this remote.

## Release gates

Local tests: `tests/v5q26_movie_preview_browser.py` and `tests/v5q26_movie_preview_contract.php`, plus the V5Q.25D and editor regression suite. Full MariaDB concurrency, multi-device physical-phone testing, GitHub CI and Windows HomeServer certification must be green on a PR SHA before merging or calling this production ready.
