# Lucky Game Hub HomeServer — offline Windows product contract

Status: V5Q.18 foundation/specification. **A standalone Windows .exe installer is not yet built or certified.** Do not label the existing cloud deployment ZIP as a HomeServer installer.

## Non-negotiable product behavior

- The Windows HomeServer install contains the web application, PHP runtime, MariaDB/MySQL-compatible database runtime, media encoder, and the host supervisor/service. There must be **no post-download internet dependency** for installation or gameplay.
- The database is **on the same user's Windows computer**. Sessions, accounts, settings, player records, claim proofs, media files, logs and exports stay in its local database and private storage directory.
- A machine on the same home Wi-Fi/LAN opens the game-host URL and can pair the user's phone as a controller; the game works even with the router's WAN disconnected.
- Never require a cloud account, public website, API key, subscription check, telemetry endpoint or vendor-operated database.
- Any optional public YouTube/video/music links are *not* offline assets. Guarantee offline behavior only for locally uploaded media. Make this explicit in admin forms.
- The first administrator is created locally during initial setup. Backups, updates, media import and restores are also local operations.
- The HomeServer's database listener must be loopback-only, authenticated and firewalled. The web listener needs a LAN-bound address and safe phone pairing/permission flow. Avoid a publicly reachable host.
- Some mobile camera/microphone APIs require a trusted HTTPS origin. Local certificate trust and onboarding on mobile devices are a release blocker, not something to assume works automatically.
- Installation, crash restart, autostart, clean uninstall with explicit data retention/erasure choice, data backup and upgrade migration all need Windows testing.

## Proposed Windows delivery

- A **single, signed where possible, Windows installer executable** bundles vetted, pinned components and the Lucky app. Build the executable in CI; do not download component binaries during user setup.
- The installer configures local database credentials and a protected data directory, initializes the schema, creates a HomeServer Windows service, configures LAN access after user approval, and opens the browser-based first-admin setup.
- The user's PC runs the HomeServer process. A desktop shortcut opens the local console; the phone uses a QR code with the LAN URL.
- Installer build provenance, dependency license notices, antivirus/SmartScreen checks, bundled-runtime version verification and a SHA-256 checksum are required for release.

## Certification gates (separate Windows release)

1. Fresh install with network disconnected.
2. Database stored on the installed computer; no remote DB connections.
3. Restart Windows and resume an active or saved game without losing state.
4. Pair phone and view TV over the home LAN with no internet.
5. Verify HTTPS camera permission behavior on real Android and iOS phones.
6. Capture proof, render a real MP4 and play it offline.
7. Upgrade an existing local database with verified backup and rollback.
8. Remove HomeServer and choose whether to retain or erase local user data.

Until these gates are green, this is a local-first architecture direction, not an executable-delivery claim.
