# Lucky Game Hub V5Q32F1 — Broadcast reliability and TURN

This release keeps Camera Director's existing peer-to-peer Live View and adds an **optional** TURN relay with short-lived credentials. A TURN service is not bundled or provisioned. The owner must provide a reachable coturn-compatible relay when viewers are behind networks that block direct WebRTC.

## Configure the TURN server

1. Deploy a coturn-compatible server over TLS where possible. Enable **use-auth-secret** and set a long random **static-auth-secret**. Configure the relay's public address, allowed ports, authentication, certificates and firewall with the TURN host's documentation.
2. In **Admin → Camera Director → Live View → Network reliability**, enter one to four TURN relay URLs, the matching TURN REST shared secret and a credential lifetime. The default is 21,600 seconds (six hours) and the allowed range is 600–86,400 seconds.
3. Enable **TURN relay for new viewer connections** and save. The shared secret is encrypted in the installation's storage directory, never rendered in the admin page and never sent to viewers.
4. Start an authorized Program and publish with the existing consent controls. Newly connecting viewers and the publisher receive short-lived HMAC credentials issued after the server authorizes them. Existing connections are not silently restarted when the relay settings change.

Example relay URL: turns:relay.example.com:5349?transport=tcp

The app falls back to its existing Google STUN configuration when TURN is disabled or not configured. Relay credentials are intentionally exposed to an **authorized WebRTC client**, not the server's shared secret. For a public broadcast this includes public viewers; configure relay quotas and traffic policy accordingly.

## Viewer recovery

- Live View shows ICE connection diagnostics and a manual **Retry connection** button.
- Failed peer connections retry with bounded exponential backoff. Temporary disconnections get a five-second recovery window.
- Private invitations stop retrying on revocation (HTTP 403). A private viewer can attempt up to six automatic reconnections before manual Retry is required.
- Permanent public show URLs continue resolving the currently authorized **public** Program. They stay off air when a Program ends or is private.
- All viewers still see **Program only**: no Preview sources, unpublished microphones or separate cameras are exposed.

## Operational limits

TURN improves connectivity but does not guarantee availability. The host's network, browser, uplink, server signaling and the separate TURN service can still fail. This release does not add media recording or grant new camera/microphone permissions. Validate real TURN connectivity between different networks before a production show.
