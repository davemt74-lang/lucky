<?php
declare(strict_types=1);
require __DIR__.'/lib/Layout.php';if(!installed()){header('Location: install.php');exit;}
if(!isAdmin()){header('Location: login.php');exit;}
shellStart('Admin Studio','admin','admin-studio-page');
?><link rel="stylesheet" href="public/recordings-library.css?v=hf8d0e"><div class="admin-console">
<aside class="admin-console-sidebar" id="admin-console-sidebar" aria-label="Admin navigation">
 <div class="admin-console-brand"><span class="mini-label">LUCKY GAMES</span><h2>Control Center</h2><p>Manage your home gaming platform.</p></div>
 <nav class="platform-admin-tabs" aria-label="Admin Studio sections">
  <button type="button" data-tab="games"><span class="admin-nav-symbol" aria-hidden="true">▦</span> Game catalog</button>
  <button type="button" data-tab="cameras"><span class="admin-nav-symbol" aria-hidden="true">▣</span> Cameras</button>
  <a href="director.php" class="admin-director-nav"><span class="admin-nav-symbol" aria-hidden="true">◉</span> Camera Director</a>
  <button type="button" data-tab="prizes" class="selected" aria-current="page"><span class="admin-nav-symbol" aria-hidden="true">✦</span> Prizes & budgets</button>
  <button type="button" data-tab="music"><span class="admin-nav-symbol" aria-hidden="true">♫</span> Music library</button>
  <button type="button" data-tab="sessions"><span class="admin-nav-symbol" aria-hidden="true">▣</span> Game sessions</button>
  <button type="button" data-tab="history"><span class="admin-nav-symbol" aria-hidden="true">◷</span> Finished archive</button>
  <button type="button" data-tab="videos"><span class="admin-nav-symbol" aria-hidden="true">▸</span> Video gallery</button>
  <button type="button" data-tab="recordings"><span class="admin-nav-symbol" aria-hidden="true">◉</span> Recordings</button>
  <button type="button" data-tab="ai"><span class="admin-nav-symbol" aria-hidden="true">✧</span> AI Studio</button>
  <button type="button" data-tab="migration"><span class="admin-nav-symbol" aria-hidden="true">⇩</span> Import V5</button>
 </nav>
 <div class="admin-console-sidebar-footer"><a href="index.php">← Games library</a><a href="game.php">▶ Host the game</a><a href="devices.php">◉ Devices</a></div>
</aside>
<div class="admin-console-workspace">
 <div class="admin-mobile-nav"><button type="button" id="admin-nav-toggle" class="btn btn-outline" aria-expanded="false" aria-controls="admin-console-sidebar">☰ Sections</button></div>
 <div class="platform-admin-layout">
<section id="tab-games" class="surface platform-admin-panel" aria-label="Game catalog" hidden>
 <div class="game-catalog-intro"><p class="mini-label">LOCAL GAME LIBRARY</p><h2>Game catalog</h2><p>Manage game artwork, installed modules, and optional publisher updates from one place.</p></div>
 <div id="game-catalog-tabs" class="game-catalog-tabs" role="tablist" aria-label="Game catalog sections">
  <button id="game-catalog-tab-profiles" type="button" role="tab" data-catalog-tab="profiles" aria-controls="game-catalog-pane-profiles" aria-selected="true" tabindex="0"><span aria-hidden="true">▦</span> Game profiles</button>
  <button id="game-catalog-tab-packages" type="button" role="tab" data-catalog-tab="packages" aria-controls="game-catalog-pane-packages" aria-selected="false" tabindex="-1"><span aria-hidden="true">▣</span> Installed packages</button>
  <button id="game-catalog-tab-publishers" type="button" role="tab" data-catalog-tab="publishers" aria-controls="game-catalog-pane-publishers" aria-selected="false" tabindex="-1"><span aria-hidden="true">◈</span> Publisher updates</button>
  <button id="game-catalog-tab-legacy" type="button" role="tab" data-catalog-tab="legacy" aria-controls="game-catalog-pane-legacy" aria-selected="false" tabindex="-1"><span aria-hidden="true">↻</span> Legacy channel</button>
 </div>
 <section id="game-catalog-pane-profiles" class="game-catalog-pane" role="tabpanel" aria-labelledby="game-catalog-tab-profiles" tabindex="0">
  <div class="game-catalog-pane-heading"><div><h3>Game profiles & artwork</h3><p>Edit the games players see, publisher credits, controller compatibility, and cover art.</p></div><button id="catalog-new-game" type="button" class="btn btn-outline">+ Create draft listing</button></div>
  <p class="game-catalog-help">Draft listings are not playable until a compatible game module is installed and approved. Artwork and settings persist across HomeServer upgrades.</p>
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
  <fieldset class="game-splash-settings" id="catalog-recording-settings">
   <legend>Game recording &amp; phone cameras</legend>
   <p class="game-catalog-help">These defaults apply to NEW recording groups linked to this game. Existing grouped recordings keep their saved rules. Individual sessions still require explicit phone approval.</p>
   <div class="game-catalog-fields">
    <label class="platform-checkbox"><input type="checkbox" id="catalog-record-multicam"> Enable multicamera</label>
    <label class="platform-checkbox"><input type="checkbox" id="catalog-record-phone"> Allow phone camera</label>
    <label>Maximum simultaneous cameras<input id="catalog-record-max" type="number" min="1" max="8" value="4"></label>
    <label>Countdown before host starts (seconds)<input id="catalog-record-countdown" type="number" min="0" max="30" value="3"></label>
    <label class="platform-checkbox"><input type="checkbox" id="catalog-record-video"> Video</label>
    <label class="platform-checkbox"><input type="checkbox" id="catalog-record-photo"> Photo</label>
    <label class="platform-checkbox"><input type="checkbox" id="catalog-record-audio"> Audio</label>
   </div>
  </fieldset>
  <div class="game-catalog-art">
   <div><h4>Cover image</h4><img id="catalog-cover-preview" alt="Game cover preview" hidden><input type="file" accept="image/jpeg,image/png,image/webp" data-game-art="cover"></div>
   <div><h4>Hero / banner image</h4><img id="catalog-hero-preview" alt="Game banner preview" hidden><input type="file" accept="image/jpeg,image/png,image/webp" data-game-art="hero"></div>
   <div><h4>Game logo</h4><img id="catalog-logo-preview" alt="Game logo preview" hidden><input type="file" accept="image/jpeg,image/png,image/webp" data-game-art="logo"></div>
  </div>
  <h4>Game gallery</h4><div id="catalog-gallery" class="game-catalog-gallery"></div>
  <label>Add gallery image (up to 8)<input type="file" accept="image/jpeg,image/png,image/webp" data-game-art="gallery"></label>
  <fieldset id="catalog-splash-settings" class="game-splash-settings">
   <legend>Game splash &amp; intro</legend>
   <p class="game-catalog-help">Configure the selected game's splash screen. Intro and theme settings are saved with the game profile.</p>
   <div class="game-catalog-fields">
    <label>Splash title<input id="intro-title" maxlength="100" required></label>
    <label>Subtitle<input id="intro-subtitle" maxlength="200"></label>
    <label>Intro song<select id="intro-track"><option value="">No song selected</option></select></label>
    <label>Intro volume<input id="intro-volume" type="range" min="0" max="100" value="70"></label>
    <label class="platform-checkbox"><input id="intro-announcement" type="checkbox" checked> Spoken welcome</label>
    <label>Accent color<input id="theme-accent" type="color" value="#f6cb74"></label>
    <label>Background color<input id="theme-background" type="color" value="#0d1423"></label>
   </div>
   <div class="game-splash-artwork">
    <div class="game-splash-preview-shell"><img id="catalog-splash-preview" alt="Uploaded game splash background" hidden><span id="catalog-splash-placeholder">No splash background uploaded</span></div>
    <div class="game-splash-upload-options"><strong>Splash background image</strong><p>Upload a JPG, PNG or WebP, up to 6 MB. Wide landscape images work best; the splash screen scales the image to fill the display.</p>
     <label class="btn btn-outline game-splash-upload">↥ Upload background image<input type="file" accept="image/jpeg,image/png,image/webp" data-game-art="splash" aria-label="Upload splash background image"></label>
     <a class="btn btn-outline" id="catalog-preview-splash" href="intro.php?game=spin-wheel" target="_blank" rel="noopener">Preview splash ↗</a>
    </div>
   </div>
  </fieldset>
  <div class="platform-control-row"><button class="btn btn-primary" type="submit">Save game profile</button><span id="catalog-save-status" class="mini-label" role="status" aria-live="polite"></span></div>
 </form>
 </div>

 </section><!-- end Game profiles tab -->
 <section id="game-catalog-pane-packages" class="game-catalog-pane" role="tabpanel" aria-labelledby="game-catalog-tab-packages" tabindex="0" hidden>

 <section class="game-package-installer" aria-label="Install game packages">
  <div class="card-title-row"><div><p class="mini-label">MODULAR GAMES · LOCAL STORAGE</p><h3>Game package library</h3></div><button id="game-package-refresh" class="btn btn-outline" type="button">Refresh packages</button></div>
  <p>Upload a versioned Lucky game ZIP without rebuilding the HomeServer. The game and its optional custom phone controller are inspected and stored independently. Uploaded packages are <strong>staged, not playable</strong> until the separate sandboxed game runtime is certified. Existing game versions and sessions remain unchanged.</p>
  <form id="game-package-upload" class="platform-form">
   <label>Lucky game package ZIP (up to 40 MB)<input type="file" name="package" accept=".zip,application/zip" required></label>
   <button class="btn btn-primary" type="submit">Upload and validate game ZIP</button>
  </form>
  <p id="game-package-status" class="voice-status" role="status" aria-live="polite"></p>
  <p id="game-package-health" role="alert" class="voice-status" hidden></p>
  <div id="game-package-list" class="game-update-releases" aria-live="polite"></div>
  <p class="mini-label">Built-in Spin the Wheel is permanent. Only verified publisher-signed updates can be staged for built-in games; no upload can delete or overwrite its installed engine.</p>
 </section>
 </section><!-- end Installed packages tab -->
 <section id="game-catalog-pane-publishers" class="game-catalog-pane" role="tabpanel" aria-labelledby="game-catalog-tab-publishers" tabindex="0" hidden>

 <section class="game-update-connection" aria-label="Per-game publisher update channels">
  <div class="card-title-row"><div><p class="mini-label">GAME CATALOG · VERIFIED PUBLISHERS</p><h3>Publisher updates by game</h3></div><span class="mini-label">Manual · Offline by default</span></div>
  <p>Each game can trust its own HTTPS publisher feed and signing key. Signed updates are checked only when you request them; downloads are staged, never activated automatically. Changing a trusted key or feed requires separate confirmation.</p>
  <form id="game-publisher-form" class="platform-form">
   <label>Game identifier
    <select id="game-publisher-game" required></select>
   </label>
   <label>Publisher name<input id="game-publisher-name" maxlength="160" required placeholder="Game studio or publisher"></label>
   <label>Publisher feed URL (HTTPS)<input id="game-publisher-url" type="url" maxlength="2048" required placeholder="https://publisher.example/updates.json"></label>
   <label>Trusted publisher public key (PEM)<textarea id="game-publisher-key" rows="4" required placeholder="-----BEGIN PUBLIC KEY-----"></textarea></label>
   <label>Optional private feed access token<input id="game-publisher-token" type="password" maxlength="1024" autocomplete="new-password" placeholder="Leave blank for a public feed"></label>
   <button class="btn btn-primary" type="submit">Save game publisher trust</button>
  </form>
  <p id="game-publisher-status" class="voice-status" role="status" aria-live="polite"></p>
  <div id="game-publisher-list" class="game-update-releases" aria-live="polite"></div>
  <div id="game-publisher-releases" class="game-update-releases" aria-live="polite"></div>
 </section>
 </section><!-- end Publisher updates tab -->
 <section id="game-catalog-pane-legacy" class="game-catalog-pane" role="tabpanel" aria-labelledby="game-catalog-tab-legacy" tabindex="0" hidden>

<section class="game-update-connection" aria-label="Cloud game updates">
  <div class="card-title-row"><div><p class="mini-label">OPTIONAL · OFFLINE BY DEFAULT</p><h3>Legacy shared publisher channel</h3></div><span id="game-update-connection-status" class="mini-label">Not connected</span></div>
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
 </section><!-- end Legacy channel tab -->
</section><!-- end Game Catalog section -->
<section id="tab-prizes" class="surface platform-admin-panel">
<div class="prize-package-header"><div id="prize-package-tabs" class="prize-package-tabs" role="tablist" aria-label="Prize packages"></div><div class="prize-package-toolbar"><button type="button" id="add-package" class="btn btn-outline prize-package-add">+ New package</button><button type="button" id="open-package-settings" class="btn btn-outline">⚙ Package settings</button><button id="save-prizes" type="button" class="btn btn-primary">Save package changes</button></div></div>
<div class="prize-package-summary"><div><span class="mini-label">SELECTED PACKAGE</span><strong id="prize-package-summary-name">Original Prizes</strong><p id="prize-package-summary-description">Manage tiers and edit prizes below. Package configuration is under Settings.</p></div><span id="prize-package-summary-state" class="prize-package-state">Published</span></div>
<dialog id="package-settings-modal" class="prize-workspace-dialog" aria-labelledby="package-settings-title"><div class="prize-dialog-shell"><header class="prize-dialog-header"><div><p class="mini-label">PACKAGE MANAGEMENT</p><h2 id="package-settings-title">Prize package settings</h2><p>Edit package artwork, budget, publication, and who may choose it.</p></div><button id="package-settings-close" type="button" class="prize-dialog-close" aria-label="Close package settings">✕</button></header>
<div class="prize-package-config"><label>Package name<input id="package-name" maxlength="100" aria-label="Selected package name"></label><label class="package-image-field">Package cover image<input id="package-image" type="url" placeholder="Optional image URL" aria-label="Prize package cover image"></label><label class="prize-upload-icon package-image-upload" title="Upload package cover image">📷<input id="package-image-upload" type="file" accept="image/jpeg,image/png,image/webp" aria-label="Upload prize package image"></label><label class="prize-package-budget-label">Package budget ($) <small>Optional · combined tier budget limit</small><input id="package-budget" type="number" min="0" max="10000000" step="0.01" placeholder="No limit" aria-label="Optional package-wide budget"></label><div class="prize-package-options" role="group" aria-label="Package options"><label class="platform-checkbox"><input id="package-enabled" type="checkbox" checked><span>Enabled</span></label><label class="platform-checkbox"><input id="package-player-choice" type="checkbox" checked><span>Players can select</span></label><label class="platform-checkbox"><input id="package-phone-camera" type="checkbox" checked><span>Enable phone camera during games</span></label></div><span id="package-publication-status" class="package-publication-status" role="status">Published</span><button id="package-publish" type="button" class="btn btn-outline">Move to Draft</button><button id="package-activate" type="button" class="btn btn-outline">Use for next game</button><button id="package-remove" type="button" class="btn btn-outline">Delete package</button><span id="package-selection-status" class="mini-label"></span></div>
<footer class="prize-dialog-footer"><button type="button" id="package-settings-cancel" class="btn btn-outline">Close</button><button type="button" id="package-settings-save" class="btn btn-primary">Save package settings</button></footer></div></dialog>
<div class="platform-tier-tabs"><button class="btn btn-outline selected" data-tier="tier1">Tier 1</button><button class="btn btn-outline" data-tier="tier2">Tier 2</button><button class="btn btn-outline" data-tier="bonus">Bonus</button></div>
<div class="platform-control-row"><label>Tier budget ($)<input id="tier-budget" type="number" min="0" step="0.01" value="300"></label><button class="btn btn-outline" type="button" id="auto-split">Auto split unclaimed prizes</button></div>
<div class="prize-gallery-header"><div><p class="mini-label">PRIZES IN THIS TIER</p><h3>Prize collection</h3><p>Each prize has its own artwork, announcement, media, and proof settings.</p></div><button id="add-prize-button" type="button" class="btn btn-teal">+ Add prize</button></div>
<div id="prize-items" class="platform-prize-items prize-card-gallery" aria-live="polite"></div>
<dialog id="prize-editor-modal" class="prize-workspace-dialog" aria-labelledby="prize-editor-heading"><div class="prize-dialog-shell"><header class="prize-dialog-header"><div><p class="mini-label">PRIZE EDITOR</p><h2 id="prize-editor-heading">Edit prize</h2><p>Edit the details players see and the words the agent reads aloud.</p></div><button id="prize-editor-close" type="button" class="prize-dialog-close" aria-label="Close prize editor">✕</button></header>
<form id="prize-editor-form" class="prize-edit-form" novalidate>
 <div class="prize-edit-grid"><label>Prize name<input id="prize-edit-name" maxlength="80" required></label><label>Value ($)<input id="prize-edit-value" type="number" min="0" step="0.01" required></label></div>
 <label>Prize URL (optional, separate from video)<input id="prize-edit-link" type="url" placeholder="https://..."></label>
 <div class="prize-edit-media-grid"><section class="prize-edit-media"><h3>Photo shown on the phone</h3><div id="prize-edit-photo-preview" class="prize-edit-media-preview"></div><div class="prize-edit-upload-actions"><label class="btn btn-outline prize-edit-upload prize-edit-upload-icon" title="Upload prize photo" aria-label="Upload prize photo">📷<input id="prize-edit-photo-upload" type="file" accept="image/jpeg,image/png,image/webp"></label><button id="prize-edit-photo-remove" type="button" class="btn btn-outline">Remove photo</button></div></section>
 <section class="prize-edit-media"><h3>Video played on the desktop</h3><div id="prize-edit-video-preview" class="prize-edit-media-preview"></div><div class="prize-edit-upload-actions"><label class="btn btn-outline prize-edit-upload prize-edit-upload-icon" title="Upload prize video" aria-label="Upload prize video">🎞<input id="prize-edit-video-upload" type="file" accept="video/mp4,video/webm"></label><button id="prize-edit-video-remove" type="button" class="btn btn-outline">Remove video</button></div><p class="prize-edit-hint">Players use Play Video on their phone. Uploaded clips open on the desktop display. No video URL is needed.</p></section></div>
 <label>Spoken prize description<textarea id="prize-edit-description" rows="5" maxlength="600" placeholder="Congratulations! You won {prize_name}, worth {prize_value}."></textarea></label><div id="prize-edit-tags" class="prize-smart-tags" aria-label="Insert a smart tag"><span class="mini-label">INSERT SMART TAG</span></div>
 <section class="prize-edit-proof"><div class="prize-edit-proof-heading"><div><h3>Proof recording angles</h3><p>Configure up to four camera views and how long each records.</p></div><button id="prize-edit-detect-cameras" type="button" class="btn btn-outline">Match cameras</button></div><div id="prize-edit-angles" class="prize-edit-angle-list"></div></section>
 <p id="prize-edit-status" role="status" aria-live="polite"></p><footer class="prize-dialog-footer"><button id="prize-edit-delete" type="button" class="btn btn-outline prize-delete-button">Delete prize</button><span class="spacer"></span><button id="prize-edit-cancel" type="button" class="btn btn-outline">Cancel</button><button id="prize-edit-save" type="submit" class="btn btn-primary">Save prize</button></footer>
 </form></div></dialog>
<hr><div class="platform-odds"><label>Surprise Bonus chance (0–100%)<input type="number" id="odds-bonusChance" min="0" max="100" step="1" value="12"></label><label>Tier 1 relative weight<input type="number" id="odds-tier1Weight" min="0" max="10" step="0.1" value="1"></label><label>Tier 2 relative weight<input type="number" id="odds-tier2Weight" min="0" max="10" step="0.1" value="1"></label><label>Bonus relative weight<input type="number" id="odds-bonusWeight" min="0" max="10" step="0.1" value="1"></label></div><p class="voice-privacy">The chance affects surprise bonus triggers; weights affect eligible prizes. Actual winners are selected on the server during each spin.</p></section>
<dialog id="prize-package-modal" class="prize-package-modal" aria-labelledby="prize-package-modal-title" aria-describedby="prize-package-modal-description">
 <form id="prize-package-modal-form" method="dialog" novalidate>
  <h3 id="prize-package-modal-title">Create prize package</h3>
  <p id="prize-package-modal-description"></p>
  <div id="prize-package-modal-fields" class="prize-package-modal-fields">
   <label>Package name<input id="prize-package-modal-name" maxlength="100" required autocomplete="off" placeholder="New prize package"></label>
   <label>Package budget ($) <small>Optional · leave blank for no limit</small><input id="prize-package-modal-budget" type="number" min="0" max="10000000" step="0.01" placeholder="No limit"></label>
   <label class="prize-package-create-camera"><input id="prize-package-modal-phone-camera" type="checkbox" checked><span><strong>Enable phone camera</strong><small>On by default. Paired phone controllers can capture video, photos, and audio while this game is active.</small></span></label>
  </div>
  <p id="prize-package-modal-error" role="alert" hidden></p>
  <div class="prize-package-modal-actions"><button id="prize-package-modal-cancel" type="button" class="btn btn-outline">Cancel</button><button id="prize-package-modal-submit" type="submit" class="btn btn-primary">Create package</button></div>
 </form>
</dialog>
<section id="tab-cameras" class="surface platform-admin-panel global-camera-admin" hidden aria-label="Shared camera setup">
 <div class="global-camera-header"><div><p class="mini-label">ALL INSTALLED GAMES</p><h2>Cameras</h2><p>Set up the cameras this computer uses by default for every installed game. In-game camera setup stays available to add or exclude cameras for an individual game.</p></div>
 <button id="global-camera-detect" class="btn btn-primary" type="button">📷 Detect Cameras</button></div>
 <div class="global-camera-toolbar"><span id="global-camera-count" class="mini-label">0 / 4 shared</span><button id="global-camera-save" class="btn btn-outline" type="button">Save shared setup</button></div>
 <p id="global-camera-status" role="status" aria-live="polite" class="global-camera-status">Camera setup has not been loaded.</p>
 <div id="global-camera-list" class="global-camera-grid" aria-label="Detected cameras on this computer"></div>
 <p class="console-footnote">Camera permissions and device IDs are specific to this browser, computer and site address. Streams never leave this device. Open this Admin section on the game-host computer to configure shared cameras. Other computers need their own camera setup.</p>
</section>
<section id="tab-music" class="surface platform-admin-panel" hidden><h2>Shared server music library</h2><p>Tracks can be played from the host device or audience display. Upload an authorized song or add its direct audio URL.</p><div class="platform-library-grid"><form id="music-upload" class="platform-form"><h3>Upload audio</h3><label>Song title<input name="title" required maxlength="160"></label><label>Audio file (up to 20MB)<input name="track" type="file" accept="audio/*,.mp3,.m4a,.wav,.ogg,.webm" required></label><button class="btn btn-teal" type="submit">Upload track</button></form><form id="music-add-url" class="platform-form"><h3>Link a hosted track</h3><label>Song title<input name="title" required maxlength="160"></label><label>Direct audio URL<input name="url" type="url" required></label><button class="btn btn-outline" type="submit">Add URL</button></form></div><div id="music-items" class="platform-claim-list"></div></section>
<section id="tab-sessions" class="surface platform-admin-panel" hidden>
 <div class="card-title-row"><div><p class="mini-label">SHARED GAME ENGINE</p><h2>Game sessions</h2></div><button type="button" id="refresh-sessions" class="btn btn-outline">↻ Refresh sessions</button></div>
 <p>Each game has one live session at a time. Save one to continue later, or finish it permanently. Saved and finished sessions remain in the game history. This works for every registered game.</p>
 <div class="admin-session-toolbar"><label>Game <select id="session-game-filter"><option value="">All games</option></select></label><button type="button" class="btn btn-primary" id="start-selected-game">+ Start game</button></div>
 <div id="admin-session-list" class="admin-session-list" aria-live="polite"><p>Loading game sessions…</p></div>
 </section>
<section id="tab-recordings" class="surface platform-admin-panel" hidden aria-label="Recording session studio">
 <div class="card-title-row"><div><p class="mini-label">MULTICAMERA · PHONE CAPTURE</p><h2>Recording Sessions</h2><p>Browse your recording library, open a session, or create a new production.</p></div><div class="recordings-header-actions"><button type="button" id="recording-refresh" class="btn btn-outline">↻ Refresh</button><button type="button" id="recording-new-open" class="btn btn-primary">＋ New recording</button></div></div>
 <dialog id="recording-new-dialog" class="recording-new-dialog" aria-labelledby="recording-new-title"><div class="recording-new-modal-head"><div><p class="mini-label">NEW RECORDING SESSION</p><h2 id="recording-new-title">Set up a recording</h2></div><button type="button" id="recording-new-close" class="btn btn-outline" aria-label="Close new recording form">✕ Close</button></div><p>Choose the type, capture permissions and camera defaults. Your new session will appear in the library.</p>
 <form id="recording-create" class="platform-form"><div class="recordings-new-grid"><label>Recording name<input id="recording-title" maxlength="160" placeholder="Birthday party, game night, scene 3…" required></label><label>Recording type<select id="recording-type"><option value="live_event">Live event</option><option value="production">Content campaign / planned shoot</option><option value="general">Custom recording</option><option value="game">Game proof / recording</option></select></label><label id="recording-game-wrap" hidden>Game session ID<input id="recording-game-id" maxlength="32" pattern="[a-f0-9]{32}" placeholder="32-character game session ID"></label></div><label>Description (optional)<textarea id="recording-description" maxlength="1800" rows="2"></textarea></label>
  <div class="recordings-new-grid"><label>Event date (optional)<input id="recording-event-date" type="date"></label><label>Location / event venue<input id="recording-location" maxlength="120" placeholder="Living room, stage, park…"></label><label>Search tags (comma separated)<input id="recording-tags" maxlength="380" placeholder="birthday, group photo, behind the scenes"></label></div>
  <label>Production brief / camera instructions<textarea id="recording-brief" maxlength="1200" rows="2" placeholder="What should the cameras capture?"></textarea></label><label>Shot list (one per line, up to 16)<textarea id="recording-shots" maxlength="2700" rows="2" placeholder="Wide establishing shot&#10;Close-up of the prize"></textarea></label>
  <fieldset class="game-splash-settings" id="recording-create-capture"><legend>Capture defaults</legend><p class="game-catalog-help" id="recording-game-policy-note" hidden>Game recordings use the saved game catalog camera policy, not these defaults.</p><div class="recordings-new-grid"><label class="platform-checkbox"><input type="checkbox" id="recording-multicam" checked> Multicamera</label><label class="platform-checkbox"><input type="checkbox" id="recording-phone" checked> Allow phone capture</label><label>Maximum cameras<input id="recording-max" type="number" min="1" max="8" value="4"></label><label>Countdown (seconds)<input id="recording-countdown" type="number" min="0" max="30" value="3"></label><label class="platform-checkbox"><input type="checkbox" id="recording-video" checked> Video</label><label class="platform-checkbox"><input type="checkbox" id="recording-photo" checked> Photo</label><label class="platform-checkbox"><input type="checkbox" id="recording-audio" checked> Audio</label></div></fieldset>
  <div class="recordings-modal-actions"><button type="button" id="recording-new-cancel" class="btn btn-outline">Cancel</button><button type="submit" class="btn btn-primary">＋ Create recording</button></div></form></dialog>
 <div class="recordings-new-grid recordings-filters"><label>Find recordings<input id="recording-search" type="search" placeholder="Name, tags, venue, game…"></label><label>Recording type<select id="recording-filter-type"><option value="">All types</option><option value="game">Game proof</option><option value="live_event">Live events</option><option value="production">Campaigns</option><option value="general">Custom</option></select></label><label>Session status<select id="recording-filter-status"><option value="">All statuses</option><option value="active">Active</option><option value="draft">Draft</option><option value="completed">Completed</option><option value="archived">Archived</option></select></label></div>
 <div class="recordings-workspace"><div><div class="recording-library-heading"><h3>Recording library</h3><span id="recording-library-count" aria-live="polite">Loading…</span></div><div id="recording-session-list" class="recordings-list">Loading recordings…</div></div><div id="recording-workspace" class="recording-workspace" hidden><h3 id="recording-selected-title">Choose a recording</h3><p id="recording-selected-details"></p><div class="recordings-actions"><button type="button" id="recording-activate" class="btn btn-primary">▶ Activate session</button><button type="button" id="recording-finish" class="btn btn-outline">✓ Complete session</button><label class="platform-checkbox"><input id="recording-phone-enabled" type="checkbox"> Allow approved phones to capture</label></div><p class="mini-label">Each approved phone opens Camera Studio, grants consent, then arms itself. Start/stop a take here to signal every armed phone. Polling coordinates cameras approximately; frame alignment remains an editor task.</p><div class="recordings-takes"><h4>Armed camera angles <button type="button" id="recording-refresh-devices" class="btn btn-outline">↻</button></h4><div id="recording-armed-devices" class="recording-camera-devices" aria-live="polite">No phones armed.</div><h4>Grouped takes</h4><div class="recordings-actions"><input id="recording-take-name" maxlength="120" aria-label="Take title" placeholder="Take 1 / scene description"><button id="recording-take-start" type="button" class="btn btn-outline">● Start multicamera take</button></div><div id="recording-takes"></div></div><h4>Captured media</h4><div id="recording-media" class="recording-media-list">No uploads yet.</div></div></div>
 <p id="recording-admin-status" class="voice-status" role="status" aria-live="polite"></p>
</section>
<section id="tab-videos" class="surface platform-admin-panel video-gallery-admin" hidden aria-label="Finished video gallery">
 <div class="card-title-row"><div><p class="mini-label">ALL INSTALLED GAMES</p><h2>Video gallery</h2><p>Finished MP4 movies are saved here by game session. Only administrators can queue, watch, or download exports.</p></div><button id="video-gallery-refresh" class="btn btn-outline" type="button">↻ Refresh videos</button></div>
 <div class="video-gallery-tabs" role="group" aria-label="Filter movie exports"><button class="selected" type="button" data-video-filter="ready" aria-pressed="true">Finished movies</button><button type="button" data-video-filter="processing" aria-pressed="false">Processing</button><button type="button" data-video-filter="failed" aria-pressed="false">Failed / cancelled</button><button type="button" data-video-filter="all" aria-pressed="false">All exports</button></div>
 <p id="video-gallery-status" class="voice-status" role="status" aria-live="polite">Select Refresh videos to load exported movies.</p>
 <div id="video-gallery-list" class="video-gallery-grid" aria-live="polite"></div>
 <p class="console-footnote">Only finished, locally stored MP4 files are playable. Pending exports require the server's background render worker; queueing an export does not immediately create a movie.</p>
 </section>
 <dialog id="video-gallery-player" class="lucky-video-gallery-dialog" aria-label="Watch exported game movie"><div class="video-gallery-player-shell"><div class="video-gallery-player-head"><strong id="video-gallery-player-title">Finished movie</strong><button id="video-gallery-player-close" type="button" class="btn btn-outline">✕ Close</button></div><video id="video-gallery-player-video" controls playsinline preload="metadata"></video></div></dialog>
<section id="tab-ai" class="surface platform-admin-panel" hidden aria-label="AI Studio and Claude settings">
 <div class="card-title-row"><div><p class="mini-label">OPTIONAL · ADMIN ONLY</p><h2>AI Studio · Claude</h2><p>Connect Claude to Video Studio's non-destructive editing assistant. Keys stay on this server and are never sent to the browser after saving.</p></div><span id="video-ai-state" class="mini-label">Not configured</span></div>
 <form id="video-ai-settings-form" class="platform-form video-ai-settings">
  <label>Claude API key<input id="video-ai-key" type="password" autocomplete="new-password" maxlength="400" placeholder="New key, or leave blank to keep the saved key"></label>
  <label>Model<select id="video-ai-model"><option value="claude-haiku-5-5">Claude Haiku 5.5 · fast</option><option value="claude-sonnet-5-5">Claude Sonnet 5.5 · detailed</option><option value="claude-opus-5-5">Claude Opus 5.5 · advanced</option></select></label>
  <div class="platform-control-row"><button class="btn btn-primary" type="submit">Save encrypted key</button><button class="btn btn-outline" type="button" id="video-ai-test">Test connection</button><button class="btn btn-outline" type="button" id="video-ai-disconnect">Remove key</button></div>
 </form>
 <p id="video-ai-feedback" class="voice-status" role="status" aria-live="polite">Claude is disabled until a key is configured.</p>
 <div class="video-ai-privacy"><h3>Data and approval policy</h3><p>When an administrator presses Generate proposal, the server sends clip names, types, durations, trim positions, order, available song names, and the entered instruction to Anthropic. It does not send camera footage, video, audio, private media URLs, credentials, or player accounts. Claude only returns editing proposals; nothing is saved or rendered until you approve the change and save the timeline.</p><p>HomeServer gameplay remains available without any cloud AI connection. No background calls are made to Claude.</p></div>
 <h3>Video editor skills</h3><div id="video-ai-admin-skills" class="video-ai-skill-list" aria-label="Available AI editing skills"></div>
</section>
<section id="tab-history" class="surface platform-admin-panel" hidden><h2>Finished-game archive</h2><p>Completed games are preserved with their audit records, prizes, and session timestamps.</p><button class="btn btn-outline" id="refresh-history">↻ Refresh</button><div id="history-items" class="platform-claim-list"></div><button class="btn btn-outline" id="export-history">Export history CSV</button></section>
<section id="tab-migration" class="surface platform-admin-panel" hidden><h2>Import a V5 browser configuration</h2><p>The older game stored prizes and music in one browser. You can import its Tier 1, Tier 2, and Bonus prize definitions here. This does not erase the old browser copy. Existing claimed prizes are imported into a separate completed historical session, never into an active game. Duplicate imports of the same game are blocked.</p><p id="legacy-status" class="voice-status">Checking this browser…</p><button id="import-legacy" class="btn btn-outline">Import prizes and archived claims</button></section>
</div><p id="admin-status" class="voice-status" role="status" aria-live="polite"></p></div></div>
<script src="public/client.js" defer></script><script src="public/video-gallery.js?v=5q26a-gallery" defer></script><script src="public/admin-recordings.js?v=hf8d0e" defer></script><script src="public/camera-profiles.js?v=5q25d" defer></script><script src="public/camera-admin.js?v=5q25d" defer></script><script src="public/admin.js?v=5q29e4d1hf2" defer></script><script src="public/admin-video-ai.js?v=5q28a" defer></script><script src="public/game-catalog.js?v=5q29e4c" defer></script><script src="public/game-catalog-tabs.js?v=5q26a" defer></script><?php shellEnd(); ?>
