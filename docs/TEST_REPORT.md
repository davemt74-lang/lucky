# V5G–V5M acceptance and score report

## What has been verified here

- **70/70 PHP game-rule assertions passed**: registry, default configuration, cents/budgets, tier progression, duplicate prevention, seeded probabilities, pause/resume, configuration changes, legacy-history import, etc.
- **28/28 isolated Chromium checks passed** with a **mock API**: hub, Game 2 disabled, configuration, music library actions, Start → Spin → Win → Claim, pause/resume, intro, read-only audience display and five mobile layouts.
- PHP lint across source and test files and JavaScript syntax checks are part of the release checklist.
- ZIP manifest, integrity and checksum are checked at packaging.

## Honest acceptance scores

These scores distinguish **local implementation coverage** from **production acceptance**. A section cannot be called an unconditional 10/10 until host-specific checks pass.

| Section | Delivered | Local code acceptance | Production certification |
| --- | --- | --- | --- |
| V5G reusable game contract | GameModule, SpinWheel adapter, registry | **10/10** | Second-game plugin smoke test pending |
| V5H PHP/MySQL installer, admin | first-admin flow, PDO and protected API | **9/10** | MySQL install and security test pending |
| V5I shared prize/claim engine | separate budgets, tier promotion, idempotent writes | **10/10** in pure rules | Concurrent MySQL session test pending |
| V5J audio, voice, intros/themes | common media, splash, parser and volume UI | **9/10** | Real microphone/music/intro playback pending |
| V5K host/audience synchronization | admin host, read-only viewer link and polling | **9/10** | Two physical devices and token/privacy checks pending |
| V5L archive and recovery | session status, claim ledger, legacy archive, exports | **9/10** | MySQL backup/restore and restart tests pending |
| V5M security, QA and deploy | HTTP protections, mobile layouts, tests, package | **9/10** | Full HTTPS-host penetration and permission test pending |

**No PR, GitHub merge, live MySQL transaction, audio-hardware validation or production deployment is claimed by this package.** This work was built and validated locally and must go through the live signoff below before a production 10/10 rating is warranted.

## Known constraints

The active execution image offers PHP and Node and an isolated Chromium runtime but **no PDO MySQL driver/MySQL service**, so the database connection, schema installation, real concurrency, live session polling and database persistence cannot be fully exercised here. Browser tests mock the server and do not certify live microphones/speakers. A photo upload is MIME- and dimension-validated, but images from earlier embedded browser state may require reupload. The reserved Game 2 is intentionally not implemented yet.

See `DEPLOYMENT_CHECKLIST.md` for release-blocking host acceptance.
