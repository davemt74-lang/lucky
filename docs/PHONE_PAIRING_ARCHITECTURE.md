# Phone controller architecture

1. `api/controller.php?action=invite`: logged-in host with CSRF creates a five-minute, high-entropy pairing invitation; the secret is persisted only as a hash.
2. `controller.php`: mobile page opens via the invite QR, asks for a display name, calls `claim`, and receives its separate controller secret.
3. `api/controller.php?action=claim_display`: alternative TV QR, based on an existing active game viewer link; this creates a **pending** phone only, not an approved controller.
4. `api/controller.php?action=list/approve/revoke`: authenticated host reviews names, approves, or disconnects the phone.
5. `api/controller.php?action=status`: phone polls for approved/expired/revoked state using the bearer capability.
6. `api/controller.php?action=command`: bearer-authorized allowlisted commands, idempotency keys, rate checks and MySQL transactions; no direct game-engine writes.
7. `api/controller.php?action=poll`: logged-in host claims queued command rows transactionally and delivers only fresh commands. `public/game.js` maps them onto existing local UI operations, preserving the server's prize-award logic.
8. `display.php`: read-only TV renders its QR locally with `public/qr.js`, without handing out host credentials.

Two schema tables: `controller_pairs`, `controller_commands`; these are added idempotently by `upgrade.php` and included in `lib/schema.sql` for new installs.

## Trust model and limits

- Viewer links allow reading the game display and creating a *pending* phone request, never controlling a game without host approval.
- Admin's session cookie and CSRF are independent of phone's bearer token. Cross-origin JSON requests are rejected and normal browser CORS policy remains in effect.
- The authenticated host tab is the command receiver, and **must stay open**. Only a single host tab should receive remote commands at a time.
- A disconnect revokes the server token and deletes undelivered controller actions.
- The phone may lose its temporary pairing when its tab closes, depending on the browser; re-pair rather than persisting the secret indefinitely.
- A malicious actor who gains access to the server, host's admin account, or an approved phone token is outside the protection of simple pair approval; protect devices, passwords and HTTPS.
