# V5P.1 — Phone / Host / TV command synchronization repair

## Root cause

In V5P, the phone's success banner meant a button press had been queued. The queue was then destructively polled only by a logged-in Host Game tab, not by the TV display, and could be removed before a display handled it. An approved phone could therefore appear connected but have no functional control when only the TV screen was open. The phone also did not distinguish queued commands from executed commands.

## Repair

1. **Server-authoritative game lifecycle:** Approved phone Start, Spin / Select, Pause / Resume, and confirmed End Game execute in the shared PHP game engine using the same persisted session and claim protections as desktop actions. Spin awards remain server-selected and cannot be chosen by the phone.
2. **TV and host both receive UI/audio commands:** Menu, Back, directional buttons, music, next track, volume, and mute are published into `controller_ui_events`. Either the host or TV can process and acknowledge commands. The TV now has a basic remote-control help/menu overlay.
3. **Phone delivery feedback:** The phone shows “waiting for game screen” until an authorized active receiver confirms execution. The phone shows an explicit failure rather than a false success after expiry, a missing song, or when the browser blocks audio.
4. **Join/resume state:** The phone's initial and repeated `status` snapshots reflect the running/paused/ended state, spin count, remaining prize count, claimed count, most recent prize, and whether Spin/Start/Resume may be used.
5. **Desktop/TV animation:** The desktop and TV animate saved server results instead of replaying spin requests and risking double claims.
6. **Receipt retry:** The display retries missed acknowledgements without repeating the actual button action.
7. **Database clock safety:** DB connections set session time zone to UTC so invite and command expiry comparisons match `CURRENT_TIMESTAMP` on shared hosting.
8. **Deployment:** The migration adds only a new receipt table, with `CREATE TABLE IF NOT EXISTS`. Earlier prize, claim, music and session data remain intact.

## Behaviour and limitations

- The **gameplay actions** above do not require a background Host Game browser tab to execute. The resulting wheel animation still requires an active Host Game or TV display browser tab.
- **Screen/audio actions** require an open Host Game or TV browser tab. They do not run on the server because browsers own audio and the user interface.
- A TV or desktop browser may block **remote-started music autoplay**. Tap its Music On button once to unlock audio; a blocked command will not receive an “executed” acknowledgement.
- Voice controls, prize budgeting, Tier 1 → Tier 2 → Bonus promotion, and claimed-prize history are unchanged.
- A TV display URL is tied to a particular game session; starting a *new* session after ending the old one requires opening the new display link. Existing paired phones re-read current game state.
- Do not share the TV display's access URL or controller invitation QR publicly.

## Upgrade steps

1. Back up database and storage, especially `storage/config.php` and uploaded music/images.
2. Upload the **full V5P.1 ZIP** over the current V5P installation. Preserve your existing `storage/config.php` and uploaded files.
3. Sign in as host and run **`upgrade.php`** to create `controller_ui_events`. This is required for UI and audio controls.
4. Reload the desktop game and TV display tabs and refresh the phone controller. The updated JavaScript is cache-busted with `?v=5p1`.
5. Pair/approve a phone; verify Start → Spin → Spin Again, Pause → Resume, confirmed End, Menu/Back, volume/mute, music with at least one library track, and a second phone joining mid-game.

## Local release checks

- PHP game engine: 70/70.
- PHP pairing validation: 24/24.
- PHP product catalog: 13/13.
- Existing browser: 28/28.
- Existing home-console browser: 23/23.
- V5P pairing UI: 25/25 (legacy destructive queue is intentionally disabled).
- V5P.1 gameplay/snapshot browser: 15/15.
- V5P.1 host/TV/phone button sync browser: 27/27, including missing-song error acknowledgment.
- **Total: 225/225 local checks passed**, plus PHP and JavaScript syntax checking.

These are mostly unit and browser tests with mocked HTTP responses. **Live PHP/MySQL transaction tests, real HTTPS network roundtrips between phones/TV, and audio permission tests have not been performed here.** Treat this as a staging candidate, not a production-certified release. No GitHub source PR has been merged: `davemt74-lang/lucky` currently contains only its initialization and release checklist PR, not this complete PHP baseline.
