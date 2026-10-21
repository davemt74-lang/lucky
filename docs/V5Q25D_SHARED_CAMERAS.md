# V5Q.25D — Shared camera settings across installed games

## Ownership and behavior

- Admin > Cameras discovers local camera inputs after explicit permission, previews devices, names them, and saves up to four selected shared defaults.
- Shared profiles are stored only on the current game-host browser origin (`lucky-shared-camera-profiles-v1`). Browser device IDs cannot be assumed valid on other PCs or alternate hostnames; no camera ID or video stream is uploaded to the HomeServer.
- Game > Live Display retains Detect Cameras. A game can add or exclude cameras without changing Admin's shared profile. Its changes are recorded under that game's ID (`lucky-game-camera-selections-v1`).
- The common proof capture page reads the shared profile plus the selected game's overrides and continues to require consent before recording. It retains the maximum of four simultaneous capture feeds.
- Packaged game host pages load the shared camera helper so trusted game host integrations have access to `window.LuckyCameraProfiles.selected(gameId)`. Opaque-origin uploaded game iframes never obtain camera IDs or camera streams.
- Existing legacy `lucky-selected-camera-ids` acts as a fallback until the Admin saves a shared profile; existing aliases remain supported.
- No database migration required. No camera access on initial page load. Previews stop when the tab is hidden or navigated away.

## Acceptance

- Admin section appears and opens from the sidebar.
- Detect shows one card per physical camera, even when there are more than four detected.
- Save limits shared defaults to four and exposes saved labels across same-origin pages.
- Game selection is additive/overriding and doesn't rewrite global defaults.
- Proof initializes from global defaults + game choices.
- No camera capture or preview occurs without an explicit user action.
- Missing/disconnected camera IDs are not silently assigned to a different device.
