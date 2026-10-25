# V5Q.29E2 — QR-paired full-screen Phone Camera Studio

## Host workflow

1. Run `upgrade.php` once after deploying this release. The additive schema
   creates recording phone permissions and private media metadata.
2. On the desktop, open **Admin → Recordings**. Create a group of type Game,
   Live event, Planned production, or General capture. Activate the group.
3. Turn on **Allow approved phones to capture** for that group. This flag is
   off by default and can be revoked at any time. It applies only to phones
   already approved through the host's existing QR pairing process.
4. Optionally start a named take; every concurrently participating phone
   captures against that same recording session and active take.
5. Open **Camera** in a paired phone's bottom navigation. Without a live prize
   proof, this launches the full-screen Camera Studio route (`camera.php`).
6. On the phone, confirm consent and capture or upload video, a photo, or
   audio. Choose **Send**. It appears under the session's media in Admin.

The existing game prize-proof camera retains its current behavior and
`Use phone camera` permission. The independent Studio does not require a
prize claim or a separate phone website login.

## Security and limits

- Phone pairing is authenticated with the controller ID and bearer token,
  requiring the host's previous approval; endpoints accept only same-origin,
  HTTPS requests (or loopback HTTP for local preview).
- A camera session must belong to the paired phone's host, be **active**, and
  explicitly have phone capture enabled. The server locks and rechecks both
  host authorization and phone status immediately before storing media.
- The phone confirms recording consent before the browser requests the camera
  or microphone and again on upload. Revocation stops subsequent captures.
- Files are validated by server-side MIME inspection, stored under randomized
  opaque asset IDs in the private `storage/recording-assets` directory, and
  served only to the owning administrator through the authenticated endpoint.
- Each file is at most **80 MB**; a recording session allows at most **160**
  camera assets totaling **600 MB**. In-browser recording is currently capped
  at **three minutes** per take segment. Existing uploads can be selected from
  the phone library without using its live camera.
- Upload receipt nonces are deterministic for a paired device. Exact retries
  return the same asset; conflicting content is rejected and existing files
  are not overwritten. Camera permission does not grant unrelated game or
  administrative capabilities.

## Synchronization boundary

All assets share one recording session and optionally one take. Recording
**timing is not frame-accurate**; cameras can start at slightly different
times due to human input and polling. Automatic audio waveform alignment,
angle switching, live-event-long segmented uploads, and multicamera edit
timeline rendering are separate later sections. E2 captures real media and
preserves the grouping/ownership needed for those capabilities.

## Certification

- `tests/v5q29e2_phone_camera_contract.php`: additive migration, routing,
  consent, server authorization and UI entrypoints.
- `tests/v5q29e2_phone_camera_db.php`: MariaDB permissions, host isolation,
  active-take grouping, revocation and expiry.
- `tests/v5q29e2_camera_browser.py`: mobile viewport camera UI, paired-session
  availability, explicit consent before enabling camera, upload to the correct
  session and immediate UI withdrawal after revocation.
- Cloud deployment and Windows offline installer retain identical tracked
  application sources and carry version V5Q.29E2.
