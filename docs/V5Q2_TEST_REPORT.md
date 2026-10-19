# V5Q.2 — Local Acceptance & Open Release Gates

## Verified local suites

| Test | Result |
|---|---|
| Existing PHP game engine | 70/70 |
| Existing PHP catalog | 13/13 |
| Existing PHP pairing | 24/24 |
| New PHP prize proof-configuration | 8/8 |
| New pure JavaScript sequencing (countdown, auto views, cancel) | 13/13 |
| New isolated Chromium with two fake USB cameras | 12/12 |
| New isolated Chromium prize admin persistence | 6/6 |
| Existing Chromium phone pairing | 25/25 |
| Updated Chromium phone D-pad and voice (no chat field) | 23/23 |
| Existing Chromium phone/game syncing | 15/15 |
| Updated Chromium host/TV control delivery | 27/27 |
| PHP and JS syntax checks | Passed |

**Total: 236/236 checks passed in the listed suites.** This count is *not* a production or hardware certification. The older optional `v5n_console.py` monolithic suite stalled during an earlier combined attempt; it is not represented as passing.

## Scores (local acceptance only)

| Section | Local score | Gate |
|---|---:|---|
| Per-prize proof angle configuration | 10/10 | Server settings validation + browser form checks passed |
| Camera naming, automatic angle count, rotation/countdown | 10/10 | Simulated two-camera browser + sequencing tests passed |
| Claim-linked media metadata and timeline UI | 9/10 | Real MySQL upgrade and file streaming still needed |
| Simplified phone, D-pad, voice Done, temporary notices | 10/10 | Isolated browser tests green; live network still needed |
| Fresh install & upgrade paths | 9/10 | Syntax reviewed; real MySQL not available in this runtime |
| GitHub PR/merge gate | Not green | Repository `davemt74-lang/lucky` contains only an initialization README and a separate draft integration checklist PR #1; complete application source not on GitHub |

## Remaining critical verification

1. Test upgrade and fresh installation on staging MySQL with real DDL/permissions.
2. Test 1–4 USB cameras (permission, source changes and browser codec compatibility).
3. Verify each claimed clip is streamed, ordered, trimmed, and associated with the correct prize claim.
4. Test actual phone commands through hosted HTTPS and disconnect/reconnection, including speech recognition on the mobile browser.
5. Import complete project source into the GitHub repository, run exact-revision CI, review, and merge only when green.
