# Audit Section C — Responsive browser sessions

V5Q32G3 follows Sections A and B. It is a targeted PHP session-lock repair, not a
replacement authentication system, a new background-job engine or a promise that
every operation in the application runs concurrently. No migration is added.

## The defect and release boundary

Bootstrap opens a normal PHP session. With the default file-session handler, a
request holds its session's exclusive lock until it closes the session or exits.
Long AI calls, local analysis and media responses therefore held up other requests
using the same browser cookie, even when the host had another available worker.

`releaseSessionLock()` persists the session and closes that lock explicitly. It is
called only at audited route boundaries, after required authentication, ownership,
CSRF and session mutations. It is a no-op when no session is active and never
reopens a session. The authenticated in-request snapshot remains available for
audit attribution; it must not be used for subsequent persisted session writes.

The helper treats a save warning or unsuccessful close as an error. PHP can return
true from `session_write_close()` even if a save handler could not write. A failed
close must not start a paid provider request or send a media response. The helper
restores the previous error handler and does not send storage diagnostics to the
client. Media routes release before setting success-only content/range headers.

## Included routes

| Area | Boundary and retained protections |
| --- | --- |
| AI connection test | Administrator and CSRF validation precede the provider request. |
| AI edit proposal | Validate the request and reserve the existing four-second rate limit while locked; persist it before the provider call. A competing request sees the saved reservation. |
| Local media analysis | Validate the game, recording and allowed media kind before FFmpeg work. Cache publication remains atomic; concurrent first cache-directory creation is tolerated. |
| Recording media and exports | Resolve the owner-scoped asset/export and validate readiness and file availability before releasing. Existing range, HEAD and method rules remain unchanged. |
| Claim clips and rendered movies/posters | Preserve the existing administrator and row/file checks and shared-admin access model. No new access permission is granted. |
| Audio, prize video/images and game artwork | Preserve each route's existing private or public access checks; release before media delivery. |
| Profile avatar | Preserve signed-in account and image-type validation; release before image delivery. Account changes and sign-out remain locked. |
| Sandboxed game document | Preserve viewer token, game, pinned version and unfinished-session checks before assembling/sending inline package media. The sandbox policy is unchanged. |
| Read-only publisher/update checks | Preserve administrator, CSRF, input and verified publisher-configuration checks. Each existing check uses one configuration snapshot. |

An already-authorized in-flight operation may finish after logout; this build does
not cancel a provider request or retract bytes already sent. Crucially, its late
completion cannot write its old login snapshot back over logout. New protected
requests must still pass the normal authentication checks.

## Verification

- `tests/v5q32g3_session_release.php`: native session persistence, retained request
  snapshot, inactive/idempotent close and injected failing save-handler behavior,
  plus explicit route-boundary contracts.
- `tests/v5q32g3_media_session_contract.php`: source-level placement and unchanged
  authorization/range/HEAD boundaries. These are not claimed as runtime tests.
- `tests/v5q32g3_session_concurrency_db.php`: isolated MariaDB fixtures and actual
  HTTP application requests on independent PHP workers sharing native session
  files. Slow work overlaps same-cookie controls, rate-limit checks and logout.
  Provider transport/failure injection is confined to the disposable test copy;
  production contains no test bypass and the suite makes no paid provider calls.

All executable suites remain explicitly registered. Both PR and post-merge release
checks must pass; actual results must be read from the workflow for the exact
commit. The release ZIP is built from that merged source and independently
verified before publication.

## Deployment and field checks

Download the direct `Lucky_Game_Hub_V5Q32G3_Full_Deploy.zip` release asset, back up,
upload to the existing Lucky installation directory and extract once. Preserve
configuration, existing media and database. See `HOSTGATOR_DIRECT_DEPLOY.md`.
Section C adds no database upgrade; older pending migrations retain their own
requirements.

On a host with multiple PHP workers, use two tabs from the same signed-in browser:

1. During an authorized AI proposal or local media analysis, confirm the second
   tab can load controls and normal status without waiting for the first request.
2. Try a second proposal immediately; it should still receive the existing
   rate-limit response. Do not repeatedly generate paid requests just to test.
3. Play/download an existing large recording while using other host controls.
   Verify full playback, seeking, range requests and private/owner checks.
4. Sign out during a slow request. New protected requests must remain signed out
   after that older request eventually finishes.

## Explicit limits and follow-up work

- Unlocking a PHP session does not create another worker. The Windows launcher
  still uses a single CGI worker, and a saturated shared-hosting worker pool can
  still serialize requests. Runtime capacity/recovery is separate audit work.
- Package staging, installation, activation/recovery, uploads and other mutations
  are not globally unlocked. Staging needs a separate single-snapshot publisher
  trust review before same-session configuration changes can overlap downloads.
- Parallel local analyses can duplicate computation; a bounded job/deduplication
  design is a future optimization. This change does not add a distributed queue.
- The existing AI rule is a four-second interval between proposals, not a
  one-request-at-a-time quota. Explicit proposals after that interval may overlap;
  connection tests and local analyses gain no new concurrency quota in this build.
- Production HostGator, actual paid-provider behavior, physical cameras and real
  router/TURN behavior still need their relevant field checks.
- Windows migration consistency and runtime recycling/recovery remain the next
  confirmed audit items. No repository settings or live installation were changed.
