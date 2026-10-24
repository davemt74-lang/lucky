# Lucky Game Hub V5Q.28B — Paired Phone Proof Capture

## User workflow

1. On desktop, accept a prize and open the full-screen **Prize Proof** modal.
2. Expand **Camera settings → Permissions**, then check **Use phone camera**.
3. On the QR-paired, host-approved phone (no Admin login needed), open **Camera** and confirm the existing recording consent checkbox.
4. Tap **Open phone camera**. Select **Photo**, **Video**, or **Audio**. Tap Start to request camera/microphone access, then take a photo or record for up to 60 seconds; alternatively select an existing photo/video/audio file.
5. Preview, **Send to Video Studio**, or Retake. The host Proof panel refreshes saved clips, and the Video Studio media bin refreshes in an open desktop editor. Phone videos are inserted into the server-maintained claimed-prize montage timeline. Photos and audio are available in the media bin for timeline placement.
6. Unchecking **Use phone camera**, ending the proof, disconnecting the phone, or allowing the pairing to expire stops new uploads. Pending uploads are checked again on the server before committing.

## Server security

- Only approved, non-expired QR controller capabilities can post media; no Admin browser cookie is used. The upload endpoint checks HTTPS and the same Origin (including port).
- The host must explicitly check **Use phone camera**, the proof must be open, the active claim/session/host must match, recording consent must be acknowledged, and the host heartbeat must be fresh.
- Phone uploads use the same MIME/content, dimensions, per-file (80 MiB), game-budget (600 MiB), clip-count (160), private file storage, audit, and video timeline pipeline as desktop uploads.
- An upload nonce produces a stable server clip ID; exact retried files return the same record instead of creating a duplicate. Different bytes with the same nonce are rejected.
- Source/angle labels are server-generated; phone uploads cannot replace an existing proof clip directly. Previous proof revisions remain protected.
- Camera/microphone never auto-activate. Tracks stop on close, retake, host revocation, or page exit. No recordings are sent to Claude.

## Deployment / upgrade

Upload the full Cloud ZIP, run `upgrade.php` as Admin. This adds `controller_proof_state.phone_camera_enabled` (default off). For camera recording, access the phone controller over HTTPS and allow browser camera/microphone permissions. PHP `upload_max_filesize` and `post_max_size` must accommodate the uploaded file plus multipart overhead; smaller hosting limits fail with an actionable error. Existing paired phones may require a reload to receive the new capture JavaScript.

## Certification

- `tests/v5q28b_phone_capture_contract.php`: pairing/host/claim/consent/revocation/heartbeat/nonce security.
- `tests/v5q28b_phone_capture_browser.py`: disabled-before-consent UI, gallery imports for all three formats, bearer/no-cookie proof binding, revocation closing modal, responsive width checks.
- Existing PHP, MariaDB, Playwright, render and Windows workflows still required before merge.
