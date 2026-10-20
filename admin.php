<?php
declare(strict_types=1);
require __DIR__.'/lib/Layout.php';if(!installed()){header('Location: install.php');exit;}
if(!isAdmin()){header('Location: login.php');exit;}
shellStart('Admin Studio','admin','admin-studio-page');
?><div class="admin-console">
<aside class="admin-console-sidebar" id="admin-console-sidebar" aria-label="Admin navigation">
 <div class="admin-console-brand"><span class="mini-label">LUCKY GAMES</span><h2>Control Center</h2><p>Manage your home gaming platform.</p></div>
 <nav class="platform-admin-tabs" aria-label="Admin Studio sections">
  <button type="button" data-tab="games"><span class="admin-nav-symbol" aria-hidden="true">▦</span> Game catalog</button>
  <button type="button" data-tab="prizes" class="selected" aria-current="page"><span class="admin-nav-symbol" aria-hidden="true">✦</span> Prizes & budgets</button>
  <button type="button" data-tab="intro"><span class="admin-nav-symbol" aria-hidden="true">◈</span> Intro & theme</button>
  <button type="button" data-tab="music"><span class="admin-nav-symbol" aria-hidden="true">♫</span> Music library</button>
  <button type="button" data-tab="sessions"><span class="admin-nav-symbol" aria-hidden="true">▣</span> Game sessions</button>
  <button type="button" data-tab="history"><span class="admin-nav-symbol" aria-hidden="true">◷</span> Finished archive</button>
  <button type="button" data-tab="migration"><span class="admin-nav-symbol" aria-hidden="true">⇩</span> Import V5</button>
 </nav>
 <div class="admin-console-sidebar-footer"><a href="index.php">← Games library</a><a href="game.php">▶ Host the game</a><a href="devices.php">◉ Devices</a></div>
</aside>
<div class="admin-console-workspace">
 <div class="admin-mobile-nav"><button type="button" id="admin-nav-toggle" class="btn btn-outline" aria-expanded="false" aria-controls="admin-console-sidebar">☰ Sections</button></div>
 <div class="platform-admin-layout">
<section id="tab-games" class="surface platform-admin-panel" aria-label="Game catalog" hidden>
 <div class="card-title-row"><div><p class="mini-label">LOCAL GAME LIBRARY</p><h2>Game catalog</h2></div><button id="catalog-new-game" type="button" class="btn btn-outline">+ Create draft listing</button></div>
 <p>Manage each game's artwork, publisher credits, supported players and controller capabilities. Draft listings are not playable until a compatible game module has been installed and approved. Game metadata and uploaded art live in persistent local storage, surviving HomeServer application upgrades.</p>
 <div class="game-catalog-workspace">
 <div class="game-catalog-list" id="catalog-game-list" aria-label="Installed games"><p>Loading games…</p></div>
 <form id="catalog-editor" class="platform-form game-catalog-editor" hidden>
  <h3 id="catalog-editor-heading">Game details</h3><p id="catalog-execution-status" class="mini-label"></p>
  <div class="game-catalog-fields">
   <label>Game ID<input id="catalog-id" readonly></label>
   <label>Game title<input id="catalog-title" maxlength="120" required></label>
   <label>Tagline<input id="catalog-tagline" maxlength="180"></label>
   <label>Publisher<input id="catalog-publisher" maxlength="160"></label>
   <label>Publisher website<input id="catalog-publisher-website" type="url" placeholder="https://..." maxlength="2048"></label>
   <label>Category<input id="catalog-category" maxlength="80"></label>
   <label>Release version<input id="catalog-version" maxlength="40"></label>
   <label>Age rating<input id="catalog-age" maxlength="40" placeholder="Everyone"></label>
   <label>Minimum players<input id="catalog-min" type="number" min="1" max="64"></label>
   <label>Maximum players<input id="catalog-max" type="number" min="1" max="64"></label>
   <label>Phone controller<select id="catalog-controller"><option value="custom">Game-specific controller</option><option value="none">No phone controller</option></select></label>
   <label class="platform-checkbox"><input id="catalog-enabled" type="checkbox"> Enabled in catalog</label>
  </div>
  <label>Game description<textarea id="catalog-description" rows="4" maxlength="3000"></textarea></label>
  <div class="game-catalog-art">
   <div><h4>Cover image</h4><img id="catalog-cover-preview" alt="Game cover preview" hidden><input type="file" accept="image/jpeg,image/png,image/webp" data-game-art="cover"></div>
   <div><h4>Hero / banner image</h4><img id="catalog-hero-preview" alt="Game banner preview" hidden><input type="file" accept="image/jpeg,image/png,image/webp" data-game-art="hero"></div>
   <div><h4>Game logo</h4><img id="catalog-logo-preview" alt="Game logo preview" hidden><input type="file" accept="image/jpeg,image/png,image/webp" data-game-art="logo"></div>
  </div>
  <h4>Game gallery</h4><div id="catalog-gallery" class="game-catalog-gallery"></div>
  <label>Add gallery image (up to 8)<input type="file" accept="image/jpeg,image/png,image/webp" data-game-art="gallery"></label>
  <div class="platform-control-row"><button class="btn btn-primary" type="submit">Save game profile</button><span id="catalog-save-status" class="mini-label" role="status" aria-live="polite"></span></div>
 </form>
 </div>

 <section class="game-package-installer" aria-label="Install game packages">
  <div class="card-title-row"><div><p class="mini-label">MODULAR GAMES · LOCAL STORAGE</p><h3>Game package library</h3></div><button id="game-package-refresh" class="btn btn-outline" type="button">Refresh packages</button></div>
  <p>Upload a versioned Lucky game ZIP without rebuilding the HomeServer. The game and its optional custom phone controller are inspected and stored independently. Uploaded packages are <strong>staged, not playable</strong> until the separate sandboxed game runtime is certified. Existing game versions and sessions remain unchanged.</p>
  <form id="game-package-upload" class="platform-form">
   <label>Lucky game package ZIP (up to 40 MB)<input type="file" name="package" accept=".zip,application/zip" required></label>
   <button class="btn btn-primary" type="submit">Upload and validate game ZIP</button>
  </form>
  <p id="game-package-status" class="voice-status" role="status" aria-live="polite"></p>
  <div id="game-package-list" class="game-update-releases" aria-live="polite"></div>
  <p class="mini-label">Built-in Spin the Wheel is permanent. Only verified publisher-signed updates can be staged for built-in games; no upload can delete or overwrite its installed engine.</p>
 </section>
 <section class="game-update-connection" aria-label="Cloud game updates">
  <div class="card-title-row"><div><p class="mini-label">OPTIONAL · OFFLINE BY DEFAULT</p><h3>Cloud game updates</h3></div><span id="game-update-connection-status" class="mini-label">Not connected</span></div>
  <p>Connect to a publisher's signed HTTPS update feed to check for new game releases. HomeServer gameplay, player data, recordings and database stay local. The connection never runs during startup or gameplay.</p>
  <form id="game-update-connect-form" class="platform-form">
   <label>Publisher update feed (HTTPS)<input id="game-update-url" type="url" maxlength="2048" placeholder="https://publisher.example/game-updates.json" required></label>
   <label>Publisher verification public key (PEM)<textarea id="game-update-public-key" rows="4" required placeholder="-----BEGIN PUBLIC KEY-----"></textarea></label>
   <label>Optional cloud access token <input id="game-update-token" type="password" autocomplete="new-password" maxlength="1024" placeholder="Leave blank for public feeds"></label>
   <div class="platform-control-row">
    <button class="btn btn-primary" type="submit">Connect update channel</button>
    <button class="btn btn-outline" type="button" id="game-update-check">Check for updates</button>
    <button class="btn btn-outline" type="button" id="game-update-disconnect">Disconnect</button>
   </div>
  </form>
  <p id="game-update-message" class="voice-status" role="status" aria-live="polite"></p>
  <div id="game-update-releases" class="game-update-releases" aria-live="polite"></div>
  <p class="mini-label">Update checks are manual. You may explicitly download and stage publisher-verified ZIPs. Automatic downloading, runtime activation and code execution remain disabled until separately certified.</p>
 </section>
</section>
<section id="tab-prizes" class="surface platform-admin-panel"><div class="card-title-row"><div><p class="mini-label">GAME 1 · SERVER PRIZES</p><h2>Prize packages</h2></div><button id="save-prizes" type="button" class="btn btn-primary">Save package changes</button></div><p>Use + to add a Draft package. Save its tiers, odds and awards, then Publish it to make it eligible for the next game. Only Published and enabled packages can be selected. Games already started retain their original prizes.</p>
<div class="prize-package-header"><div id="prize-package-tabs" class="prize-package-tabs" role="tablist" aria-label="Prize packages"></div><button type="button" id="add-package" class="btn btn-primary prize-package-add" aria-label="Create prize package" title="New prize package">+</button></div>
<div class="prize-package-config"><label>Package name<input id="package-name" maxlength="100" aria-label="Selected package name"></label><label class="platform-checkbox"><input id="package-enabled" type="checkbox" checked> Enabled</label><label class="platform-checkbox"><input id="package-player-choice" type="checkbox" checked> Players can select</label><span id="package-publication-status" class="package-publication-status" role="status">Published</span><button id="package-publish" type="button" class="btn btn-outline">Move to Draft</button><button id="package-activate" type="button" class="btn btn-outline">Use for next game</button><button id="package-remove" type="button" class="btn btn-outline">Delete package</button><span id="package-selection-status" class="mini-label"></span></div>
<div class="platform-tier-tabs"><button class="btn btn-outline selected" data-tier="tier1">Tier 1</button><button class="btn btn-outline" data-tier="tier2">Tier 2</button><button class="btn btn-outline" data-tier="bonus">Bonus</button></div>
<div class="platform-control-row"><label>Tier budget ($)<input id="tier-budget" type="number" min="0" step="0.01" value="300"></label><button class="btn btn-outline" type="button" id="auto-split">Auto split unclaimed prizes</button></div><div id="prize-items" class="platform-prize-items"></div><form id="add-prize" class="platform-form-inline"><label>Prize name<input name="name" required maxlength="80" placeholder="New prize"></label><label>Value ($)<input name="value" type="number" value="0" min="0" step="0.01"></label><label>Optional URL<input name="url" type="url" placeholder="https://..."></label><label>Optional prize video URL<input name="videoUrl" type="url" placeholder="https://.../clip.mp4 or YouTube"></label><label>Optional image URL<input name="image" type="url" placeholder="https://..."></label><label>Or upload photo<input name="imagefile" type="file" accept="image/png,image/jpeg,image/webp"></label><button class="btn btn-teal" type="submit">+ Add Prize</button></form>
<hr><div class="platform-odds"><label>Surprise Bonus chance (0–100%)<input type="number" id="odds-bonusChance" min="0" max="100" step="1" value="12"></label><label>Tier 1 relative weight<input type="number" id="odds-tier1Weight" min="0" max="10" step="0.1" value="1"></label><label>Tier 2 relative weight<input type="number" id="odds-tier2Weight" min="0" max="10" step="0.1" value="1"></label><label>Bonus relative weight<input type="number" id="odds-bonusWeight" min="0" max="10" step="0.1" value="1"></label></div><p class="voice-privacy">The chance affects surprise bonus triggers; weights affect eligible prizes. Actual winners are selected on the server during each spin.</p></section>
<section id="tab-intro" class="surface platform-admin-panel" hidden><div class="card-title-row"><h2>Per-game splash & intro</h2><button id="save-intro" class="btn btn-primary">Save intro</button></div><div class="platform-admin-form"><label>Game<select id="intro-game"><option value="spin-wheel">Spin the Wheel</option><option value="game-2">Game 2 — reserved</option></select></label><label>Intro title<input id="intro-title" maxlength="100"></label><label>Subtitle<input id="intro-subtitle" maxlength="200"></label><label>Intro song<select id="intro-track"><option value="">No intro music</option></select></label><label>Intro volume <input id="intro-volume" type="range" min="0" max="100" value="70"></label><label class="platform-checkbox"><input type="checkbox" id="intro-announcement" checked> Spoken welcome</label><label>Accent color<input type="color" id="theme-accent" value="#f6cb74"></label><label>Background color<input type="color" id="theme-background" value="#0d1423"></label></div><a class="btn btn-outline" href="intro.php?game=spin-wheel" target="_blank" rel="noopener">Preview splash ↗</a></section>
<section id="tab-music" class="surface platform-admin-panel" hidden><h2>Shared server music library</h2><p>Tracks can be played from the host device or audience display. Upload an authorized song or add its direct audio URL.</p><div class="platform-library-grid"><form id="music-upload" class="platform-form"><h3>Upload audio</h3><label>Song title<input name="title" required maxlength="160"></label><label>Audio file (up to 20MB)<input name="track" type="file" accept="audio/*,.mp3,.m4a,.wav,.ogg,.webm" required></label><button class="btn btn-teal" type="submit">Upload track</button></form><form id="music-add-url" class="platform-form"><h3>Link a hosted track</h3><label>Song title<input name="title" required maxlength="160"></label><label>Direct audio URL<input name="url" type="url" required></label><button class="btn btn-outline" type="submit">Add URL</button></form></div><div id="music-items" class="platform-claim-list"></div></section>
<section id="tab-sessions" class="surface platform-admin-panel" hidden>
 <div class="card-title-row"><div><p class="mini-label">SHARED GAME ENGINE</p><h2>Game sessions</h2></div><button type="button" id="refresh-sessions" class="btn btn-outline">↻ Refresh sessions</button></div>
 <p>Each game has one live session at a time. Save one to continue later, or finish it permanently. Saved and finished sessions remain in the game history. This works for every registered game.</p>
 <div class="admin-session-toolbar"><label>Game <select id="session-game-filter"><option value="">All games</option></select></label><button type="button" class="btn btn-primary" id="start-selected-game">+ Start game</button></div>
 <div id="admin-session-list" class="admin-session-list" aria-live="polite"><p>Loading game sessions…</p></div>
 </section>
<section id="tab-history" class="surface platform-admin-panel" hidden><h2>Finished-game archive</h2><p>Completed games are preserved with their audit records, prizes, and session timestamps.</p><button class="btn btn-outline" id="refresh-history">↻ Refresh</button><div id="history-items" class="platform-claim-list"></div><button class="btn btn-outline" id="export-history">Export history CSV</button></section>
<section id="tab-migration" class="surface platform-admin-panel" hidden><h2>Import a V5 browser configuration</h2><p>The older game stored prizes and music in one browser. You can import its Tier 1, Tier 2, and Bonus prize definitions here. This does not erase the old browser copy. Existing claimed prizes are imported into a separate completed historical session, never into an active game. Duplicate imports of the same game are blocked.</p><p id="legacy-status" class="voice-status">Checking this browser…</p><button id="import-legacy" class="btn btn-outline">Import prizes and archived claims</button></section>
</div><p id="admin-status" class="voice-status" role="status" aria-live="polite"></p></div></div>
<script src="public/client.js" defer></script><script src="public/admin.js?v=5q22" defer></script><script src="public/game-catalog.js?v=5q22f" defer></script><?php shellEnd(); ?>
