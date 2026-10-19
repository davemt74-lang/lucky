# V5P phone controller — acceptance and release gate

Date: 2026-10-17

## Sections

| Section | Scope | Local score | Production gate |
| --- | --- | ---: | --- |
| V5P-A | Host QR invitation and TV QR | 10/10 | Scan on real TV and phone |
| V5P-B | Cryptographic pairing token and host approval | 10/10 | Real MySQL transaction test |
| V5P-C | Controller buttons and command delivery | 10/10 | Two-device network reliability test |
| V5P-D | Expiry, revoke, idempotency, rate limits | 10/10 | Concurrency test on deployed MySQL |
| V5P-E | Upgrade, regressions, mobile layout | 10/10 | Staging migration + restore drill |
| V5P-F | Production certification and PR merge | **Open** | GitHub repo, green workflow, HTTPS hardware checks |

These scores reflect **local, testable criteria only**. They are not proof of production-grade security or end-to-end reliability.

## Tests executed

- Existing PHP game engine: **70/70 passed**
- PHP product-catalog rules: **13/13 passed**
- PHP phone pairing rules: **24/24 passed**
- Existing V5M isolated-browser regression: **28/28 passed**
- V5N console browser regression: **23/23 passed**
- V5P pairing browser/UI mocks: **25/25 passed**
- QR image encode/decode check via OpenCV: passed
- PHP syntax for all PHP files and Node JS syntax for all JS files: passed
- ZIP integrity and SHA-256: checked in release packaging

Total automated assertions listed above: **183/183**. Browser network activity and API responses were mocked for UI tests; no real MySQL service, deployed HTTPS endpoint, phone camera, or TV receiver participated.

## Security properties tested by design

- Random 256-bit invitation and controller secrets. Hashed token storage.
- Unknown or malformed pairing IDs, secrets, nonces, and commands rejected.
- Host approval before any phone command becomes available.
- HTTPS required except local development.
- Host POSTs are authenticated and CSRF-protected; phone requests carry independent bearer authorization.
- Revoke/expiry checks on every controller action; late/stale queued commands expire.
- Rate limiting, idempotency and exactly-once queue claim via database transaction.
- No phone endpoint selects a wheel winner, creates a claim, edits prizes, or changes admin settings.

## Tests required before production certification

1. Migrate a staging copy of V5N with real MySQL and confirm all data remains intact.
2. Pair iPhone and Android over HTTPS, scan the actual host/TV QR and test controller actions.
3. Attempt simultaneous taps from multiple phones, repeated/spam taps, mobile reconnect, host-tab restart and manual revocation.
4. Verify expired invitations cannot join, expired approved phones cannot command, and network interruptions do not replay old spins.
5. Verify TV display QR turns off when a game ends, and the host must approve each phone.
6. Create GitHub repository/branch, open PR, run CI against exact revision, review, merge, and package main.

Current release status: **locally green candidate, production and PR gates open**.
