# Lucky Game Hub V5Q.24 — Universal Game/Plugin SDK v2.0.0

Games and plugins are the **same installable package type**. A ZIP contains
the game's desktop `host.html`, optional independent `phone.html`, JavaScript,
styles, media and a validated `manifest.json`. The HomeServer executes **no
uploaded PHP**. All game scripts run inside opaque-origin, network-disabled
browser sandboxes.

## Developer workflow

```sh
python tools/game_sdk.py init ./my-game --id my-game --title "My Game"
# In game.json, declare only the platform capabilities you need.
python tools/game_sdk.py build ./my-game --output ./my-game-0.1.0.zip
python tools/game_sdk.py verify ./my-game-0.1.0.zip
```

The SDK-generated `game.json` now includes:

```json
{
  "id": "my-game", "title": "My Game", "publisher": "Local developer",
  "version": "0.1.0", "host": "host.html", "phone": "phone.html",
  "dataVersion": 1,
  "capabilities": [
    "storage.read", "storage.write",
    "players.read", "players.write",
    "scores.read", "scores.write",
    "rounds.read", "rounds.write",
    "turns.read", "turns.write",
    "timers.read", "timers.write",
    "session.write"
  ]
}
```

The list above is an **example**, not the default. New SDK projects default
to `capabilities: []` (basic inputs, state, and media only). Developers
explicitly opt into required capabilities, and the owner sees them before
activating a package. Old v1 packages have **zero privileged permissions**
until their developer publishes a new version with declarations.

## Game-specific storage and capabilities

The SDK runs in the isolated host or phone frame. Authorized **desktop-host**
game scripts may call:

```js
LuckyGameSDK.onState(state => {
  console.log(state.status, state.plugin.scores, state.plugin.round);
});

// Key/value data (game-scoped by default, or pass "session"):
const old = await LuckyGameSDK.storage.get("level");
const saved = await LuckyGameSDK.storage.set("level", 5, old.revision);

// Registered players, server-authoritative scoring:
await LuckyGameSDK.players.add("alice", "Alice");
await LuckyGameSDK.scores.add("alice", 100);
await LuckyGameSDK.rounds.next();
await LuckyGameSDK.turns.set("alice");
await LuckyGameSDK.timers.start("round", 60);
const clock = await LuckyGameSDK.timers.get("round");

// Save a persistent, versioned session without ending it:
await LuckyGameSDK.session.save();
```

Each call is a bounded async message to the trusted HomeServer host. The
host validates the source iframe and its opaque origin, and forwards it
through the existing owner-authenticated, CSRF-protected API. The HomeServer
checks the **specific installed package's pinned version and declared
capability** before applying any change. All writes use a transactional
session version and a unique idempotency key; replay cannot award points
twice. One device cannot change an unrelated game's state or storage.

### Storage

HomeServer owns one dedicated `game_plugin_storage` table. Rows are scoped
by `game_id`, `scope_id` (private game-wide or a single game session) and
a validated key. Each write uses optimistic `expectedRevision`, starting
at zero. A key can store JSON data up to 8 KB; each game may store up to 128
keys per scope. Data remains local across game updates and HomeServer
restarts. **No custom SQL, tables or filesystem paths** may be supplied by a
package. The `dataVersion` manifest field is reserved for future declarative
data migrations; it does not execute game-submitted migration scripts.

### Common gameplay rules

- Player IDs are validated, names are limited to 48 characters; maximum
  16 registered players per session.
- Score updates require a registered player and a bounded integer delta
  (-10,000..+10,000), with cumulative score limits.
- Round advancement and turn assignment are server-authoritative;
  a turn can be assigned only to a registered player.
- Timers are wall-clock timestamps with 1–3,600-second durations, not
  background JavaScript processes. No scheduler or remote time service.
- Saving/pausing/ending returns to the existing shared session lifecycle.
  Existing Spin the Wheel mechanics and prize security are untouched.

This v2 foundation implements **standardized capabilities** but not arbitrary
developer-written server-side game rules. A sophisticated game's custom
rule logic can coordinate these primitives in its isolated UI; high-stakes
or adversarial game rules would require a separately reviewed declarative
server-side rule module, never arbitrary uploaded PHP.

### Phone controller boundary

The independently authored phone UI receives the same public server state:
`state.plugin.players`, `scores`, `round`, `turn`, `timers`. It can send
bounded `LuckyGameSDK.sendInput` messages through its existing owner-approved
controller pairing. **Phones cannot invoke privileged SDK RPCs**, change
private storage, or directly modify scoring; the desktop host remains the
authorized coordinator.

### Migration and installation

Cloud: Admin → `upgrade.php` provisions the idempotent `game_plugin_storage`
schema, retaining existing sessions and games. New installs include it in
`lib/schema.sql`.

Windows HomeServer: the bundled offline launcher already calls
`homeserver/migrate.php` on startup; it now provisions plugin storage
automatically, without a network dependency. The schema is persistent and
survives application reinstall/update.

Existing v1 games continue using `sendInput` and `onState`. For v2
capabilities, update their SDK copy and declare the exact required
permissions. The Game Catalog shows these before owner activation.

## Certification

```sh
php tests/v5q24_plugin_permissions.php
node tests/v5q24_sdk_contract.js
# Real database tests run only against isolated CI MariaDB:
php tests/v5q24_plugin_mariadb.php
```

The full workflow also checks browser sandbox messaging, existing games and
controller behavior, migration idempotency, and a packaged Windows clean
install/restart. **No consumer Game #2 is shipped in this phase.**
