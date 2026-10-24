# Lucky Game Hub V5Q.28A — Claude Video Editor

This is an **optional** extension of the existing non-destructive Video Studio.

## Setup

1. Sign in as administrator and open **Admin → AI Studio**.
2. Paste your Claude API key and select Haiku 5.5 (default), Sonnet 5.5, or Opus 5.5.
3. Save the key; optionally use **Test connection** to make one small external request.
4. Open **Video Studio → AI Editor**, select a skill, and enter an instruction.
5. Select **Generate proposal**. Review each tool action. Choose **Apply to unsaved edit** and then **Save edit** if the outcome is useful.

The application never needs a Claude key to play games, edit videos manually, or render existing projects.

## Skill registry

| Skill | Permitted operations |
| --- | --- |
| Highlight reel | Reorder, trim and transition |
| Cinematic finish | Filters, transitions and text overlays |
| Story & titles | Timed titles, overlays and reorder |
| Music & sound | Uploaded soundtrack, mute/unmute and transitions |

The registry is server-side (`lib/VideoEditorSkills.php`), with tool arguments checked against the existing session's `claim_media` source rows. Claude has no file, SQL, publishing, deletion, rendering, or unapproved save tool.

## Privacy and governance

- Only an authenticated administrator can configure or invoke Claude. POST actions require CSRF tokens.
- The key is encrypted with AES-256-GCM. The ciphertext and distinct random per-installation key are stored as non-exposing PHP files with restrictive permissions in the protected `storage` directory. Replacing the key invalidates the previous ciphertext.
- Pressing **Generate proposal** explicitly authorizes a Claude Messages API call to Anthropic. The payload contains the chosen skill, instruction, clip IDs/titles/types and trim/duration/order metadata, plus IDs/titles of uploaded soundtrack selections.
- **No actual video, audio, images, URLs, player accounts, saved tokens, or API credentials** appear in Claude's prompt. The Claude key is only in an HTTPS request header.
- Responses are bounded, JSON-decoded, and validated by tool/skill whitelist, clip ownership and source duration. The browser additionally checks its timeline revision fingerprint before applying. Invalid plans are discarded.
- Approved proposals modify only the current unsaved editor state. The existing `Save edit` endpoint validates source media and writes the project. There is no automatic export or overwrite.
- No background AI request is made. An offline HomeServer continues to function when Claude is unconfigured or unreachable.

## Deliberate first-section limits

- The AI works from metadata, not frame/audio analysis; it cannot honestly detect a laugh, recognize a face, identify speech, or generate real subtitles from audio.
- It cannot add new source clips, delete originals, publish movies or trigger FFmpeg automatically.
- Key management is write-only. Tests should mock the Claude service and must never use a real customer API key.
