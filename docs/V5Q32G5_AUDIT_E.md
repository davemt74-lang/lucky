# Audit Section E — Bounded Windows HomeServer worker recovery

V5Q32G5 changes the Windows launcher, not the educational/marketing product's
hosted PHP configuration. The two products continue to share phone connections,
multiplayer and video-editing foundations while retaining independent accounts,
databases, settings and gameplay options. No repository split or Gameroot account,
subscription, license or update-service change is performed.

## Confirmed defect

The bundled PHP CGI executable defaults to a finite FastCGI request limit unless
`PHP_FCGI_MAX_REQUESTS` overrides it. The previous launcher did not set that
variable and treated any PHP worker exit as failure of the entire local stack.
A normal worker recycle could therefore stop the database and web server along
with the PHP worker. User environment variables could also alter the intended
single-worker model.

The production launcher now sets `PHP_FCGI_MAX_REQUESTS=0` and
`PHP_FCGI_CHILDREN=0` for every initial and replacement worker. It uses process
environment inheritance compatible with Windows PowerShell 5.1 and restores the
launcher's previous values afterward. No system-wide environment is changed.

## Recovery and safety boundaries

- Only exit of the current, owned PHP process triggers worker recovery. A slow
  response from a living worker does not. Initial application startup retains its
  existing bounded HTTP readiness check; recovery uses listener ownership, not an
  HTTP health timeout.
- Each PHP generation must own the loopback FastCGI listener on port 9017.
  An unrelated listener is neither adopted nor stopped. A replacement does not
  reopen database initialization, rerun schema migrations or restart Caddy.
- At most three replacement attempts are allowed in a rolling 60-second window,
  with 1/2/4-second backoff. Failed launches count. Time accounting is monotonic;
  exhausting the budget is a visible terminal error, not an infinite crash loop.
- Caddy is explicitly configured with zero load-balancer retries and zero retry
  duration. The generated configuration is validated by the bundled executable
  before the web server starts. The supervisor never resends a request.
- If a worker dies during an upload, game action or other write, the response can
  be lost after data was committed. Recovery does not establish whether that
  operation succeeded. Check the saved result before retrying; this release does
  not claim general exactly-once writes or resumable uploads.
- Process handles, PID/generation events and bounded cleanup are used to keep
  ownership explicit. Exited render-worker handles are retired safely. Cleanup
  targets only processes created by this supervisor, not every process with the
  same executable name. The single-instance mutex is released on normal cleanup.

## Ten automated release gates

The scoped score reaches 10/10 only after all of these gates pass for the final
source. This checklist does not predeclare results or certify every user device.

1. Installed application and launcher match the reviewed source, including build
   identity; installation uses a path containing spaces.
2. Both ordinary smoke starts and fault-injection launches use the real Windows
   PowerShell 5.1 executable used by the installed shortcut.
3. A real PHP probe confirms both inherited FastCGI settings are overridden, and
   at least 600 actual HTTP health requests retain the same PHP PID.
4. Killing only the verified owned PHP worker produces a replacement while the
   MariaDB and Caddy PIDs remain unchanged.
5. A legitimate long-running request leaves its living worker intact despite
   short concurrent probe timeouts.
6. A POST which writes before worker interruption is not automatically replayed.
   The fixture verifies one committed receipt, not merely a mocked request count.
7. A same-name unrelated process survives recovery and terminal cleanup; an
   unrelated listener on the required FastCGI port is refused without adoption.
8. Both repeated worker exits and failed process launches exhaust the finite
   restart budget, return a nonzero launcher status, release the mutex and remove
   owned listeners/processes. A later normal startup preserves configuration,
   database records and a media sentinel.
9. All registered Linux regression suites and Windows clean install, restart,
   package recovery, native runtime and bundled FFmpeg checks pass without
   disabling existing tests.
10. Post-merge certification and the exact-source deployment package pass; the
    published archive and source identity are independently verified.

The native fixture is `tests/homeserver_runtime_windows.ps1`. Its disposable PHP
probe is token-protected, loopback-only and removed in cleanup. Tests are not
shipped in the product installer. Failed-launch injection temporarily denies
execution of only the isolated CI installation's CGI executable and restores its
original access rules in cleanup; it never changes a user's installation or
directory/system permissions. Evidence contains assertions and process events,
not credentials, database dumps, media contents or private certificate keys.
The source contract supplements this native test; it cannot substitute for it.

## Deployment and retained limits

Install the matching Windows package after backing up the user-owned database
and storage. Application files may be replaced; user data is retained. The
educational/subdomain deployment ZIP contains the same shared application source;
it does not change the web host's process manager. Preserve `storage/config.php`,
uploaded media and the database, and run `upgrade.php` as administrator if the
installation has not yet applied the shared schema release.

- This is still one PHP CGI worker, not a concurrency or multiplayer capacity
  upgrade. Slow operations can queue other requests. Recovery is not failover or
  an assurance of uninterrupted gameplay.
- Abruptly killing the supervisor itself, Windows reboot recovery, forced power
  loss, service-manager supervision and full backup/rollback remain separate
  scenarios. Normal cleanup and tested worker faults are not equivalent to them.
- Physical Windows/iPhone/Android testing, trusted local HTTPS, LAN firewall
  behavior and internet-disconnected capture/upload/playback remain field gates.
  Optional WebRTC live preview has separate connectivity requirements.
- First-install seed/config publication crash recovery and arbitrary incompatible
  database changes retain Section D's documented limits.
- No online gameplay license check, public media upload, shared customer database,
  new billing policy or new public endpoint is introduced.
