# V5Q.25 — Prize presentation and gameplay controls (candidate)

- Prize photo and video are independent. Phone shows photo, prize name, amount and expanded description; only a Play Video button appears for video. When tapped, host opens the video on the desktop display; after acceptance this command targets the live Edit Proof page.
- Admin can upload JPEG/PNG/WebP images (5 MB), MP4/WebM videos (50 MB), or supply a supported HTTPS video URL. Media upload icon controls are compact; unrelated purchase URLs do not become videos. Video upload is admin-only, privately stored, uses byte-range delivery for playback, and requires a signed-in host to stream. Uploaded files remain private after a session ends.
- Admin description smart tags: `{prize_name}`, `{prize_value}`, `{prize_tier}`. Unknown tags are rejected. Rendering is deterministic, plain text and done server-side for display and phone speech.
- When two or more enabled, player-selectable, published packages exist, desktop displays the illustrated chooser before Start. Paired phone arrows move the highlight and Select starts the chosen package using the existing HomeServer settings.
- Live tab provides camera detection, preview and selected-camera sharing with proof capture; no recording without consent.
- Edit Proof displays a 5-second camera countdown, per-angle remaining time and a configurable manual recording countdown. Browser-native camera permission and live device testing remain field gates.
- Existing installations must deploy the full package including `storage/prize-videos/.htaccess`, run `upgrade.php`, preserve existing `storage/` files, then clear cached browser scripts.
- **Status**: locally tested candidate; not merged or release-certified on GitHub.
