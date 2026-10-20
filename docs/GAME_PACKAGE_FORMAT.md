# V5Q.22E — HomeServer Game Package ZIP Contract

The Game Catalog now accepts versioned ZIP bundles from an administrator.
Packages are **verified and staged** in persistent storage: they do **not**
replace the Windows installer, the bundled PHP application, game sessions,
or HomeServer's local MariaDB. They do not yet become playable or run code.
Runtime execution, state synchronization, and game-specific phone pairing
require their own certification before activation is available.

## ZIP layout

ZIP the files at archive root (not inside a wrapping folder):

```text
manifest.json
manifest.sig               optional: base64 RSA/ECDSA signature over exact manifest.json bytes
host.html                   required: independent desktop game view
phone.html                  optional: unique phone controller for this game
assets/game.js              optional static game assets
assets/game.css
assets/cover.webp
```

Do not include explicit directory ZIP entries. Folder names are implied by
file paths. Allowed files: HTML, JS, CSS, JSON, PNG, JPG/JPEG, WebP, MP3,
M4A, OGG, WAV, WebM, WOFF2. PHP, SQL, executable binaries, symlinks,
device nodes, absolute paths, parent paths and unexpected files are rejected.

```json
{
  "schema": "lucky.game-package.v1",
  "id": "table-trivia",
  "version": "1.0.0",
  "title": "Table Trivia",
  "publisher": "Your Game Studio",
  "host": "host.html",
  "phone": "phone.html",
  "files": {
    "host.html": "SHA256_OF_EXACT_HOST_HTML_BYTES_LOWERCASE_HEX",
    "phone.html": "SHA256_OF_EXACT_PHONE_HTML_BYTES_LOWERCASE_HEX",
    "assets/game.js": "SHA256_OF_EXACT_GAME_JS_BYTES_LOWERCASE_HEX"
  }
}
```

Use real 64-character lowercase hexadecimal file hashes; placeholders above
are explanatory only. A game without a phone controller declares
`"phone": null` and omits `phone.html`.

## Offline upload

In Admin → Game Catalog → Game package library, choose and upload a ZIP.
The server validates the schema, paths, archive contents and each file hash,
then stores its immutable files under:

`storage/game-packages/<game-id>/versions/<version>/`

Windows HomeServer's `storage/` mount points to:
`%LOCALAPPDATA%\\LuckyGameHub\\storage`. Updates to the Windows executable
never overwrite these files. The server keeps every previously staged
version. Retrying an identical version is idempotent; attempting to silently
replace the same version with different content fails.

Archive limits: 40 MiB compressed, 90 MiB expanded, 150 entries,
12 MiB per asset, and strict ZIP content/path validation. Installation
is atomic: incomplete stages are deleted after failure. The Admin JSON
endpoint is available only to a signed-in administrator with a CSRF token.

## Publisher signatures and built-in games

A publisher can sign the exact `manifest.json` bytes using a SHA-256
RSA-2048+ or EC-256+ key and store the base64 signature in
`manifest.sig`. The Admin Cloud game updates section pins the trusted
publisher's public key. A signed bundle is accepted only if the detached
manifest signature matches this exact key. Every file hash in the signed
manifest must match the extracted bytes.

Unsigned locally authored packages may be **staged** for development but
will never be treated as publisher verified. Spin the Wheel is a protected
built-in game: only packages that verify against a pinned publisher key
can be staged as potential updates. No game ZIP may delete or overwrite
Spin the Wheel's engine. Game activations remain disabled in this stage.

The signed cloud update feed (V5Q.22C) is separate from this upload route:
release advertisements are not automatic downloads or installations.
No cloud connection is necessary to stage a locally authored package.

## Remaining activation milestone

Build and certify an isolated host/phone runtime, controller-specific
input mapping, shared session lifecycle, compatibility rules, activation/
rollback semantics and a permission model for package features. Until those
checks pass, displayed packages remain staged, not ready/playable.
