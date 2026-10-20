# Lucky Game Hub — shared game module contract (V5Q.18)

## Adding Game 2

1. Add a class in `lib/modules/` implementing all methods of `GameModule`, then register it in `GameRegistry::readyModules()` once the new game page exists. Give it a unique lower-case `id()` and a ready `manifest()` with an internal relative PHP page path.
2. Implement `defaults()` and `validateSettings()` with complete independent game settings. The shared `GameFoundation::settings()` creates its settings row at first start, under a transaction, without a new game-specific migration. Existing settings are retained.
3. Implement `createSession($settings,$id)`: return `id`, matching `game`, `status=active`, `version=1`, and game-specific state. Implement `publicView()` as a safe, minimal view containing the session ID.
4. Implement `action(&$state,$verb)`. Each successful action increases `version` exactly once, returns a bounded lowercase `type`, and validates permitted state transitions. Avoid adding game-specific behavior to the shared API. Expose custom verbs through authenticated `game_action` requests with `id`, `verb`, and a unique `idempotencyKey`.
5. Implement pause, resume and end rules; the shared engine already handles Active / Paused / Saved / Finished, concurrency, audit and deletion. Game 2 can use its own mechanics, state and controls while reusing these platform services.
6. Give Game 2 its own gameplay page and phone control adapter. Spin the Wheel's current prize capture, acceptance and spin-specific logic must not be copied into unrelated games. A shared phone controller routing API needs separate certification before Game 2 is publicly playable.

## Lifecycle invariants

- Only one active/paused session per game. Multiple saved and finished sessions may exist independently.
- Active/Paused → Saved, Saved → Active or Finished, Active/Paused → Finished, Finished → immutable.
- Saving, resuming, finishing, deleting and executing actions are authenticated, CSRF-protected, idempotency-keyed where applicable, and logged.
- Use the game ID from the persisted session state, never assume `spin-wheel`.
- Session data, proof recordings and exports remain on the local HomeServer database and storage volume.
- Test game state, version increments, invalid transitions, duplicate commands, simultaneous starts, restart recovery, and controller reconnection before marking Game 2 ready.

## Scope

This section delivers the *foundation*, not the mechanics or a playable Game 2. `game-2` remains marked upcoming until its module, UI and device adapters are complete.
