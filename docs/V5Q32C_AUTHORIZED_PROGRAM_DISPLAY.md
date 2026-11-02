# V5Q32C — Authorized Live Program inside gameplay and the TV screen

V5Q32B introduced a separate Camera Director with real Preview/Take switching and safe on-screen game cues. V5Q32C adds an **optional receiver** to the existing host gameplay page and dedicated audience/TV display. The receiver reuses the established WebRTC Live View signaling and Program compositor.

## How to run the two-computer show

1. On **computer A**, start Spin the Wheel. The dedicated TV invitation remains `display.php?session=…&token=…`; the host itself can stay on `game.php`.
2. On **computer B**, open `director.php?game=…`, connect approved player cameras or a desktop source, and **Cue Preview → Take to Program**.
3. In Camera Director, select private (recommended) or public visibility, confirm participant/content broadcast permission, and click **Publish Program to Live View**. Recording and publishing remain deliberate actions.
4. Copy **Open Live View** / the private invitation link from Director.
5. On computer A's gameplay page or the audience TV browser, click **Connect Program**, paste that Live View link, then **Connect feed**. The Program video appears over the wheel in a resizable view (regular or expanded); camera changes taken in Director update the same stream. Audio is muted until **Enable sound** is clicked.
6. Press **Disconnect** on the receiving screen, or **End Live View** in Director, to stop the remote feed. The wheel and synchronized lower-third show cues continue to work independently.

## Authorization boundaries

- **No automatic connection:** viewing the game, starting a game, or assigning a phone does not request camera/microphone permissions, start recording, publish video, or join a broadcast.
- **No shared bearer credentials:** the private broadcast invitation is **never** stored in `GameEngine::publicView`, Director event history, a game-level database row, a session URL, or browser storage. Only a viewer who has been given the original invitation can connect to a private feed.
- **Private invitations are ephemeral:** the pasted input is cleared on submit. The browser retains the invitation only in memory for signaling while connected. The embedded viewer sends authorization data in JSON POST bodies for `watch`, `offer`, and `poll`—not in its own page URL or read-only game state. Legacy standalone Live View links continue to work.
- **Same-origin requirement:** pasted links must point to this Lucky installation's `live-view.php` with a valid broadcast ID and optionally a valid invitation key. Cross-origin links are rejected locally. Server-side `DirectorBroadcast` still verifies validity, scope, live heartbeat, recording eligibility, consent for proof sessions, and the viewer limit.
- **Revocation:** ending a broadcast or losing server authorization stops the receiver at its next periodic status check and clears the video. Disconnecting on the viewer closes immediately. The receiver does not create a new media stream or control any camera.
- **Capacity:** the existing Live View protocol allows up to eight viewers per broadcast, counting this embedded viewer.

## Limits and next testing

- On typical HostGator/shared hosting, Program video remains peer-to-peer WebRTC using the existing STUN-only configuration. Network environments requiring a TURN relay may fail to connect. This build deliberately does not add a paid relay or broaden permissions.
- The overlay toggles between Picture-in-Picture-style and expanded in-page modes; it does not use the browser's native Picture-in-Picture permission API.
- Multi-device real-world testing must confirm Program delivery on the user's actual two computers and a paired phone. CI tests exercise safe invitation parsing, signaling, video lifecycle, mobile layout, and revocation; they do not replace live network tests.
