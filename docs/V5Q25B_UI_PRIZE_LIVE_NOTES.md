# Lucky Game Hub V5Q.25B — Admin, Prize Media, and Live Camera UI

Local candidate, based on last verified V5Q.24 merge. **Not a GitHub-certified or merged release.**

## Admin → Prizes & Budgets

- The package toolbar now switches packages; **Package settings** opens an independent dialog containing cover, name, optional budget, publication, enablement, and player selection controls.
- Individual prizes render as distinct full-width cards with preview artwork, name, value, description excerpt, and media/proof status. **Edit prize** opens the complete editor, including description smart tags, photo upload, private video upload, and proof-angle settings.
- A new prize opens the same editor dialog; **New package** opens its own creation dialog.
- The old prize video URL input has been removed. Uploaded MP4/WebM files are saved to the protected local `storage/prize-videos` directory, and a phone **Play Video** command opens the video on the desktop. Photos remain independent and render on the phone. Legacy external URLs are not advertised as uploaded media.

## Host game → Live Display

- Removed the `Cameras / Session / Prize video` top-button strip and the extra `Turn off previews` toolbar button.
- The camera panel contains a single **Detect Cameras** button. Camera access is only requested after the user's click.
- Each detected camera gets its own responsive card with source name, inclusion checkbox, private live preview, status, and an individual Preview/Stop preview button. Selections are shared with the prize-proof camera screen through the existing per-browser local setting.
- Up to four cameras are shown because the proof engine currently supports four angles. Media streams stop when the user leaves or hides the page.
- Session status and private prize-video display features remain available below the camera controls without adding top-level mode buttons.

## Prize proof recording countdown

- Pre-recording countdown options: **3 (default), 5, 10 or 30 seconds**. The selector is visible in the Edit Proof header. This is distinct from the recording duration.
- The large on-video timer shows the countdown before MediaRecorder starts; once it reaches zero, the recording timer takes over and the REC indicator appears only during active recording.
- Rotating proof views use the configured pre-countdown before each angle. Stop/abort cancels a pending countdown.

## Tested locally

Browser checks for admin dialogs, published package chooser, phone arrows, desktop video handoff, photo/video independent visibility, claim isolation, and camera cards; PHP metadata and session contract tests; countdown contract; PHP and JS syntax. Live USB-camera hardware, simultaneous physical phones, database contention, full GitHub CI, and Windows clean-install certification remain release gates.
