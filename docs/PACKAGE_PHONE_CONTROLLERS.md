# V5Q.22H — Packaged Game-Specific Phone Controllers

A game ZIP may include **its own** `phone.html`, independently of its
`host.html`. The desktop uses the main HomeServer game engine for session
history, persistence, Pause/Resume/End, idempotency and controller approvals.

## Playing together

1. Activate the game in Admin → Game Catalog, then launch it from Games.
2. On the computer, select **Start game**.
3. If the game has a phone UI, the computer displays a QR pairing link.
4. Scan on the phone, enter a device name, and request connection.
5. The computer lists the pending phone; the host selects **Approve**.
6. The phone loads that game release's dedicated `phone.html` UI in an
   opaque-origin, script-limited iframe and receives live session state.
7. Game-specific button presses send bounded input events through the
   paired, rate-limited and replay-resistant HomeServer API.
8. The phone can Pause/Resume or End Game. End is explicitly confirmed,
   persists to the database, then the computer returns to the main dashboard.
   The phone closes gameplay and displays the dashboard link.

Each phone authorization is scoped to the owning host, an exact 128-bit
session ID and a read-only session viewer capability. The same existing
`controller_pairs` table and owner approval UI are reused; no new public
user account or cloud pairing service is needed.

The package controller cannot select Spin the Wheel prizes, change game odds,
execute uploaded PHP, access local files or issue arbitrary API requests.
A game without `phone.html` does not show a phone pairing panel.

## Revocation and recovery

Hosts can revoke a paired phone in the computer panel at any time. Subsequent
phone status and commands require a non-revoked, unexpired pairing token.
Commands are persisted with unique nonces and use the same authoritative
game session row, with concurrent state/version guards. Replaying a command
does not apply the action twice. When disconnected, the phone must request
host approval again. Neither the phone nor computer needs cloud access.

## Game author HTML

The host and phone use the same bounded message protocol:

```html
<button data-game-input="buzz" data-game-value="player-one">Buzz</button>
```

Uploaded HTML/JS runs only inside sandboxed frames. You can listen for
`{type:'lucky:state', state: ...}` from the trusted parent and submit a
`{type:'lucky:input', name:'buzz', value:'player-one'}` message to the parent.
The HomeServer decides whether the input is accepted, and both displays poll
the authoritative persisted state. Generic inputs are not a way for third
party packages to edit prizes, settings, administrative state or databases.

Future game-specific rule executors will require independent certification.
