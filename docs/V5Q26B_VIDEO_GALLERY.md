# V5Q.26B — Admin video gallery and session exports

- Admin → Video gallery displays finished MP4s across all game sessions, with Watch, Download, and edit links. Processing/failed exports have separate filters.
- Admin → Game sessions → Export current clips creates a server-owned immutable render job. It uses the saved timeline if present, otherwise a validated automatic draft, and never changes the stored edit.
- This action is valid for active or paused sessions only. Archived sessions can be exported through the admin-only editor. Missing media and an intentionally empty saved timeline do not queue jobs.
- The montage API uses requireAdmin() and CSRF validation. No phone/guest route may queue, download, or list private exports. The existing approved phone controller can Play/Pause/Stop a *temporary* movie preview on the host only.
- Preview is not an MP4 export. Enqueued jobs must run through `bin/render-worker.php` (or a configured scheduler); until then the gallery shows them as Processing.
- HomeServer and Cloud use identical shared PHP/JS/CSS application sources. No new Windows installer is built for this UI iteration.

## Finished-media library details
Each completed MP4 retains its immutable edit snapshot in `montage_renders.snapshot_json`,
with its game/session/date, and a private duration sidecar (`storage/renders/{id}.meta.json`).
The worker also produces a private poster JPEG (`storage/renders/{id}.jpg`) when FFmpeg supports it.
The Admin Gallery serves posters only through an authenticated endpoint and the editor
link reopens the original session timeline. Nothing is published to the public web.
A missing poster never hides a playable MP4. Files remain until explicitly deleted by
a future admin retention action; the existing system has no automated media deletion.
