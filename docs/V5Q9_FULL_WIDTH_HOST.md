# V5Q.9 · Full-Width Host and Winnings Summary

## UI
- `game.php` uses a dedicated `game-fullwidth` body class; only the host page widens the site shell and header to the available viewport.
- The four action links above the wheel have been removed, leaving **Game**, **Music**, and **Connected phones** tabs. Prize settings and Video Editor remain accessible from the existing Game Menu (Escape / phone Menu), along with the global site navigation.
- The claim ledger remains in the right column on larger displays. Below 1050px it moves below the game workspace to preserve usable wheel size.

## Winnings summary
The claim sidebar displays `X claimed · $Y awarded · $Z available to win`. **Z** is the sum of values (`cents > 0`) of configured unclaimed prizes in Tier 1, Tier 2, and Bonus for this session, excluding already claimed `tier:prizeId` pairs. A prize can be in a later, not-yet-promoted tier and still be included in the remaining configuration; unused budget unattached to any prize is excluded. The figure is the *potential remaining pool*, not a guaranteed cash balance or a promise of winning all prizes. Live session sync updates the value.

## Validation
- 24/24 new Chromium checks at 375px / 768px / 1280px / 1920px for full-width display, tab and menu remote control, no excess quick links, and correct monetary totals.
- Existing editor, capture, proof, and host navigation suites kept as regressions.
- No database changes required in V5Q.9; preserve `storage` configuration and all media on upgrade.
