# Lucky Game Hub V5Q.25C — Game Catalog tabs (local candidate)

Game Catalog has four accessible tabs under Admin Studio:

1. **Game profiles** — game listing, publisher credit, players, phone controller, cover, hero, logo, and gallery. **Create draft listing** belongs here only.
2. **Installed packages** — upload, verify, inspect installed/staged packages, recover damaged activations, activate/roll back versions.
3. **Publisher updates** — explicit per-game trust, HTTPS feed and signing keys, release checks and staged downloads.
4. **Legacy channel** — older shared signed publisher channel, manual updates and disconnection controls.

The four panels reuse the existing actions, form IDs, and authorization. No API, database, trust, activation, or game-session rules change. Switching tabs hides panels without removing or resetting unsaved values. Keyboard navigation supports Left/Right, Home and End; the selected tab and panel have ARIA relationships. The optional `?tab=games&catalogTab=packages` URL selects a subsection on page load. Tabs stay within the viewport on mobile. The original V5Q.25B prize-editor, camera and recording work remains included.

## Certification

Run `python tests/v5q25c_catalog_tabs_browser.py` against Chromium. It exercises tab visibility, form preservation, publisher and package actions, keyboard accessibility, and 375/768/1280 px widths.

Not production-certified. The V5Q.25B candidate and this incremental work still require the complete GitHub CI suite, real MariaDB concurrency tests, and Windows installer certification before merging/deployment.
