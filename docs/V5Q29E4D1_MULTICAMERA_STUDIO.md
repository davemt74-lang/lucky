# V5Q.29E4D1 — Full-screen multicamera editor foundation

This section upgrades the existing `recording-editor.php` into a dedicated,
viewport-filling editing workspace while preserving the E4A alignment and
camera-cut timeline and E4B authenticated MP4 export path.

## Workspace

- Global site header/footer are hidden only within the studio; **Recordings**
  returns the admin to the recording library.
- Searchable media library filters video, photo and audio uploads by source,
  title or take. Selecting a source seeks the shared session clock and opens
  the clip inspector. No direct media file URLs are exposed.
- Preview monitor, camera switcher and multi-lane shared timeline remain live.
  Timeline lanes scroll horizontally without causing document-wide overflow.
- The inspector is a dismissible overlay with its own close button, backdrop
  and Escape shortcut. It updates only non-destructive alignment offsets.
- MP4 export options and job history live in a modal; pending edits must still
  be saved before queuing a render. Browser reload restores saved revisions.
- On narrow screens the panels stack, preserving functional playback, editing
  and rendering while allowing vertical page scrolling.

## Acceptance boundaries

- This is the **workspace foundation**, not yet split/trim handles, transitions,
  overlay layers or automated highlights. Those belong to later E4D sections.
- Authentication, CSRF, project ownership, optimistic revision checks, export
  nonce handling and media streaming stay in their existing server APIs.
- Studio media titles and camera names are inserted as plain text. No browser
  permission to record cameras/microphones is requested by the editor.

## Tests

- `tests/v5q29e4d1_studio_contract.php`: full-screen layout, media filters,
  modal/inspector wiring and existing edit-path guards.
- `tests/v5q29e4d1_studio_browser.py`: desktop/mobile overflow, filters,
  inspector, existing revision save, export modal and browser console errors.
- Existing E4A/E4B browser tests continue validating timeline and export flows.
