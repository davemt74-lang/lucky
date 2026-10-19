# V5Q.8B — Verified Prize Proof Navigation and Live Cameras

The individual prize proof editor now restores the **exact originating game session** when you exit (and Claims when the entry came from Claims). Pressing Back from the approved phone controller or Escape on desktop follows that return destination.

Both the proof editor and the Video Studio show live, named USB/built-in camera previews after **Enable & find cameras**. The Video Studio lets you select a real claimed prize, capture clips against its claim ID, and append those clips to the current timeline without replacing a saved or unsaved edit. Consent remains mandatory for recording and upload, but not for an already-authorized, local preview.

**Important:** Camera permission is granted by the browser over HTTPS. Selecting camera sources does not start a recording. All uploads remain claim-scoped and protected by admin + CSRF server checks. Previewed streams stop on page exit. Existing proof history is preserved.

For existing installations back up database and `storage/`, upload the full deployment package while preserving `storage/config.php` and uploaded clips, then run `upgrade.php` if any earlier migration is pending. Refresh the host, proof and editor pages to clear old JS assets.

**Open field tests:** Physical USB-camera bandwidth, hosted MySQL and FFmpeg have not been tested. End-to-end footage capture on your actual host remains required.

## V5Q.7 — Tabbed Host Workspace and Full-Screen Editor

The host screen now has Game (default), Music and Connected Phones tabs on the left, with the claimed-prize/proof-history list permanently on the right. The video editor has a full-screen timeline workspace with an integrated inspector and MP4 export dock. Phone arrow navigation shares keyboard/gamepad focus behavior. See `docs/V5Q7_WORKSPACE.md`.

# Lucky Game Hub V5Q.6 — Proof Revision Studio and Live Game Controls

The latest full deployment includes immutable claim-linked proof revision history, a full-screen **Edit proof** page, a **Needs revision** marker, a claimed-prize ledger above the wheel, and Music / Connected Phones tabs. The existing multi-camera prize capture, phone controller, music library, and video timeline remain available.

**Update:** Back up the MySQL database and `storage/`, replace application files, preserve `storage/config.php` and original media, then run `upgrade.php`.

**Details:** [V5Q.6 proof revision and layout guide](docs/V5Q6_PROOF_REVISIONS.md). Full source, tests and release workflows are included.

# Lucky Game Hub V5Q.5 — Post-game Highlights & Draft Limits

This full deployment includes the previous private PHP/MySQL home gaming platform, wireless phone controls, and the **per-prize proof camera capture/timeline upgrade**. Each prize can define up to four proof camera angles; the number actually recorded matches the checked active cameras at the game host. Automatic recording rotates through camera views with a five-second countdown before each, per-angle duration, next camera announcement, and independently labeled timeline clips. Cameras can be named locally and clips can be reordered/trimmed in the private editor.

**[V5Q.3 video rendering setup](docs/V5Q3_RENDERING.md)** · **[V5Q.3 acceptance](docs/V5Q3_TEST_REPORT.md)** · [Camera proof setup](docs/V5Q2_PROOF_CAMERAS.md)

**Upgrade required:** Back up your existing MySQL DB and `storage`, upload the full archive preserving `storage/config.php` and clips, run `upgrade.php` signed in, refresh host and phone browsers, and enable browser camera permissions on HTTPS. Fresh installs now create the claim-media schema automatically.

**Release gate:** Live USB cameras, production MySQL transactions, and hosting FFmpeg execution require on-site validation. The Video Studio can export a final MP4 through the configured CLI FFmpeg render worker, subject to server support. The post-game review link appears after a session ends and allows manual edits before export.

---

# Lucky Game Hub V5P.1 — Phone Controller Sync Repair

A full deployable upgrade to V5N Home Console Edition. This adds a private wireless phone controller without requiring a mobile app. The existing games, prize budgets, music, controllers, product catalog, and claim history remain intact.

## Upgrade on your PHP/MySQL host

1. Back up your **MySQL database** and `storage/` folder before replacing files.
2. Extract the deployment ZIP and upload **all files, including `.htaccess`**, over V5N in the same directory. **Do not replace** the existing `storage/config.php` or uploaded media.
3. Sign in with the administrator account and visit `upgrade.php`. Run **V5P.1 database upgrade**. The migrations use `CREATE TABLE IF NOT EXISTS` and preserve existing prizes and claims.
4. Open `game.php` as host. Click **Connect Phone** to create a 5-minute QR invitation, or open `display.php` on your TV (using the Display link from an active game) for the built-in TV QR invitation.
5. Scan the QR code using an iPhone or Android phone, enter a phone name, and request a connection. The host clicks **Approve** on the Host Game screen (or `devices.php`).
6. Use the mobile controller for **Start, Spin, Spin Again, Pause/Resume, Menu, Back, End, navigation, music, song changes, volume, and mute**. The host can disconnect phones at any time.

**Requirements:** PHP 8.1+, PDO MySQL, HTTPS, a modern phone browser, and a host account for approving paired phones. Keep either the TV display or the Host Game page open for UI and music controls. The phone and host only need to be able to reach the same HTTPS website; Bluetooth and being on the same Wi-Fi network are not required.

## How it works

- The host generates an unguessable QR invitation. The phone requests pairing, remains inactive while pending, and requires host approval.
- The dedicated read-only TV display also shows a QR link during active or paused games. Scanning it *only requests* pairing; the host still has to approve.
- The phone receives a random bearer token, stored as a SHA-256 hash on the server. The phone keeps its token in tab-scoped storage if the browser permits it.
- Allowed Start, Spin, Pause/Resume, and confirmed End Game commands execute through the authoritative server game engine. Screen-only controls are delivered to an active TV or host browser and must be acknowledged before the phone reports success. The phone **never selects or awards a prize directly**. Awards remain server-selected and protected against duplicate claims.
- Fresh host QR invitations expire after **5 minutes**. Approved phone connections expire after **8 hours** unless manually disconnected. Host approval and token checking are mandatory.
- Commands have per-press idempotency keys, rate limits, expiry and revocation checks. Screen-only controls use non-destructive delivery and acknowledged receipts, with retry-safe acknowledgements.
- Disconnect from either phone or host. Pair again for a fresh phone session.

## What is not included

- Phone control does **not** emulate Bluetooth or USB hardware. It uses HTTPS to communicate with the game host browser.
- Real-world, multi-device network testing, MySQL transactional/locking tests, and scanning on your actual television still require testing on the deployed host.
- This isn't a public phone-control service. Do not post QR invites or TV display links publicly. Continue to use admin passwords, HTTPS, and backed-up server data.
- The TV display remains read-only for game and prize mutations, but can receive and acknowledge phone screen/music controls. The Host Game tab is **not required** for phone Start/Spin/Pause/End to update shared server state.
- The Game 2 module remains a placeholder; the pairing foundation is reusable for its future controls.

## Tests

```text
php tests/engine.php
php tests/catalog.php
php tests/pairing.php
python tests/browser.py
python tests/v5n_console.py
python tests/v5p_pairing.py
python tests/v5p_sync.py
python tests/v5p_button_sync.py
```

See `docs/V5P1_SYNC_FIX.md` and `docs/V5P_TEST_REPORT.md` for checked counts, local phase scores, limitations, and release gates. The QR code is produced locally by an MIT-licensed library; the QR URL is never sent to a third-party image service.

## GitHub status

V5P was prepared from the standalone V5N deploy source. No project-owned Lucky Game Hub GitHub repository was available in the connected account at build time. The deployment ZIP is not a pull request and has **not** been merged, deployed, or production-certified. A matching repository needs to be connected or created before the requested PR/checks/merge can happen.

## V5P.1 command synchronization fix

See [`docs/V5P1_SYNC_FIX.md`](docs/V5P1_SYNC_FIX.md) for the root cause, command-by-command fix, migration steps and open production certification gates. After installing over V5P, **run upgrade.php**; uploading only the PHP and JavaScript without the new table will not make the music/navigation buttons work.

## V5P.2 navigation and phone voice upgrade

This package includes the V5P.1 cross-device command sync repair and extends it with:

- **Phone D-pad / desktop arrow keys**: navigate actionable screen elements, game menus, winner popups, music selectors and sliders. **Select / Enter** activates the highlighted control. **Back / Escape** closes popups and menus.
- **Spoken / typed Done**: dismisses a prize popup or menu without ending a game or spinning again.
- **Phone composer**: a minimal message bar with text-entry game commands, a microphone toggle, visible recognition results and execution receipts. Say or type **Spin**, **Done**, **Move right**, **Pause game**, **Next song**, or **Volume 50**.
- **Fallbacks**: microphone recognition only where supported; text and buttons work without speech recognition. Use HTTPS for phone microphone access.

The upgrade does not introduce new database migrations beyond the earlier V5P and V5P.1 tables. On a new or older installation, run `upgrade.php`; on a current V5P.1 install, replace all files, preserve `storage/config.php` and media, then refresh the desktop, TV, and phone pages to clear old JavaScript.

See `docs/V5P2_NAVIGATION_VOICE.md` for detailed limitations and acceptance gates.

## V5Q.3 new features

The claim-linked montage editor now supports dragging/reordering media, trimming, splitting a recording non-destructively, saving projects to MySQL, one-click automatic story sequencing, uploaded Music Library soundtrack mixing with speech ducking, a private MP4 render queue and final video playback/download. Export jobs need the CLI FFmpeg worker; see [rendering instructions](docs/V5Q3_RENDERING.md).

**Existing data is retained** during upgrades. Run `upgrade.php` after uploading. This release is a local staging candidate; GitHub PR/merge and production video worker certification remain incomplete.

## V5Q.4 — End-of-game automatic highlight drafts

When a game ends, the platform creates a non-destructive, video-first montage **draft** from that session's already-uploaded claim footage. Any previously saved manual timeline is preserved. You can open the Media Timeline, adjust the draft, select music and explicitly queue MP4 export; completing a game **never launches a render job**. See `docs/V5Q4_AUTO_DRAFT.md`.

## V5Q.5 — Post-game review and long-session draft handling

When a session ends, the host screen shows **Review highlights** linking directly to that game’s editor. Existing manual timelines are never overwritten. For long game nights, auto-draft prefers video from each prize claim, trims overly long individual recordings to two minutes without altering source files, and stays within 80 clips/20 minutes. Nothing renders until you press **Export MP4**. See `docs/V5Q5_POSTGAME_REVIEW.md`.
