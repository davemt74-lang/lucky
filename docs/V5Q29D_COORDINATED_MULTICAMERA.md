# V5Q.29D — Coordinated multicamera proof recording

## Operator workflow

1. Open a claimed prize's proof editor on the desktop. In **Camera settings → Permissions**, check **Use phone camera**.
2. Ensure the phone was QR-paired and approved by the desktop host. On each participating phone, enable recording consent, open **Phone camera**, choose **Video**, and tap **Arm for multicamera**. This user gesture is required to request camera and microphone access. Multiple paired phones can arm independently.
3. Start the desktop's existing multicamera recording (including its countdown). Each armed phone watches the existing authenticated, claim-bound proof status: countdown leaves it armed; `recording` starts its local MediaRecorder once; leaving `recording` stops it.
4. Review each phone take locally, then tap **Send to Video Studio**. Phones never auto-upload, and each video arrives as a separate media-bin clip with a server-originated **Phone · [paired device]** angle label. Combine angles in the Video Studio timeline.

## Timing and limitations

This release uses the existing desktop heartbeat and phone status polling, so phone and desktop recordings are **coordinated, not frame-accurately synchronized**. Each browser may start or stop up to several polling/network intervals after the host. Align angles during timeline editing. Phone video recording remains limited to 60 seconds / 80 MB; individual device codecs and recording permissions vary by browser. Keep the phone capture screen active during recording.

## Access, privacy, and failure behavior

- QR pairing alone does not grant camera access. The desktop host must enable **Use phone camera** and the phone user must affirm consent and explicitly arm; standard browser camera/mic permission remains required.
- No camera or microphone stream is uploaded just by arming. A file is sent only after the user taps **Send**, via the existing token-bound `api/phone-media.php` endpoint with server-side paired device, live host, session, claim, consent and nonce checks.
- Host permission revocation, connection expiry, proof close, or changing session/claim closes phone capture, stops device tracks and prevents upload. The host does not silently start an unarmed camera.
- Phone camera streams are local to their individual browsers; the server receives only intentionally submitted files. Filenames and camera labels are not treated as authorization or path components.

## Certification

`tests/v5q29d_multicamera_browser.py` verifies opt-in arming, no recording until host begins, countdown, exactly-once start across polls, host-driven stop, explicit review/upload, revocation/disarming, secure upload binding and absence of browser exceptions. The existing V5Q.28B phone photo, video, audio, gallery and access-control tests remain in CI.
