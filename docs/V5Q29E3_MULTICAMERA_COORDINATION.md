# V5Q.29E3 — Coordinated multicamera recording

This release extends V5Q.29E2 Camera Studio without changing controller pairing, game proof capture, or prior uploads.

## Host workflow

1. In Admin → Recordings, create or choose a game, live event, production, or general session.
2. Activate it and check **Allow approved phones to capture**.
3. Each phone uses the existing desktop QR pairing; the host approves the pairing.
4. On each phone, open Camera Studio, choose Video or Audio, confirm participant consent, open the camera/mic, and tap **Arm for host**.
5. Check the **Armed camera angles** list for connected phones. Start a **multicamera take**; armed phones observe the start signal on their next ~1.2-second control poll.
6. Stop the take on the desktop. Each connected phone stops its recording and uploads it to the same session and take. For a long event, uploads are segmented at about three minutes or 75 MB and resume while the take remains active.
7. The media list displays estimated start offset from the group's take start; this supports E4 editor alignment.

## Timing, reliability and safety

- Control polling is **not frame-accurate**. No low-latency, synchronized camera shutter claim is made. The phone estimates the difference between its clock and the server using the response's `serverTimeMs` and round-trip midpoint. This may drift if network conditions change.
- Each take has one server-generated ID and UTC start timestamp. Every coordinated asset stores its take ID, camera source, approximate offset in milliseconds (`sync_confidence=1`), and start/end/clock metadata. No existing media is changed.
- Only one host take can be active per recording group at once. Repeated start requests with the same nonce are idempotent; overlapping new takes are rejected.
- Each phone explicitly consents and arms itself. Phone permissions are checked against existing approved, non-expired QR pairing and host-owned recording approval on every command and upload. Revocation/completion closes the control channel.
- A phone stops communicating while backgrounded; returning to foreground resumes polling. A phone that misses a whole take cannot retroactively capture it. The host sees stale phones as offline.
- When a host stops a take, phones upload automatically. If upload fails, the local file remains for retry. Media remains private and is only served through the authenticated stream endpoint.
- File caps from E2 remain: 80 MB per media upload, 160 assets or 600 MB per session. Long takes are captured in segments; camera/browser resource limitations may cause gaps during segment uploads.
- Legacy game claim/photo/video/proof flows remain independent and unchanged.

## Next: V5Q.29E4

Add all angles as lanes of a common editor timeline, compensate for estimated offsets, allow manual/audio-assisted fine alignment and multi-angle switching, and preserve the originals. E4 is not part of E3.
