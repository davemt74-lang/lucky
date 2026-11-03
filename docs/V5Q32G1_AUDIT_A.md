# Audit Section A — Test and release reliability

This release is a bounded maintenance section, not a claim that every issue in the
Lucky codebase audit is fixed. Runtime correctness and media reliability work
follow in separate sections.

## Implemented scope

- One authoritative release.json supplies the app version, Windows installer
  version, deployment filenames and release tag. SDK compatibility has its own
  explicit sdkVersion in the same manifest.
- The footer reports release version plus source commit when generated
  build-info.json is valid and matches. Source builds are labeled honestly.
- tests/suites.json assigns every executable root-level PHP, Python and JavaScript
  suite to exactly one Linux CI group. Missing, duplicate or unregistered suites
  fail verification. The runner fails missing runtimes, failing tests and timeouts.
- All existing JavaScript suites use node:test with bounded test timeouts. A
  deliberately unresolved async test is regression-tested to exit nonzero; it can
  no longer appear green merely because Node's event loop ran out of work.
- Five omitted historical suites are registered again: browser.py,
  v5n_console.py, v5p_pairing.py, v5q17_session_delete.php and
  v5q17_export_parity.php. Browser fixtures use current UI behavior and isolated
  application rendering; they do not change the checkout's live configuration.
- Restoring the real-page browser tests exposed an unwired **Open Game Menu**
  button on the host's Live Display tab. Its click now uses the same menu action
  as keyboard/phone controls, with cache busting and real visible-button tests.
- The same Linux and Windows workflows run for PR verification and as reusable
  gates on the merged source before deployment publication. A successful PR run
  alone does not certify a different merged commit.
- The Python packager reads raw Git tree blobs of the exact source commit, checks required
  application files and emits deterministic ZIPs without an enclosing directory.
  Archive attributes cannot silently omit or rewrite tracked application files.
  Tests cover a single extraction over existing configuration and media, nested
  ZIP rejection, source identity, missing files, secret exclusion and tampering.
- Direct GitHub Release assets are immutable. An identical-source rerun is
  verified; mismatched source or assets require a version bump. Publication
  validates the direct-download files before making a new release public.

## Developer verification

Use a clean, isolated checkout, not an installed site with storage/config.php.
Requirements differ by group: PHP 8.4, Python 3.12 and Node 22 for core; a real
MariaDB test service for database; Chromium/Playwright and PHP for browsers;
FFmpeg/ffprobe and PHP for video. Windows certification compiles and installs the
bundled runtime installer on its own Windows runner.

```sh
python3 tools/test_inventory.py --check
python3 tools/test_inventory.py --group core
python3 tools/test_inventory.py --group database
python3 tools/test_inventory.py --group browsers
python3 tools/test_inventory.py --group video
```

Each group has explicit per-suite timeouts, with process-group cleanup on POSIX.
Failures remain failures; absent tools and unexecuted hardware scenarios are not
counted as passing tests. Packaging/publication unit tests use isolated temporary
repositories and mock remote APIs; they do not publish a real test release.

## Release procedure

1. Update release.json for the new release and commit all intended changes.
2. Review the PR and require all Linux and Windows certification jobs to pass.
3. Merge only the verified PR head. The main release workflow re-runs the full
   certification on the exact merged Git SHA.
4. Only after both reusable certification workflows succeed, build and verify
   the full deployment and SDK ZIPs from that SHA.
5. Publish the verified Full_Deploy.zip directly as a GitHub Release asset with
   checksum and build metadata. The intermediate Actions artifact is archival,
   not the user-facing upload package.
6. Verify the published version and commit, then follow HOSTGATOR_DIRECT_DEPLOY.md.

No database migration is introduced by this section. Existing uploads, private
settings and databases are not included in the package and must not be removed.

## Explicit remaining limits

- The repository did not have an enforced main-branch protection rule at audit
  time. This release enforces publication gates, but does not change repository
  administrator settings or prevent an authorized person from bypassing a PR.
- These automated gates are not a substitute for physical camera, phone,
  router/TURN, HostGator or offline Windows field testing.
- The audit's phone-upload SQL error, publishing-consent race, viewer heartbeat
  expiry, session-locking, Windows lifecycle/migration and other runtime findings
  are outside Section A and are not claimed fixed here.
- The SDK paused-read tests validate the SDK transport boundary, not the full host
  UI's paused capability path. That integration difference remains a follow-up.
- Automated pass counts should be taken from the actual workflow run associated
  with the release commit, never copied from an earlier version.
