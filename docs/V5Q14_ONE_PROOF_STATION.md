# V5Q.14 · Unified desktop proof + phone controller

## User path

1. **Start Game** from the desktop. The browser asks for camera permission during that desktop click.
2. A winning spin finishes animating, then opens `proof.php?session=<session>&claim=<claim>&from=game&mode=win` in the same desktop tab.
3. The same two-column claim proof station is used by the **Edit Proof** action in the game's claimed-prize ledger, with revision history on the left and live cameras/capture on the right.
4. While this station is open, its heartbeat publishes the exact session/claim to approved phones. The phone hides Spin, game navigation, and music and shows a **default-closed Proof controls** accordion. Within that accordion are Record, Stop, Play, Approve, Re-record, Delete, and Done.
5. When video is recorded, it is kept locally for review until approved. Delete/Re-record do not upload rejected clips.
6. Done returns the desktop to that same game session and clears the phone proof mode; Spin Again is available on a fresh winner's desktop proof page.

## What is not included

The phone never records media. USB cameras attach to the desktop. No extra winner capture modal exists on the game page. A browser cannot grant webcam permission in response to a remote phone command; first consent requires a local user gesture or previously granted permission.

## Verification

CI covers the proof page, game award handoff with exact claim/session IDs, two simulated USB cameras, editing-history regressions, phone mode changes, and FFmpeg smoke rendering. **Real-camera hardware, actual phone-to-host network delivery, and production MySQL/FFmpeg still require field testing.**
