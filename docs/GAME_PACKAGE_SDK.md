# V5Q.23B — Game Package SDK and Offline Media

Build new games for Lucky Game Hub without rebuilding the Windows HomeServer.
The SDK runs entirely inside the existing opaque-origin game iframe; it has no
cloud dependency, remote network APIs, database access, camera privileges, or
ability to execute uploaded PHP.

## 1. Scaffold and package a game

Requirements on the developer computer: Python 3.10+ (standard library);
OpenSSL CLI only if you sign a publisher release.

```sh
python tools/game_sdk.py init ./my-game --id my-game --title "My Game" --publisher "My Studio"
python tools/game_sdk.py build ./my-game --output ./my-game-0.1.0.zip
python tools/game_sdk.py verify ./my-game-0.1.0.zip
```

For a game **without a phone controller**:

```sh
python tools/game_sdk.py init ./tv-only --id tv-only --no-phone
```

For signed releases (Admin must first trust the publisher's public key):

```sh
python tools/game_sdk.py build ./my-game --output ./signed.zip --sign-key ./publisher-private.pem
```

Do not ship the private signing key. The optional detached `manifest.sig` is
base64-encoded SHA-256 RSA/ECDSA over the **exact manifest.json bytes**.

The SDK folder contains `game.json`, `host.html`, optional `phone.html`,
`scripts/lucky-game.js`, `scripts/game.js`, and `styles/game.css`.
Edit these files, then run build. The CLI automatically creates the
`lucky.game-package.v1` manifest with a SHA-256 hash for every asset, checks
file names, unsupported extensions, missing media references and ZIP safety.
The resulting ZIP uses reproducible ordering and timestamps.

The HomeServer Admin → Game Catalog accepts this ZIP, stages it, and
activates a selected version. The Game Catalog can also download and stage
verified publisher releases. Spin the Wheel remains permanent and protected.

## 2. Media inside a game (new in V5Q.23B)

Use a literal `lucky-asset:` reference to any asset shipped in the same
ZIP. Each reference must match the full relative path (case-sensitive).

```html
<img src="lucky-asset:media/cover.webp" alt="Cover">
<audio id="cue" src="lucky-asset:media/cue.ogg" preload="auto"></audio>
<video src="lucky-asset:media/demo.webm"
       poster="lucky-asset:media/poster.png" playsinline></video>
<style>
@font-face {
  font-family: MyGameFont;
  src: url("lucky-asset:media/my-font.woff2") format("woff2");
}
</style>
```

The same token works as a literal string in inline JS or CSS. It is resolved
only when the HomeServer renders an approved game session: assets are read
from that game's **pinned immutable version**, checked against the stored
per-file hashes, and embedded as `data:` URIs into the standalone frame.

Supported media: PNG/JPEG/WebP images, MP3/M4A/OGG/WAV audio, WebM video and
WOFF2 fonts. Every asset is already restricted to 12 MiB by the package
installer. Each rendered frame additionally limits distinct referenced media
to **24 MiB unencoded**, reusing cached URIs when referenced more than once.
Media never requires an external HTTP resource URL and never exposes the
HomeServer's local filesystem. Unknown references or altered files fail
closed. Existing packages without the new receipt hash map stay compatible.

Scripts and styles explicitly referenced by the game HTML are embedded in
their specified order; unreferenced legacy JS/CSS still load at the end
for backward compatibility. Remote script/style links are removed. Never
depend on CDN scripts, external URLs or browser cookies inside a game ZIP.

## 3. Host and phone event SDK

Include the scaffold's `scripts/lucky-game.js`:

```html
<script src="scripts/lucky-game.js"></script>
<script src="scripts/game.js"></script>
```

Inside `game.js`:

```js
LuckyGameSDK.onState(state => {
  document.getElementById('status').textContent = state.status;
});
document.getElementById('buzz').addEventListener('click', () => {
  LuckyGameSDK.sendInput('buzz', 'player-one');
});
```

`LuckyGameSDK.onState(callback)` returns an unsubscribe function;
`getState()` returns the last authoritative state; `sendInput(name,value)`
submits a bounded primitive input, returning false when not currently active.
Names use lowercase ASCII letters, digits, underscores and hyphens. Strings
are limited to 240 characters, and numbers must be bounded integers.

The desktop and optional custom phone controller share the same persisted
server game session. Pause, End Game, permissions, reconnect and idempotency
are owned by the HomeServer, not by untrusted package code.

## 4. Certification and supported limits

Run locally:

```sh
python tests/v5q23b_game_sdk.py
node tests/v5q23b_sdk.js
php tests/v5q23b_offline_media.php
```

CI also runs Chromium acceptance to verify image decoding and offline
audio/video/font URI delivery with a sandboxed iframe, and executes the
existing HomeServer, controller, and Windows installer regressions.

Game SDK example files are **test/developer scaffolding only**, not a second
shipped consumer game. The owner still chooses which game versions to install
and activate. Game-specific PHP modules, OS executable binaries, unrestricted
network access and new cloud dependencies remain forbidden.
