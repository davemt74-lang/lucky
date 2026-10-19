# Live hosting and release signoff (not yet executed)

Before calling V5M production-ready, verify on the destination PHP/MySQL server:

- [ ] Upload files **with** `.htaccess`; verify PHP 8.1+, `pdo_mysql`, secure HTTPS, `fileinfo`, working sessions and writable protected `storage`.
- [ ] Run `install.php` with a new database, create first admin, sign in, confirm installation can't be repeated.
- [ ] Confirm anonymous users cannot access admin JSON actions, Admin Studio or claims. Verify CSRF denies forged actions, cookies are Secure/HttpOnly on HTTPS and login rate limits behave as intended.
- [ ] Check `storage/config.php`, music/image bytes, `lib/schema.sql`, and `tests` cannot be served directly over HTTP.
- [ ] Save individual prize values and budgets; auto-split; upload a product photo; enter a prize URL; confirm popup and claims snapshots.
- [ ] Start a new session; spin until Tier 1 → Tier 2 → Bonus positions empty; verify Bonus surprise probability and no repeated claims.
- [ ] Send simultaneous spin requests from two clients; check exactly one winning award, idempotency and no double count.
- [ ] Use two devices: host sees protected controls, TV only sees public view; network interruptions and recovery preserve session state.
- [ ] Test browser microphone in Chrome and Edge; music on/off, song selection, all volumes, effect/voice toggles, spoken Spin/End and spoken winner announcements. Check autoplay fallback.
- [ ] Stop and restart PHP/browser mid-session and resume; verify data survives and the audience display reconnects.
- [ ] Test CSV history and optional V5 legacy prize/claim import in the original browser; verify repeat import rejected. Re-upload any missing legacy images/audio.
- [ ] Test responsive host, intro, Admin Studio and display on real phone/tablet/desktop and TV/projector.
- [ ] Back up and restore both MySQL database **and** `/storage` image/audio files into a staging copy.
- [ ] Inspect Apache/PHP logs and database credentials handling; verify upload size restrictions and user storage limits.

This list is mandatory live certification, not a list of tests already passed.
