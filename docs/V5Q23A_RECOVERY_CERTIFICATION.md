# V5Q.23A — Foundation recovery and certification

This section adds no consumer game. It certifies the existing modular
game foundation with temporary test packages (one has an independent phone
controller, the other deliberately has no phone controller).

## Recovery model

A broken third-party `active.json` no longer prevents the Game Registry
from listing Spin the Wheel or healthy installed titles. Game Package Catalog
reports the affected game ID rather than hiding an error, and only the
affected game is omitted from available modules.

The Admin Game Catalog provides **Recover using vX.Y.Z** for broken
activation pointers. Recovery requires an admin session, CSRF token,
explicit confirmation, a prevalidated locally staged package version, and
zero live sessions for the selected game. The original damaged activation
pointer is preserved as `activation-damaged-<random>.json`, not silently
destroyed. The new activation pointer is atomically published. Healthy
activations cannot be rewritten through the recovery API. Spin the Wheel
remains permanently protected.

## Phone outage handling

The phone sends an exact expected game version and a unique nonce with every
command. The server checks the version *after* duplicate-nonce detection and
after locking the session. Duplicate delivery returns without applying a
second action. A delayed first command against a changed game is rejected.

If Wi-Fi drops after the server commits a command but before the phone
receives the response, the phone persists the original nonce, action, and
expected version in session storage. It offers **Retry last command** and
**Discard pending command**. A reload retains the unconfirmed request only
for the exact approved phone, session, and viewer token. Normal gameplay
buttons are disabled until the owner confirms or discards the pending
action. Refreshing or revoking a phone removes stale authorization and,
when game play ends, closes the sandboxed frame.

## Certification gates

- Core: damaged activation detection, crash remnants, explicit repair,
  unaffected installed games, protected Spin the Wheel, stale version gate
- Real MariaDB: two temporary installed games, host approval, duplicate
  phone nonce, pause and rollback on rejected action, save/restart/resume,
  per-game live-session guard, immutable version, End Game and rollback
- Browser: Wi-Fi status outage, lost command response *after commit*,
  retry with the same nonce, refresh with pending command, reconnection,
  and phone End Game cleanup
- Windows: the normal offline installer/restart checks plus execution of
  isolated recovery certification via the **installed Windows PHP runtime**
- Cloud/HomeServer: identical shared application source via existing
  Windows packaging parity checks

## Non-goals

This phase does not build Game #2, run unsigned PHP from game ZIPs, enable
cloud gameplay, or automatically recover a damaged activation by changing
its version without host approval. Media loading, multi-publisher signing,
full database backup/restore, and a game development SDK remain separate,
subsequent gated sections.
