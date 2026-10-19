# V5Q.7 — Host workspace and video editor redesign

## Main game page

- **Game**, **Music** and **Connected Phones** are tabs in the primary, left-hand workspace. **Game** is selected on initial load.
- Claimed prizes, proof versions and Edit/Revise Proof buttons persist in the right-hand column, independent of the selected tab.
- On small viewports the claims column stacks beneath the primary workspace; the page does not require horizontal scrolling.
- The existing host game, claim revision, pairing and music control element IDs are preserved for compatibility.

## Phone navigation

- A paired phone sends directional UI events via the existing acknowledged controller transport.
- The shared `LuckyConsole` navigation handles the same actions as a keyboard or gamepad.
- Left/Right on a focused host tab changes the active panel. Select activates focused buttons or links. Hidden panels cannot acquire directional focus.
- The controller may navigate an open editor page when that page is the active host receiver. It cannot issue extra spins or change prize awards through these UI events.
- When more than one authenticated host tab is open, controllers should be tested with the intended host tab visible; UI event reception is currently per host account rather than exclusively leased to one tab.

## Full-screen video editor

- Editor no longer has the normal site navigation, large page-intro or footer.
- Toolbar includes session selector, preview, stop, auto-assembly, save timeline, export edit project and return links.
- Dedicated video monitor, timeline and clip inspector share the primary workspace. A separate bottom export dock provides quality, uploaded soundtrack, volume and MP4 rendering controls.
- All prior non-destructive timeline and proof-revision behavior remains unchanged.

## Local checks

- `python tests/v5q7_workspace_browser.py` validates desktop/mobile layouts, tab behavior and D-pad integration.
- `python tests/v5q6_proof_browser.py` and `python tests/v5q3_editor_browser.py` cover claim history and existing editor operations.
- PHP/JS syntax, game engine, catalog, controller pairing, montage, proof configuration and proof-revision model checks are unchanged.

## Installation

Back up database and `storage` first. Deploy ZIP over the current application without overwriting runtime configuration or uploaded media. Run `upgrade.php` after uploading. Clear cached front-end assets if pages look unchanged.

## Production gate

Live multi-device command routing, your hosted MySQL database, actual cameras and FFmpeg rendering remain on-site verification requirements. Automated browser tests use mocks and cannot certify real devices or the hosting configuration.
