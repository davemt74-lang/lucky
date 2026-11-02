# V5Q.30 — Multiplayer Spin the Wheel, Parties, Themes and Costumes

## Host workflow

1. From **Admin Studio**, open the three new main-sidebar sections: **Costumes**, **Themes**, **Parties**.
2. Create a costume and add individual pieces (ordered layers, category and notes). The saved order follows the costume into a game.
3. Create a theme and attach one or more costumes. Create a party with a theme plus guests/attendees, players and hosts. Guests stay on the party list but do not take wheel turns.
4. Connect and approve phones from **Devices** or the existing Game Host phone panel. A player's phone is optional.
5. Start Spin the Wheel from the desktop host. The setup dialog asks for the party/theme, number of players, optional names and costumes, and the number of hosts. Blank names become **Player 1**, **Host 1**, etc. Each player can optionally select an approved phone.
6. The first player spins. After a prize award, the server records the player and turn number, then advances to the next player. A bonus-trigger spin stays with the same player until its prize is awarded. Prize decisions remain associated with the winning player.
7. During the game, the host can refresh phone connections and reassign a phone to a player. Assigned phones can control the assigned turn; host desktop controls remain authoritative. Other player phones cannot spin out of turn.
8. On the prize proof screen, the host enables phone camera use and recording consent. A player-assigned phone can then offer a live preview and upload its photo/video/audio as a **Player camera · Name** source. No background permission prompt or automatic recording is introduced.

## State and data model

- New tables: `lucky_costumes`, `lucky_themes`, and `lucky_parties`. Party attendees are saved as a bounded JSON array with roles and costume references. Theme costume IDs reference existing library records.
- Active game `state_json.multiplayer` contains immutable snapshots of the selected party, theme, players, hosts, and each costume's ordered items. Phone pair IDs can be changed by host action, but are not sent to public TV/display viewers.
- Each new award has `playerId`, `playerName`, and `turnNumber`. The session has `turnHistory` to support scoreboard, narration, and downstream content cues. Public views omit claim IDs and internal phone pair IDs.
- Auto-created video highlight timelines prefix claim-scoped clip titles with player/turn information. Original media and manually edited timelines are not rewritten.
- Deleting a costume referenced by a current theme or party is blocked until the association is removed; already-started games always retain their snapshots.

## Installation and verification

- Back up the database and saved media. Upload the full merged deploy ZIP. Keep `storage/config.php` and uploaded media. Run `upgrade.php` once to add the new library tables.
- Automated CI includes pure multiplayer turn/privacy tests and a MariaDB CRUD, snapshot, phone-assignment and cameraman-labeling test. Keep the PR unmerged until every core, database, browser, encoding and Windows workflow is green.
- On the physical host, verify simultaneous paired phones, phone camera consent, full-session replay, and multi-person recording timelines. At most four phone live proof previews are currently supported simultaneously by the existing signaling path; more player phones can connect, but only a subset can stream at once.

## Follow-on capabilities

- Advanced per-player montage tracks, cue scheduling, automated costume-item reveal actions and guest self-service invitations can be built on the saved turn history and itemized costume snapshots, without redesigning the library or game state.
