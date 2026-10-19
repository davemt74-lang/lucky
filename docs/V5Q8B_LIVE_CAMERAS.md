# V5Q.8B — Live cameras and session-scoped proof return

## User flow

1. On the Host Game screen, select **Edit proof** for a claimed prize. The link includes `session`, `claim`, and `from=game`. The exact session is verified against the authenticated game API. A five-minute browser-session fallback exists only for a recent explicit Edit proof click; the application never guesses a prize.
2. The proof screen loads the claim before enabling **Enable & find cameras**. Video is previewed live, with saved editable names. Recording consent is required for capture and upload, but not for local video preview permission.
3. **Back to game**, desktop Escape, and the approved phone's Back control return to `game.php?session=...`, which reads and synchronizes that session rather than switching to the most recent session. Proof entries launched from Claims return to that session's Claims list.
4. The Video Studio has a live camera panel with an explicit claim selector. Capture/upload is rejected unless a claim is chosen. Each successfully saved clip is inserted into the media bin and current timeline without changing the existing edit. The original clip and older proof revision history remain intact.
5. Browser page exit releases camera streams. USB cameras require HTTPS, browser-granted camera access, and sufficient bandwidth for the selected previews.

## Acceptance

- `python tests/v5q8b_claim_camera_return_browser.py`: claim context and origin return; two simulated camera feeds in both editors; consent boundary; automatic timeline insertion; no exceptions.
- Existing browser/editor and PHP tests plus GitHub core/browser/video-encode CI.
- Physical USB cameras, production MySQL and hosted MP4 encoding remain field-certification items, not software CI claims.

## Updating

Back up the database and `storage/` media, upload the complete release while preserving `storage/config.php`, then run `upgrade.php` for outstanding older migrations. Refresh browsers to load `v=5q8b` assets. No new database migration is introduced in V5Q.8B.
