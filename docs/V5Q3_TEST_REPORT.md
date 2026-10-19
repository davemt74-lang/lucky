# V5Q.3 — Acceptance and blocked gates

## Passing local checks

- PHP syntax: all PHP under `lib/`, `api/` and `bin/`.
- JavaScript syntax: all public JS files and sequencing logic.
- PHP game engine: **70/70**.
- PHP product catalog: **13/13**.
- PHP phone pairing: **24/24**.
- PHP prize-proof configuration: **8/8**.
- Proof capture sequence: **13/13**.
- V5Q.3 montage unit tests: **15/15**.
- V5Q.3 editor Chromium: **14/14** (including 375px overflow fix, split, saved cuts, soundtrack and queue).
- Existing proof-camera browser: **12/12**.
- Existing admin proof browser: **6/6**.
- Phone navigation + voice browser: **23/23**.
- Phone/game sync browser: **15/15**.
- Local FFmpeg media test: encoded playable 720p MP4 using a photo, trimmed video, and audio clip with selected music. FFprobe confirmed a nonzero output duration.

**213 unit/browser assertions** in the enumerated suites, plus PHP/JS lint and one real MP4 encoding smoke test. The older `v5p_button_sync.py` suite did not complete within the execution limit during the latest regression pass and is not counted as green.

## Development scores (local only)

| Section | Local score | Open gate |
|---|---:|---|
| V5Q.3A — non-destructive editor, split, transitions & reorder | 10/10 | Real MySQL project persistence |
| V5Q.3B — auto-assemble by claim and camera angle | 10/10 | Real game-night footage |
| V5Q.3C — soundtrack and speech ducking | 10/10 | Production audio device output |
| V5Q.3D — queued H.264/AAC MP4 export | 9/10 | Deployed FFmpeg CLI cron, storage and codecs |
| V5Q.3E — repository PR/CI/merge | Blocked | Full application source not on GitHub; PR #1 is draft checklist only |

Do **not** mark production certification green or merge a documentation-only PR. Import the verified full source, run CI against an exact commit, audit failures, then merge if all required gates pass.
