# V5Q.23C — Publisher Trust by Game

Lucky Game Hub's HomeServer can now maintain **a separate trusted publisher
connection per game**. Previously the optional Cloud update feature stored only
one legacy shared update feed. That feed is retained, unchanged, for backward
compatibility.

## In Admin → Game Catalog

- Select a catalog game and enter its publisher name, HTTPS signed-update
  feed URL, pinned PEM public verification key, and optional private access
  token. Save once to trust that publisher **for only that selected game**.
- Each configured publisher appears with its game ID, HTTPS URL and
  SHA-256 signing-key fingerprint. The full key and access token stay in
  private HomeServer storage and never appear in the API list.
- Click **Check game updates** for an opt-in network request. The HomeServer
  verifies the RSA/ECDSA signature and freshness of the feed, filters to
  releases for that exact game, and displays the compatible versions.
- Select **Download & stage verified ZIP** to download and verify the
  signed ZIP again on the server against its source's checksum and
  game/version identity. **Staging does not activate the game.**
- Click **Disconnect this publisher** to revoke that game's feed/key.
  Already installed games, sessions, and other publishers remain intact.

**Publisher trust changes are sensitive:** changing a saved publisher name,
feed URL, or signing key is rejected unless the owner explicitly confirms
replacement; credentials are never changed silently. The old connection
cannot substitute its key for a game that has a new game-specific trust
anchor. An unauthenticated phone or an untrusted game ZIP cannot set the
source of its own updates.

## Compatibility

The signed `lucky.game-update-feed.v1` release object may now carry
`"sdkMin":"1.0.0"` in addition to `game`, `version`, `url`,
`sha256`, `bytes` and `notes`. Omission defaults to `0.0.0` for
older publisher feeds. The current game SDK contract is **1.0.0**.

A release requiring a newer SDK is still visible but **cannot be
downloaded/staged**, even with a forged browser request: the server rechecks
its requirements against a freshly authenticated publisher feed.

There are no automatic update checks, downloads or activations. Every network
request is an explicit, authenticated Admin action, through the existing
HTTPS-only, public-DNS-only, no-redirect publisher transport. ZIPs and their
publisher verification keys are always selected server-side, not supplied by
the browser during staging.

## Offline data and migration

Trust records live in
`storage/game-updates/channels/<game-id>.json`, under private HomeServer
storage, **outside application updates and the Windows installer**. Their
files are written atomically, with locks and private permissions. Existing
`storage/game-updates/connection.json` continues to back the legacy channel.

A newly installed game does not require a publisher cloud connection, and
the HomeServer runs offline once games have been installed. The catalog,
session history, controller pairing, and built-in Spin the Wheel game are
not dependent on any publisher availability.

Tests: `php tests/v5q23c_publisher_channels.php` covers multiple
independent signing keys, sensitive-credential redaction, authorized
rotation, URL isolation, invalid/expired signatures, SDK requirements,
publisher revocation and staged ZIP identity. This section adds no new
consumer game.
