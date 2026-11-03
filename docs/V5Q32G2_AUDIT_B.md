# Audit Section B — Camera and publishing reliability

V5Q32G2 is a bounded maintenance release following Section A's test and delivery
work. It addresses three confirmed camera/Live View defects, not every remaining
finding in the codebase audit. It introduces no database migration.

## Scope and acceptance

| Area | Required behavior | Regression coverage |
| --- | --- | --- |
| Phone upload | Read the media kind from recording_assets, where the schema defines it; accept an authorized real multipart upload and preserve safe identical retries. | v5q32g2_phone_upload_db.php exercises the real HTTP endpoint and MariaDB persistence, consent, owner/pairing checks, nonce conflicts and rejection cleanup. |
| Program publishing | Stop/Cancel, revoked consent, session changes and page exit cannot be undone by delayed asynchronous work. Dispose of each cancelled attempt's own Program tracks; revoke a late server result without stopping a newer publication. | v5q32g2_publisher_lifecycle.js and v5q32g2_publisher_lifecycle_browser.py exercise delayed authentication/publication, cancellation, serialized creation, controls and track cleanup. |
| Viewer activity | Both the standalone viewer and embedded TV/game viewer keep their own signaling session alive while connected. Inactive sessions still expire; expired or unauthorized sessions cannot be revived. Counts and the eight-viewer limit use the same activity boundary. | v5q32g2_viewer_heartbeat.js executes both real client scripts; v5q32g2_viewer_heartbeat_db.php exercises the MariaDB lifecycle and authorization boundaries. |

Publishing requests are serialized because the server replaces the previous
broadcast for the same recording/proof. A newer intent must not overtake an older
in-flight create and then be deleted by it. Cancelling releases local media
immediately; if a cancelled create returns later, cleanup targets only that
returned broadcast ID. Failure to reach the server cannot keep local media live;
the server's existing publication expiry remains a fallback.

Viewer heartbeats use the existing authorized per-viewer poll action, including
after the SDP answer has been received. Merely viewing a directory/show URL or
calling watch does not create or extend a viewer slot. The reported count remains
active signaling sessions, not a claim that each session has a rendered video
frame or represents a unique person. Disconnected sessions can remain counted
until their 30-second activity window expires.

## Verification and release gates

The five new suites are registered in tests/suites.json. Core JavaScript tests use
node:test with bounded completion. Database tests require an isolated CI MariaDB
service; the upload test uses a temporary application root and a loopback PHP
server, not a production installation or a bypass of is_uploaded_file.

Run the existing registered groups from a clean, uninstalled checkout:

```sh
python3 tools/test_inventory.py --check
python3 tools/test_inventory.py --group core
python3 tools/test_inventory.py --group database
python3 tools/test_inventory.py --group browsers
python3 tools/test_inventory.py --group video
```

Release publication also requires the Windows installer certification. The
main-branch workflow reruns the complete gates on the exact merged commit before
packaging and publishing. Actual pass totals and commit identity must come from
that workflow, not a previous release or this scope document. Automated browser
and signaling tests do not certify physical camera or real router behavior.

## Deploy and field checks

Use the direct **Lucky_Game_Hub_V5Q32G2_Full_Deploy.zip** release asset. Upload it to
the existing Lucky installation directory and extract once; there is no wrapper
folder or inner deployment ZIP. Preserve storage/config.php, existing media and
the database. Follow HOSTGATOR_DIRECT_DEPLOY.md and back up first. No new migration
is required for Section B; any older pending migration retains its own upgrade
requirements. Refresh host, phone, TV/game and Live View pages after extraction;
changed browser assets have new cache revisions.

On the deployed host, verify:

1. An approved phone can upload a supported clip into the selected recording.
   Retrying the same completed upload does not create a second asset.
2. Begin publishing and immediately use Cancel or revoke the publishing consent.
   The UI remains OFF AIR; no delayed response turns it back on. Publish again
   with explicit consent and verify Stop releases the outgoing feed.
3. Watch for at least one minute from both Live View and a TV/game display. The
   host count should retain active viewers. Close one viewer and allow the
   30-second expiry window for its count to disappear.
4. A private invitation still requires its key. Stopping or replacing the
   publication revokes the previous broadcast; private feeds do not appear in the
   permanent public Show URL or public directory.
5. Repeat with the actual phone, USB cameras and intended Wi-Fi/mobile networks.
   Automated tests do not replace real device, TURN or HostGator checks.

## Remaining audit work

- Transient publisher poll errors still stop publishing immediately (fail-closed).
  A retry policy must distinguish connectivity trouble from consent/access loss
  before it can safely change that behavior.
- Publish/stop requests do not yet have a bounded network timeout or server-side
  publication-intent IDs. Serialization handles delayed successful responses;
  an accepted request whose response is lost cannot be conclusively ordered by
  the client alone. Cancellation still stops local output, and unmaintained
  broadcasts expire. Full uncertain-delivery recovery is follow-up work.
- Long-running PHP session locks, Windows runtime recycling/migration coverage,
  embedded/phone TURN configuration, permanent-show support in the TV invitation
  field, media queue head-of-line blocking and export-limit consistency remain
  separate follow-up work.
- This release does not change repository protection settings, provision TURN,
  modify a live database or deploy automatically to HostGator.
