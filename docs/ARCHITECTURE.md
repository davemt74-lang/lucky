# V5N architecture

- **Game registry:** `lib/GameRegistry.php` handles new game modules; only `spin-wheel` is playable.
- **Shared server:** V5M `GameEngine.php` and SQL tables remain authoritative for prizes, awards and saved game sessions.
- **Home console input:** `public/console.js` normalizes keyboard/Gamepad API actions. Games can register additional handlers. Controllers are detected locally; unsupported hardware is not paired or authorized.
- **Catalog:** `lib/ProductCatalog.php`, `api/index.php?action=catalog`, `catalog_admin`, `catalog_save`, `catalog_delete` and `catalog_products` table. Admin mutations need a session and CSRF token. Public catalog only exposes available/coming-soon items. A secure external URL is required for available products.
- **Future hardware:** use isolated adapters with explicit user approval for new APIs. Neither Bluetooth nor WebUSB can be silently accessed by this app.
- **Future commerce:** add provider-hosted checkout/webhooks, purchases and inventory in a separate security-reviewed module. Do not make client-submitted order prices authoritative.
- **Migration:** existing V5M sites run `upgrade.php`; new installs include the table in schema.sql.
