# V5Q.22G — Package activation and sandboxed game host

The HomeServer can install or download a static game ZIP without changing the
Windows application. This section adds a separate **Activate game version**
operation, immutable active-version records, explicit rollback and a generic
server-backed game-session module. Spin the Wheel always keeps its bundled
game engine; package activation of `spin-wheel` is refused.

## Owner workflow

1. Admin → Game Catalog → Game package library
2. Upload a Lucky game ZIP or download and stage a signed publisher release
3. Choose **Activate game version** to confirm the installed release
4. The newly activated game appears on the main game dashboard
5. Start, Pause/Resume, Save or End the game from its desktop host
6. Activate an older preserved version to roll back

Each active version is recorded in `storage/game-packages/<game>/active.json`.
Assets remain under immutable `versions/<semver>/` directories. HomeServer
startup, ordinary play and saved sessions do not require the cloud. Existing
sessions pin their original package version and cannot be overwritten by
package upgrades. Changing the active version is refused while any live
session for that game exists.

## Security boundary

A validated ZIP provides **static HTML, JS, CSS and media only**. Its files
never enter PHP include paths, filesystem executable paths or the main page
DOM. Game HTML is served only to a session with the matching read-only
viewer token. The iframe has `sandbox="allow-scripts"` without
`allow-same-origin`. The server also applies a sandbox CSP: no external
network calls, cookies, remote scripts, forms, frames, top-level navigation
or new windows. Package JS/CSS is embedded inside the isolated document.

Game inputs from the sandbox are messages to the trusted desktop host, which
checks the iframe source, validates the action name and bounded primitive
payload, and submits a CSRF-protected `game_action` against the exact
persisted session. The server rejects input when paused/ended, updates
versioned state, and applies the existing event idempotency rules. A game
does not get arbitrary database APIs or direct access to other games.

Example game HTML:

```html
<button data-game-input="answer" data-game-value="A">Answer A</button>
<script>
window.addEventListener('message', event => {
  if (event.data?.type === 'lucky:state') {
    document.body.dataset.gameStatus = event.data.state.status;
  }
});
</script>
```

This static package foundation passes a generic named input/value event.
Game-specific prize rules, camera permissions, arbitrary server-side logic,
third-party network access, and game-specific business state transitions are
**not** allowed through package scripts.

## Remaining certification milestone: paired phone controller execution

A ZIP may contain a separate `phone.html`, but remote pairing and the
authenticated phone-to-server action bridge are not enabled by this first
activation section. The existing Spin the Wheel phone controller is unchanged.
A phone-less game can be activated and hosted without any phone interface.

The next gated section will bind approved phones to the active packaged
session, mount its own isolated phone HTML UI, synchronize state, reject
stale/revoked commands, and return both displays to the dashboard on End.
