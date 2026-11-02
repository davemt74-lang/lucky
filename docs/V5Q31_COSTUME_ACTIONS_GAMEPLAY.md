# V5Q31 — Costume Actions, Prize Attachments, Game Moments

## Host setup

1. Run `upgrade.php` from the host account once. The idempotent `v5q31_costume_actions.sql` migration adds the master costume action library. No existing costume, party, prize, or recording data is removed.
2. Under **Costumes** in the main admin sidebar, add/edit master costume actions. `Equip` and `Remove` are built-in; custom actions have names and optional descriptions. They are event cues and never automatically change an item's equipped status.
3. Add/edit a costume, add itemized ordered layers, and check **multiple** actions on each item. Save the costume. Each item keeps a stable ID, so prize links survive reordering and renaming.
4. Under **Prizes**, edit an individual prize in a collection and optionally select a linked costume item. Save the collection/package. Missing/deleted links are rejected at save, publish, and new game setup.
5. Start Spin the Wheel with player and host costumes, optional phones, party and theme as before. These settings are snapshotted per session.

## Gameplay and video

- During a game, the Game menu shows each player and host with their costume items and only the actions selected for those items. Each action is an explicit host event with an optional note.
- A winning prize's linked costume item is added to that winner's **prize items** list, initially not equipped. It is not silently dressed onto the player. The item's actions come from the version saved at game start.
- Equip and Remove update the item status. Other actions (for example “Reveal visor” or “Pose for camera”) create timestamped moments without changing equipment.
- Events are associated with the participant, item, layer, game turn and, where applicable, winning claim. They use an idempotency key and optimistic game version to avoid duplicated/stale mutations.
- The existing prize-video editor's **Game moments** pane lists prize wins and costume actions. The editor can add a caption to an existing matching claim clip; it does not pretend an event time is an exact video frame offset. Review and save the edit manually before MP4 export.
- Player phones remain optional and may act as host-approved prize camera sources through the existing camera director and recording consent flow. Costume actions do **not** start a camera or microphone.

## Safe lifecycle

- Deleting a master action used by a costume item is blocked. Remove it from those items first.
- Deleting costumes linked by themes, parties or prize packages is blocked. Deleting an item referenced by a prize package is also blocked until the prize link is removed.
- Previously started sessions retain their selected actions and item/claim snapshots after library edits.
- Viewer/player responses never expose phone pair tokens, action retry keys, or internal claim references from the roster.

## Certification

CI runs PHP syntax and JS checks, existing multiplayer/recording regressions, new `v5q31_costume_actions.php` engine checks, and `v5q31_costume_actions_db.php` against MariaDB. A complete V5Q31 deployment ZIP is built from tracked production files only after merge to `main`.
