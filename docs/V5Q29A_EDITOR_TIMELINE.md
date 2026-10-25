# Lucky Game Hub V5Q.29A — Video Studio Timeline Foundation

The existing Video Studio now supports reversible, non-destructive edits with accurate timing and optional snapping. The editor remains fully usable without Claude or network access.

## Editing

- **Undo** and **Redo** are in the timeline header. `Ctrl/⌘+Z` and `Ctrl/⌘+Shift+Z` (or `Ctrl+Y`) work outside form controls; native undo inside text fields is untouched.
- Up to 60 timeline snapshots cover clip trim, split, remove, reorder, looks, text overlays, timed titles, background music and locally applied AI suggestions. A new change after undo clears the redo branch. Duplicate redraws do not fill history.
- History is scoped to one currently selected game session. Switching sessions or receiving an independently saved proof upload creates a new baseline. Permanent deletion of original proof media is **not** undoable; history is cleared after deletion so no deleted files can be referenced from a restored draft.
- The dirty/saved state is recalculated from the last persisted project. Undoing to the last saved state clears the unsaved-change warning. An in-flight save does not erase a later edit.
- **Snap** is checked by default and can be toggled off. Pointer trimming snaps to nearby half-second grid lines. T1 and B1 dragging additionally snaps to sequence clip boundaries and other title/music edges. Holding Shift during a drag temporarily bypasses snap. Keyboard trim remains a precise 0.1-second step (Shift = 1 second).
- The visible ruler now projects labels into the same variable-width timeline coordinates as V1, including the minimum-width display for short clips. T1/B1 and the source audio lane share the same projection.

## Sound waveforms

- A1 source-audio and B1 soundtrack blocks can display peaks calculated from real decoded samples, not generated decorative bars. A waveform is never mistaken for a transcription or speech analysis.
- Decoding takes place in the signed-in administrator's browser using native `AudioContext`, via an authorized same-origin stream. No waveform bytes go to Claude or a third party.
- Only audio files under 12 MiB are decoded for visualization, with a bounded sequential queue and cached results. Unsupported audio codecs, large source files and embedded video audio may show the normal track label without a waveform; timeline editing and FFmpeg export continue to work.
- Waveform data is an optional visual aid. Volume, trimming and rendered audio still use the existing source and project records.

## Acceptance gates

1. Node unit assertions for history isolation/limits/branching, snap behavior and actual per-channel amplitude samples.
2. Chromium browser acceptance for new toolbar, time ruler, undo/redo/keyboard actions, titles, soundtrack and waveform canvas.
3. Existing editor, phone upload, proof revision, FFmpeg, MariaDB and Windows HomeServer suites remain green.
4. No source files deleted or overwritten by an undo. App files must be identical across Cloud and Windows builds.

## Deployment

This section requires no database migration. Deploy the matching Cloud package or Windows HomeServer installer and refresh Video Studio to pick up `public/editor-timeline.js` plus updated `editor.js` and CSS. Cache-buster version is `5q29a`.
