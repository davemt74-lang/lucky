<?php
declare(strict_types=1);
require __DIR__.'/lib/Layout.php';if(!installed()){header('Location: install.php');exit;}
require_once __DIR__.'/lib/PairingWidget.php';
shellStart('Controllers & Devices','devices');
?><section class="page-intro"><div><p class="eyebrow">YOUR LIVING ROOM SETUP</p><h1>Controllers & <span class="accent">Devices.</span></h1><p>Pair phones, approve or disconnect controllers, and check locally connected gamepads.</p></div><a href="index.php" class="btn btn-outline">← Games</a></section>
<section class="device-phone-primary" aria-label="Phone controller management">
<?php if(isAdmin()){pairingWidget();?><script src="public/qr.js?v=5q10" defer></script><script src="public/pairing-host.js?v=5q10" defer></script><?php }else{?><section class="surface console-section"><h2>Phone controllers</h2><p>Sign in as host to pair or manage phones.</p><a href="login.php" class="btn btn-outline">Host sign in</a></section><?php } ?>
</section>
<div class="console-grid device-secondary"><section class="surface console-section"><span class="mini-label">LOCAL HARDWARE</span><h2>Detected gamepads</h2><p id="controller-status" role="status">Checking for connected gamepads…</p><div id="controller-list" class="console-device-list"></div><button class="btn btn-outline" id="controller-refresh" type="button">Refresh devices</button></section><section class="surface console-section"><span class="mini-label">KEYBOARD · GAMEPAD · PHONE</span><h2>On-screen controls</h2><p>Use arrow keys or a D-pad to navigate the active screen, Select / Enter to activate the focused control, and Back / Escape to close overlays.</p><p>Paired phones control the active host or TV display. Keep that screen open to receive navigation commands.</p></section></div>
<script src="public/device-page.js" defer></script><?php shellEnd(); ?>
