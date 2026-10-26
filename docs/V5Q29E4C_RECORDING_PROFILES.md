# V5Q.29E4C — Recording profiles and camera policies

This is an incremental extension of the E1 universal recording types, E3
host-controlled phone capture, E4A grouped timeline, and E4B MP4 export.
Existing sessions and original prize clips remain unchanged.

## Recording session profile

Admin → Recording Sessions supports four established types:

- **Game proof:** links to a real game session and snapshots that game's
  recording policy at creation. Captures still belong to the game session.
- **Live event:** event date, venue, notes, tags, and a multicamera session.
- **Content campaign:** a production brief and checklist/shot list travel
  with the recording to the paired phone.
- **Custom:** independent ad-hoc capture with the same core media pipeline.

Each new session saves structured event date, venue, up to 12 tags, a production
brief, up to 16 shot-list items, and immutable capture policy JSON in
`recording_session_profiles`. The library filters type, title, description,
tags, venue, and game-session identifier without leaking another administrator's
data. Older recordings retain safe defaults with no added row.

## Per-game capture policy

Admin → Game Catalog → Game Profiles → **Game recording & phone cameras**:

- Enable or disable multicamera; single-camera mode caps active phones at one.
- Set camera limit (1–8), allowed video/photo/audio, optional phone capability,
  and host countdown (0–30 seconds).
- Changing a game policy affects **new recording groups**. Existing recordings
  are intentionally immutable. Explicit host phone permission is still required
  before capture, and the phone must be QR paired and consent to recording.
- Non-game captures may use their own session-specific capture policy.

## Enforcement

The backend checks allowed kinds for phone uploads, blocks host phone grants
when the profile forbids them, and validates multicamera arm operations while
holding a lock on the recording row. Only fresh, paired, armed phones occupy
the camera budget. Camera permissions are never inferred solely from disabled
buttons. The camera page shows brief, shot list, tags, venue, and allowed modes.

The countdown is a host-side delay before creating a coordinated take; it is
**not frame-accurate synchronization**. E4A alignment remains available, and
E4B exports remain capped at 20 minutes and 720p.

## Upgrade / certification

Apply `upgrade.php` for existing Cloud deployments. Fresh Cloud and Windows
installers and Windows restart migrations include E4C automatically. E4C
tests cover game policy persistence, validation boundaries, nonce conflicts,
admin isolation, phone permissions and camera quotas, and phone browser UX.
