# Lucky Game Hub HomeServer — offline Windows product contract

Status: a standalone Windows `.exe` installer is built from the same application
source as the educational/subdomain package. CI exercises clean installation,
local MariaDB, restart, package recovery and bundled FFmpeg. V5Q32G5 adds native
Windows PowerShell 5.1 worker-longevity and fault-recovery gates. Inspect the exact
release workflow for their outcomes. **Complete physical-device and
internet-disconnected certification is still pending.** The hosted deployment
ZIP is not a HomeServer installer.

V5Q32G6 adds a shared resumable setup protocol, private complete configuration
publication and native Windows hard-link/publication failure tests. Keep the
private `storage/.setup-attempt.json` identity with the local secrets, database
and media backup. A lost identity or corrupt existing configuration is a recovery
error, not permission for setup to reset accounts. See [Section F](V5Q32G6_AUDIT_F.md).

## Non-negotiable product behavior

- The Windows HomeServer install contains the web application, PHP runtime, MariaDB/MySQL-compatible database runtime, media encoder, and a launcher/supervisor. The offline product target is **no post-download internet dependency** for core installation or gameplay; the field gates below must verify it.
- The database is **on the same user's Windows computer**. Sessions, accounts, settings, player records, claim proofs, media files, logs and exports stay in its local database and private storage directory.
- A machine on the same home Wi-Fi/LAN opens the game-host URL and can pair the user's phone as a controller; the game works even with the router's WAN disconnected.
- Core local gameplay must not require a cloud account, public website, API key, subscription check, telemetry endpoint or vendor-operated database. Future software-update licensing belongs to the independent HomeServer Gameroot site; it must not silently become a live gameplay dependency.
- Any optional public YouTube/video/music links are *not* offline assets. Guarantee offline behavior only for locally uploaded media. Make this explicit in admin forms.
- The first administrator is created locally during initial setup. Backups, updates, media import and restores are also local operations.
- The HomeServer's database listener must be loopback-only, authenticated and firewalled. The web listener needs a LAN-bound address and safe phone pairing/permission flow. Avoid a publicly reachable host.
- Some mobile camera/microphone APIs require a trusted HTTPS origin. Local certificate trust and onboarding on mobile devices are a release blocker, not something to assume works automatically.
- Installation, crash restart, autostart, clean uninstall with explicit data retention/erasure choice, data backup and upgrade migration all need Windows testing.

## Current Windows delivery and remaining work

- A single Windows installer executable bundles pinned components and the Lucky app. CI builds the executable; user setup does not fetch component binaries. Code signing and SmartScreen behavior require separate release verification.
- The launcher configures local database credentials and the per-user data directory, initializes or upgrades the shared schema, and opens browser-based first-admin setup. It is a supervised desktop process, **not a registered Windows service**. Desktop/start-menu shortcuts and optional per-user startup launch are provided. Firewall and phone certificate onboarding remain explicit setup/field checks.
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

Phone file upload uses the current installation's endpoint and local storage; it
does not require routing through either Gameroot website. LAN camera access still
needs a trusted HTTPS origin, pairing, host permission and consent. Optional live
WebRTC preview is a different path and must not be used as evidence that offline
file transfer is or is not working.

Until every field gate is green, describe the EXE as an automated-tested preview,
not a fully certified offline Windows product. See
[Section E](V5Q32G5_AUDIT_E.md) for the scoped runtime safety tests and remaining
limits, and [Section D](V5Q32G4_AUDIT_D.md) for shared schema behavior.
