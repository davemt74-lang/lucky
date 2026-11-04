# V5Q32G7A — HomeServer LAN sharing and listener restrictions

## Bounded scope

Fix the normal desktop-console path that previously resolved relative invitations
against `http://127.0.0.1:8833`, which means the phone itself when scanned there.
The existing approved-controller, upload and private recording systems remain
the authority. No second pairing system, cloud media relay, account sync,
Gameroot billing, repository split or gameplay-license dependency is introduced.

The educational/marketing product remains self-hosted on its existing URL and
subdirectory. Its database, accounts and configuration remain independent of the
private-entertainment HomeServer product.

## Address and media rules

- The launcher selects a preferred, nonvirtual RFC1918 IPv4 interface. Public,
  loopback, link-local, VPN-like excluded adapters and noncanonical IP spellings
  are not advertised as a home-network URL.
- Caddy's cleartext desktop listener explicitly binds to `127.0.0.1:8833`; LAN
  HTTPS explicitly binds to the selected private IP on `8843`. A Caddy site
  address is not itself an interface restriction; see the official
  [bind directive](https://caddyserver.com/docs/caddyfile/directives/bind).
- `storage/homeserver-network.json` contains only schema version `1` and the
  canonical `https://<private-ip>:8843` origin. It is private runtime data, not
  source, an uploaded asset, an editable browser setting, or release content.
- The active launcher renews the network lease every ten seconds while its
  selected interface and owned listeners remain available. PHP accepts it for
  thirty seconds with at most five seconds of future-clock tolerance. A normal
  shutdown withdraws it. A killed launcher cannot renew it; no process-ID or
  request-header value can extend the lease in PHP.
- Only a HomeServer deployment reads that lease. Hosted installations ignore it.
  Missing, malformed, oversized, linked or stale metadata renders no LAN origin.
  Host/forwarding headers never supply a substitute.
- Long-lived HomeServer pages refresh that nonsecret metadata through a
  same-origin read-only endpoint. The browser cache has a bounded monotonic
  lifetime and invalidates on expiry or refresh failure; sharing UI clears or
  rebuilds without stopping a game or moving authenticated requests elsewhere.
  Hosted pages make no LAN-readiness polling requests.
- The controller invitation endpoint resolves its URL before creating an
  invitation row. No LAN therefore consumes no invitation slot. Admin session,
  CSRF, one-use invitations, host approval, expiry and revocation remain intact.
- Shared browser URL handling accepts only known installation sharing routes
  and trusted origins. It does not move host API requests, cookies, or playback
  authentication to the phone origin. Camera/file uploads continue using the
  receiving phone's current installation and existing permissions.
- New HomeServer prize media and package covers store installation-relative paths.
  Educational uploads preserve their absolute hosted URL behavior, including
  installations under encoded or non-ASCII subdirectories.
  Only exact supported upload routes and IDs may use this exception. External
  prize links retain their existing HTTP(S) validation.
- Old, recognized HomeServer loopback upload URLs are adapted when displayed,
  without rewriting database records or treating arbitrary external URLs as
  installation-owned. Third-party media is not an offline guarantee.

## Ten acceptance gates

Each gate must pass before calling this section 10/10. The score is scoped to
these automated/code-review criteria, not an assertion that all hardware or
production environments have been certified.

1. Strict canonical private-origin parsing, bounded lease reads, stale/future
   rejection and no Host/forwarded-header authority.
2. Hosted-domain/subdirectory URLs stay on the hosted installation and ignore
   HomeServer runtime metadata.
3. HomeServer phone, TV, packaged-controller and Director sharing use the
   advertised LAN HTTPS origin or show an unavailable state without localhost QR.
4. Host navigation/API calls and recognized media playback remain same-origin;
   invitation capabilities cannot be sent to an unrelated origin by URL repair.
5. Portable uploaded media passes real validation/persistence and existing
   external-media behavior remains unchanged.
6. Real HTTP/MariaDB pairing demonstrates no-mutation rejection and preserves
   claim, approval, status, revocation and CSRF/ownership boundaries.
7. Actual Windows PowerShell 5.1 tests execute the installed launcher helpers,
   filesystem publication and bundled-Caddy configuration validation.
8. Native installed-runtime tests establish exact listener binding, live network
   lease renewal and withdrawal without weakening worker-recovery tests.
9. All registered Linux core, database, browser and FFmpeg suites plus Windows
   installation, runtime, setup recovery and MP4 gates pass for the PR head.
10. Exact merged-source gates pass, root-level deploy and Windows packages are
    produced with provenance/checksums, and the published web download is
    independently verified. Runtime network state is excluded from both bundles.

## Deployment and remaining work

Windows startup readiness is checked through a bounded, launcher-owned native
client. A temporarily refused connection during MariaDB recovery remains a retry;
native stderr alone is neither a fatal PowerShell error nor proof of readiness.
The existing retry budget and live-database checks remain in force. Each probe
cleans up only its own retained process, without exposing credentials in logs.

Back up the database, private configuration, setup identity and media. Install
the new web files without replacing runtime storage. There is no G7A database
migration; `upgrade.php` remains appropriate if an installation is behind earlier
schema releases. The Windows package updates the launcher as well as the app;
uploading the web ZIP alone does not update an installed Windows launcher.

Phone and TV must be on the same reachable home network. Network/guest isolation,
firewall permissions and trusted local HTTPS still need the guided G7B setup and
actual device testing. No firewall rule is changed and no CA certificate is
silently trusted by G7A. The local CA private key is never published.

Network selection is performed at startup, not hot-swapped while a recording is
active. If the address changes, restart HomeServer and reload the host/phone
pages. Already copied URLs and open QR images cannot be recalled; do not treat a
previously copied invitation as proof that the current address is reachable.

G7C field checks remain: Windows PC plus iPhone/Android/TV, WAN disconnected,
trusted HTTPS camera permission, capture/upload, editor playback and MP4 export.
Optional live WebRTC preview is separate from local file upload. Full offline
certification, backup/rollback, code signing/SmartScreen and uninstall/data
retention are not certified by this narrow address change.

The confirmed Director upload queue head-of-line blocking/request-timeout issue
and planner/encoder segment-limit mismatch are separate next-section work.
