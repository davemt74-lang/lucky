# V5Q.29B — Private Scene & Silence Intelligence

This release adds local source analysis to Lucky Game Hub Video Studio's Claude-powered editor. **Claude never receives media files, audio waveforms, images, video frames or transcripts.**

## Admin workflow

1. Choose an existing game session and add a video or audio recording to the editor timeline.
2. Open **AI Editor → Analyze timeline locally**. FFprobe and FFmpeg inspect up to the first 12 unique video/audio sources on the local Cloud or offline HomeServer installation. Repeated runs use the private cache until the recording changes. Still images do not need timing analysis.
3. Review counts of detected scene changes and quiet sections. Analysis happens on this server, without contacting Claude.
4. Optionally check **Include numerical scene and silence timings in my next Claude proposal**. It is unchecked by default.
5. Press **Generate proposal**. As before, clip metadata and the entered instruction go to Claude; only when the box is checked do cached scene-cut and silence timestamps accompany the proposal. The response stays an owner-reviewed suggestion and cannot save, delete or render without the existing workflow.

## Security contract

- `POST /api/video-ai.php?action=analyze` requires authenticated admin role, CSRF, a real session and a media ID owned by that session. It runs local-only and returns bounded numerical cues. Anonymous paired phone controllers cannot invoke it.
- FFmpeg and FFprobe are restricted to `file,pipe` input protocols, preventing remote media references in an uploaded container from initiating remote fetches during analysis.
- Cached observations live under `storage/media-analysis` (private server storage blocked by the existing web-server rules), with 0700 directory and 0600 cache file mode. Cached observations are invalidated when the source filesize or mtime changes; deletion of an original removes its analysis file.
- `POST /api/video-ai.php?action=plan` only includes the optional `timingCues` property when `includeTimingCues` is **exactly** `true`. It retrieves cached server-derived cues from authenticated session media, never from client-provided cue values.
- The AI boundary reduces observations to up to 12 source timeline instances, 12 scene timestamps and 12 silence intervals each. No transcription or speaker analysis is supported by this release.
- The existing Claude key remains encrypted server-side, and skill/tool allowlists remain unchanged.

## Certification

- PHP unit contract: parser accuracy, cue sanitization, cache path traversal rejection and never leaking private metadata.
- FFmpeg integration: hard-cut scene detection, audio-only silence detection, cache re-use and invalidation.
- Playwright browser: default opt-out, admin POST/CSRF, local-only analysis, explicit timing-sharing opt-in and owner review.
- Full existing CI and real Windows executable installation/restart parity are release gates.
