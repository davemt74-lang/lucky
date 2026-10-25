# V5Q.29C — Scene-Aware Timeline Actions

In **Video Studio → AI Editor**, select a game timeline and press **Analyze timeline locally**. For each recognized scene-change marker, use **Set In**, **Set Out** or **Split** to edit the exact timeline instance at that source timestamp. For silence intervals, **Set Out** at the start of quiet and **Set In** at the end of quiet are available.

These are bounded, non-destructive source-time edits:

- Trims preserve the remaining visible segment and enforce a minimum 500 ms clip length.
- Split duplicates the source *reference* into two contiguous timeline instances; it does not copy, change or erase the original recording.
- Every edit records in the existing 60-step Undo/Redo history, and remains unsaved until **Save edit** is clicked.
- Stale clips, invalid timestamps, over-80 segment splits, photographs and unknown clip durations are rejected.
- No Claude request or database write takes place when pressing a marker action. Claude remains optional, and private numerical timing-cue sharing stays off by default.
- Two additional Claude specialties, **Scene-aware cuts** and **Silence-aware pacing**, still use only existing whitelisted, reviewer-approved tools.

Certification includes pure marker-boundary tests, browser UI privacy tests, full editor integration with split/undo/redo/save, all inherited PHP/FFmpeg/MariaDB/Playwright checks and Windows HomeServer clean-install/restart.
