# Audit Section F — Resumable installation and safe configuration publication

V5Q32G6 repairs first-installation failure boundaries for the two independent
Lucky products. It does not add billing, licensing, account synchronization,
automatic password recovery, or a repository split. Educational/marketing
instances remain self-hosted installations; HomeServer remains local/private.

## Confirmed defect

The earlier educational installer committed its administrator and each default
game separately, then wrote configuration. A seed or configuration-publication
failure could leave an administrator committed with no usable installation.
Repeating setup refused the existing administrator. Merely adding a transaction
would still leave a process-interruption window between database commit and
filesystem publication.

The frozen, hash-identified G5 installer in `tests/fixtures/install_g5.php.txt`
exists only to reproduce that defect in an isolated MariaDB/HTTP fixture. It is
not shipped in deployment packages and is never a runtime fallback.

## Installation and recovery protocol

1. Respect every existing `config.php` pathname. An ordinary existing file keeps
   the historical installed redirect/no-op. A directory or symbolic link blocks
   setup explicitly. The installer never executes existing configuration to
   decide whether it can replace it. A corrupt existing file requires manual
   restoration; it must not reopen first-admin creation.
2. Acquire the installation directory's bounded lock and authenticate the
   supplied database credentials. An educational database with administrators
   also requires an existing administrator's password before local staging.
   This read-only proof is repeated under the database setup lock/transaction.
3. Prepare canonical configuration and a private local attempt identity. The
   identity contains a random token and configuration HMAC, not plaintext DB
   credentials. It is bound to the exact canonical database/deployment settings.
4. Acquire the database-scoped setup lock, apply the fixed shared schema and
   verify transactional-table readiness. Begin one transaction for administrator
   creation or verification, missing-only defaults, and the singleton receipt.
   Existing rows and password hashes are authoritative and are not reset.
5. Commit the database before publishing configuration. Publish the complete,
   verified staging file with an atomic no-replace hard link. A racing target
   wins safely: it is never overwritten. Keep the database lock through this
   operation and completion bookkeeping.
6. Record `published` after successful activation. A failed or interrupted
   publication retains `database_ready` for an authenticated same-attempt retry.
   Matching local identity, configuration HMAC and deployment are mandatory.
   Lost or mismatched identities fail closed; setup does not mint a replacement
   administrator or silently rebind an existing receipt.

`database_ready` means the DB setup transaction committed. It is not proof that
configuration is absent: a process can stop after activation but before the last
bookkeeping update. In that case the installed application remains usable;
ordinary setup entry points leave the active configuration and receipt alone.
If a later missing-config recovery is authorized with the same identity, it can
complete publication/bookkeeping without reseeding existing records.

HomeServer never creates an administrator in initialization. Local first-admin
setup remains the separate loopback-only, CSRF-protected page. Existing app
credentials are authenticated before reapplying grants, not reset with
`ALTER USER`. Products have independent DBs, secrets, receipts and accounts.

## Private configuration publication

`SetupConfiguration` writes an exclusive staging file with restrictive permissions
before any credential bytes, handles partial positive writes until complete,
checks length and exact readback, flushes and synchronizes the file when supported,
and publishes only the verified file. POSIX mode checks require owner-only access;
Windows uses the existing per-user storage ACL boundary, not an invented claim
that PHP `chmod` configures a Windows DACL.

The stage and target are in the same resolved storage directory; the normal
HomeServer storage junction is supported. Each prepared object is bound to its
own lock acquisition so an escaped older object cannot clean up a newer retry.
Cleanup owns only its exact temporary files and never owns the active config.
A new retry can reclaim the deterministic, private stage belonging to its bound
attempt after a process interruption; there is no broad temporary-file sweep.

The design uses [PHP's no-replace `link` behavior](https://www.php.net/manual/en/function.link.php).
On Windows, the normal supported path is a same-volume NTFS hard link;
[Microsoft documents its file/volume and security-descriptor behavior](https://learn.microsoft.com/en-us/windows/win32/api/winbase/nf-winbase-createhardlinkw).
If linking is unavailable, setup fails rather than falling back to a potentially
overwriting rename or a partially visible copy.

## Ten scoped release gates

The section reaches 10/10 only when these gates pass for the reviewed and merged
source. This document defines requirements, not predeclared test results.

1. The exact frozen G5 endpoint reproduces orphaned-admin behavior, while the new
   real HTTP endpoint rolls back a rejected seed and subsequently installs once.
2. Incorrect CSRF, DB credentials and existing-administrator credentials cannot
   create an active configuration or reset existing accounts/data.
3. Real educational publication failure and authenticated retry preserve the
   same administrator ID/hash, missing-only defaults and private attempt identity.
4. Process interruption before commit, after commit/before publication, and after
   publication preserves the documented rollback/recovery boundaries.
5. Concurrent setup requests and competing directories using one DB cannot
   replace an established receipt or create conflicting first administrators.
6. Filesystem behavior covers short/failed writes, flush/sync/readback errors,
   permission/link failures, colliding paths, symlinks and stale-object cleanup.
7. Real HomeServer CLI failure/retry preserves local secrets, settings and
   independent accounts, without importing or creating a cloud administrator.
8. Existing installation, media and unrelated files survive every relevant
   recovery path; corrupt existing configuration remains locked, not reset.
9. The complete registered Linux suites, clean/restart Windows installation,
   native Windows configuration-publication tests and prior runtime/MP4 gates pass.
10. The verified PR is merged only when green, all post-merge checks pass, and
    published deployment bytes and source identity match an independent rebuild.

## Regression implementation

- `tests/setup_configuration_test.php` exercises real temporary files, locks and
  links. Subclassed protected I/O methods inject failures only inside the test;
  production has no request/environment failure bypass. The suite also runs
  against the installed Windows PHP and application publisher.
- The two `v5q32g6_*_install_recovery_db.php` suites use a dedicated disposable
  MariaDB service, real HTTP/CLI entry points, real triggers and filesystem
  failures. Exact crash-boundary probes instrument only isolated copies of the
  service and restore their source bytes before ordinary recovery requests.
- Existing migration contracts follow the real installation-service delegation;
  comments, quoted examples and wrong call/import/PDO patterns cannot satisfy
  their wiring checks. Shared schema coverage includes the receipt table.

## Deployment, recovery and explicit limits

Back up the database and complete private storage before upgrading. Use the
direct Full_Deploy.zip and extract once over the existing educational app; do not
delete configuration or media. Run `upgrade.php` as administrator to create the
new receipt table without changing existing application rows. Existing working
installations are not forced through setup and do not receive a replacement
identity. HomeServer runs the shared additive schema upgrade on startup.

For an interrupted G6 setup, retain `storage/.setup-attempt.json` and retry with
the same database settings and original administrator credentials. For a legacy
partial educational setup with no G6 receipt, existing-administrator proof is
required before missing defaults and the new recovery receipt can be created.
Never delete administrators, receipts, setup identity or config merely to get
past an error. Restore consistent backups if those records are damaged or lost.

These tests cover application-process interruption, not hardware failure or a
guarantee that directory entries are durably committed through sudden power loss.
An interruption before private attempt metadata is linked may leave an unused
private token-only temporary file; it contains no database credentials and is
not swept automatically. Corrupt existing config, missing local DB secrets,
unsupported storage, incompatible databases and lost recovery identity require
explicit operator recovery, not a destructive reinstall.

Actual HostGator filesystem behavior, real Windows/phone trusted HTTPS, LAN
pairing, internet-disconnected capture/upload/playback, whole-system backup/restore
and power-loss certification retain their separate field gates. This section
does not certify the complete offline HomeServer product.
