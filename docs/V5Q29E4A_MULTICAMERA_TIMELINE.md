# V5Q.29E4A — Shared multicamera timeline

This section adds a non-destructive editor for grouped phone recordings. E3's host take coordination and phone permission rules are unchanged.

## Using the editor

1. In **Admin → Recordings**, choose a recording session and open **Edit multicamera timeline**.
2. The editor shows each approved phone/source on its own lane. Recording takes are placed on one session-wide clock. Clips captured through E3 use their approximate take-relative start offsets.
3. Select a clip, inspect its take/source and synchronization confidence, then use **−0.1 s**, **+0.1 s**, or type an offset to fine-align it. The original start is displayed and can always be restored.
4. Scrub the shared timeline. Any angles available at the playhead appear as buttons. Choose an angle to create a camera cut; a list of cuts supports quick navigation and removal.
5. Click **Save alignment**. The saved project has an optimistic revision to reject stale editors. **Edit list** downloads a portable JSON decision list with media IDs and offsets; video originals are never duplicated or changed.

## Security and known limitations

- Only an authenticated administrator who owns the recording can read or save the timeline. Mutations require CSRF protection; all referenced media must belong to that same recording. Private clips continue to use `api/recording-media.php`.
- Concurrent edits cannot silently overwrite one another. New projects start at revision 0; each save increments the revision.
- The common timeline uses E3's **approximate** polling clock alignment. Manual offsets can correct visible drift. This does not promise frame-accurate sync, automatic waveform-based correction, or simultaneous multi-camera hardware capture.
- The media lane currently covers direct phone/file uploads. Linked legacy proof clips continue to use the existing game proof editor until bridge support is added.
- The stage previews selected angles and records the decision list. **MP4 composition/export of multicamera cuts** belongs to the next E4B section; E4A does not claim to render a finished movie.

## Validation

- Contract checks: setup, editor UI, server-side scoping/CSRF, persistence and migration.
- MariaDB checks: multiple cameras on a common clock, revisions, stale conflicts, rejected cross-owner media, invalid cuts, and non-destructive clearing.
- Browser checks: angle lanes, nudge, live cuts, persistence across reload, and conflict feedback.
