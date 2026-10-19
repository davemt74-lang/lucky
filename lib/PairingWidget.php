<?php
declare(strict_types=1);
function pairingWidget(bool $receiver=false):void { ?>
<section class="surface platform-widget phone-pair-panel" id="phone-pairing" data-controller-receiver="<?= $receiver ? '1' : '0' ?>" aria-labelledby="pairing-title">
  <div class="phone-pair-heading"><div><p class="mini-label">WIRELESS GAME CONTROLS</p><h2 id="pairing-title">Connect a phone</h2></div><button type="button" class="btn btn-primary" id="pair-new">＋ Connect Phone</button></div>
  <p>Your phone becomes a private controller. Scan the code, name the phone, then approve it here. Connections last up to 8 hours or until disconnected.</p>
  <div id="pair-invite" hidden><div class="phone-pair-invite"><div id="pair-qr" class="phone-pair-qr" aria-label="Controller pairing QR code"></div><div><strong>Scan with your phone camera</strong><p>The invitation expires after five minutes. Keep it private.</p><label>Or open this secure link <input type="url" readonly id="pair-link" autocomplete="off"></label><button type="button" class="btn btn-outline" id="pair-copy">Copy pairing link</button></div></div></div>
  <div id="pair-host-status" role="status" class="voice-status" aria-live="polite">No phone connected yet.</div>
  <div id="pair-devices" class="phone-pair-devices"></div>
</section>
<?php }
