# Audit Section D — Shared installation and upgrade consistency

V5Q32G4 repairs database drift between two products currently developed in
`lucky`: the educational/marketing installation self-hosted on a subdomain and
the Windows HomeServer product for private entertainment. Their accounts,
databases, settings and product-specific gameplay remain independent. Phone
connections, multiplayer and video editing are shared foundations. A future
repository split is not performed by this release.

## Confirmed defect and implementation

`install.php`, `upgrade.php`, `homeserver/initialize.php` and
`homeserver/migrate.php` previously maintained separate migration lists. Current
application files could therefore run against an older, incomplete HomeServer
database. Examples included permanent recording show channels and phone-camera
permission columns. A successful launcher smoke test did not prove these tables
and fields existed.

All four entry points now delegate schema creation and repair to
`SchemaMigrations::ensure(PDO $db)`. The shared service:

- Preloads the entire fixed, dependency-ordered, bundled SQL catalog before DDL.
  Missing, empty, unexpected or mismatched sources fail before schema changes.
  No request can supply SQL, filenames or an alternative migration directory.
- Rejects execution inside an existing transaction because MySQL DDL can commit
  implicitly. It takes a bounded, database-specific advisory lock and releases
  it on success or failure. Concurrent upgrade requests cannot race the shared
  column checks and ALTER statements.
- Creates missing tables and adds the known missing columns. It does not drop
  tables/columns, replace credentials, reset gameplay configuration, delete media,
  overwrite permissions, or republish an existing draft prize package.
- Verifies required tables, columns, InnoDB storage, primary keys and required
  uniqueness before claiming the schema is ready. Unknown corruption requiring
  destructive repair stops with an actionable error instead of guessing.

Admin authentication, CSRF, the HomeServer CLI/bootstrap guard, loopback database
policy, local first-admin setup, configuration publication and product-specific
initial seeds remain the responsibility of their original entry points.

## Verification and release checklist

The section's automated score is based on these ten release gates, not on a
claim that every feature or physical device in the entire application is perfect:

1. Every current bundled migration belongs to the one explicit catalog.
2. All four real entry points use that routine; source-contract tests inspect
   actual PHP tokens rather than accepting comments as evidence.
3. A fresh educational/subdomain installation creates the complete shared schema.
4. A fresh HomeServer initialization creates the same required shared schema.
5. An older educational installation upgrades without losing its seeded data.
6. An older HomeServer installation repairs the previously omitted schema.
7. Repeated upgrades are idempotent and preserve independent accounts, settings,
   configuration and existing media.
8. Missing bundles, failed readiness, invalid authorization/CSRF and simultaneous
   upgrades have explicit failure boundaries rather than false success.
9. Real database operations exercise the repaired phone/recording foundation;
   the complete registered regression inventory and native Windows installer job
   pass for the PR's exact source.
10. Post-merge certification passes, and the published root-level deployment ZIP
    matches the exact merged commit and its verification manifest.

Actual outcomes must be read from the workflow for the release commit. This
document specifies the gates; it does not predeclare them passed.

New tests include `schema_migrations_test.php`,
`schema_migrations_database_test.php` and `v5q32g4_setup_paths_db.php`. The last
suite runs real HTTP install/login/upgrade requests and the unmodified HomeServer
CLI scripts against isolated application copies and a dedicated CI MariaDB on
port 3307. It does not rewrite production handlers or disable their guards.
The Docker fixture needs a test-only bridge-network database grant; the separate
native Windows installation check exercises the production loopback account.

Existing camera/director source-contract suites now check the shared catalog and
the relevant entry-point delegation. They remain source contracts, not simulated
claims of real camera coverage. Linux behavioral/database/browser/FFmpeg suites
and Windows clean install/restart/bundled-FFmpeg checks remain required.

## Deployment

For the educational/subdomain installation: back up the database and `storage/`,
upload/extract the direct full deployment ZIP once, preserve `storage/config.php`
and uploaded media, sign in as administrator and run `upgrade.php`. Do not replace
an existing database with a new installation. If upgrade fails, keep the backup
and resolve the reported bundle/schema problem before using affected features.

For HomeServer: back up its user-owned database/storage, install the new Windows
package and start it normally. Existing configuration is retained; the launcher
runs the shared upgrade routine before starting the web application. This release
does not add database synchronization or an online license requirement.

## Explicit limits and next sections

- Windows worker recycling/recovery and long-running capacity are separate work.
  This release does not add PHP workers or alter process supervision.
- Actual iPhone/Windows capture, local certificate trust, LAN firewall/router
  behavior and internet-disconnected phone upload/playback need physical tests.
  File upload is local HTTP(S); optional live WebRTC preview has separate network
  requirements. No physical-device certification is implied by schema parity.
- Existing first-install admin/default-data writes and configuration-file
  publication are not one cross-resource atomic transaction. Crash recovery
  during that later first-install phase remains a distinct follow-up; the shared
  migration preflight runs before those writes. No automatic destructive reset
  or speculative recovery is introduced here.
- Readiness validates required structure, not arbitrary operator modifications
  to every SQL type, foreign key or index. Known additive omissions are repaired;
  unknown incompatible schemas require an operator's backup/repair procedure.
- The two independent Gameroot websites, subscriptions, update licenses and
  protected software distribution are not changed or enabled by this section.
