# V5Q.14B — live proof camera + paired phone synchronization

- One desktop capture station: `proof.php?session=...&claim=...`, used by both new wins and Edit Proof.
- The claim must be verified against its game session before camera access begins or the phone receives proof mode.
- After claim verification, the browser attempts to request access and open live USB/built-in camera preview feeds immediately. A first-time browser permission prompt may require desktop approval; if denied, the Enable Cameras button offers a manual retry.
- Media recording still requires explicit consent. New videos remain local until Play/Approve/Re-record/Delete action.
- The desktop announces proof mode as soon as the exact claim is verified, **before** waiting for proof-history snapshots; a 2.5-second heartbeat maintains the session.
- Paired phone polls and switches all gameplay controls to its default-closed proof accordion; leaving restores gameplay.
- No duplicate winner modal or phone camera capture.

## Test gates

- `python tests/v5q14b_live_phone_handoff.py` — shared mocked server, two independent browser pages, 13 state/camera assertions.
- `python tests/v5q13_camera_review_browser.py` — recorded review and approval.
- `python tests/v5q13_phone_proof_browser.py` — phone mode and button state.
- `python tests/v5q8b_claim_camera_return_browser.py` — claimed-prize selection, live camera, timeline integration.
- PHP/JS syntax, older regressions, GitHub CI on exact branch SHA.

## Live checks required

Test on HTTPS with a connected USB camera and a separate paired phone. If the user declines camera permission or the camera is already in use, auto-start cannot override browser permissions; the user can retry manually. Verify fresh-win entry, old Edit Proof entry, phone Record/Stop/Approve, and Done back to the same game session.
