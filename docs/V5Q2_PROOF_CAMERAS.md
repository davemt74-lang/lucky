# V5Q.2 — Prize Proof Angles, Timed Camera Rotation & Editor

## Functionality

- Each Tier 1, Tier 2, and Bonus prize has an optional `proofAngles` array in its **server-validated prize definition**. Up to four entries each with an angle title (1–80 characters) and recording time (3–30 seconds).
- In **Admin Studio → Prizes & Budgets**, expand a prize's **Proof angles** section and press **Match cameras on this device**. Browser permission must be granted. The current camera inputs populate the entries; you may edit the names and seconds, then **Save all prize settings**.
- In the **full-screen claim modal**, enable camera access, rename individual USB/built-in inputs, and select the active checkboxes. The proof plan **automatically matches the active checked camera count** (up to four). When a prize has fewer saved angle profiles, missing labels default to the camera names; surplus profiles do not add phantom cameras.
- Press **Start rotating proof**. A **5-second countdown** appears before *each* angle, showing the current angle and the **next camera**. Each selected camera is then recorded for its configured duration, after which the next view is prepared. Only one camera is recorded at a time to reduce USB bandwidth contention.
- Completed clips are uploaded to the private server-backed claim timeline with `source_label`, `angle_label`, and `angle_index`; the video editor displays angle identity, supports ordering and trim edits, and exports a timeline project JSON. Photos and audio are also supported through the existing manual capture/upload controls.
- Consent is mandatory for recording, capture is optional, and **Done** dismisses the modal without recording or changing claims.
- Physical camera aliases remain **local to the browser/device**. The angle profiles are saved with the prize on the server. Browser camera identities can change after unplugging or clearing permissions.
- The phone controller is minimal: D-pad, game/audio controls, and one microphone button with commands like **Done**. Transient notifications now dismiss automatically; connection status is in the synchronized game-state panel.

## Deployment

1. Back up MySQL and `storage/` including any existing `storage/claim-media`.
2. Upload the full deployment ZIP over the current installation. **Preserve `storage/config.php` and uploaded media**. Keep `.htaccess` rules intact.
3. Sign in to the host as administrator and run **`upgrade.php`** once. Existing `claim_media` rows gain optional angle metadata without deleting clips. Re-running is safe.
4. Hard-refresh your desktop/phone and reconnect the controller. Use **HTTPS** for camera and microphone access.
5. In Admin Studio, sync and name cameras for desired prizes, configure view duration, then save.
6. Win a prize, check consent, enable cameras, rename/activate them, and start rotating proof. Check the timeline at `editor.php`.

## Boundaries and production gates

- Capturing from **real connected cameras** must be validated on your system; isolated tests use simulated camera streams. Simultaneous devices, browser codec support, USB bandwidth, media permissions and storage limits vary.
- Current video editor is a **clip-based timeline/preview with edit-project JSON export**, not yet a server-rendered, encoded MP4 or full audio-mixed final video. Do not describe the export as a finished movie.
- Media is stored under `storage/claim-media/*.bin`, inaccessible via direct URL; authenticated PHP streaming serves clips. Keep the game host private and only record with everyone's consent.
- Uploaded file limit 80 MB per clip, 600 MB per game and 160 clips per game. PHP `post_max_size` and `upload_max_filesize` must permit your intended recordings; recordings beyond these limits should be shortened.
- Deployment/CI certification requires live MySQL migration, USB camera tests, phone-to-host reconnection, real browser audio, and an actual source-code PR with passing checks.
