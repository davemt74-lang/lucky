# Lucky Game Hub V5Q.27A — Phone controller navigation

The paired phone controller now uses a six-action sticky dock: Mic, Game, Arrows, Prize, Camera, More. Dashboard navigation remains available through More → Controls when game authorization allows it.

- **Mic** is the single listening button; its pressed state follows speech recognition, including browser permission failures.
- **Arrows** opens a modal remote: Up, Left, center Select, Right, Down. Each uses the existing authenticated controller command API, with no new privilege or command path.
- **More** fills the mobile viewport, with Controls as the first tab and Music as the second. On-host music and volume commands stay intact.
- The spinning-game stats move beneath Spin and the secondary action row to the bottom of the gameplay screen.
- Pairing, pending-prize/recording restrictions, movie preview, Escape-to-close, and narrow-phone layouts remain in place.

## Certification

Run PHP/JavaScript syntax checks and the existing Lucky suites, including `tests/v5q26d_phone_navigation_browser.py`. The browser regression verifies the six-button nav, D-pad dispatch, Select placement, tab switching, full-screen More, state gating and 320/360/390/768px overflow checks.
